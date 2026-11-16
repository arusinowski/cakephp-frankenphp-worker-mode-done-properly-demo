<?php
declare(strict_types=1);

namespace App\Test\Fixture;

use Cake\TestSuite\Fixture\TestFixture;

class TenantsFixture extends TestFixture
{
    /**
     * @return void
     */
    public function init(): void
    {
        $this->records = [
            [
                'id' => 1,
                'slug' => 'acme',
                'name' => 'Acme',
                'webhook_secret' => 'acme-test-secret',
                'flags_version' => 1,
                'created' => '2026-11-14 10:00:00',
                'modified' => '2026-11-14 10:00:00',
            ],
            [
                'id' => 2,
                'slug' => 'globex',
                'name' => 'Globex',
                'webhook_secret' => 'globex-test-secret',
                'flags_version' => 1,
                'created' => '2026-11-14 10:00:00',
                'modified' => '2026-11-14 10:00:00',
            ],
        ];
        parent::init();
    }
}
