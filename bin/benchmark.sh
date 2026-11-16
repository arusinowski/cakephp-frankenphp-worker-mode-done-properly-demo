#!/bin/sh
# Classic vs worker mode on three endpoints (article section 7).
#
# Run it next to the two FrankenPHP services, with the leak detector off (WORKER_LEAK_DETECTOR=0, the default):
#   docker compose run --rm cli bin/benchmark.sh [requests] [concurrency] [runs] > benchmarks/results.md
#
# Both services run the same image; FRANKENPHP_THREADS in compose.yaml sets how many PHP threads serve the app in
# each. Each cell is the median of [runs] runs of [requests] requests after a warm-up; the runs alternate between
# classic and worker so drift hits both. The webhook writes to the database on every request, so it increments
# acme's flags version thousands of times: reset the data afterwards with bin/reset-demo-db.sh if you need
# version 1 back.
set -eu

REQUESTS=${1:-3000}
CONCURRENCY=${2:-4}
RUNS=${3:-3}
TARGETS="frankenphp-classic frankenphp-worker"

# The demo webhook secret comes from the environment (compose.yaml sets it for the cli service), or from
# config/.env when it is not set. PHP reads it from the environment, so it never appears on a command line
# (visible in `ps`) or in the output.
if [ -z "${DEMO_ACME_WEBHOOK_SECRET:-}" ] && [ -f config/.env ]; then
    # shellcheck disable=SC1091
    . config/.env
fi
[ -n "${DEMO_ACME_WEBHOOK_SECRET:-}" ] || { echo "benchmark.sh: set DEMO_ACME_WEBHOOK_SECRET" >&2; exit 1; }
export DEMO_ACME_WEBHOOK_SECRET
BODY_FILE=$(mktemp)
OUT=$(mktemp)
trap 'rm -f "$BODY_FILE" "$OUT"' EXIT
printf '%s' '{"flag":"bench-probe","enabled":true}' > "$BODY_FILE"
SIGNATURE="sha256=$(php -r 'echo hash_hmac("sha256", file_get_contents($argv[1]), getenv("DEMO_ACME_WEBHOOK_SECRET"));' "$BODY_FILE")"

fail() {
    echo "benchmark.sh: $*" >&2
    exit 1
}

run_ab() { # target path mode requests -> ab output in $OUT
    if [ "$3" = post ]; then
        ab -q -n "$4" -c "$CONCURRENCY" -p "$BODY_FILE" -T application/json -H "X-Signature: $SIGNATURE" \
            "http://$1$2" > "$OUT" 2>&1 || fail "ab failed for $1$2: $(tail -1 "$OUT")"
    else
        ab -q -n "$4" -c "$CONCURRENCY" "http://$1$2" > "$OUT" 2>&1 || fail "ab failed for $1$2: $(tail -1 "$OUT")"
    fi
}

# $OUT -> "rps p50 p99". ab counts a changed body length as a "failed request" (the webhook's version grows
# a digit), so only incomplete runs and non-2xx responses count as errors.
parse() { # target path requests
    awk -v n="$3" '/^Complete requests/ {done=$3} /^Requests per second/ {rps=$4} /^  50%/ {p50=$2}
         /^  99%/ {p99=$2} /^Non-2xx responses/ {bad=$3}
         END {if (done != n || bad > 0 || rps == "") exit 1; printf "%s %s %s\n", rps, p50, p99}' "$OUT" \
        || fail "bad run for $1$2: $(grep -E '^(Complete|Non-2xx)' "$OUT" | tr '\n' ' ')"
}

median() { sort -n | awk '{a[NR]=$1} END {print a[int((NR+1)/2)]}'; }

echo "# Classic vs worker mode"
echo
echo "$(date -u +%Y-%m-%d), $(nproc) CPUs visible to the benchmark container, ${REQUESTS} requests x ${RUNS} runs, concurrency ${CONCURRENCY}," \
    "median of runs, classic and worker runs alternating. Same FrankenPHP image for both, DEBUG=false;" \
    "PHP threads per service: FRANKENPHP_THREADS in compose.yaml."
echo
echo "| Endpoint | Mode | Requests/s | p50 ms | p99 ms |"
echo "|---|---|---:|---:|---:|"

for endpoint in "GET /api/flags/acme?user=u1&country=PL&plan=pro" "GET /admin/flags" "POST /webhooks/acme"; do
    method=${endpoint%% *}
    path=${endpoint#* }
    mode=get
    [ "$method" = POST ] && mode=post
    for target in $TARGETS; do
        run_ab "$target" "$path" "$mode" 300 # warm-up: OPcache, worker boot, compiled flags
    done
    classic=""
    worker=""
    i=0
    while [ "$i" -lt "$RUNS" ]; do
        run_ab frankenphp-classic "$path" "$mode" "$REQUESTS"
        classic="$classic$(parse frankenphp-classic "$path" "$REQUESTS")
"
        run_ab frankenphp-worker "$path" "$mode" "$REQUESTS"
        worker="$worker$(parse frankenphp-worker "$path" "$REQUESTS")
"
        i=$((i + 1))
    done
    for target in classic worker; do
        eval "results=\$$target"
        rps=$(printf '%s' "$results" | awk '{print $1}' | median)
        p50=$(printf '%s' "$results" | awk '{print $2}' | median)
        p99=$(printf '%s' "$results" | awk '{print $3}' | median)
        echo "| \`$endpoint\` | $target | $rps | $p50 | $p99 |"
    done
done
