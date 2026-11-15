#!/usr/bin/env bash
# Server-time budgets (run against a running app, e.g. in CI after `php artisan serve`).
#   uncached pages p95 < 150 ms, API responses p95 < 100 ms.
# usage: scripts/perf-budget.sh http://127.0.0.1:8000
set -euo pipefail
BASE=${1:-http://127.0.0.1:8000}
fail=0
p95() { # url accept limit_ms samples
  local raw times
  raw=$(for i in $(seq 1 "$4"); do curl -s -o /dev/null -H "Accept: $2" -w '%{http_code} %{time_starttransfer}\n' "$1"; done)
  if echo "$raw" | grep -qv '^200 '; then echo "$1: not every answer was 200 ($(echo "$raw" | cut -d' ' -f1 | sort | uniq -c | tr '\n' ' '))"; fail=1; fi
  times=$(echo "$raw" | cut -d' ' -f2 | sort -n)
  local v; v=$(echo "$times" | awk '{a[NR]=$1} END{printf "%.0f", a[int(NR*0.95)]*1000}')
  local status="ok"; if [ "$v" -ge "$3" ]; then status="OVER BUDGET"; fail=1; fi
  printf '%-60s p95 %4s ms (budget %s) %s\n' "$1" "$v" "$3" "$status"
}
slug=$(curl -s -H 'Accept: application/json' "$BASE/api/v1/products?per_page=1" | php -r '$d=json_decode(stream_get_contents(STDIN),true); echo $d["data"][0]["slug"] ?? "";')
for path in "/?b=1" "/shop?b=1" "/shop/$slug?b=1" "/membership?b=1" "/signals?b=1"; do p95 "$BASE$path" text/html 150 20; done   # ?b= skips the page cache
for path in /api/v1/products "/api/v1/products/$slug" /api/v1/categories /api/v1/membership/info /api/v1/signals/info; do p95 "$BASE$path" application/json 100 10; done   # 10 each: stays under the API rate limit
exit $fail
