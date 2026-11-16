<?php
declare(strict_types=1);

namespace App\Test\TestCase\Flags;

use App\Flags\TenantSecretCache;
use App\Worker\ResettableInterface;
use Cake\TestSuite\TestCase;

class TenantSecretCacheTest extends TestCase
{
    /**
     * @var list<string>
     */
    protected array $fixtures = ['app.Tenants'];

    public function testLoadsSecret(): void
    {
        $this->assertSame('acme-test-secret', (new TenantSecretCache())->get(1));
    }

    public function testSecretsAreKeyedByTenant(): void
    {
        // Article section 5: the fix for the leftover-secret leak.
        $cache = new TenantSecretCache();
        $cache->get(1);
        $this->assertSame('globex-test-secret', $cache->get(2));
    }

    public function testResetForgetsSecrets(): void
    {
        $cache = new TenantSecretCache();
        $this->assertInstanceOf(ResettableInterface::class, $cache);
        $cache->get(1);
        $this->fetchTable('Tenants')->updateAll(['webhook_secret' => 'rotated'], ['id' => 1]);
        $this->assertSame('acme-test-secret', $cache->get(1));
        $cache->reset();
        $this->assertSame('rotated', $cache->get(1));
    }
}
