<?php
declare(strict_types=1);

namespace App\Test\TestCase\Worker;

use App\Application;
use App\Flags\TenantSecretCache;
use App\Worker\RequestCycle;
use App\Worker\WorkerRuntime;
use Cake\Container\Container as CakeContainer;
use Cake\Core\CakeContainerBridge;
use Cake\Core\Configure;
use Cake\Http\ServerRequest;
use Cake\Routing\Router;
use Cake\TestSuite\TestCase;
use Laminas\Diactoros\StreamFactory;
use League\Container\Container as LeagueContainer;
use ReflectionProperty;
use RuntimeException;

/**
 * Runs requests through one WorkerRuntime, the way a FrankenPHP worker does, without FrankenPHP.
 */
class WorkerRuntimeTest extends TestCase
{
    /**
     * @var list<string>
     */
    protected array $fixtures = ['app.Tenants', 'app.Flags'];

    protected function setUp(): void
    {
        parent::setUp();
        Router::reload();
    }

    public function testHandleBeforeBootFails(): void
    {
        $this->expectException(RuntimeException::class);
        (new WorkerRuntime(new Application(CONFIG)))->handle($this->get('/api/flags/acme'));
    }

    public function testServesManyRequestsWithOneBoot(): void
    {
        $runtime = $this->bootedRuntime();
        foreach (['acme', 'globex', 'acme'] as $slug) {
            $response = $runtime->handle($this->get("/api/flags/{$slug}"));
            $this->assertSame(200, $response->getStatusCode());
            $this->assertSame($slug, json_decode((string)$response->getBody(), true)['tenant']);
        }
    }

    public function testRoutesAreBuiltOnce(): void
    {
        // Article section 3: RoutingMiddleware::process() calls loadRoutes() on every request.
        // BaseApplication::routes() loads config/routes.php only while the Router is empty, but
        // pluginRoutes() has no such guard: every plugin with routes (here DebugKit, loaded because
        // tests run with debug on) would add the same routes again. KeepRoutesRoutingMiddleware
        // loads them once per worker, and the reset layer keeps the compiled collection.
        $runtime = $this->bootedRuntime();
        $counts = [];
        for ($i = 0; $i < 3; $i++) {
            $runtime->handle($this->get('/api/flags/acme'));
            $counts[] = count(Router::routes());
        }
        $this->assertGreaterThan(0, $counts[0]);
        $this->assertSame([$counts[0], $counts[0], $counts[0]], $counts);
    }

    public function testContainerDoesNotAccumulatePerRequestDefinitions(): void
    {
        // BaseApplication::handle() adds ServerRequest and ContainerInterface definitions, and
        // ControllerFactory::create() a ComponentRegistry, on every request. League Container appends
        // rather than replaces, and resolves the first match: in a long-lived container that is a memory
        // leak, lookups that get slower with every request, and injection of the worker's first request.
        $app = new Application(CONFIG);
        $runtime = new WorkerRuntime($app, cycle: new RequestCycle($app));
        $runtime->boot();
        $definitions = new ReflectionProperty(LeagueContainer::class, 'definitions');

        $counts = [];
        foreach (['acme', 'globex', 'acme', 'globex'] as $slug) {
            $runtime->handle($this->get("/api/flags/{$slug}"));
            $counts[] = iterator_count($definitions->getValue($app->getContainer()));
        }

        $this->assertSame([$counts[0], $counts[0], $counts[0], $counts[0]], $counts);
        $request = $app->getContainer()->get(ServerRequest::class);
        $this->assertSame('/api/flags/globex', $request->getUri()->getPath());
    }

    public function testRoutesAreLoadedAtBoot(): void
    {
        // So the reset layer's after-boot snapshot already holds the routes and any URL filters.
        $this->bootedRuntime();
        $this->assertNotEmpty(Router::routes());
    }

    public function testResponseIsSentBeforeTheReset(): void
    {
        $app = new Application(CONFIG);
        $runtime = new WorkerRuntime($app, cycle: new RequestCycle($app));
        $runtime->boot();
        $terminated = 0;
        $app->getEventManager()->on('Server.terminate', function () use (&$terminated): void {
            $terminated++;
        });

        $requestWhileSending = null;
        $runtime->handle($this->get('/api/flags/acme'), function () use (&$requestWhileSending): void {
            $requestWhileSending = Router::getRequest();
        });
        $app->getEventManager()->off('Server.terminate');

        $this->assertNotNull($requestWhileSending, 'the reset ran before the response was sent');
        $this->assertNull(Router::getRequest(), 'the reset did not run after sending');
        $this->assertSame(1, $terminated);
    }

    public function testCakeContainerDoesNotAccumulateDefinitionsEither(): void
    {
        // With 'App.container' => 'cake', CakePHP 5.4 uses its own container, which keys definitions by id:
        // the per-request definitions replace each other without defaultToOverwrite(). The reset layer's
        // container tag works there too.
        Configure::write('App.container', 'cake');
        $app = new Application(CONFIG);
        $runtime = new WorkerRuntime($app, cycle: new RequestCycle($app));
        $runtime->boot();
        $container = $app->getContainer();
        $this->assertInstanceOf(CakeContainerBridge::class, $container);
        $inner = (new ReflectionProperty(CakeContainerBridge::class, 'container'))->getValue($container);
        $aggregate = (new ReflectionProperty(CakeContainer::class, 'definitions'))->getValue($inner);
        $definitions = new ReflectionProperty($aggregate, 'definitions');

        $counts = [];
        foreach (['acme', 'globex', 'acme', 'globex'] as $slug) {
            $this->assertSame(200, $runtime->handle($this->get("/api/flags/{$slug}"))->getStatusCode());
            $counts[] = count($definitions->getValue($aggregate));
        }

        $this->assertSame([$counts[0], $counts[0], $counts[0], $counts[0]], $counts);
        $this->assertSame('/api/flags/globex', $container->get(ServerRequest::class)->getUri()->getPath());
        $this->assertTrue($container->has(RequestCycle::RESETTABLE_TAG));
        $this->assertInstanceOf(TenantSecretCache::class, $container->get(RequestCycle::RESETTABLE_TAG)[0]);
    }

    public function testTenantsStayApart(): void
    {
        // Article sections 3 and 5: the shared FlagEvaluator outlives the request.
        // Globex's new-checkout flag is disabled; Acme's is enabled.
        $runtime = $this->bootedRuntime();
        $runtime->handle($this->get('/api/flags/acme'));
        $globex = json_decode((string)$runtime->handle($this->get('/api/flags/globex'))->getBody(), true);
        $this->assertFalse($globex['flags']['new-checkout']);
    }

    public function testWebhookSecretsStayApart(): void
    {
        $runtime = $this->bootedRuntime();
        $this->assertSame(200, $runtime->handle($this->webhook('acme', 'acme-test-secret'))->getStatusCode());
        $this->assertSame(200, $runtime->handle($this->webhook('globex', 'globex-test-secret'))->getStatusCode());
    }

    private function bootedRuntime(): WorkerRuntime
    {
        $app = new Application(CONFIG);
        $runtime = new WorkerRuntime($app, cycle: new RequestCycle($app));
        $runtime->boot();

        return $runtime;
    }

    private function get(string $url): ServerRequest
    {
        return new ServerRequest(['url' => $url, 'environment' => ['REQUEST_METHOD' => 'GET']]);
    }

    private function webhook(string $slug, string $secret): ServerRequest
    {
        $body = '{"flag":"new-checkout","enabled":true}';
        $request = new ServerRequest([
            'url' => "/webhooks/{$slug}",
            'environment' => [
                'REQUEST_METHOD' => 'POST',
                'CONTENT_TYPE' => 'application/json',
                'HTTP_X_SIGNATURE' => 'sha256=' . hash_hmac('sha256', $body, $secret),
            ],
        ]);

        return $request->withBody((new StreamFactory())->createStream($body));
    }
}
