<?php
declare(strict_types=1);

use Migrations\BaseSeed;
use function Cake\Core\env;

/**
 * Demo data: two tenants with 50 flags each, with opposite on/off values.
 *
 * Run with `bin/cake seeds run Demo`. Webhook secrets come from
 * DEMO_ACME_WEBHOOK_SECRET and DEMO_GLOBEX_WEBHOOK_SECRET (see config/.env.example).
 */
class DemoSeed extends BaseSeed
{
    /**
     * @var int
     */
    private const FLAGS_PER_TENANT = 50;

    /**
     * @return void
     */
    public function run(): void
    {
        $now = date('Y-m-d H:i:s');
        $tenants = [
            ['slug' => 'acme', 'name' => 'Acme', 'secret' => 'DEMO_ACME_WEBHOOK_SECRET'],
            ['slug' => 'globex', 'name' => 'Globex', 'secret' => 'DEMO_GLOBEX_WEBHOOK_SECRET'],
        ];

        foreach ($tenants as $tenant) {
            $secret = env($tenant['secret']);
            if (!is_string($secret) || $secret === '') {
                throw new RuntimeException(sprintf(
                    'Set %s in config/.env before seeding (see config/.env.example).',
                    $tenant['secret'],
                ));
            }
            $this->table('tenants')->insert([
                'slug' => $tenant['slug'],
                'name' => $tenant['name'],
                'webhook_secret' => $secret,
                'flags_version' => 1,
                'created' => $now,
                'modified' => $now,
            ])->saveData();

            $row = $this->fetchRow(sprintf("SELECT id FROM tenants WHERE slug = '%s'", $tenant['slug']));
            $tenantId = (int)$row['id'];

            $rows = [];
            foreach ($this->flags() as $code => [$enabled, $rules]) {
                $rows[] = [
                    'tenant_id' => $tenantId,
                    'code' => $code,
                    // Globex gets the opposite on/off values, so a flag served to the wrong tenant is visible.
                    'enabled' => $tenant['slug'] === 'globex' ? !$enabled : $enabled,
                    'rules' => $rules === null ? null : json_encode($rules),
                    'created' => $now,
                    'modified' => $now,
                ];
            }
            $this->table('flags')->insert($rows)->saveData();
        }
    }

    /**
     * Flags covering every op, a partial rollout and a disabled flag, padded to FLAGS_PER_TENANT.
     *
     * @return array<string, array{0: bool, 1: array<string, mixed>|null}>
     */
    private function flags(): array
    {
        $flags = [
            'new-checkout' => [true, null],
            'dark-mode' => [true, ['rollout' => 25]],
            'pro-reports' => [true, ['segments' => [['attr' => 'plan', 'op' => 'eq', 'value' => 'pro']]]],
            'free-upsell' => [true, ['segments' => [['attr' => 'plan', 'op' => 'neq', 'value' => 'pro']]]],
            'eu-invoices' => [true, ['segments' => [['attr' => 'country', 'op' => 'in', 'values' => ['PL', 'DE', 'FR']]]]],
            'non-us-shipping' => [true, ['segments' => [['attr' => 'country', 'op' => 'not_in', 'values' => ['US']]]]],
            'beta-search' => [true, [
                'rollout' => 10,
                'segments' => [
                    ['attr' => 'plan', 'op' => 'eq', 'value' => 'pro'],
                    ['attr' => 'country', 'op' => 'in', 'values' => ['PL', 'DE']],
                ],
            ]],
            'legacy-export' => [false, null],
        ];

        $ops = ['eq', 'neq', 'in', 'not_in'];
        $countries = ['PL', 'DE', 'FR', 'US', 'GB'];
        for ($i = 1; count($flags) < self::FLAGS_PER_TENANT; $i++) {
            $op = $ops[$i % 4];
            $segment = in_array($op, ['eq', 'neq'], true)
                ? ['attr' => 'plan', 'op' => $op, 'value' => $i % 2 ? 'pro' : 'free']
                : ['attr' => 'country', 'op' => $op, 'values' => array_slice($countries, $i % 3, 2)];
            $flags[sprintf('feature-%02d', $i)] = [$i % 7 !== 0, ['rollout' => ($i * 13) % 101, 'segments' => [$segment]]];
        }

        return $flags;
    }
}
