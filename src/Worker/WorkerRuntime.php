<?php
declare(strict_types=1);

namespace App\Worker;

use Cake\Core\ContainerApplicationInterface;
use Cake\Core\PluginApplicationInterface;
use Cake\Http\BaseApplication;
use Cake\Http\MiddlewareQueue;
use Cake\Http\ResponseEmitter;
use Cake\Http\Runner;
use Cake\Http\Server;
use Cake\Http\ServerRequest;
use Cake\Http\ServerRequestFactory;
use Cake\Routing\Router;
use League\Container\Container as LeagueContainer;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use RuntimeException;

/**
 * Runs a CakePHP application in a FrankenPHP worker: boot once, then handle requests in a loop.
 *
 * This does what Cake\Http\Server::run() and Server::emit() do, split in two halves. `Server::run()` cannot
 * be called per request in a worker, because it calls `bootstrap()` and builds the middleware queue on every
 * call: that would repeat the work worker mode exists to skip, and register event listeners again each time.
 */
class WorkerRuntime
{
    private ?MiddlewareQueue $middleware = null;

    private Runner $runner;

    /**
     * Subject of the Server.* events, as in classic mode. Never run(): it would bootstrap again.
     */
    private Server $server;

    /**
     * @param \Cake\Http\BaseApplication $app The application.
     * @param int $maxRequests Requests per worker before FrankenPHP restarts it (0 = no limit).
     * @param \App\Worker\RequestCycle|null $cycle The reset layer, run after every request.
     */
    public function __construct(
        private BaseApplication $app,
        private int $maxRequests = 0,
        private ?RequestCycle $cycle = null,
    ) {
        $this->runner = new Runner();
        $this->server = new Server($app, $this->runner);
    }

    /**
     * Boot once per worker: bootstrap the app and its plugins, load the routes, build the middleware queue.
     *
     * @return void
     */
    public function boot(): void
    {
        $this->app->bootstrap();
        if ($this->app instanceof PluginApplicationInterface) {
            $this->app->pluginBootstrap();
        }

        $queue = new MiddlewareQueue();
        if ($this->app instanceof ContainerApplicationInterface) {
            $container = $this->app->getContainer();
            // BaseApplication::handle() adds ServerRequest and ContainerInterface definitions, and
            // ControllerFactory::create() a ComponentRegistry, on every request. League Container appends
            // definitions and resolves the first match, so a container that outlives requests would keep
            // every request (memory), scan a longer list on every lookup (slower each request), and inject
            // the worker's first request. With overwrite on, each add() replaces the previous definition.
            // Even so, never inject ServerRequest into a shared service: it keeps the request it got first.
            if ($container instanceof LeagueContainer) {
                $container->defaultToOverwrite(true);
            }
            $queue = new MiddlewareQueue([], $container);
        }
        $queue = $this->app->middleware($queue);
        if ($this->app instanceof PluginApplicationInterface) {
            $queue = $this->app->pluginMiddleware($queue);
        }
        $this->server->dispatchEvent('Server.buildMiddleware', ['middleware' => $queue]);
        $this->middleware = $queue;

        // Load the routes now, not on the first request, so the reset layer's after-boot snapshot holds
        // them together with any URL filters routes.php adds. KeepRoutesRoutingMiddleware then skips loading.
        if (Router::routes() === []) {
            $builder = Router::createRouteBuilder('/');
            $this->app->routes($builder);
            if ($this->app instanceof PluginApplicationInterface) {
                $this->app->pluginRoutes($builder);
            }
        }

        $this->cycle?->afterBoot();
    }

    /**
     * Handle one request with the queue built at boot, send the response, then reset.
     *
     * The reset runs after the response is sent: a body that streams lazily (a CallbackStream over an ORM
     * query, a Router::url() call while rendering) still sees the request's tables, routes and connection.
     *
     * @param \Psr\Http\Message\ServerRequestInterface|null $request Request, or null to build it from globals.
     * @param callable|null $send Sends the response (the worker loop passes the ResponseEmitter), null for none.
     * @return \Psr\Http\Message\ResponseInterface
     */
    public function handle(?ServerRequestInterface $request = null, ?callable $send = null): ResponseInterface
    {
        if ($this->middleware === null) {
            throw new RuntimeException('Call boot() before handle().');
        }
        $request ??= ServerRequestFactory::fromGlobals();
        try {
            $response = $this->runner->run($this->middleware, $request, $this->app);
            if ($request instanceof ServerRequest) {
                $request->getSession()->close();
            }
            if ($send !== null) {
                $send($response);
            }
            $this->server->dispatchEvent('Server.terminate', ['request' => $request, 'response' => $response]);
        } finally {
            // Also after an uncaught error: the next request must not see this one's state.
            $this->cycle?->afterRequest();
        }

        return $response;
    }

    /**
     * The worker loop. Only works inside a FrankenPHP worker.
     *
     * @return void
     */
    public function run(): void
    {
        // frankenphp_handle_request() also exists in classic mode, where calling it fails only after the
        // whole app has booted. FrankenPHP sets FRANKENPHP_WORKER for worker scripts: check that first.
        if (!function_exists('frankenphp_handle_request') || empty($_SERVER['FRANKENPHP_WORKER'])) {
            throw new RuntimeException('WorkerRuntime::run() needs FrankenPHP in worker mode.');
        }
        ignore_user_abort(true);
        $this->boot();

        $emitter = new ResponseEmitter();
        $handler = function () use ($emitter): void {
            $this->handle(null, $emitter->emit(...));
        };

        for ($count = 0; $this->maxRequests === 0 || $count < $this->maxRequests; $count++) {
            $keepRunning = frankenphp_handle_request($handler);
            gc_collect_cycles();
            if (!$keepRunning) {
                break;
            }
        }
    }
}
