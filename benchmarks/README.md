# Benchmarks

- `results.md`: `bin/benchmark.sh 3000 4 3`, classic vs worker on three endpoints (see the script for the method).
  Both services run the same FrankenPHP image with 2 PHP threads serving requests; the worker runs the plain
  `WorkerRuntime` (`WORKER_EXPOSE_ID=0`, leak detector off).
- `decay.md`: `bin/decay.sh 8 4`, worker throughput over eight consecutive runs of 1,000 requests, with and without
  `defaultToOverwrite(true)` in `WorkerRuntime::boot()`.

How to read the numbers:

- The gain follows the share of each request that is start-up work. The flag API does little per request (one
  tenant query, then closures compiled once per worker), so skipping bootstrap, plugin loading, route loading,
  middleware setup and the database connection is most of its cost, plus the compiled-flag cache. The admin page
  spends its time on an ORM query with 100 flags and an HTML view, and the webhook on a transaction behind a row lock,
  so they gain less.
- These are local Docker numbers on WSL2 with ApacheBench at concurrency 4, not production capacity. Compare the two
  rows of an endpoint with each other, not with other setups.
- A leak that slows every request does not show in a short benchmark after a warm-up; `decay.md` shows why to measure
  over thousands of requests per worker.
