<?php
declare(strict_types=1);

namespace App\Test\TestCase\Flags;

use App\Flags\FlagEvaluator;
use App\Flags\RuleCompiler;
use Cake\TestSuite\TestCase;

class FlagEvaluatorTest extends TestCase
{
    /**
     * @var list<string>
     */
    protected array $fixtures = ['app.Tenants', 'app.Flags'];

    public function testEvaluatesFlagsForContext(): void
    {
        $result = (new FlagEvaluator(new RuleCompiler()))->evaluate(
            $this->fetchTable('Tenants')->get(1),
            ['plan' => 'pro'],
        );
        $this->assertSame(
            ['disabled-flag' => false, 'half-rollout' => false, 'new-checkout' => true, 'pro-only' => true],
            $result,
        );
    }

    public function testCacheHitRunsNoFlagsQuery(): void
    {
        $evaluator = new FlagEvaluator(new RuleCompiler());
        $acme = $this->fetchTable('Tenants')->get(1);
        $first = $evaluator->evaluate($acme, []);
        $this->fetchTable('Flags')->deleteAll(['tenant_id' => 1]);
        $this->assertSame($first, $evaluator->evaluate($acme, []));
    }

    public function testNewVersionRecompiles(): void
    {
        $evaluator = new FlagEvaluator(new RuleCompiler());
        $tenants = $this->fetchTable('Tenants');
        $evaluator->evaluate($tenants->get(1), []);
        $this->fetchTable('Flags')->deleteAll(['tenant_id' => 1]);
        $tenants->updateAll(['flags_version' => 2], ['id' => 1]);
        $this->assertSame([], $evaluator->evaluate($tenants->get(1), []));
    }

    public function testTenantsAtTheSameVersionStayApart(): void
    {
        // Article section 5: the key includes the tenant. Both fixture tenants are at version 1.
        $evaluator = new FlagEvaluator(new RuleCompiler());
        $tenants = $this->fetchTable('Tenants');
        $evaluator->evaluate($tenants->get(1), []);
        $this->assertSame(['new-checkout' => false], $evaluator->evaluate($tenants->get(2), []));
    }

    public function testNewVersionReplacesTheOldOne(): void
    {
        // One slot per tenant: once version 2 is compiled, version 1 is no longer cached, so going back to
        // version 1 recompiles from the database (now without flags) instead of serving the old closures.
        $evaluator = new FlagEvaluator(new RuleCompiler());
        $tenants = $this->fetchTable('Tenants');
        $evaluator->evaluate($tenants->get(1), []);
        $tenants->updateAll(['flags_version' => 2], ['id' => 1]);
        $evaluator->evaluate($tenants->get(1), []);
        $this->fetchTable('Flags')->deleteAll(['tenant_id' => 1]);
        $tenants->updateAll(['flags_version' => 1], ['id' => 1]);
        $this->assertSame([], $evaluator->evaluate($tenants->get(1), []));
    }

    public function testCacheIsCappedOldestTenantFirst(): void
    {
        // With room for one tenant, compiling Globex drops Acme, so Acme recompiles from the database.
        $evaluator = new FlagEvaluator(new RuleCompiler(), maxTenants: 1);
        $tenants = $this->fetchTable('Tenants');
        $evaluator->evaluate($tenants->get(1), []);
        $evaluator->evaluate($tenants->get(2), []);
        $this->fetchTable('Flags')->deleteAll(['tenant_id' => 1]);
        $this->assertSame([], $evaluator->evaluate($tenants->get(1), []));
    }
}
