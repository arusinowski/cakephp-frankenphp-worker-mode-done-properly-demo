<?php
declare(strict_types=1);

namespace App\Worker;

use Cake\Core\Configure;
use Cake\Database\Connection;
use Cake\Datasource\ConnectionManager;
use Cake\Datasource\FactoryLocator;
use Cake\Event\Event;
use Cake\Http\BaseApplication;
use Cake\I18n\I18n;
use Cake\Log\Log;
use Cake\ORM\Locator\TableLocator;
use Cake\Routing\Router;
use Cake\Utility\Inflector;
use ReflectionClass;
use ReflectionProperty;

/**
 * The reset layer: puts a worker back into its after-boot state after every request (article section 5).
 *
 * The strategy is a hardcoded list for CakePHP's own static registries, which cannot opt in to anything,
 * plus opt-in for the app: services implement ResettableInterface and carry the container tag
 * RESETTABLE_TAG, and code outside the container listens to the `Worker.afterRequest` event.
 *
 * Order matters:
 *
 * 1. Roll back open transactions first: a request that failed half-way must not leave a transaction that
 *    the next request, for another user, would commit.
 * 2. Then app code (event listeners, resettable services): it may still need routes, config and tables.
 * 3. CakePHP's registries last: the request's router state (keeping the compiled routes), the Table
 *    instances, runtime changes to Configure and the locale.
 *
 * Deliberately kept: database connections, the compiled route collection, the DI container and its
 * shared services (such as the compiled flag cache), and everything bootstrap set up.
 */
class RequestCycle
{
    public const RESETTABLE_TAG = 'worker.resettable';

    /**
     * Configure as it was after boot.
     *
     * @var array<string, mixed>
     */
    private array $configure = [];

    private string $locale = '';

    /**
     * Router's static properties as they were after boot (routes loaded, URL filters added).
     *
     * @var array<string, mixed>
     */
    private array $router = [];

    /**
     * Table locator config set at boot (`setConfig()` per alias).
     *
     * @var array<string, mixed>
     */
    private array $tableConfig = [];

    /**
     * Inflector's cache as it was after boot.
     *
     * @var array<string, mixed>
     */
    private array $inflectorCache = [];

    /**
     * @param \Cake\Http\BaseApplication $app The application.
     */
    public function __construct(private BaseApplication $app)
    {
    }

    /**
     * Record the after-boot state that afterRequest() restores.
     *
     * @return void
     */
    public function afterBoot(): void
    {
        $this->configure = (array)Configure::read();
        $this->locale = I18n::getLocale();
        $this->tableConfig = $this->tableLocator()->getConfig();
        $this->inflectorCache = (array)(new ReflectionProperty(Inflector::class, '_cache'))->getValue();

        // Not Router::reload(): every call replaces the route collection with an empty one, so routes would be
        // rebuilt on every request, and from its second call on it also resets every Router static to its
        // declared default (get_class_vars()), dropping URL filters, the default route class and extensions
        // set up at boot. Snapshot the after-boot state instead and restore exactly that.
        $this->router = [];
        foreach ((new ReflectionClass(Router::class))->getProperties(ReflectionProperty::IS_STATIC) as $property) {
            if ($property->isInitialized()) {
                $this->router[$property->getName()] = $property->getValue();
            }
        }
    }

    /**
     * Reset everything a request may have changed. Runs after every request, also after one that failed.
     *
     * @return void
     */
    public function afterRequest(): void
    {
        $this->rollbackTransactions();

        $this->app->getEventManager()->dispatch(new Event('Worker.afterRequest', $this->app));
        $container = $this->app->getContainer();
        if ($container->has(self::RESETTABLE_TAG)) {
            foreach ((array)$container->get(self::RESETTABLE_TAG) as $service) {
                if ($service instanceof ResettableInterface) {
                    $service->reset();
                }
            }
        }

        // Back to the after-boot Router: the request and request context go, the route collection compiled
        // at boot (the same object), URL filters and route class stay.
        foreach ($this->router as $name => $value) {
            (new ReflectionProperty(Router::class, $name))->setValue(null, $value);
        }

        // Clear the Table instances, but keep the locator object (long-lived services hold it) and the
        // per-alias config set at boot, which clear() also wipes.
        $locator = $this->tableLocator();
        $locator->clear();
        foreach ($this->tableConfig as $alias => $options) {
            $locator->setConfig($alias, $options);
        }

        Configure::clear();
        Configure::write($this->configure);
        I18n::setLocale($this->locale);

        // Inflector caches every string it inflects; with fallback routes that is every distinct URL path.
        // Not Inflector::reset(): like Router::reload(), it also drops custom rules set at boot.
        (new ReflectionProperty(Inflector::class, '_cache'))->setValue(null, $this->inflectorCache);
    }

    /**
     * @return \Cake\ORM\Locator\TableLocator
     */
    private function tableLocator(): TableLocator
    {
        $locator = FactoryLocator::get('Table');
        assert($locator instanceof TableLocator);

        return $locator;
    }

    /**
     * Roll back and log any transaction a request left open, on every connected connection.
     *
     * @return void
     */
    private function rollbackTransactions(): void
    {
        foreach (ConnectionManager::configured() as $name) {
            $connection = ConnectionManager::get($name);
            if ($connection instanceof Connection && $connection->inTransaction()) {
                $connection->rollback(true);
                Log::warning(sprintf('Worker: rolled back a transaction left open on connection "%s".', $name));
            }
        }
    }
}
