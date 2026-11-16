<?php
declare(strict_types=1);

namespace App\Controller\Api;

use App\Controller\AppController;
use App\Flags\FlagEvaluator;

/**
 * Flag API: `GET /api/flags/{tenant}?user=&country=&plan=`.
 */
class FlagsController extends AppController
{
    /**
     * @param string $tenant Tenant slug.
     * @param \App\Flags\FlagEvaluator $evaluator Shared evaluator from the container.
     * @return void
     */
    public function view(string $tenant, FlagEvaluator $evaluator): void
    {
        /** @var \App\Model\Entity\Tenant $entity */
        $entity = $this->fetchTable('Tenants')->find()->where(['slug' => $tenant])->firstOrFail();

        $context = [];
        foreach (['user', 'country', 'plan'] as $attr) {
            $value = $this->request->getQuery($attr);
            // Arrays and empty strings count as missing: `?user=` must not become one shared rollout bucket.
            if (is_string($value) && $value !== '') {
                $context[$attr] = $value;
            }
        }

        $this->set([
            'tenant' => $entity->slug,
            'version' => $entity->flags_version,
            'flags' => $evaluator->evaluate($entity, $context),
        ]);
        $this->viewBuilder()
            ->setClassName('Json')
            ->setOption('serialize', ['tenant', 'version', 'flags']);
    }
}
