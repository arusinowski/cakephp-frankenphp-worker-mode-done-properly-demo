<?php
declare(strict_types=1);

namespace App\Command;

use App\Flags\FlagEvaluator;
use App\Flags\RuleCompiler;
use App\Model\Entity\Tenant;
use Cake\Command\Command;
use Cake\Console\Arguments;
use Cake\Console\ConsoleIo;
use Cake\Console\ConsoleOptionParser;

/**
 * Alternating-tenant soak test against a running server (article section 6).
 *
 * Each round, for every tenant in turn:
 *
 * 1. `wrong-flags`: reads its flags for a few callers and compares them with a fresh evaluation from the
 *    database (catches tenant A being served tenant B's flags);
 * 2. `wrong-secret`: sends a webhook signed with the tenant's own secret and expects 200 (catches a webhook
 *    checked against another tenant's secret);
 * 3. `stale-flags`: reads its flags again and expects the new version;
 * 4. `workers-missed`: those reads go out in parallel batches until every worker has answered, told apart by
 *    the dev-only X-Worker-Id header (WORKER_EXPOSE_ID=1). Sequential requests all land on the same idle
 *    worker, so without this a pass would say nothing about the other workers.
 *
 * It changes data: it upserts a `soak-probe` flag per tenant and increments the tenants' flags versions.
 *
 *     bin/cake worker_soak http://frankenphp-worker --rounds 20 --workers 2
 *     bin/cake worker_soak http://frankenphp-classic --workers 0     # no workers to tell apart
 */
class WorkerSoakCommand extends Command
{
    /**
     * @var list<array<string, string>>
     */
    private const CALLERS = [
        ['user' => 'u1', 'country' => 'PL', 'plan' => 'pro'],
        ['user' => 'u2', 'country' => 'US', 'plan' => 'free'],
        ['user' => 'u3'],
    ];

    /**
     * @inheritDoc
     */
    public static function getDescription(): string
    {
        return 'Alternating-tenant soak test of the flag API and webhook against a running server.';
    }

    /**
     * @inheritDoc
     */
    public function buildOptionParser(ConsoleOptionParser $parser): ConsoleOptionParser
    {
        return $parser
            ->setDescription(static::getDescription())
            ->addArgument('base_url', ['help' => 'Server to test, e.g. http://frankenphp-worker', 'required' => true])
            ->addOption('rounds', ['help' => 'Rounds over all tenants', 'default' => '20'])
            ->addOption('workers', ['help' => 'Workers that must answer after each webhook; 0 skips', 'default' => '2'])
            ->addOption('batch', ['help' => 'Parallel reads per batch after each webhook', 'default' => '8']);
    }

    /**
     * @inheritDoc
     */
    public function execute(Arguments $args, ConsoleIo $io): int
    {
        $baseUrl = rtrim((string)$args->getArgument('base_url'), '/');
        $rounds = (int)$args->getOption('rounds');
        $workers = (int)$args->getOption('workers');
        $batch = (int)$args->getOption('batch');
        $failures = array_fill_keys(['wrong-flags', 'wrong-secret', 'stale-flags', 'workers-missed'], 0);
        $first = [];
        $fail = function (string $kind, string $problem) use (&$failures, &$first): void {
            $failures[$kind]++;
            $first[$kind] ??= $problem;
        };
        $requests = 0;
        $seenWorkers = [];

        $slugs = $this->fetchTable('Tenants')->find()->orderBy(['slug' => 'ASC'])->all()->extract('slug')->toList();
        for ($round = 1; $round <= $rounds; $round++) {
            foreach ($slugs as $slug) {
                foreach (self::CALLERS as $caller) {
                    $requests++;
                    [$response] = $this->send([$this->flagsRequest($baseUrl, $slug, $caller)]);
                    $problem = $this->checkFlags($slug, $caller, $response);
                    if ($problem !== null) {
                        $fail('wrong-flags', $problem);
                    }
                }

                $requests++;
                [$response] = $this->send([$this->webhookRequest($baseUrl, $slug, $round % 2 === 0)]);
                if ($response['status'] !== 200) {
                    $fail('wrong-secret', "{$slug}: HTTP {$response['status']} {$response['body']}");
                    continue;
                }

                $answered = [];
                $read = $this->flagsRequest($baseUrl, $slug, self::CALLERS[0]);
                for ($i = 0; $i < 10; $i++) {
                    foreach ($this->send(array_fill(0, $batch, $read)) as $response) {
                        $requests++;
                        $answered[$response['worker']] = true;
                        $seenWorkers[$response['worker']] = true;
                        $problem = $this->checkFlags($slug, self::CALLERS[0], $response);
                        if ($problem !== null) {
                            $fail('stale-flags', $problem);
                        }
                    }
                    unset($answered['']);
                    if (count($answered) >= $workers) {
                        break;
                    }
                }
                if (count($answered) < $workers) {
                    $fail('workers-missed', sprintf('%s: %d of %d answered', $slug, count($answered), $workers));
                }
            }
        }

        unset($seenWorkers['']);
        $io->out(sprintf('%d requests to %s, %d rounds over %s', $requests, $baseUrl, $rounds, implode(', ', $slugs)));
        $io->out(sprintf('%d distinct workers answered', count($seenWorkers)));
        foreach ($failures as $kind => $count) {
            $line = sprintf('%-15s %d', $kind, $count);
            $count === 0 ? $io->success($line) : $io->error($line . '  first: ' . $first[$kind]);
        }

        return array_sum($failures) === 0 ? static::CODE_SUCCESS : static::CODE_ERROR;
    }

    /**
     * Compare an answer of the flag API with a fresh evaluation from the database.
     *
     * @param string $slug Tenant slug.
     * @param array<string, string> $caller Query parameters.
     * @param array{status: int, body: string, worker: string} $response Response.
     * @return string|null Problem, null when correct.
     */
    private function checkFlags(string $slug, array $caller, array $response): ?string
    {
        $tenant = $this->tenant($slug);
        $body = json_decode($response['body'], true);
        $where = sprintf('%s (worker %s)', $slug, $response['worker'] ?: '?');
        if ($response['status'] !== 200 || !is_array($body)) {
            return "{$where}: HTTP {$response['status']}";
        }
        if (($body['version'] ?? null) !== $tenant->flags_version) {
            $version = json_encode($body['version'] ?? null);

            return "{$where}: version {$version}, database has {$tenant->flags_version}";
        }
        $actual = (array)($body['flags'] ?? []);
        ksort($actual);
        $expected = (new FlagEvaluator(new RuleCompiler()))->evaluate($tenant, $caller);

        return $actual === $expected ? null : "{$where}: flags differ from the database";
    }

    /**
     * @param string $baseUrl Server.
     * @param string $slug Tenant slug.
     * @param array<string, string> $caller Query parameters.
     * @return array<int, mixed> curl options.
     */
    private function flagsRequest(string $baseUrl, string $slug, array $caller): array
    {
        return [CURLOPT_URL => "{$baseUrl}/api/flags/{$slug}?" . http_build_query($caller)];
    }

    /**
     * A webhook for the tenant's `soak-probe` flag, signed with the tenant's own secret.
     *
     * @param string $baseUrl Server.
     * @param string $slug Tenant slug.
     * @param bool $enabled New value of the flag.
     * @return array<int, mixed> curl options.
     */
    private function webhookRequest(string $baseUrl, string $slug, bool $enabled): array
    {
        $body = (string)json_encode(['flag' => 'soak-probe', 'enabled' => $enabled]);
        $signature = 'sha256=' . hash_hmac('sha256', $body, $this->tenant($slug)->webhook_secret);

        return [
            CURLOPT_URL => "{$baseUrl}/webhooks/{$slug}",
            CURLOPT_POSTFIELDS => $body,
            CURLOPT_HTTPHEADER => ['Content-Type: application/json', "X-Signature: {$signature}"],
        ];
    }

    /**
     * Send requests in parallel (one request is a batch of one).
     *
     * @param list<array<int, mixed>> $requests curl options per request.
     * @return list<array{status: int, body: string, worker: string}>
     */
    private function send(array $requests): array
    {
        $multi = curl_multi_init();
        $handles = [];
        $workers = [];
        foreach ($requests as $i => $options) {
            $workers[$i] = '';
            $handle = curl_init();
            curl_setopt_array($handle, $options + [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT => 10,
                CURLOPT_HEADERFUNCTION => function ($handle, string $header) use (&$workers, $i): int {
                    if (stripos($header, 'X-Worker-Id:') === 0) {
                        $workers[$i] = trim(substr($header, 12));
                    }

                    return strlen($header);
                },
            ]);
            curl_multi_add_handle($multi, $handle);
            $handles[$i] = $handle;
        }
        do {
            curl_multi_exec($multi, $running);
            curl_multi_select($multi);
        } while ($running > 0);

        $responses = [];
        foreach ($handles as $i => $handle) {
            $responses[] = [
                'status' => (int)curl_getinfo($handle, CURLINFO_HTTP_CODE),
                'body' => (string)curl_multi_getcontent($handle),
                'worker' => $workers[$i],
            ];
            curl_multi_remove_handle($multi, $handle);
        }
        curl_multi_close($multi);

        return $responses;
    }

    /**
     * @param string $slug Tenant slug.
     * @return \App\Model\Entity\Tenant
     */
    private function tenant(string $slug): Tenant
    {
        /** @var \App\Model\Entity\Tenant */
        return $this->fetchTable('Tenants')->find()->where(['slug' => $slug])->firstOrFail();
    }
}
