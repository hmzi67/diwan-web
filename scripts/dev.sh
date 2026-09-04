#!/usr/bin/env bash
# Local development server that mirrors the production URL layout:
#   /            -> frontend/dist
#   /api/*.php   -> backend/public
set -Eeuo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
PORT="${PORT:-8000}"

if [[ ! -f "$ROOT/backend/config/.env" ]]; then
  echo "No backend/config/.env — copying from .env.example"
  cp "$ROOT/.env.example" "$ROOT/backend/config/.env"
  # A dev APP_KEY, so licence hashing works locally.
  KEY="$(openssl rand -base64 32)"
  sed -i.bak "s|^APP_KEY=.*|APP_KEY=${KEY}|" "$ROOT/backend/config/.env"
  sed -i.bak "s|^PRIVATE_STORAGE_PATH=.*|PRIVATE_STORAGE_PATH=${ROOT}/backend/private-storage|" "$ROOT/backend/config/.env"
  rm -f "$ROOT/backend/config/.env.bak"
  echo "Edit backend/config/.env and fill in your local DB credentials."
fi

bash "$ROOT/frontend/build.sh"

SERVE="$ROOT/.dev-root"
rm -rf "$SERVE"
mkdir -p "$SERVE/api"
# Symlink so edits are picked up without rebuilding the whole tree.
cp -R "$ROOT/frontend/dist/." "$SERVE/"
for f in "$ROOT"/backend/public/*.php; do ln -sf "$f" "$SERVE/api/$(basename "$f")"; done

echo
echo "Diwan dev server:  http://localhost:${PORT}"
echo "Health check:      http://localhost:${PORT}/api/health.php"
echo
php -S "localhost:${PORT}" -t "$SERVE"
