#!/usr/bin/env bash
# Post-deploy verification. Fails the workflow if the site is broken OR if any
# path that must be private has become reachable.
#
#   bash scripts/smoke-test.sh https://diwan.example.com
#   bash scripts/smoke-test.sh --local http://127.0.0.1:8000
#
# --local is for CI's own `php -S` server: it has no Apache, so .htaccess rules
# do not apply there and the private-path checks are skipped (dotfiles and
# config are simply not in its document root). Everything else runs the same.
#
# NOTE ON EXIT CODES
#   0  all checks passed
#   1  a real failure — the site is broken, or something private is reachable
#   2  the test could not run (bad usage, the host's bot protection blocked us,
#      or no connection to the site at all)
#
# Exit 2 exists because a blocked run is NOT a passing run and NOT a failing
# site. The host's CDN challenges datacenter IPs (which is what a CI runner is)
# with a JavaScript interstitial, returning 403 for every request. That used to
# make every "must not be reachable" assertion pass trivially — see the guard
# in expect_blocked below.
set -Eeuo pipefail

LOCAL=0
if [[ "${1-}" == "--local" ]]; then
  LOCAL=1
  shift
fi

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

# curl's own user agent: Hostinger's CDN answers 429 to curl claiming to be a
# browser, and 200 to plain curl. SMOKE_UA overrides it for other hosts.
CURL=(curl -sS --max-time 25)
if [[ -n "${SMOKE_UA-}" ]]; then CURL+=(-A "$SMOKE_UA"); fi

# Signatures of an interstitial rather than our own content.
CHALLENGE_RE='Checking your browser before accessing|/hcdn-cgi/|__cf_chl|Just a moment'

# curl already writes 000 when it gets no answer; the fallback is for when it writes nothing.
code() {
  local got; got="$("${CURL[@]}" -o /dev/null -w '%{http_code}' -L "$1" 2>/dev/null || true)"
  echo "${got:-000}"
}
# No -L: an API answer is checked as given, never after a redirect.
post_code() {
  local got
  got="$("${CURL[@]}" -o /dev/null -w '%{http_code}' -X POST -H 'Content-Type: application/json' \
    --data "${2:-{\}}" "$1" 2>/dev/null || true)"
  echo "${got:-000}"
}
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
  # edge refused US, and 000 means no answer reached us at all (the host drops
  # connections from CI runners); a 500 or a 404 on the homepage is the site's
  # own problem and must still break the build.
  if is_challenge "${home_body-}" || [[ "$home_code" == "403" || "$home_code" == "429" || "$home_code" == "000" ]]; then
    cat >&2 <<MSG
::warning::Smoke test could not run — the host's edge blocked this runner or never answered it (HTTP ${home_code}).

Requests were refused, rate limited or dropped before reaching the site, so no
assertion below can be trusted. THIS DOES NOT MEAN THE SITE IS DOWN — it means CI cannot see it.
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
  # Tolerant of JSON spacing: the shape is checked, not the encoder's output.
  if ! grep -Eq '"status"[[:space:]]*:[[:space:]]*"ok"' <<<"$health"; then
    echo "  FAIL  health endpoint is not reporting ok"
    failures=$((failures + 1))
  fi
fi

expect_post() {
  local url="$1" body="$2" want="$3" label="$4"
  local got; got="$(post_code "$url" "$body")"
  if [[ "$got" == "$want" ]]; then
    printf '  ok    %-52s %s\n' "$label" "$got"
  else
    printf '  FAIL  %-52s got %s, expected %s\n' "$label" "$got" "$want"
    failures=$((failures + 1))
  fi
}

# Every check below is refused before any state changes, so it is as safe to
# run against production as against CI's scratch database.
echo
echo "API refuses what it must:"
expect      "$BASE/api/products.php"                          200 "products.php lists editions"
expect      "$BASE/api/payment-return.php?order_id=NONE"      200 "payment-return.php shows a pending page"
expect      "$BASE/api/payment-webhook.php"                   405 "payment-webhook.php refuses GET"
expect_post "$BASE/api/payment-webhook.php" '{}'              400 "payment-webhook.php refuses an unsigned call"
expect      "$BASE/api/dashboard-data.php"                    401 "dashboard-data.php needs a session"
expect_post "$BASE/api/checkout.php" '{}'                     410 "checkout.php is closed (plans need admin approval)"
expect      "$BASE/api/plans.php"                             200 "plans.php lists the plans"
expect      "$BASE/api/license-requests.php"                  401 "license-requests.php needs a session"
expect_post "$BASE/api/license-requests.php" '{"plan":"basic"}' 401 "a plan request needs a session"
expect      "$BASE/api/notifications.php"                     401 "notifications.php needs a session"
expect      "$BASE/api/business.php?view=context"             401 "business.php needs a session"
expect_post "$BASE/api/business.php" '{"action":"save"}'      401 "business edits need a session"
expect_post "$BASE/api/admin-login.php" '{}'                  422 "admin-login.php rejects an empty form"
for admin_get in admin-session admin-dashboard admin-users admin-license-requests admin-licenses \
                 admin-plans admin-downloads admin-releases admin-notifications admin-audit-logs admin-settings; do
  expect    "$BASE/api/${admin_get}.php"                      401 "${admin_get}.php needs an admin session"
done
expect_post "$BASE/api/admin-license-requests.php" '{"id":1,"action":"approve"}' 401 "approving needs an admin session"
expect_post "$BASE/api/admin-plans.php" '{"action":"update_plan","id":1}'     401 "plan edits need an admin session"
expect_post "$BASE/api/resend-license-email.php" '{}'         401 "resend-license-email.php needs a session"
expect_post "$BASE/api/sign-in.php" '{}'                      422 "sign-in.php rejects an empty form"
expect_post "$BASE/api/activate-license.php" '{}'             422 "activate-license.php rejects an empty key"
expect_post "$BASE/api/license-status.php" '{"license_key":"x"}' 422 "license-status.php rejects a missing fingerprint"
expect_post "$BASE/api/issue-download.php" '{"platform":"beos"}' 422 "issue-download.php rejects an unknown platform"

if [[ "$LOCAL" -eq 1 ]]; then
  echo
  echo "Private paths: skipped (--local: no Apache, so .htaccess does not apply)"
else
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
expect_blocked "$BASE/.git/config"                            "/.git/config"
expect_blocked "$BASE/database/schema.sql"                    "/database/schema.sql"
expect_blocked "$BASE/scripts/smoke-test.sh"                  "/scripts/"
expect_blocked "$BASE/private-storage/logs/"                  "/private-storage/logs/"
expect_blocked "$BASE/api/router.php"                         "/api/router.php (dev only)"
fi

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
