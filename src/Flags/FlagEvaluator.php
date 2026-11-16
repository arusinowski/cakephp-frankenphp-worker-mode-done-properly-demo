<?php
declare(strict_types=1);

namespace App\Flags;

use App\Model\Entity\Flag;
use App\Model\Entity\Tenant;
use Cake\ORM\Locator\LocatorAwareTrait;
use Closure;

/**
 * Evaluates a tenant's feature flags for a caller context.
 *
 * Compiled rules are cached in this instance. The service is registered as shared in the DI container
 * (`Application::services()`): in classic mode the container lives for one request, in FrankenPHP worker
 * mode it lives as long as the worker, so this cache is state kept between requests on purpose.
 *
 * Kept state follows three rules (article section 5):
 *
 * - Scoped keys: one slot per tenant, so two tenants at the same version never share compiled flags. A key of
 *   the version alone looks fine in classic mode and serves tenant A's flags to tenant B in a worker.
 * - A bound: a new version replaces the tenant's old one, and at most $maxTenants tenants stay cached,
 *   the oldest dropped first.
 * - Invalidation through a shared signal: the caller loads the tenant row on every request, and its
 *   flags_version, bumped by the webhook on any worker, tells every worker when to recompile.
 *
 * Tables come from the table locator on each miss. The reset layer clears that locator after every
 * request; it must not replace it, or this long-lived service would keep the first one it used.
 */
class FlagEvaluator
{
    use LocatorAwareTrait;

    /**
     * Compiled flags by tenant id: the version they were compiled from, and closures by flag code.
     *
     * @var array<int, array{version: int, flags: array<string, \Closure(array<string, mixed>): bool>}>
     */
    private array $compiled = [];

    /**
     * @param \App\Flags\RuleCompiler $compiler Rule compiler.
     * @param int $maxTenants Tenants kept compiled at most, oldest dropped first.
     */
    public function __construct(private RuleCompiler $compiler, private int $maxTenants = 1000)
    {
    }

    /**
     * Evaluate every flag of a tenant.
     *
     * On a cache hit no flags query runs and nothing is compiled; on a miss the tenant's flags are
     * loaded and compiled once.
     *
     * @param \App\Model\Entity\Tenant $tenant Tenant, loaded for this request (carries flags_version).
     * @param array<string, mixed> $context Caller attributes: user, country, plan.
     * @return array<string, bool> Flag code => on, sorted by code.
     */
    public function evaluate(Tenant $tenant, array $context): array
    {
        $slot = $this->compiled[$tenant->id] ?? null;
        if ($slot === null || $slot['version'] !== $tenant->flags_version) {
            // Re-inserting moves the tenant to the end, so the cap drops the least recently compiled one.
            unset($this->compiled[$tenant->id]);
            if (count($this->compiled) >= $this->maxTenants) {
                unset($this->compiled[array_key_first($this->compiled)]);
            }
            $slot = ['version' => $tenant->flags_version, 'flags' => $this->compileTenant($tenant)];
            $this->compiled[$tenant->id] = $slot;
        }

        return array_map(static fn(Closure $flag): bool => $flag($context), $slot['flags']);
    }

    /**
     * @param \App\Model\Entity\Tenant $tenant Tenant.
     * @return array<string, \Closure(array<string, mixed>): bool>
     */
    private function compileTenant(Tenant $tenant): array
    {
        $compiled = [];
        $flags = $this->fetchTable('Flags')->find()
            ->where(['Flags.tenant_id' => $tenant->id])
            ->orderBy(['Flags.code' => 'ASC'])
            ->all();
        /** @var \App\Model\Entity\Flag $flag */
        foreach ($flags as $flag) {
            assert($flag instanceof Flag);
            $compiled[$flag->code] = $this->compiler->compile($flag->code, $flag->enabled, $flag->rules);
        }

        return $compiled;
    }
}
