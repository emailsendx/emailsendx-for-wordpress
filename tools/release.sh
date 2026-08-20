#!/usr/bin/env bash
#
# One-command release for "EmailSendX for WordPress".
#
#   bash tools/release.sh <x.y.z> ["changelog summary"]
#   e.g.  bash tools/release.sh 1.3.2 "Fix WooCommerce phone mapping"
#
# It bumps the version in all three places (plugin header, the
# EMAILSENDX_SYNC_VERSION constant, and the readme Stable tag), adds a
# changelog + upgrade-notice entry, builds and shape-checks the package,
# then commits and tags vX.Y.Z.
#
# Nothing is pushed or uploaded for you. The script ends by printing the
# exact git push and R2 upload commands — running them is what actually
# ships the update to every installed site. ShaonPro.
set -euo pipefail

VERSION="${1:-}"
SUMMARY="${2:-Maintenance release.}"

if ! printf '%s' "$VERSION" | grep -qE '^[0-9]+\.[0-9]+\.[0-9]+$'; then
  echo "Usage: bash tools/release.sh <x.y.z> [\"changelog summary\"]" >&2
  echo "  e.g. bash tools/release.sh 1.3.2 \"Fix WooCommerce phone mapping\"" >&2
  exit 1
fi

SLUG="emailsendx-for-wordpress"
R2_BASE="${ESX_R2_BASE:-https://storage.emailsendx.com/wp-plugin}"
R2_BUCKET="${ESX_R2_BUCKET:-emailsendx-storage}"
R2_PREFIX="${ESX_R2_PREFIX:-wp-plugin}"

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
ESX_VER="$VERSION" ESX_SUM="$SUMMARY" perl -0777 -pi -e '
  s/(== Changelog ==\n\n)/$1= $ENV{ESX_VER} =\n$ENV{ESX_SUM}\n\n/;
  s/(== Upgrade Notice ==\n\n)/$1= $ENV{ESX_VER} =\n$ENV{ESX_SUM}\n\n/;
' "$README"

echo "→ Building (enforces version agreement, slug match, and archive shape)"
bash "$ROOT/tools/build.sh" >/dev/null

echo "→ Committing + tagging $TAG"
git add emailsendx-sync.php readme.txt
git commit -m "Release $TAG — $SUMMARY"
git tag -a "$TAG" -m "$TAG"

cat <<EOF

✓ $TAG committed, tagged, package built and shape-checked.

  Nothing has shipped yet. Two steps, in this order:

  1. Upload to R2 — versioned zip FIRST, manifest LAST. The manifest is the
     trigger: the moment it says $VERSION, every installed site starts
     downloading the URL it names, so that file must already be there.

       cd $DIST

       # a) the immutable package updates actually download
       wrangler r2 object put $R2_BUCKET/$R2_PREFIX/$SLUG-$VERSION.zip \\
         --file $SLUG-$VERSION.zip \\
         --content-type application/zip \\
         --cache-control "public, max-age=31536000, immutable"

       # b) the stable link behind the website's download button
       wrangler r2 object put $R2_BUCKET/$R2_PREFIX/$SLUG.zip \\
         --file $SLUG.zip \\
         --content-type application/zip \\
         --cache-control "public, max-age=300"

       # c) the manifest — this is what ships the update
       wrangler r2 object put $R2_BUCKET/$R2_PREFIX/$SLUG.json \\
         --file $SLUG.json \\
         --content-type application/json \\
         --cache-control "public, max-age=300"

     Then confirm what the world sees:

       curl -s $R2_BASE/$SLUG.json | grep -E '"version"|"download_url"'
       curl -sI $R2_BASE/$SLUG-$VERSION.zip | head -3

  2. Push the source (optional for shipping, but keep the tag in sync):

       git push origin main --follow-tags

  Sites pick the update up within ~12h, or immediately via
  Dashboard → Updates → "Check again".
EOF
