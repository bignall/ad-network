#!/usr/bin/env bash
# Build an installable release zip: plugin code + prefixed WP Foundation, no dev tools.
# Usage: bin/build-zip.sh [output-dir]   (default: dist/)
set -euo pipefail

ROOT="$(cd "$(dirname "$0")/.." && pwd)"
SLUG="ad-network"
OUT="$(cd "${1:-$ROOT}" && pwd)"; [ -z "${1:-}" ] && OUT="$ROOT/dist"
VERSION="$(sed -n 's/^ \* Version: *//p' "$ROOT/$SLUG.php" | tr -d '[:space:]')"
WORK="$(mktemp -d)"; trap 'rm -rf "$WORK"' EXIT
DEST="$WORK/$SLUG"

mkdir -p "$DEST" "$OUT"
cp -r "$ROOT"/{$SLUG.php,readme.txt,composer.json,composer.lock,src,assets} "$DEST/"

cd "$DEST"
# Full install runs Strauss (a dev dependency), which writes vendor-prefixed/.
composer install --no-interaction --no-progress --quiet
# Rebuild the autoloader for runtime code only, then drop everything else in vendor/.
composer dump-autoload --no-dev --classmap-authoritative --quiet
find vendor -mindepth 1 -maxdepth 1 ! -name autoload.php ! -name composer -exec rm -rf {} +
# composer/* dev packages (Strauss needs composer/composer) also live in vendor/composer/.
find vendor/composer -mindepth 1 -maxdepth 1 -type d -exec rm -rf {} +
rm -f composer.json composer.lock

# Fail the build if an unprefixed copy of the library slipped in.
if grep -rq "namespace Bignall\\\\WPFoundation" vendor vendor-prefixed 2>/dev/null; then
	echo "Unprefixed WP Foundation found in build" >&2; exit 1
fi

rm -f "$OUT/$SLUG-$VERSION.zip"
(cd "$WORK" && zip -qr "$OUT/$SLUG-$VERSION.zip" "$SLUG")
echo "$OUT/$SLUG-$VERSION.zip"
