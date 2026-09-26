#!/usr/bin/env bash
#
# One-command release for "EmailSendX for WordPress".
#
#   bash tools/release.sh <x.y.z> "change" ["change" …]
#   e.g.  bash tools/release.sh 1.4.1 "Fix: WooCommerce phone mapping" "New: …"
#
# It bumps the version in all three places (plugin header, the
# EMAILSENDX_SYNC_VERSION constant, and the readme Stable tag), adds a
# changelog entry (one bullet per change) and an upgrade notice (the first
# change), runs the build (shape-checked), commits and tags vX.Y.Z, and
# shows the zip + changelog in Finder. If anything fails the bump is undone.
#
# Nothing is pushed or uploaded for you: upload the zip to
# push.thedevgarden.dev → Releases and paste the changelog. ShaonPro.
set -euo pipefail

VERSION="${1:-}"
shift || true

if ! printf '%s' "$VERSION" | grep -qE '^[0-9]+\.[0-9]+\.[0-9]+$' || [ "$#" -eq 0 ]; then
  echo "Usage: bash tools/release.sh <x.y.z> \"change\" [\"change\" …]" >&2
  echo "  e.g. bash tools/release.sh 1.4.1 \"Fix: WooCommerce phone mapping\"" >&2
  exit 1
fi
SUMMARY="$1"

SLUG="emailsendx-for-wordpress"

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
MAIN="$ROOT/emailsendx-sync.php"
README="$ROOT/readme.txt"
DIST="$ROOT/tools/dist"
TAG="v$VERSION"
cd "$ROOT"

# Clean tree required, so the release commit contains only the bump.
if [ -n "$(git status --porcelain)" ]; then
  echo "✗ Commit or stash your changes first — release needs a clean tree." >&2
  git status --short >&2
  exit 1
fi

# Never clobber an existing release.
if git rev-parse "$TAG" >/dev/null 2>&1; then
  echo "✗ Tag $TAG already exists. Pick a higher version." >&2
  exit 1
fi

# Refuse to go backwards — a lower version ships an update nobody can install.
CURRENT="$(grep -E '^[[:space:]]*\*[[:space:]]*Version:' "$MAIN" | grep -oE '[0-9]+(\.[0-9]+){1,}' | head -1)"
if [ "$(printf '%s\n%s\n' "$CURRENT" "$VERSION" | sort -V | tail -1)" != "$VERSION" ] || [ "$CURRENT" = "$VERSION" ]; then
  echo "✗ $VERSION is not higher than the current $CURRENT." >&2
  exit 1
fi

echo "→ Bumping $CURRENT → $VERSION"
perl -pi -e "s/^(\s*\*\s*Version:\s*)[0-9]+\.[0-9]+\.[0-9]+/\${1}$VERSION/" "$MAIN"
perl -pi -e "s/(EMAILSENDX_SYNC_VERSION[^0-9]+)[0-9]+\.[0-9]+\.[0-9]+/\${1}$VERSION/" "$MAIN"
perl -pi -e "s/^(Stable tag:\s*)[0-9]+\.[0-9]+\.[0-9]+/\${1}$VERSION/" "$README"

echo "→ Adding changelog + upgrade-notice entries"
BULLETS=""
for change in "$@"; do BULLETS="$BULLETS* $change"$'\n'; done
ESX_VER="$VERSION" ESX_BULLETS="$BULLETS" ESX_SUM="$SUMMARY" perl -0777 -pi -e '
  s/(== Changelog ==\n\n)/$1= $ENV{ESX_VER} =\n$ENV{ESX_BULLETS}\n/;
  s/(== Upgrade Notice ==\n\n)/$1= $ENV{ESX_VER} =\n$ENV{ESX_SUM}\n\n/;
' "$README"

rollback() {
  echo "✗ Release aborted — version bump reverted." >&2
  git checkout -- emailsendx-sync.php readme.txt
}
trap rollback ERR

echo "→ Building (enforces version agreement, slug match, and archive shape)"
bash "$ROOT/tools/build.sh" >/dev/null

echo "→ Committing + tagging $TAG"
git add emailsendx-sync.php readme.txt
git commit -q -m "Release $TAG — $SUMMARY"
git tag -a "$TAG" -m "$TAG"
trap - ERR

CHANGELOG="$DIST/$SLUG-$VERSION-changelog.md"
cat <<EOF

✓ $TAG ready
  Zip:        $DIST/$SLUG-$VERSION.zip
  Changelog:  $CHANGELOG

$(cat "$CHANGELOG")

  push.thedevgarden.dev → Releases → EmailSendX for WordPress:
  upload the zip, paste the changelog, Publish.

  Committed and tagged locally; push from GitHub Desktop when you're happy.
EOF

if command -v open >/dev/null 2>&1; then open -R "$DIST/$SLUG-$VERSION.zip"; fi
