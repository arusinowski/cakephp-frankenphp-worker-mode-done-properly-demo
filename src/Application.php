<?php
declare(strict_types=1);

/**
 * CakePHP(tm) : Rapid Development Framework (https://cakephp.org)
 * Copyright (c) Cake Software Foundation, Inc. (https://cakefoundation.org)
 *
 * Licensed under The MIT License
 * For full copyright and license information, please see the LICENSE.txt
 * Redistributions of files must retain the above copyright notice.
 *
 * @copyright Copyright (c) Cake Software Foundation, Inc. (https://cakefoundation.org)
 * @link      https://cakephp.org CakePHP(tm) Project
 * @since     3.3.0
 * @license   https://opensource.org/licenses/mit-license.php MIT License
 */
namespace App;

use App\Flags\FlagEvaluator;
use App\Flags\RuleCompiler;
use App\Flags\TenantSecretCache;
use App\Middleware\HostHeaderMiddleware;
use App\Worker\KeepRoutesRoutingMiddleware;
use App\Worker\RequestCycle;
use Cake\Core\Configure;
use Cake\Core\ContainerInterface;
use Cake\Datasource\FactoryLocator;
use Cake\Error\Middleware\ErrorHandlerMiddleware;
use Cake\Event\EventManagerInterface;
use Cake\Http\BaseApplication;
use Cake\Http\Middleware\BodyParserMiddleware;
use Cake\Http\Middleware\CsrfProtectionMiddleware;
use Cake\Http\MiddlewareQueue;
use Cake\ORM\Locator\TableLocator;
use Cake\Routing\Middleware\AssetMiddleware;
use Closure;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * Application setup class.
 *
 * This defines the bootstrapping logic and middleware layers you
 * want to use in your application.
 *
 * @extends \Cake\Http\BaseApplication<\App\Application>
 */
class Application extends BaseApplication
{
    /**
     * Load all the application configuration and bootstrap logic.
     *
     * @return void
     */
    public function bootstrap(): void
    {
        // Call parent to load bootstrap from files.
        parent::bootstrap();

        // By default, does not allow fallback classes.
        FactoryLocator::add(
            'Table',
            (new TableLocator())->allowFallbackClass(false),
        );
    }

    /**
     * Setup the middleware queue your application will use.
     *
     * @param \Cake\Http\MiddlewareQueue $middlewareQueue The middleware queue to setup.
     * @return \Cake\Http\MiddlewareQueue The updated middleware queue.
     */
    public function middleware(MiddlewareQueue $middlewareQueue): MiddlewareQueue
    {
        $bodyParser = new BodyParserMiddleware();
        $middlewareQueue
            // Catch any exceptions in the lower layers,
            // and make an error page/response
            ->add(new ErrorHandlerMiddleware(Configure::read('Error'), $this))

            // Validate Host header to prevent Host Header Injection attacks.
            // In production, ensures App.fullBaseUrl is configured and validates
            // the incoming Host header against it.
            ->add(new HostHeaderMiddleware())

            // Handle plugin/theme assets like CakePHP normally does.
            ->add(new AssetMiddleware([
                'cacheTime' => Configure::read('Asset.cacheTime'),
            ]))

            // Add routing middleware.
            // If you have a large number of routes connected, turning on routes
            // caching in production could improve performance.
            // See https://github.com/CakeDC/cakephp-cached-routing
            // KeepRoutesRoutingMiddleware skips loading routes while the Router has some: in a worker they are
            // loaded once at boot and kept; in classic mode the Router starts empty, so it loads as before.
            ->add(new KeepRoutesRoutingMiddleware($this))

            // Parse various types of encoded request bodies so that they are
            // available as array through $request->getData()
            // https://book.cakephp.org/5/en/controllers/middleware.html#body-parser-middleware
            // Webhooks verify the signature over the raw body and decode it themselves (invalid JSON is a 422),
            // so they bypass body parsing.
            ->add($this->except(['/webhooks/'], $bodyParser))

            // Cross Site Request Forgery (CSRF) Protection Middleware
            // https://book.cakephp.org/5/en/security/csrf.html#cross-site-request-forgery-csrf-middleware
            // Bypassed entirely for the machine endpoints, so they get no csrfToken cookie either:
            // the flag API is GET only, and webhooks authenticate only by the HMAC X-Signature header
            // and never read the session, so a cross-site POST carrying the session cookie gains nothing.
            ->add($this->except(['/api/', '/webhooks/'], new CsrfProtectionMiddleware([
                'httponly' => true,
            ])));

        return $middlewareQueue;
    }

    /**
     * Wrap a middleware so requests whose path starts with one of the prefixes skip it.
     *
     * @param list<string> $prefixes Path prefixes that bypass the middleware.
     * @param \Psr\Http\Server\MiddlewareInterface $middleware Middleware to wrap.
     * @return \Closure(\Psr\Http\Message\ServerRequestInterface, \Psr\Http\Server\RequestHandlerInterface): \Psr\Http\Message\ResponseInterface
     */
    private function except(array $prefixes, MiddlewareInterface $middleware): Closure
    {
        return function (
            ServerRequestInterface $request,
            RequestHandlerInterface $handler,
        ) use (
            $prefixes,
            $middleware,
        ): ResponseInterface {
            $path = $request->getUri()->getPath();
            foreach ($prefixes as $prefix) {
                if (str_starts_with($path, $prefix)) {
                    return $handler->handle($request);
                }
            }

            return $middleware->process($request, $handler);
        };
    }

    /**
     * Register application container services.
     *
     * @param \Cake\Core\ContainerInterface $container The Container to update.
     * @return void
     * @link https://book.cakephp.org/5/en/development/dependency-injection.html#dependency-injection
     */
    public function services(ContainerInterface $container): void
    {
        // Allow your Tables to be dependency injected
        //$container->delegate(new \Cake\ORM\Locator\TableContainer());

        // Shared: one instance per container. In worker mode the container outlives the request.
        $container->addShared(RuleCompiler::class);
        $container->addShared(FlagEvaluator::class)->addArgument(RuleCompiler::class);
        // Per-request state: the worker's RequestCycle calls reset() on everything with this tag.
        $container->addShared(TenantSecretCache::class)->addTag(RequestCycle::RESETTABLE_TAG);
    }

    /**
     * Register custom event listeners here
     *
     * @param \Cake\Event\EventManagerInterface $eventManager
     * @return \Cake\Event\EventManagerInterface
     * @link https://book.cakephp.org/5/en/core-libraries/events.html#registering-listeners
     */
    public function events(EventManagerInterface $eventManager): EventManagerInterface
    {
        // $eventManager->on(new SomeCustomListenerClass());

        return $eventManager;
    }
}
