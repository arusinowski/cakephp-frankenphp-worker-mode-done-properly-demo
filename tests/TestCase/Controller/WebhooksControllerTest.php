<?php
declare(strict_types=1);

namespace App\Test\TestCase\Controller;

use App\Model\Entity\Flag;
use Cake\TestSuite\IntegrationTestTrait;
use Cake\TestSuite\TestCase;

class WebhooksControllerTest extends TestCase
{
    use IntegrationTestTrait;

    /**
     * @var list<string>
     */
    protected array $fixtures = ['app.Tenants', 'app.Flags'];

    public function testValidSignatureUpdatesFlagAndBumpsVersion(): void
    {
        $this->send('acme', '{"flag":"new-checkout","enabled":false}', 'acme-test-secret');
        $this->assertResponseOk();
        $this->assertSame(['version' => 2], $this->json());
        $this->assertFalse($this->flag(1, 'new-checkout')->enabled);
        $this->assertSame(2, $this->fetchTable('Tenants')->get(1)->flags_version);
    }

    public function testOmittedRulesKeepExisting(): void
    {
        $this->send('acme', '{"flag":"pro-only","enabled":true}', 'acme-test-secret');
        $this->assertResponseOk();
        $this->assertSame(['segments' => [['attr' => 'plan', 'op' => 'eq', 'value' => 'pro']]], $this->flag(1, 'pro-only')->rules);
    }

    public function testNullRulesClear(): void
    {
        $this->send('acme', '{"flag":"pro-only","enabled":true,"rules":null}', 'acme-test-secret');
        $this->assertResponseOk();
        $this->assertNull($this->flag(1, 'pro-only')->rules);
    }

    public function testRulesAreReplaced(): void
    {
        $this->send('acme', '{"flag":"pro-only","enabled":true,"rules":{"rollout":10}}', 'acme-test-secret');
        $this->assertResponseOk();
        $this->assertSame(['rollout' => 10], $this->flag(1, 'pro-only')->rules);
    }

    public function testUnknownFlagIsCreated(): void
    {
        $this->send('acme', '{"flag":"brand-new","enabled":true}', 'acme-test-secret');
        $this->assertResponseOk();
        $flag = $this->flag(1, 'brand-new');
        $this->assertTrue($flag->enabled);
        $this->assertNull($flag->rules);
    }

    public function testMissingSignatureIs401(): void
    {
        $this->send('acme', '{"flag":"new-checkout","enabled":false}', null);
        $this->assertResponseCode(401);
        $this->assertUnchanged();
    }

    public function testWrongSecretIs401(): void
    {
        $this->send('acme', '{"flag":"new-checkout","enabled":false}', 'globex-test-secret');
        $this->assertResponseCode(401);
        $this->assertUnchanged();
    }

    public function testSignatureWithoutPrefixIs401(): void
    {
        $body = '{"flag":"new-checkout","enabled":false}';
        $this->configRequest(['headers' => [
            'Content-Type' => 'application/json',
            'X-Signature' => hash_hmac('sha256', $body, 'acme-test-secret'),
        ]]);
        $this->post('/webhooks/acme', $body);
        $this->assertResponseCode(401);
        $this->assertUnchanged();
    }

    public function testSignatureOfDifferentBodyIs401(): void
    {
        $this->configRequest(['headers' => [
            'Content-Type' => 'application/json',
            'X-Signature' => 'sha256=' . hash_hmac('sha256', '{"flag":"new-checkout","enabled":false}', 'acme-test-secret'),
        ]]);
        $this->post('/webhooks/acme', '{"enabled":false,"flag":"new-checkout"}');
        $this->assertResponseCode(401);
        $this->assertUnchanged();
    }

    public function testUnknownTenantIs404(): void
    {
        $this->send('nope', '{"flag":"new-checkout","enabled":false}', 'acme-test-secret');
        $this->assertResponseCode(404);
    }

    public function testInvalidPayloadIs422(): void
    {
        $bodies = [
            'not json',
            '[1,2]',
            '"x"',
            '{"enabled":true}',
            '{"flag":"a"}',
            '{"flag":"","enabled":true}',
            '{"flag":"a","enabled":"true"}',
            '{"flag":"a","enabled":true,"rules":"x"}',
            '{"flag":"a","enabled":true,"rules":""}',
            '{"flag":"a","enabled":true,"rules":[1,2]}',
            '{"flag":"a","enabled":true,"rules":{"rollout":101}}',
        ];
        foreach ($bodies as $body) {
            $this->send('acme', $body, 'acme-test-secret');
            $this->assertResponseCode(422, $body);
            $this->assertArrayHasKey('error', $this->json(), $body);
        }
        $this->assertSame(1, $this->fetchTable('Tenants')->get(1)->flags_version);
        $this->assertSame(0, $this->fetchTable('Flags')->find()->where(['code' => 'a'])->count());
    }

    private function send(string $slug, string $body, ?string $secret): void
    {
        $headers = ['Content-Type' => 'application/json'];
        if ($secret !== null) {
            $headers['X-Signature'] = 'sha256=' . hash_hmac('sha256', $body, $secret);
        }
        $this->configRequest(['headers' => $headers]);
        $this->post("/webhooks/{$slug}", $body);
    }

    private function flag(int $tenantId, string $code): Flag
    {
        /** @var \App\Model\Entity\Flag */
        return $this->fetchTable('Flags')->find()->where(['tenant_id' => $tenantId, 'code' => $code])->firstOrFail();
    }

    private function assertUnchanged(): void
    {
        $this->assertTrue($this->flag(1, 'new-checkout')->enabled);
        $this->assertSame(1, $this->fetchTable('Tenants')->get(1)->flags_version);
    }

    /**
     * @return array<string, mixed>
     */
    private function json(): array
    {
        return (array)json_decode((string)$this->_response->getBody(), true);
    }
}
