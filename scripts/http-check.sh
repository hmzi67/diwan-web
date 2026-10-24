#!/usr/bin/env bash
# Serves the built site and the API with `php -S`, the same layout as
# scripts/dev.sh, then runs the smoke test against it. Used by CI; safe to
# run locally — it never touches backend/config/.env, because the app is
# assembled in a scratch directory with its own config.
#
#   bash scripts/http-check.sh /path/to/test.env [port]
#
# The env file needs the DB_* values and APP_KEY of a disposable database.
set -Eeuo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
ENV_FILE="${1:?usage: http-check.sh /path/to/test.env [port]}"
PORT="${2:-8000}"
WORK="$(mktemp -d)"
trap 'kill "${SERVER_PID:-}" 2>/dev/null || true; rm -rf "$WORK"' EXIT

[[ -d "$ROOT/frontend/dist" ]] || bash "$ROOT/frontend/build.sh" > /dev/null

# Web root: the built site at /, entrypoints at /api/.
mkdir -p "$WORK/site/api" "$WORK/app/config" "$WORK/app/private-storage/logs" "$WORK/app/private-storage/releases"
cp -R "$ROOT/frontend/dist/." "$WORK/site/"
cp "$ROOT"/backend/public/*.php "$WORK/site/api/"
# The app outside the web root; _app_root.php is _bootstrap.php's override.
cp -R "$ROOT/backend/src" "$WORK/app/src"
printf "<?php return %s;\n" "'$WORK/app'" > "$WORK/site/api/_app_root.php"

{
  grep -vE '^(APP_ENV|APP_URL|LICENSE_SIGNING_[A-Z_]+|PRIVATE_STORAGE_PATH)=' "$ENV_FILE"
  echo "APP_ENV=testing"
  echo "APP_URL=http://127.0.0.1:${PORT}"
  echo "LICENSE_SIGNING_KEY_ID=http-check"
  php -r 'require $argv[1]; echo "LICENSE_SIGNING_PRIVATE_KEY=", Diwan\License\LicenseSigner::generateKeyPair()["private"], "\n";' \
    "$ROOT/backend/src/bootstrap-autoload.php"
} > "$WORK/app/config/.env"

php -S "127.0.0.1:${PORT}" -t "$WORK/site" > "$WORK/server.log" 2>&1 &
SERVER_PID=$!
for _ in $(seq 1 40); do
  curl -fs -o /dev/null "http://127.0.0.1:${PORT}/" && break
  sleep 0.25
done

if ! bash "$ROOT/scripts/smoke-test.sh" --local "http://127.0.0.1:${PORT}"; then
  echo "--- php -S log ---"
  cat "$WORK/server.log"
  exit 1
fi

# Plan licensing end to end: customer request, admin approval with CSRF,
# POS activation and the signed payload. Uses the same database and config.
if ! php "$ROOT/scripts/tests/http-flow.php" "http://127.0.0.1:${PORT}" "$WORK/app/config/.env"; then
  echo "--- php -S log ---"
  cat "$WORK/server.log"
  exit 1
fi
