<?php
declare(strict_types=1);

namespace App\Worker;

use Cake\Routing\Middleware\RoutingMiddleware;
use Cake\Routing\Router;

/**
 * RoutingMiddleware that does not connect routes again while the Router has some.
 *
 * The core middleware calls loadRoutes() on every request. BaseApplication::routes() skips
 * config/routes.php while routes exist, but pluginRoutes() does not: in a worker every plugin with
 * routes would connect them again on every request. WorkerRuntime::boot() loads the routes once and the
 * reset layer keeps them, so this never loads in a worker; in classic mode the Router starts empty on
 * every request, so it loads as before.
 */
class KeepRoutesRoutingMiddleware extends RoutingMiddleware
{
    /**
     * @inheritDoc
     */
    protected function loadRoutes(): void
    {
        if (Router::routes() !== []) {
            return;
        }
        parent::loadRoutes();
    }
}
