#!/bin/sh
# Throughput over time on one worker server: consecutive runs of 1,000 requests, no warm-up, so the first run
# starts on freshly booted workers. A leak that slows every request shows up as falling numbers.
#
# Restart the worker service first, then run it next to it:
#   docker compose restart frankenphp-worker
#   docker compose run --rm cli bin/decay.sh [runs] [concurrency] [url]
set -eu

RUNS=${1:-8}
CONCURRENCY=${2:-4}
URL=${3:-http://frankenphp-worker/api/flags/acme?user=u1}
OUT=$(mktemp)
trap 'rm -f "$OUT"' EXIT

echo "| Requests | Requests/s |"
echo "|---|---:|"
i=1
while [ "$i" -le "$RUNS" ]; do
    ab -q -n 1000 -c "$CONCURRENCY" "$URL" > "$OUT" 2>&1 || { echo "decay.sh: ab failed: $(tail -1 "$OUT")" >&2; exit 1; }
    rps=$(awk '/^Requests per second/ {print $4}' "$OUT")
    echo "| $(( (i - 1) * 1000 + 1 ))–$(( i * 1000 )) | $rps |"
    i=$((i + 1))
done
