<?php
declare(strict_types=1);

namespace App\Test\TestCase\Controller\Api;

use Cake\TestSuite\IntegrationTestTrait;
use Cake\TestSuite\TestCase;

class FlagsControllerTest extends TestCase
{
    use IntegrationTestTrait;

    /**
     * @var list<string>
     */
    protected array $fixtures = ['app.Tenants', 'app.Flags'];

    public function testReturnsFlagsForTenant(): void
    {
        $this->get('/api/flags/acme?plan=pro');
        $this->assertResponseOk();
        $this->assertContentType('application/json');
        $this->assertSame(
            [
                'tenant' => 'acme',
                'version' => 1,
                'flags' => ['disabled-flag' => false, 'half-rollout' => false, 'new-checkout' => true, 'pro-only' => true],
            ],
            $this->json(),
        );
    }

    public function testContextChangesResult(): void
    {
        $this->get('/api/flags/acme?plan=free');
        $this->assertResponseOk();
        $this->assertFalse($this->json()['flags']['pro-only']);
    }

    public function testUserParamDrivesRollout(): void
    {
        $this->get('/api/flags/acme?user=u1');
        $this->assertSame(crc32('half-rollout:u1') % 100 < 50, $this->json()['flags']['half-rollout']);
    }

    public function testUnknownTenantIs404(): void
    {
        $this->get('/api/flags/nope');
        $this->assertResponseCode(404);
    }

    public function testArrayQueryParamsAreIgnored(): void
    {
        $this->get('/api/flags/acme?country[]=PL&plan[]=pro');
        $this->assertResponseOk();
        $this->assertFalse($this->json()['flags']['pro-only']);
    }

    public function testDoesNotStartSession(): void
    {
        $this->get('/api/flags/acme');
        $this->assertResponseOk();
        $this->assertCookieNotSet('CAKEPHP');
        $this->assertCookieNotSet('csrfToken');
    }

    public function testEmptyUserCountsAsMissing(): void
    {
        $this->get('/api/flags/acme?user=&plan=');
        $this->assertResponseOk();
        $this->assertFalse($this->json()['flags']['half-rollout']);
    }

    /**
     * @return array<string, mixed>
     */
    private function json(): array
    {
        return (array)json_decode((string)$this->_response->getBody(), true);
    }
}
