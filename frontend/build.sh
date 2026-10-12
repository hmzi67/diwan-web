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

# ---------------------------------------------------------------------------
# sitemap.xml: every page that is not marked noindex, so a new public page is
# listed (and an account page left out) without anyone editing a list. lastmod
# is the page's last commit date, falling back to today for uncommitted files.
# ---------------------------------------------------------------------------
SITE_URL="${SITE_URL:-https://diwan.codehunts.co.uk}"
echo "==> Generating sitemap.xml for $SITE_URL"
{
  echo '<?xml version="1.0" encoding="UTF-8"?>'
  echo '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">'
  while IFS= read -r rel; do
    grep -qiE '<meta[^>]+name="robots"[^>]+noindex' "$SRC/$rel" && continue
    lastmod="$(git -C "$ROOT" log -1 --format=%cs -- "src/$rel" 2>/dev/null || true)"
    [[ -n "$lastmod" ]] || lastmod="$(date -u +%Y-%m-%d)"
    path="/$rel"; [[ "$rel" == "index.html" ]] && path="/"
    printf '  <url><loc>%s%s</loc><lastmod>%s</lastmod></url>\n' "$SITE_URL" "$path" "$lastmod"
  done < <(cd "$SRC" && find . -name '*.html' | sed 's|^\./||' | LC_ALL=C sort)
  echo '</urlset>'
} > "$DIST/sitemap.xml"
echo "    $(grep -c '<loc>' "$DIST/sitemap.xml") URLs"

# ---------------------------------------------------------------------------
# CSS: Tailwind v4 compiles tailwind.css -> dist/css/styles.css, scanning
# src/**/*.html and src/js/*.js for the classes actually in use. Needs Node;
# `npm ci` runs once (network) if node_modules is absent, so CI and a fresh
# clone both work from this single entrypoint.
# ---------------------------------------------------------------------------
echo "==> Building CSS (Tailwind v4)"
[[ -f "$ROOT/tailwind.css" ]] || { echo "::error::frontend/tailwind.css is missing"; exit 1; }
[[ -d "$ROOT/node_modules" ]] || (cd "$ROOT" && npm ci --no-fund --no-audit)
mkdir -p "$DIST/css"
"$ROOT/node_modules/.bin/tailwindcss" \
  -i "$ROOT/tailwind.css" \
  -o "$DIST/css/styles.css" \
  --minify
[[ -s "$DIST/css/styles.css" ]] || { echo "::error::Tailwind produced an empty stylesheet"; exit 1; }

echo "==> Validating HTML entrypoints reference existing assets"
fail=0
while IFS= read -r -d '' html; do
  # Extract local href/src paths and confirm each one exists in dist.
  # Any ?query or #fragment is stripped before the existence check, so a
  # cache-busting suffix (added below) does not look like a missing file.
  grep -oE '(href|src)="/[^"]+"' "$html" \
    | sed -E 's/.*"\/(.*)"/\1/' \
    | sed -E 's/[?#].*$//' \
    | while read -r asset; do
        [[ -e "$DIST/$asset" ]] || { echo "::error file=${html#$ROOT/}::missing asset: /$asset"; exit 1; }
      done || fail=1
done < <(find "$DIST" -name '*.html' -print0)
[[ $fail -eq 0 ]] || { echo "::error::asset validation failed"; exit 1; }

# ---------------------------------------------------------------------------
# Cache-busting.
#
# .htaccess caches CSS and JS for 7 days. On an unversioned filename that means
# a bad or stale copy sticks in a visitor's browser — and in the CDN — for a
# week, with no way to push a correction. It also makes "did my fix deploy?"
# unanswerable, because you cannot tell a stale client from a broken server.
#
# Stamping a build id onto the URL makes every deploy a new URL, so a stale
# copy can never be served for the new build. Runs AFTER validation above,
# which checks the on-disk path.
# ---------------------------------------------------------------------------
export BUILD_ID="$(printf '%s' "${GITHUB_SHA:-$(date -u +%Y%m%d%H%M%S)}" | cut -c1-12)"
echo "==> Stamping assets with build id: $BUILD_ID"

stamped=0
while IFS= read -r -d '' html; do
  # Only local /css/*.css and /js/*.js. External URLs, and any reference that
  # already carries a query or fragment, are left untouched — so this is safe
  # to run twice.
  perl -pi -e 's{((?:href|src)=")(/(?:css|js)/[^"?#]+\.(?:css|js))(?=")}{$1 . $2 . "?v=" . $ENV{BUILD_ID}}ge' "$html"
  stamped=$((stamped + 1))
done < <(find "$DIST" -name '*.html' -print0)
echo "    stamped $stamped HTML files"

# Build stamp so you can tell at a glance what is live.
cat > "$DIST/build.json" <<JSON
{
  "commit": "${GITHUB_SHA:-local}",
  "ref": "${GITHUB_REF_NAME:-local}",
  "build_id": "${BUILD_ID}",
  "built_at": "$(date -u +%Y-%m-%dT%H:%M:%SZ)"
}
JSON

echo "==> Build complete: $(find "$DIST" -type f | wc -l | tr -d ' ') files"
