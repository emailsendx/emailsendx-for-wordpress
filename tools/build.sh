#!/usr/bin/env bash
#
# Build the WordPress-installable zip for "EmailSendX for WordPress".
#
#     bash tools/build.sh
#
# Produces in tools/dist/:
#
#     emailsendx-for-wordpress-<version>.zip            upload to push.thedevgarden.dev
#     emailsendx-for-wordpress-<version>-changelog.md   paste into the Changelog box
#
# Updates ship only from DevGarden Push (push.thedevgarden.dev → Releases).
#
# The zip contains a single top-level `emailsendx-for-wordpress/` folder.
# That folder name is load-bearing: WordPress installs a plugin into the
# folder the zip carries, so a mismatch turns every auto-update into a
# SECOND copy of the plugin sitting beside the old one. The build refuses
# to finish if the shape is wrong — a hand-rolled zip that shipped
# before had no top-level folder at all and could not be installed via
# Plugins → Add New → Upload. ShaonPro.
set -euo pipefail

SLUG="emailsendx-for-wordpress"          # install folder + zip basename
MAINFILE="emailsendx-sync.php"           # main PHP file (NOT renamed — renaming it
                                         # would deactivate every existing install)

HERE="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
ROOT="$(cd "$HERE/.." && pwd)"
DIST="$ROOT/tools/dist"
STAGE="$ROOT/tools/.build"
MAIN="$ROOT/$MAINFILE"

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

# ── The slug the plugin announces must equal the folder we're building ──
DECLARED_SLUG="$(grep -oE "EMAILSENDX_SYNC_SLUG',[[:space:]]*'[^']+'" "$MAIN" | grep -oE "'[^']+'$" | tr -d "'")"
if [ "$DECLARED_SLUG" != "$SLUG" ]; then
  echo "✗ EMAILSENDX_SYNC_SLUG is '$DECLARED_SLUG' but this build makes '$SLUG/'." >&2
  echo "  They must match or updates install beside the plugin instead of over it." >&2
  exit 1
fi
echo "✓ Slug $SLUG matches EMAILSENDX_SYNC_SLUG"

# ── Syntax-check every shipped PHP file before packaging ────────────────
if command -v php >/dev/null 2>&1; then
  while IFS= read -r -d '' f; do
    php -l "$f" >/dev/null || { echo "✗ PHP syntax error in $f" >&2; exit 1; }
  done < <(find "$ROOT" -name '*.php' -not -path '*/.git/*' -not -path '*/tools/*' -print0)
  echo "✓ PHP syntax clean"
else
  echo "  (php CLI not on PATH — skipping syntax check)"
fi

# ── Stage a clean copy under the slug folder, excluding dev/VCS junk ────
rm -rf "$STAGE"
mkdir -p "$STAGE/$SLUG" "$DIST"
ZIP="$DIST/$SLUG-$HEADER_VER.zip"
rm -f "$DIST"/*.zip "$DIST"/*.json "$DIST"/*.md

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
( cd "$STAGE" && zip -rqX "$ZIP" "$SLUG" -x '*.DS_Store' )
rm -rf "$STAGE"

# ── Shape gate: exactly one top-level entry, and it's the slug folder ───
# List once into a variable. Piping `unzip -Z1` into `grep -q` is a trap:
# grep exits on the first match, unzip takes SIGPIPE, and `pipefail` turns
# that into a spurious build failure — intermittently, depending on whether
# unzip finished writing first. ShaonPro.
LISTING="$(unzip -Z1 "$ZIP")"

TOPLEVEL="$(printf '%s\n' "$LISTING" | cut -d/ -f1 | sort -u)"
if [ "$TOPLEVEL" != "$SLUG" ]; then
  echo "✗ Bad archive shape. Top-level entries found:" >&2
  printf '    %s\n' $TOPLEVEL >&2
  echo "  WordPress needs exactly one top-level folder named '$SLUG'." >&2
  exit 1
fi
case "$LISTING" in
  *\\*) echo "✗ Archive contains backslash paths — rebuild with Info-ZIP, not Finder/Explorer." >&2; exit 1 ;;
esac
printf '%s\n' "$LISTING" | grep -Fxq "$SLUG/$MAINFILE" || {
  echo "✗ $SLUG/$MAINFILE missing from the archive." >&2; exit 1; }
printf '%s\n' "$LISTING" | grep -Fxq "$SLUG/includes/push-sdk/load.php" || {
  echo "✗ includes/push-sdk missing — the shipped build would have no auto-updates." >&2; exit 1; }
echo "✓ Archive shape valid (single top-level $SLUG/, forward slashes, updater bundled)"

# ── Changelog for the push panel: this version's readme entry ──────────
CHANGELOG="$DIST/$SLUG-$HEADER_VER-changelog.md"
awk -v v="$HEADER_VER" '
  /^== / { inlog = ($0 ~ /^== Changelog ==/); next }
  inlog && /^= / { take = ($0 ~ "^= " v " ="); next }
  inlog && take { sub(/^\* /, "- "); print }
' "$ROOT/readme.txt" | perl -0777 -pe 's/\A\s+//; s/\s+\z/\n/' > "$CHANGELOG"
if [ ! -s "$CHANGELOG" ]; then
  echo "✗ readme.txt has no changelog entry for $HEADER_VER (= $HEADER_VER = under == Changelog ==)." >&2
  exit 1
fi

echo "── archive contents ──────────────────────────────────────────────"
unzip -l "$ZIP" | tail -3
echo "── changelog (paste into the panel) ──────────────────────────────"
cat "$CHANGELOG"
echo "──────────────────────────────────────────────────────────────────"
echo "✓ Upload:    $ZIP"
echo "✓ Changelog: $CHANGELOG"
echo
echo "  push.thedevgarden.dev → Releases → EmailSendX for WordPress:"
echo "  upload the zip, paste the changelog, Publish."
