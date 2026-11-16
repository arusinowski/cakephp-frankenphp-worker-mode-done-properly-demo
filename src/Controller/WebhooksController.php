<?php
declare(strict_types=1);

namespace App\Controller;

use App\Flags\TenantSecretCache;
use App\Model\Entity\Flag;
use Cake\Database\Driver\Sqlite;
use Cake\Database\Expression\QueryExpression;
use Cake\Http\Response;
use JsonException;

/**
 * Signed flag webhook: `POST /webhooks/{tenant}`.
 *
 * Header `X-Signature: sha256=<hex HMAC-SHA256 of the raw body, keyed with the tenant's secret>`.
 * Body `{"flag": "<code>", "enabled": <bool>, "rules": <object|null, optional>}`. Upserts one flag and
 * increments the tenant's flags_version in one transaction.
 */
class WebhooksController extends AppController
{
    /**
     * @param string $tenant Tenant slug.
     * @param \App\Flags\TenantSecretCache $secrets Shared, reset after every request in a worker.
     * @return \Cake\Http\Response
     */
    public function receive(string $tenant, TenantSecretCache $secrets): Response
    {
        $tenants = $this->fetchTable('Tenants');
        /** @var \App\Model\Entity\Tenant $entity */
        $entity = $tenants->find()->where(['slug' => $tenant])->firstOrFail();

        $body = (string)$this->request->getBody();
        $expected = 'sha256=' . hash_hmac('sha256', $body, $secrets->get($entity->id));
        if (!hash_equals($expected, $this->request->getHeaderLine('X-Signature'))) {
            return $this->jsonResponse(401, ['error' => 'Invalid signature.']);
        }

        try {
            $payload = json_decode($body, true, 32, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return $this->jsonResponse(422, ['error' => 'Body must be valid JSON.']);
        }
        if (!is_array($payload) || array_is_list($payload)) {
            return $this->jsonResponse(422, ['error' => 'Body must be a JSON object.']);
        }
        if (!is_string($payload['flag'] ?? null) || $payload['flag'] === '') {
            return $this->jsonResponse(422, ['error' => '"flag" must be a non-empty string.']);
        }
        if (!is_bool($payload['enabled'] ?? null)) {
            return $this->jsonResponse(422, ['error' => '"enabled" must be a boolean.']);
        }

        $flags = $this->fetchTable('Flags');
        $error = null;
        $version = $flags->getConnection()->transactional(
            function () use ($flags, $tenants, $entity, $payload, &$error): int|false {
                // Serialize webhooks per tenant. Without the lock, concurrent webhooks for one tenant fail on
                // MariaDB 11.8 (snapshot isolation: error 1020 "Record has changed since last read") and race
                // to create the same new flag. Every other supported driver takes FOR UPDATE except SQLite,
                // used by the tests, which has no such clause and locks the whole database on write anyway.
                if (!($tenants->getConnection()->getDriver() instanceof Sqlite)) {
                    $tenants->find()->select(['id'])->where(['id' => $entity->id])->epilog('FOR UPDATE')->first();
                }
                $flag = $flags->find()
                    ->where(['tenant_id' => $entity->id, 'code' => $payload['flag']])
                    ->first() ?? $flags->newEmptyEntity();
                assert($flag instanceof Flag);
                $data = ['tenant_id' => $entity->id, 'code' => $payload['flag'], 'enabled' => $payload['enabled']];
                if (array_key_exists('rules', $payload)) {
                    $data['rules'] = $payload['rules'];
                }
                $flag = $flags->patchEntity($flag, $data);
                if (!$flags->save($flag)) {
                    $error = $flag->getErrors();

                    return false;
                }
                $tenants->updateAll(new QueryExpression('flags_version = flags_version + 1'), ['id' => $entity->id]);

                /** @var \App\Model\Entity\Tenant $fresh */
                $fresh = $tenants->get($entity->id);

                return $fresh->flags_version;
            },
        );
        if ($version === false) {
            return $this->jsonResponse(422, ['error' => 'Invalid flag.', 'fields' => $error]);
        }

        return $this->jsonResponse(200, ['version' => $version]);
    }

    /**
     * @param int $status HTTP status.
     * @param array<string, mixed> $data Response body.
     * @return \Cake\Http\Response
     */
    private function jsonResponse(int $status, array $data): Response
    {
        return $this->response
            ->withStatus($status)
            ->withType('application/json')
            ->withStringBody((string)json_encode($data));
    }
}
