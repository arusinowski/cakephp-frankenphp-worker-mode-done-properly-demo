# Worker throughput over time

`bin/decay.sh 8 4`: eight consecutive runs of 1,000 requests at concurrency 4 against
`GET /api/flags/acme?user=u1` on a freshly restarted worker service (2 worker threads, `DEBUG=false`,
`WORKER_EXPOSE_ID=0`). 2026-11-16, same machine as `results.md`.

| Requests | With `defaultToOverwrite(true)` (req/s) | Without it (req/s) |
|---|---:|---:|
| 1–1000 | 2,104 | 1,348 |
| 1001–2000 | 2,464 | 671 |
| 2001–3000 | 2,409 | 411 |
| 3001–4000 | 2,393 | 263 |
| 4001–5000 | 2,329 | 196 |
| 5001–6000 | 2,414 | 160 |
| 6001–7000 | 2,320 | 136 |
| 7001–8000 | 2,307 | 116 |

Without the line, CakePHP adds `ServerRequest`, `ContainerInterface` and `ComponentRegistry` definitions to the
long-lived container on every request; League Container appends them and scans the list on every lookup, so each
request is slower than the one before. To reproduce the right-hand column, remove the line from
`WorkerRuntime::boot()`, restart the worker service and run the script.
