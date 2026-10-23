#!/usr/bin/env bash
# Builds the installable ZIP from an explicit list of runtime files.
set -euo pipefail
cd "$(dirname "$0")/.."
SLUG=sidrena-cijena-za-woocommerce
VERSION=$(grep -m1 'Version:' "$SLUG.php" | sed 's/.*Version: *//')
BUILD=build/$SLUG
rm -rf build dist && mkdir -p "$BUILD" dist
# No runtime Composer dependencies. An allowlist also keeps local caches and CI out.
rsync -a --exclude='.DS_Store' --exclude='._*' \
  src templates assets languages "$SLUG.php" uninstall.php readme.txt README.md LICENSE "$BUILD/"
if command -v msgfmt >/dev/null; then
  for po in "$BUILD"/languages/*.po; do [ -f "$po" ] && msgfmt -o "${po%.po}.mo" "$po"; done
fi
# Owner's manual (PDF) ships inside the plugin folder.
cp docs/manual/Sidrena-cijena-za-WooCommerce-prirucnik.pdf "$BUILD/prirucnik.pdf"
( cd build && zip -qr "../dist/$SLUG-$VERSION.zip" "$SLUG" )
echo "Built dist/$SLUG-$VERSION.zip"
