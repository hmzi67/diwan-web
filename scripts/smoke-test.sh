#!/usr/bin/env bash
# Post-deploy verification. Fails the workflow if the site is broken OR if any
# path that must be private has become reachable.
#
#   bash scripts/smoke-test.sh https://diwan.example.com
#
# NOTE ON EXIT CODES
#   0  all checks passed
#   1  a real failure — the site is broken, or something private is reachable
#   2  the test could not run (bad usage, or the host's bot protection blocked us)
#
# Exit 2 exists because a blocked run is NOT a passing run and NOT a failing
# site. The host's CDN challenges datacenter IPs (which is what a CI runner is)
# with a JavaScript interstitial, returning 403 for every request. That used to
# make every "must not be reachable" assertion pass trivially — see the guard
# in expect_blocked below.
set -Eeuo pipefail

if [[ -z "${1-}" ]]; then
  echo "usage: smoke-test.sh <base-url>      e.g. https://diwan.codehunts.co.uk" >&2
  echo >&2
  echo "Called from CI with an empty argument? The SITE_URL variable is unset" >&2
  echo "for this environment. Settings -> Environments -> <env> -> Variables." >&2
  exit 2
fi
BASE="${1%/}"
failures=0
reachable=0

# A plain curl looks like a bot to most CDNs. Presenting a normal browser UA
# does not defeat a real JS challenge, but it avoids the trivial UA-only rules.
UA="${SMOKE_UA:-Mozilla/5.0 (X11; Linux x86_64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/124.0 Safari/537.36}"
CURL=(curl -sS --max-time 25 -A "$UA")

# Signatures of an interstitial rather than our own content.
CHALLENGE_RE='Checking your browser before accessing|/hcdn-cgi/|__cf_chl|Just a moment'

code() { "${CURL[@]}" -o /dev/null -w '%{http_code}' -L "$1" 2>/dev/null || echo 000; }
body() { "${CURL[@]}" -L "$1" 2>/dev/null || true; }

is_challenge() { grep -qE "$CHALLENGE_RE" <<<"${1-}"; }

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

  # GUARD: if the site itself is not reachable, a 403 here proves nothing —
  # everything is 403. Reporting "ok" in that state would be a false negative
  # on the checks that matter most, so refuse to draw a conclusion.
  if [[ "$reachable" -ne 1 ]]; then
    printf '  SKIP  %-52s INCONCLUSIVE — site unreachable, cannot verify\n' "$label"
    failures=$((failures + 1))
    return
  fi

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

# --- Preflight -------------------------------------------------------------
# Establish whether we can see the real site at all before asserting anything.
# Retried: challenges and cold caches are often transient.
echo "Preflight:"
for attempt in 1 2 3; do
  home_body="$(body "$BASE/")"
  home_code="$(code "$BASE/")"

  if is_challenge "$home_body"; then
    printf '  attempt %d: bot-protection interstitial (HTTP %s)\n' "$attempt" "$home_code"
    [[ $attempt -lt 3 ]] && sleep 10
    continue
  fi
  if [[ "$home_code" == "200" ]]; then
    reachable=1
    printf '  ok    site is reachable and serving its own content (200)\n'
    break
  fi
  printf '  attempt %d: HTTP %s\n' "$attempt" "$home_code"
  [[ $attempt -lt 3 ]] && sleep 10
done

if [[ "$reachable" -ne 1 ]]; then
  echo
  # Split "we were blocked" (inconclusive, exit 2) from "the site is wrong"
  # (a real failure, exit 1). A challenge page, a 403 or a 429 all mean the
  # edge refused US; a 500 or a 404 on the homepage is the site's own problem
  # and must still break the build.
  if is_challenge "${home_body-}" || [[ "$home_code" == "403" || "$home_code" == "429" ]]; then
    cat >&2 <<MSG
::warning::Smoke test could not run — the host's edge blocked this runner (HTTP ${home_code}).

Requests were refused by bot protection or rate limiting, so no assertion below
can be trusted. THIS DOES NOT MEAN THE SITE IS DOWN — it means CI cannot see it.
Verify from a normal browser before assuming a problem.

To fix, do one of:
  * hPanel -> Security / CDN -> disable or relax the JS "browser check" for
    this domain (simplest, and the site has no other bot-protection need);
  * allowlist GitHub Actions egress ranges (large and they change — painful);
  * run this smoke test from somewhere with a residential/allowlisted IP.
MSG
    exit 2
  fi

  echo "::error::Smoke test FAILED — $BASE/ returned HTTP ${home_code}, not 200." >&2
  echo "This is the site's own response, not a bot challenge. The deploy is broken." >&2
  exit 1
fi

echo
echo "Availability:"
expect "$BASE/"                    200 "homepage"
expect "$BASE/css/styles.css"      200 "stylesheet"
expect "$BASE/js/main.js"          200 "javascript"

echo
echo "Application health:"
health="$(body "$BASE/api/health.php")"
if is_challenge "$health"; then
  echo "  FAIL  health endpoint returned a bot-protection interstitial, not JSON"
  failures=$((failures + 1))
else
  echo "  ${health:0:400}"
  if ! grep -q '"status":"ok"' <<<"$health"; then
    echo "  FAIL  health endpoint is not reporting ok"
    failures=$((failures + 1))
  fi
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
