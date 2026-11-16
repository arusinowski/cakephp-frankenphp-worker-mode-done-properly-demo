<?php
declare(strict_types=1);

/**
 * FrankenPHP worker entry point (see docker/frankenphp/Caddyfile.worker).
 *
 * Boots the application once per worker, then handles requests in a loop until FrankenPHP stops the
 * worker or WORKER_MAX_REQUESTS is reached (FrankenPHP then starts a fresh one).
 */

use App\Application;
use App\Worker\Dev\DevWorkerRuntime;
use App\Worker\Dev\LeakDetector;
use App\Worker\RequestCycle;
use App\Worker\WorkerRuntime;

require dirname(__DIR__) . '/vendor/autoload.php';

$app = new Application(dirname(__DIR__) . '/config');
$cycle = new RequestCycle($app);
$maxRequests = (int)(getenv('WORKER_MAX_REQUESTS') ?: 0);

// Dev only: the leak detector (slow) and the X-Worker-Id header used by `bin/cake worker_soak`.
$leakDetector = getenv('WORKER_LEAK_DETECTOR') === '1';
$exposeWorkerId = getenv('WORKER_EXPOSE_ID') === '1';

$runtime = $leakDetector || $exposeWorkerId
    ? new DevWorkerRuntime($app, $maxRequests, $cycle, $leakDetector ? new LeakDetector() : null, $exposeWorkerId)
    : new WorkerRuntime($app, $maxRequests, $cycle);
$runtime->run();
