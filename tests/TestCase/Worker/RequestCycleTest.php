<?php
declare(strict_types=1);

namespace App\Test\TestCase\Worker;

use App\Application;
use App\Worker\RequestCycle;
use App\Worker\ResettableInterface;
use Cake\Core\Configure;
use Cake\Datasource\ConnectionManager;
use Cake\Datasource\FactoryLocator;
use Cake\Event\EventInterface;
use Cake\Http\ServerRequest;
use Cake\I18n\I18n;
use Cake\ORM\Entity;
use Cake\Routing\Router;
use Cake\TestSuite\TestCase;
use Cake\Utility\Inflector;
use ReflectionProperty;

class RequestCycleTest extends TestCase
{
    /**
     * @var list<string>
     */
    protected array $fixtures = ['app.Tenants'];

    private Application $app;

    private RequestCycle $cycle;

    protected function setUp(): void
    {
        parent::setUp();
        $this->app = new Application(CONFIG);
        $this->cycle = new RequestCycle($this->app);
        $this->cycle->afterBoot();
    }

    protected function tearDown(): void
    {
        I18n::setLocale(I18n::getDefaultLocale());
        parent::tearDown();
    }

    public function testRollsBackOpenTransactions(): void
    {
        $connection = ConnectionManager::get('test');
        $connection->begin();
        $connection->execute("UPDATE tenants SET name = 'changed' WHERE id = 1");
        $this->cycle->afterRequest();
        $this->assertFalse($connection->inTransaction());
        $this->assertSame('Acme', $this->fetchTable('Tenants')->get(1)->name);
    }

    public function testRestoresConfigureAndLocale(): void
    {
        Configure::write('Tenant.current', 'acme');
        I18n::setLocale('pl_PL');
        $this->cycle->afterRequest();
        $this->assertNull(Configure::read('Tenant.current'));
        $this->assertNotNull(Configure::read('App.namespace'));
        $this->assertNotSame('pl_PL', I18n::getLocale());
    }

    public function testDropsTheRequestButKeepsRoutes(): void
    {
        // Routes are loaded at boot (WorkerRuntime::boot()), before afterBoot() takes its snapshot.
        Router::reload();
        Router::createRouteBuilder('/')->connect('/x', ['controller' => 'Webhooks', 'action' => 'receive']);
        $this->cycle->afterBoot();
        Router::setRequest(new ServerRequest(['url' => '/x']));
        $this->cycle->afterRequest();
        $this->assertNull(Router::getRequest());
        $this->assertCount(1, Router::routes());
    }

    public function testKeepsRouterStateSetUpAtBootAcrossRequests(): void
    {
        // A URL filter added at boot (routes.php or bootstrap) must survive every request, and each
        // request's Router::setRequest() must not leak into the next one.
        Router::reload();
        Router::createRouteBuilder('/')->connect('/x/{tenant}', ['controller' => 'Webhooks', 'action' => 'receive']);
        Router::addUrlFilter(function (array $params): array {
            $params['tenant'] ??= 'acme';

            return $params;
        });
        $cycle = new RequestCycle($this->app);
        $cycle->afterBoot();

        for ($i = 0; $i < 3; $i++) {
            Router::setRequest(new ServerRequest(['url' => '/x/globex']));
            $cycle->afterRequest();
            $this->assertNull(Router::getRequest(), "request {$i}");
            $this->assertSame('/x/acme', Router::url(['controller' => 'Webhooks', 'action' => 'receive']), "request {$i}");
        }
    }

    public function testKeepsTableConfigSetAtBoot(): void
    {
        $locator = FactoryLocator::get('Table');
        $locator->setConfig('Tenants', ['entityClass' => Entity::class]);
        $cycle = new RequestCycle($this->app);
        $cycle->afterBoot();

        $locator->get('Tenants');
        $cycle->afterRequest();

        $this->assertSame(['entityClass' => Entity::class], $locator->getConfig('Tenants'));
        $this->assertSame(Entity::class, $locator->get('Tenants')->getEntityClass());
    }

    public function testInflectorCacheDoesNotGrowButBootRulesStay(): void
    {
        // With fallback routes every distinct URL path adds Inflector cache entries; reset() would also drop
        // custom rules set at boot, so only the cache goes back to its after-boot state.
        Inflector::rules('plural', ['/^(flagz)$/i' => '\1en']);
        $cycle = new RequestCycle($this->app);
        $cycle->afterBoot();
        $cache = new ReflectionProperty(Inflector::class, '_cache');
        $afterBoot = $cache->getValue();

        Inflector::camelize('probe-' . uniqid());
        $cycle->afterRequest();

        $this->assertSame($afterBoot, $cache->getValue());
        $this->assertSame('flagzen', Inflector::pluralize('flagz'));
        Inflector::reset();
    }

    public function testClearsTablesButKeepsTheLocator(): void
    {
        $locator = FactoryLocator::get('Table');
        $locator->get('Tenants');
        $this->cycle->afterRequest();
        $this->assertSame($locator, FactoryLocator::get('Table'));
        $this->assertSame([], (new ReflectionProperty($locator, 'instances'))->getValue($locator));
    }

    public function testResetsTaggedServicesAndDispatchesAfterRequest(): void
    {
        $service = new class implements ResettableInterface {
            public int $resets = 0;

            public function reset(): void
            {
                $this->resets++;
            }
        };
        $this->app->getContainer()->addShared('test.resettable', $service)->addTag(RequestCycle::RESETTABLE_TAG);
        $events = 0;
        $this->app->getEventManager()->on('Worker.afterRequest', function (EventInterface $event) use (&$events): void {
            $events++;
        });

        $this->cycle->afterRequest();
        $this->app->getEventManager()->off('Worker.afterRequest');

        $this->assertSame(1, $service->resets);
        $this->assertSame(1, $events);
    }
}
