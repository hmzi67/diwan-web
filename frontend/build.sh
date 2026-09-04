#!/usr/bin/env bash
# Build the static frontend: frontend/src -> frontend/dist
#
# Deliberately dependency-free (no Node, no bundler) because the site is plain
# HTML/CSS/JS. It still exists as a real, failable build step so that:
#   - CI has one place to fail before anything is uploaded,
#   - dist/ is unambiguously the only thing that gets deployed,
#   - swapping in a real bundler later means changing this file only.
set -Eeuo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
SRC="$ROOT/src"
DIST="$ROOT/dist"

[[ -d "$SRC" ]]            || { echo "::error::frontend/src is missing"; exit 1; }
[[ -f "$SRC/index.html" ]] || { echo "::error::frontend/src/index.html is missing"; exit 1; }

echo "==> Cleaning $DIST"
rm -rf "$DIST"
mkdir -p "$DIST"

echo "==> Copying source"
cp -R "$SRC/." "$DIST/"

# Never let development leftovers reach the server.
find "$DIST" -name '.DS_Store' -delete
find "$DIST" -name '*.map' -delete
rm -rf "$DIST/.git" "$DIST/node_modules"

echo "==> Validating HTML entrypoints reference existing assets"
fail=0
while IFS= read -r -d '' html; do
  # Extract local href/src paths and confirm each one exists in dist.
  grep -oE '(href|src)="/[^"]+"' "$html" \
    | sed -E 's/.*"\/(.*)"/\1/' \
    | while read -r asset; do
        [[ -e "$DIST/$asset" ]] || { echo "::error file=${html#$ROOT/}::missing asset: /$asset"; exit 1; }
      done || fail=1
done < <(find "$DIST" -name '*.html' -print0)
[[ $fail -eq 0 ]] || { echo "::error::asset validation failed"; exit 1; }

# Build stamp so you can tell at a glance what is live.
cat > "$DIST/build.json" <<JSON
{
  "commit": "${GITHUB_SHA:-local}",
  "ref": "${GITHUB_REF_NAME:-local}",
  "built_at": "$(date -u +%Y-%m-%dT%H:%M:%SZ)"
}
JSON

echo "==> Build complete: $(find "$DIST" -type f | wc -l | tr -d ' ') files"
