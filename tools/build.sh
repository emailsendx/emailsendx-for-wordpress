#!/usr/bin/env bash
#
# Build a WordPress-installable zip for "EmailSendX for WordPress".
#
# Produces tools/dist/emailsendx-sync.zip containing a single top-level
# `emailsendx-sync/` folder with forward-slash paths — the shape WordPress
# (and the Plugin Update Checker) require. Run from anywhere:
#
#     bash tools/build.sh
#
# The release asset is named for the SLUG (emailsendx-sync.zip), not the
# repo, so the installed folder + auto-update matching stay stable even
# though the GitHub repo is "emailsendx-for-wordpress".
set -euo pipefail

SLUG="emailsendx-sync"
HERE="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
ROOT="$(cd "$HERE/.." && pwd)"
DIST="$ROOT/tools/dist"
STAGE="$ROOT/tools/.build"
MAIN="$ROOT/$SLUG.php"

# ── Version gate: header, runtime constant, and readme must agree ───────
ver_from() { grep -E "$2" "$1" | grep -oE '[0-9]+(\.[0-9]+){1,}' | head -1; }
HEADER_VER="$(ver_from "$MAIN" '^[[:space:]]*\*[[:space:]]*Version:')"
CONST_VER="$(ver_from "$MAIN" 'EMAILSENDX_SYNC_VERSION')"
README_VER="$(ver_from "$ROOT/readme.txt" '^Stable tag:')"

echo "  Header Version:           ${HEADER_VER:-<none>}"
echo "  EMAILSENDX_SYNC_VERSION:  ${CONST_VER:-<none>}"
echo "  readme.txt Stable tag:    ${README_VER:-<none>}"
if [ -z "$HEADER_VER" ] || [ "$HEADER_VER" != "$CONST_VER" ] || [ "$HEADER_VER" != "$README_VER" ]; then
  echo "✗ Version mismatch — align the header, EMAILSENDX_SYNC_VERSION, and readme Stable tag before building." >&2
  exit 1
fi
echo "✓ Version $HEADER_VER consistent across all three"

# ── Stage a clean copy under the slug folder, excluding dev/VCS junk ────
rm -rf "$STAGE"
mkdir -p "$STAGE/$SLUG" "$DIST"
rm -f "$DIST/$SLUG.zip"

rsync -a \
  --exclude '.git' \
  --exclude '.github' \
  --exclude '.gitignore' \
  --exclude '.gitattributes' \
  --exclude '.DS_Store' \
  --exclude 'tools' \
  --exclude 'README.md' \
  --exclude '*.zip' \
  "$ROOT/" "$STAGE/$SLUG/"

# ── Zip (Info-ZIP on macOS/Linux → forward slashes, single top folder) ──
( cd "$STAGE" && zip -rqX "$DIST/$SLUG.zip" "$SLUG" -x '*.DS_Store' )
rm -rf "$STAGE"

echo "✓ Built $DIST/$SLUG.zip"
echo "── archive contents ──────────────────────────────────────────────"
unzip -l "$DIST/$SLUG.zip"
