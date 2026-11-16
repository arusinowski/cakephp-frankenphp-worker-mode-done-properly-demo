<?php
declare(strict_types=1);

namespace App\Test\Fixture;

use Cake\TestSuite\Fixture\TestFixture;

class FlagsFixture extends TestFixture
{
    /**
     * @return void
     */
    public function init(): void
    {
        $flags = [
            [1, 'new-checkout', true, null],
            [1, 'pro-only', true, ['segments' => [['attr' => 'plan', 'op' => 'eq', 'value' => 'pro']]]],
            [1, 'half-rollout', true, ['rollout' => 50]],
            [1, 'disabled-flag', false, null],
            [2, 'new-checkout', false, null],
        ];
        $this->records = [];
        foreach ($flags as $i => [$tenantId, $code, $enabled, $rules]) {
            $this->records[] = [
                'id' => $i + 1,
                'tenant_id' => $tenantId,
                'code' => $code,
                'enabled' => $enabled,
                'rules' => $rules,
                'created' => '2026-11-14 10:00:00',
                'modified' => '2026-11-14 10:00:00',
            ];
        }
        parent::init();
    }
}
