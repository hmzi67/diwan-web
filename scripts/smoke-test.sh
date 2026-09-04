#!/usr/bin/env bash
# Post-deploy verification. Fails the workflow if the site is broken OR if any
# path that must be private has become reachable.
#
#   bash scripts/smoke-test.sh https://diwan.example.com
set -Eeuo pipefail

BASE="${1:?usage: smoke-test.sh <base-url>}"
BASE="${BASE%/}"
failures=0

code() { curl -sS -o /dev/null -w '%{http_code}' --max-time 20 -L "$1" 2>/dev/null || echo 000; }

expect() {
  local url="$1" want="$2" label="$3"
  local got; got="$(code "$url")"
  if [[ "$got" == "$want" ]]; then
    printf '  ok    %-52s %s\n' "$label" "$got"
  else
    printf '  FAIL  %-52s got %s, expected %s\n' "$label" "$got" "$want"
    failures=$((failures + 1))
  fi
}

expect_blocked() {
  local url="$1" label="$2"
  local got; got="$(code "$url")"
  # 403 or 404 are both fine — anything that serves content is not.
  if [[ "$got" == "403" || "$got" == "404" ]]; then
    printf '  ok    %-52s blocked (%s)\n' "$label" "$got"
  else
    printf '  FAIL  %-52s REACHABLE (%s) — this must not be public\n' "$label" "$got"
    failures=$((failures + 1))
  fi
}

echo "Smoke testing $BASE"
echo
echo "Availability:"
expect "$BASE/"                    200 "homepage"
expect "$BASE/css/styles.css"      200 "stylesheet"
expect "$BASE/js/main.js"          200 "javascript"

echo
echo "Application health:"
health="$(curl -sS --max-time 20 "$BASE/api/health.php" || true)"
echo "  $health"
if ! grep -q '"status":"ok"' <<<"$health"; then
  echo "  FAIL  health endpoint is not reporting ok"
  failures=$((failures + 1))
fi

echo
echo "Private paths must NOT be reachable:"
expect_blocked "$BASE/private-storage/"                       "/private-storage/"
expect_blocked "$BASE/private-storage/releases/"              "/private-storage/releases/"
expect_blocked "$BASE/api/_app_root.php"                      "/api/_app_root.php"
expect_blocked "$BASE/api/_bootstrap.php"                     "/api/_bootstrap.php"
expect_blocked "$BASE/.env"                                   "/.env"
expect_blocked "$BASE/api/.env"                               "/api/.env"
expect_blocked "$BASE/src/bootstrap.php"                      "/src/bootstrap.php"
expect_blocked "$BASE/api/src/bootstrap.php"                  "/api/src/bootstrap.php"
expect_blocked "$BASE/.ftp-deploy-web.json"                   "FTP sync state file"
expect_blocked "$BASE/build.json"                             "build stamp"

echo
echo "Download gate must reject unauthenticated requests:"
expect "$BASE/api/download.php"                          400 "download.php with no token"
expect "$BASE/api/download.php?token=$(printf '0%.0s' {1..64})" 404 "download.php with a bogus token"

echo
if [[ $failures -gt 0 ]]; then
  echo "::error::$failures smoke test(s) failed against $BASE"
  exit 1
fi
echo "All smoke tests passed."
