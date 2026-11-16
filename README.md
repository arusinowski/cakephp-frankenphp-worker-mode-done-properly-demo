# CakePHP 5.4 in FrankenPHP worker mode — demo app

Reference application for the article **"CakePHP 5.4 in FrankenPHP worker mode, done properly"** (link to follow).

It shows how to run a CakePHP app in [FrankenPHP worker mode](https://frankenphp.dev/docs/worker/) safely: what
worker mode gives you, what state outlives a request in CakePHP, how to reset it (and what to keep on purpose), and
how to prove there are no leaks.

> This is a reference app, not a maintained product. It is tested against the versions listed under
> [Tested with](#tested-with). Copy what you need and check it against your own versions.

## Running it

Requirements: Docker with Compose. Everything runs in containers: MariaDB, FrankenPHP in classic mode, FrankenPHP in
worker mode (same image), and a `cli` service for Composer, `bin/cake`, the tests, the soak test and the benchmark.

```bash
git clone https://github.com/arusinowski/cakephp-frankenphp-worker-mode-done-properly-demo.git
cd cakephp-frankenphp-worker-mode-done-properly-demo

# The cli service runs as your user; set UID/GID if yours are not 1000.
docker compose run --rm cli composer install
docker compose run --rm cli bin/cake migrations migrate
docker compose run --rm cli bin/cake seeds run Demo     # two tenants, acme and globex, 50 flags each
docker compose up -d
```

| Service | Mode | Caddyfile |
|---|---|---|
| `http://frankenphp-classic` | classic: `webroot/index.php` boots the app on every request | `docker/frankenphp/Caddyfile.classic` |
| `http://frankenphp-worker` | worker: `webroot/frankenphp-worker.php` boots once per worker | `docker/frankenphp/Caddyfile.worker` |

Both run with 2 PHP threads and `DEBUG=false`. With `DEBUG=false`, `HostHeaderMiddleware` accepts only the host in
`APP_FULL_BASE_URL`, so the services are not published on the host: call them from the `cli` service, which is on
the same network.

```bash
docker compose run --rm cli curl -s 'http://frankenphp-worker/api/flags/acme?user=u1'
docker compose logs frankenphp-worker      # the services log to stderr
docker compose run --rm cli composer check # tests (SQLite) and coding standard
```

OPcache does not check timestamps and workers keep code in memory: run
`docker compose restart frankenphp-classic frankenphp-worker` after a code change. Restart them after a migration
too: each FrankenPHP container keeps its table schema cache in its own in-memory `tmp/`, which
`bin/cake schema_cache clear` in the `cli` service does not reach.

`docker compose run --rm cli bin/reset-demo-db.sh` rebuilds the demo data from scratch (it deletes everything in the
database).

## The demo: a multi-tenant feature-flag service

| Endpoint | Purpose in the article |
|---|---|
| `GET /api/flags/{tenant}` | Hot path: tiny JSON at high request rates. Flag targeting rules are compiled once per worker and kept in memory. |
| `POST /webhooks/{tenant}` | Verifies a per-tenant signing secret and updates flags. Drives cross-worker cache invalidation. |
| `GET /admin/flags` | HTML page with ORM queries, for a second benchmark. |

There is no home page and there are no fallback routes: these three endpoints are the whole app. All worker-mode code
lives in the app itself (no plugin), so every piece can be read in one place.

### Flag rules

Each flag has `enabled` and optional JSON `rules`. A flag is on for a caller when it is enabled, every segment matches
and the caller falls inside the rollout. No rules means on for everyone.

```json
{
  "rollout": 25,
  "segments": [
    {"attr": "country", "op": "in", "values": ["PL", "DE"]},
    {"attr": "plan", "op": "eq", "value": "pro"}
  ]
}
```

- Ops: `eq`, `neq` (with `value`), `in`, `not_in` (with `values`). Attributes: `user`, `country`, `plan`.
  A missing attribute never matches `eq`/`in` and always matches `neq`/`not_in`.
- `rollout` is 0–100 (default 100). A caller is inside when `crc32("<flag>:<user>") % 100 < rollout`, so the same user
  keeps the same answer. A partial rollout without `user` is off.

### `GET /api/flags/{tenant}`

Query parameters `user`, `country`, `plan` (all optional) describe the caller.

```bash
docker compose run --rm cli curl -s 'http://frankenphp-worker/api/flags/acme?user=u1&country=PL&plan=pro'
```

```json
{"tenant": "acme", "version": 1, "flags": {"beta-search": false, "dark-mode": false, "eu-invoices": true, "pro-reports": true}}
```

Shortened: the response lists every flag of the tenant. `version` is the tenant's flags version. Unknown tenant: `404`.

### `POST /webhooks/{tenant}`

Updates or creates one flag and increments the tenant's flags version.

- Header `X-Signature: sha256=<hex HMAC-SHA256 of the raw request body, keyed with the tenant's webhook secret>`.
- Body `{"flag": "<code>", "enabled": true|false, "rules": {...}|null}`. `flag` and `enabled` are required. Leaving
  `rules` out keeps the current rules; `"rules": null` clears them.
- Responses: `200 {"version": <new version>}`; `401` bad or missing signature; `404` unknown tenant; `422` invalid JSON,
  missing or mistyped fields, or invalid rules (`{"error": "..."}`). Nothing changes on an error.

The demo secrets are set for the `cli` service in `compose.yaml` (demo values, not real secrets):

```bash
docker compose run --rm cli sh -c '
  body="{\"flag\":\"dark-mode\",\"enabled\":true,\"rules\":{\"rollout\":50}}"
  sig=$(php -r "echo hash_hmac(\"sha256\", \$argv[1], getenv(\"DEMO_ACME_WEBHOOK_SECRET\"));" "$body")
  curl -s -X POST -H "Content-Type: application/json" -H "X-Signature: sha256=$sig" -d "$body" \
    http://frankenphp-worker/webhooks/acme'
```

### `GET /admin/flags`

Read-only list of tenants and their flags. It has no login because this is a demo app: do not deploy it as is.

### State that outlives a request

Two places in the app keep state between requests in a worker, and each follows the rules from the article:

- `src/Flags/FlagEvaluator.php`: compiled rules kept on purpose. One slot per tenant (a key of the version alone
  would serve one tenant's flags to another), a new version replaces the old one, at most 1000 tenants.
- `src/Flags/TenantSecretCache.php`: per-request state, keyed by tenant and dropped after every request
  (`ResettableInterface`). **Demo only:** a real app does not need this class, because the webhook controller has
  already loaded the tenant entity, which carries the secret. It is the example of a resettable service.

CakePHP itself adds `ServerRequest`, `ContainerInterface` and `ComponentRegistry` definitions to the container on every
request. The default container (`App.container` not set, as in this app) extends League Container, which appends
rather than replaces, so `WorkerRuntime::boot()` turns on `defaultToOverwrite()`. CakePHP 5.4's own container
(`'App.container' => 'cake'`) replaces definitions by id and needs no change; `WorkerRuntimeTest` covers both.
To see why, remove that line, restart the worker service and run `bin/decay.sh`: worker throughput falls with every
request served (on the flag API 1,348 requests/s in the first 1,000 requests and 116 in the eighth 1,000, against
2,300–2,460 with the line in place; see `benchmarks/decay.md`).

`RequestCycle` also restores Inflector's cache after every request: with fallback routes, every distinct URL path
adds entries to it (this app has no fallback routes).

### What is the app, and what is the demo

| Part | Purpose |
|---|---|
| `src/Flags/RuleCompiler.php`, `FlagEvaluator.php`, Tables, controllers, migrations | The feature |
| `webroot/frankenphp-worker.php`, `src/Worker/*.php`, `docker/frankenphp/`, `frankenphp-worker` service | Worker mode: what you copy into a real app |
| `src/Worker/Dev/` (`DevWorkerRuntime`, `LeakDetector`) | Dev diagnostics, used only when `WORKER_LEAK_DETECTOR` or `WORKER_EXPOSE_ID` is 1 |
| `src/Flags/TenantSecretCache.php` | Demo only: the example of a resettable per-request service |
| `src/Command/`, `bin/benchmark.sh`, `bin/decay.sh`, `bin/reset-demo-db.sh`, `benchmarks/`, `frankenphp-classic` service | Soak test, benchmarks and their classic-mode baseline |
| `config/Seeds/DemoSeed.php` | Demo data: globex's flags are inverted so a leak is visible, 50 flags per tenant for the benchmark |

## Soak test, leak detector, benchmarks

```bash
# Alternating-tenant soak test: wrong flags, wrong webhook secret, stale flags after a webhook, workers missed.
# After each webhook it reads in parallel until every worker answered (X-Worker-Id header, WORKER_EXPOSE_ID=1).
# It writes to the database: reset it first and afterwards.
docker compose run --rm cli bin/reset-demo-db.sh
docker compose run --rm cli bin/cake worker_soak http://frankenphp-worker --rounds 20 --workers 2

# Leak detector (dev only, slow): logs static state that a request changed and that outlived it.
WORKER_LEAK_DETECTOR=1 docker compose up -d frankenphp-worker
docker compose logs frankenphp-worker | grep 'Worker leak'
docker compose up -d frankenphp-worker            # back to the default (off)

# Classic vs worker on three endpoints, with the dev diagnostics off. Changes data: reset the database afterwards.
WORKER_EXPOSE_ID=0 docker compose up -d frankenphp-worker
docker compose run --rm cli bin/benchmark.sh > benchmarks/results.md

# Worker throughput over time: consecutive runs of 1,000 requests on freshly restarted workers.
docker compose restart frankenphp-worker
docker compose run --rm cli bin/decay.sh

docker compose up -d frankenphp-worker            # back to the defaults (X-Worker-Id on for the soak test)
```

The detector watches `App\` by default. To watch plugins and libraries too, pass their namespaces where
`webroot/frankenphp-worker.php` creates it: `new LeakDetector(['App\\', 'MyPlugin\\'])`. Routes are loaded at boot,
so a `Router routes` report means something connects routes during a request.

## Repository layout

| Path | Contents |
|---|---|
| `webroot/frankenphp-worker.php` | Worker entry point |
| `src/Worker/WorkerRuntime.php` | Boot once, then handle requests with the middleware queue built at boot |
| `src/Worker/RequestCycle.php` | The reset layer, run after every request |
| `src/Worker/ResettableInterface.php` | Opt-in reset for container services (tag `worker.resettable`) |
| `src/Worker/KeepRoutesRoutingMiddleware.php` | Loads routes once per worker instead of once per request |
| `src/Worker/Dev/DevWorkerRuntime.php` | Dev only: WorkerRuntime plus the leak detector and the `X-Worker-Id` header |
| `src/Worker/Dev/LeakDetector.php` | Dev only: snapshot and diff of static state per request |
| `src/Command/WorkerSoakCommand.php` | `bin/cake worker_soak` |
| `docker/frankenphp/`, `compose.yaml` | The FrankenPHP image (server and cli stages) and the demo stack |
| `bin/benchmark.sh`, `bin/decay.sh`, `benchmarks/` | Benchmark scripts and results |

## Tested with

- CakePHP 5.4.4
- PHP 8.4
- MariaDB 11.8
- FrankenPHP 1.13.5 (`dunglas/frankenphp:1.13.5-php8.4`)

## License

MIT. See [LICENSE](LICENSE).
