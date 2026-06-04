#!/usr/bin/env bash
#
# One-command release for "EmailSendX for WordPress".
#
#   bash tools/release.sh <x.y.z> ["changelog summary"]
#   e.g.  bash tools/release.sh 1.2.3 "Fix WooCommerce phone mapping"
#
# It bumps the version in all three places (plugin header, the
# EMAILSENDX_SYNC_VERSION constant, and the readme Stable tag), adds a
# changelog + upgrade-notice entry, verifies the build, then commits and
# tags vX.Y.Z. You then run the single push it prints — CI builds the
# zip, publishes the GitHub Release, and every installed site auto-updates
# from there. Pushing stays your call (nothing is pushed for you).
set -euo pipefail

VERSION="${1:-}"
SUMMARY="${2:-Maintenance release.}"

if ! printf '%s' "$VERSION" | grep -qE '^[0-9]+\.[0-9]+\.[0-9]+$'; then
  echo "Usage: bash tools/release.sh <x.y.z> [\"changelog summary\"]" >&2
  echo "  e.g. bash tools/release.sh 1.2.3 \"Fix WooCommerce phone mapping\"" >&2
  exit 1
fi

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
MAIN="$ROOT/emailsendx-sync.php"
README="$ROOT/readme.txt"
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

echo "→ Bumping to $VERSION"
perl -pi -e "s/^(\s*\*\s*Version:\s*)[0-9]+\.[0-9]+\.[0-9]+/\${1}$VERSION/" "$MAIN"
perl -pi -e "s/(EMAILSENDX_SYNC_VERSION[^0-9]+)[0-9]+\.[0-9]+\.[0-9]+/\${1}$VERSION/" "$MAIN"
perl -pi -e "s/^(Stable tag:\s*)[0-9]+\.[0-9]+\.[0-9]+/\${1}$VERSION/" "$README"

echo "→ Adding changelog + upgrade-notice entries"
ESX_VER="$VERSION" ESX_SUM="$SUMMARY" perl -0777 -pi -e '
  s/(== Changelog ==\n\n)/$1= $ENV{ESX_VER} =\n* $ENV{ESX_SUM}\n\n/;
  s/(== Upgrade Notice ==\n\n)/$1= $ENV{ESX_VER} =\n$ENV{ESX_SUM}\n\n/;
' "$README"

echo "→ Verifying build (also enforces header == constant == readme)"
bash "$ROOT/tools/build.sh" >/dev/null

echo "→ Committing + tagging $TAG"
git add emailsendx-sync.php readme.txt
git commit -m "Release $TAG — $SUMMARY"
git tag -a "$TAG" -m "$TAG"

cat <<EOF

✓ $TAG committed, tagged, build verified.

  Publish it (this is the step that ships the update to users):

      git push origin main --follow-tags

  CI then builds the zip, creates the GitHub Release, and installed sites
  pick up the update automatically (within ~12h, or instantly via
  Dashboard → Updates → "Check again").
EOF
