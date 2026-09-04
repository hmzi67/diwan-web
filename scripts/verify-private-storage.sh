#!/usr/bin/env bash
# Standalone probe you can run any time (not just at deploy) to prove that
# nothing under private-storage is reachable from the internet.
#
#   bash scripts/verify-private-storage.sh https://diwan.example.com
set -Eeuo pipefail

BASE="${1:?usage: verify-private-storage.sh <base-url>}"
BASE="${BASE%/}"
bad=0

paths=(
  "/private-storage/"
  "/private-storage/releases/"
  "/private-storage/releases/Diwan-Setup.exe"
  "/private-storage/logs/"
  "/app/private-storage/releases/"
  "/api/private-storage/releases/"
  "/../app/private-storage/releases/"
  "/src/"
  "/src/Config/Env.php"
  "/api/src/Config/Env.php"
  "/config/.env"
  "/api/config/.env"
  "/.env"
)

echo "Probing $BASE for exposed private paths…"
for p in "${paths[@]}"; do
  status="$(curl -sS -o /dev/null -w '%{http_code}' --max-time 15 "${BASE}${p}" || echo 000)"
  size="$(curl -sS -o /dev/null -w '%{size_download}' --max-time 15 "${BASE}${p}" || echo 0)"
  if [[ "$status" == "200" && "$size" -gt 0 ]]; then
    printf '  EXPOSED  %-46s %s (%s bytes)\n' "$p" "$status" "$size"
    bad=$((bad + 1))
  else
    printf '  safe     %-46s %s\n' "$p" "$status"
  fi
done

echo
if [[ $bad -gt 0 ]]; then
  echo "$bad path(s) are publicly reachable. Fix before selling anything."
  exit 1
fi
echo "No private paths are reachable."
