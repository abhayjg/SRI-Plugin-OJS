#!/usr/bin/env bash
#
# Downloads and extracts an OJS release into the Apache webroot.
#
# Usage: install-ojs.sh <version>
#   version e.g. "3.5.0", "3.3.0-22", "3.4.0-4"
#
# Release archives live on PKP's site; the URL layout is:
#   https://pkp.sfu.ca/ojs/download/ojs-<version>.tar.gz
#
# NOTE: PKP occasionally renames archives; if the download 404s, fetch the
# release manually from https://pkp.sfu.ca/software/ojs/download/ and drop the
# .tar.gz next to this file, then set OJS_LOCAL_TARBALL=1 to use it instead.

set -euo pipefail

VERSION="${1:-3.5.0}"
WORK="/tmp/ojs"
WEBROOT="/var/www/html"

mkdir -p "$WORK"
cd "$WORK"

if [ -z "${OJS_LOCAL_TARBALL:-}" ]; then
  echo "==> Downloading OJS $VERSION"
  curl -fsSL "https://pkp.sfu.ca/ojs/download/ojs-${VERSION}.tar.gz" -o ojs.tar.gz \
    || curl -fsSL "https://pkp.sfu.ca/ojs/download/ojs-${VERSION}.tgz" -o ojs.tar.gz \
    || { echo "OJS download failed for $VERSION"; exit 1; }
else
  echo "==> Using local tarball"
  cp /tmp/ojs-local.tar.gz ojs.tar.gz
fi

echo "==> Extracting into webroot"
tar -xzf ojs.tar.gz -C "$WORK"
# The archive extracts into a folder like ojs-3.5.0/ ; move contents up.
SRC="$(find "$WORK" -maxdepth 1 -type d -name 'ojs-*' | sort | tail -1)"
if [ -z "$SRC" ]; then SRC="$WORK"; fi
rsync -a --delete "$SRC"/ "$WEBROOT"/ 2>/dev/null || cp -a "$SRC"/. "$WEBROOT"/

mkdir -p "$WEBROOT/cache" "$WEBROOT/public"
echo "==> OJS $VERSION installed. Finish setup via the browser installer."
