<?php
declare(strict_types=1);

namespace App\Worker;

/**
 * A service that holds per-request state and must drop it after each request in a worker.
 *
 * Register the service in the container with the tag `RequestCycle::RESETTABLE_TAG`; RequestCycle calls
 * reset() after every request. In classic mode the container dies with the request, so reset() never runs.
 */
interface ResettableInterface
{
    /**
     * Drop all per-request state.
     *
     * @return void
     */
    public function reset(): void;
}
