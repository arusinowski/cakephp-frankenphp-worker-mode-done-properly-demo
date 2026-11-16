<?php
declare(strict_types=1);

namespace App\Worker\Dev;

use App\Worker\RequestCycle;
use App\Worker\WorkerRuntime;
use Cake\Http\BaseApplication;
use Cake\Http\ServerRequestFactory;
use Cake\Log\Log;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * WorkerRuntime with development diagnostics, for the article and the soak test. Not for production.
 *
 * - The leak detector: snapshot before each request, diff after the request and the reset layer, and a
 *   log line for every piece of static state that changed and outlived it, plus memory every 100 requests.
 * - An X-Worker-Id response header, so `bin/cake worker_soak` can count the workers that answered.
 *   FrankenPHP workers are threads of one process, so the pid cannot tell them apart: the id is random.
 *
 * webroot/frankenphp-worker.php uses this class only when WORKER_LEAK_DETECTOR or WORKER_EXPOSE_ID is 1;
 * otherwise it runs the plain WorkerRuntime.
 */
class DevWorkerRuntime extends WorkerRuntime
{
    private string $workerId;

    private int $handled = 0;

    /**
     * @param \Cake\Http\BaseApplication $app The application.
     * @param int $maxRequests Requests per worker before FrankenPHP restarts it (0 = no limit).
     * @param \App\Worker\RequestCycle|null $cycle The reset layer, run after every request.
     * @param \App\Worker\Dev\LeakDetector|null $detector Logs state that outlived a request (slow).
     * @param bool $exposeWorkerId Add an X-Worker-Id response header.
     */
    public function __construct(
        BaseApplication $app,
        int $maxRequests = 0,
        ?RequestCycle $cycle = null,
        private ?LeakDetector $detector = null,
        private bool $exposeWorkerId = false,
    ) {
        parent::__construct($app, $maxRequests, $cycle);
        $this->workerId = bin2hex(random_bytes(4));
    }

    /**
     * @inheritDoc
     */
    public function handle(?ServerRequestInterface $request = null, ?callable $send = null): ResponseInterface
    {
        $request ??= ServerRequestFactory::fromGlobals();
        if ($this->exposeWorkerId && $send !== null) {
            $send = fn(ResponseInterface $response) => $send($response->withHeader('X-Worker-Id', $this->workerId));
        }
        $before = $this->detector?->snapshot();
        try {
            $response = parent::handle($request, $send);
        } finally {
            $this->handled++;
            if ($this->detector !== null && $before !== null) {
                $this->report($request, $this->detector->diff($before, $this->detector->snapshot()));
            }
        }

        return $this->exposeWorkerId ? $response->withHeader('X-Worker-Id', $this->workerId) : $response;
    }

    /**
     * @param \Psr\Http\Message\ServerRequestInterface $request The request just handled.
     * @param list<string> $changes State that changed during the request and outlived it.
     * @return void
     */
    private function report(ServerRequestInterface $request, array $changes): void
    {
        $where = sprintf(
            'worker %s, request %d (%s %s)',
            $this->workerId,
            $this->handled,
            $request->getMethod(),
            $request->getUri()->getPath(),
        );
        foreach ($changes as $change) {
            Log::warning(sprintf('Worker leak: %s changed and outlived the request [%s]', $change, $where));
        }
        if ($this->handled % 100 === 0) {
            $kib = intdiv(memory_get_usage(), 1024);
            Log::info(sprintf('Worker memory: %d KiB after %d requests [%s]', $kib, $this->handled, $where));
        }
    }
}
