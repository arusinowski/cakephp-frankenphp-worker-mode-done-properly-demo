# Classic vs worker mode

2026-11-16, 22 CPUs visible to the benchmark container, 3000 requests x 3 runs, concurrency 4, median of runs, classic and worker runs alternating. Same FrankenPHP image for both, DEBUG=false; PHP threads per service: FRANKENPHP_THREADS in compose.yaml.

| Endpoint | Mode | Requests/s | p50 ms | p99 ms |
|---|---|---:|---:|---:|
| `GET /api/flags/acme?user=u1&country=PL&plan=pro` | classic | 498.70 | 7 | 12 |
| `GET /api/flags/acme?user=u1&country=PL&plan=pro` | worker | 2296.59 | 2 | 3 |
| `GET /admin/flags` | classic | 314.23 | 12 | 19 |
| `GET /admin/flags` | worker | 658.15 | 6 | 9 |
| `POST /webhooks/acme` | classic | 407.84 | 9 | 14 |
| `POST /webhooks/acme` | worker | 642.05 | 6 | 8 |
