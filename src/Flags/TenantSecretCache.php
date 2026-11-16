<?php
declare(strict_types=1);

namespace App\Flags;

use App\Worker\ResettableInterface;
use Cake\ORM\Locator\LocatorAwareTrait;

/**
 * Memoizes tenants' webhook signing secrets for the current request.
 *
 * DEMO ONLY: a real app does not need this class. WebhooksController has already loaded the tenant entity,
 * which carries `webhook_secret`. The class exists as the example of a ResettableInterface service; in your own
 * app, read the secret from the entity you already have.
 *
 * Per-request state done safely in a worker (article section 5): the memo is keyed by tenant, lives in a
 * shared container service instead of a static property, and is dropped after every request through
 * ResettableInterface, so a rotated secret takes effect on the next request. The pattern to avoid is a static
 * memo without the tenant in its key: in a worker, the next tenant's webhook is checked against the first
 * tenant's secret.
 */
class TenantSecretCache implements ResettableInterface
{
    use LocatorAwareTrait;

    /**
     * @var array<int, string>
     */
    private array $secrets = [];

    /**
     * Get the webhook secret of a tenant.
     *
     * @param int $tenantId Tenant id.
     * @return string
     */
    public function get(int $tenantId): string
    {
        if (!isset($this->secrets[$tenantId])) {
            /** @var \App\Model\Entity\Tenant $tenant */
            $tenant = $this->fetchTable('Tenants')->get($tenantId);
            $this->secrets[$tenantId] = $tenant->webhook_secret;
        }

        return $this->secrets[$tenantId];
    }

    /**
     * @inheritDoc
     */
    public function reset(): void
    {
        $this->secrets = [];
    }
}
