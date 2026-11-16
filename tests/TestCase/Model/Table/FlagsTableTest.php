<?php
declare(strict_types=1);

namespace App\Test\TestCase\Model\Table;

use Cake\TestSuite\TestCase;

class FlagsTableTest extends TestCase
{
    /**
     * @var list<string>
     */
    protected array $fixtures = ['app.Tenants', 'app.Flags'];

    public function testRejectsInvalidRules(): void
    {
        $flags = $this->fetchTable('Flags');
        $flag = $flags->newEntity(['tenant_id' => 1, 'code' => 'bad', 'enabled' => true, 'rules' => ['rollout' => 101]]);
        $this->assertNotEmpty($flag->getError('rules'));
    }

    public function testRejectsEmptyStringRules(): void
    {
        $flag = $this->fetchTable('Flags')->newEntity(['tenant_id' => 1, 'code' => 'bad', 'enabled' => true, 'rules' => '']);
        $this->assertNotEmpty($flag->getError('rules'));
    }

    public function testAcceptsNullAndEmptyObjectRules(): void
    {
        $flags = $this->fetchTable('Flags');
        $this->assertSame([], $flags->newEntity(['tenant_id' => 1, 'code' => 'a', 'enabled' => true, 'rules' => null])->getErrors());
        $this->assertSame([], $flags->newEntity(['tenant_id' => 1, 'code' => 'b', 'enabled' => true, 'rules' => []])->getErrors());
    }

    public function testAcceptsValidRules(): void
    {
        $flags = $this->fetchTable('Flags');
        $flag = $flags->newEntity(['tenant_id' => 1, 'code' => 'ok', 'enabled' => true, 'rules' => ['rollout' => 10]]);
        $this->assertSame([], $flag->getErrors());
    }

    public function testCodeIsUniquePerTenant(): void
    {
        $flags = $this->fetchTable('Flags');
        $this->assertFalse($flags->save($flags->newEntity(['tenant_id' => 1, 'code' => 'new-checkout', 'enabled' => true])));
        $this->assertNotFalse($flags->save($flags->newEntity(['tenant_id' => 2, 'code' => 'pro-only', 'enabled' => true])));
    }

    public function testRulesRoundTripAsArray(): void
    {
        $flag = $this->fetchTable('Flags')->find()->where(['code' => 'pro-only'])->firstOrFail();
        $this->assertSame(['segments' => [['attr' => 'plan', 'op' => 'eq', 'value' => 'pro']]], $flag->rules);
    }

    public function testTenantHidesSecret(): void
    {
        $json = (string)json_encode($this->fetchTable('Tenants')->get(1));
        $this->assertStringNotContainsString('acme-test-secret', $json);
        $this->assertStringContainsString('acme', $json);
    }
}
