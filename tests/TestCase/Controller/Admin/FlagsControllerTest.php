<?php
declare(strict_types=1);

namespace App\Test\TestCase\Controller\Admin;

use Cake\TestSuite\IntegrationTestTrait;
use Cake\TestSuite\TestCase;

class FlagsControllerTest extends TestCase
{
    use IntegrationTestTrait;

    /**
     * @var list<string>
     */
    protected array $fixtures = ['app.Tenants', 'app.Flags'];

    public function testListsTenantsAndFlags(): void
    {
        $this->get('/admin/flags');
        $this->assertResponseOk();
        $this->assertResponseContains('acme');
        $this->assertResponseContains('globex');
        $this->assertResponseContains('pro-only');
        $this->assertResponseContains('&quot;rollout&quot;:50');
        $this->assertResponseNotContains('acme-test-secret');
        $this->assertResponseNotContains('globex-test-secret');
    }
}
