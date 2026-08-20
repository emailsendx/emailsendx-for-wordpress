#!/usr/bin/env bash
#
# Build the WordPress-installable zip + auto-update manifest for
# "EmailSendX for WordPress".
#
#     bash tools/build.sh
#
# Produces three files in tools/dist/ — upload ALL THREE to R2, same folder:
#
#     emailsendx-for-wordpress-<version>.zip   what the manifest points at
#     emailsendx-for-wordpress.zip             stable link for the website button
#     emailsendx-for-wordpress.json            the update manifest sites poll
#
# The manifest deliberately points at the VERSIONED zip. R2 sits behind
# Cloudflare, and a fixed filename means a cached edge copy can hand a site
# the previous release's bytes while the manifest promises the new version —
# WordPress then "updates" to the same version forever. A versioned URL is
# immutable, so it can be cached hard and is always the right bytes.
#
# The zip contains a single top-level `emailsendx-for-wordpress/` folder.
# That folder name is load-bearing: WordPress installs a plugin into the
# folder the zip carries, so a mismatch turns every auto-update into a
# SECOND copy of the plugin sitting beside the old one. The build refuses
# to finish if the shape is wrong — the hand-rolled zip that shipped to R2
# before had no top-level folder at all and could not be installed via
# Plugins → Add New → Upload. ShaonPro.
set -euo pipefail

SLUG="emailsendx-for-wordpress"          # install folder + zip/manifest basename
MAINFILE="emailsendx-sync.php"           # main PHP file (NOT renamed — renaming it
                                         # would deactivate every existing install)

# Where the two files will live once uploaded. Override for a staging bucket:
#   ESX_R2_BASE=https://staging.example.com/wp-plugin bash tools/build.sh
R2_BASE="${ESX_R2_BASE:-https://storage.emailsendx.com/wp-plugin}"

HERE="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
ROOT="$(cd "$HERE/.." && pwd)"
DIST="$ROOT/tools/dist"
STAGE="$ROOT/tools/.build"
MAIN="$ROOT/$MAINFILE"

command -v python3 >/dev/null 2>&1 || { echo "✗ python3 is required (manifest generation)." >&2; exit 1; }

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
rm -f "$DIST/$SLUG.zip" "$DIST/$SLUG.json"

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

# ── Shape gate: exactly one top-level entry, and it's the slug folder ───
# List once into a variable. Piping `unzip -Z1` into `grep -q` is a trap:
# grep exits on the first match, unzip takes SIGPIPE, and `pipefail` turns
# that into a spurious build failure — intermittently, depending on whether
# unzip finished writing first. ShaonPro.
LISTING="$(unzip -Z1 "$DIST/$SLUG.zip")"

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
printf '%s\n' "$LISTING" | grep -Fxq "$SLUG/vendor/plugin-update-checker/plugin-update-checker.php" || {
  echo "✗ vendor/plugin-update-checker missing — the shipped build would have no auto-updates." >&2; exit 1; }
echo "✓ Archive shape valid (single top-level $SLUG/, forward slashes, updater bundled)"

# ── Immutable, versioned copy — this is what the manifest links to ─────
rm -f "$DIST/$SLUG"-*.zip
cp "$DIST/$SLUG.zip" "$DIST/$SLUG-$HEADER_VER.zip"
echo "✓ Versioned copy $SLUG-$HEADER_VER.zip"

# ── Update manifest (Plugin Update Checker JSON schema) ─────────────────
ESX_SLUG="$SLUG" ESX_VER="$HEADER_VER" ESX_R2="$R2_BASE" ESX_ROOT="$ROOT" \
ESX_ZIP="$DIST/$SLUG.zip" ESX_OUT="$DIST/$SLUG.json" python3 - <<'PY'
import os, re, json, hashlib, datetime

root   = os.environ['ESX_ROOT']
slug   = os.environ['ESX_SLUG']
ver    = os.environ['ESX_VER']
base   = os.environ['ESX_R2'].rstrip('/')
zippath= os.environ['ESX_ZIP']
out    = os.environ['ESX_OUT']

readme = open(os.path.join(root, 'readme.txt'), encoding='utf-8').read()

def header(field, default=''):
    m = re.search(r'^%s:\s*(.+)$' % re.escape(field), readme, re.M)
    return m.group(1).strip() if m else default

def section(title):
    m = re.search(r'^==\s*%s\s*==\s*\n(.*?)(?=\n==\s|\Z)' % re.escape(title), readme, re.M | re.S)
    return m.group(1).strip() if m else ''

def to_html(text):
    """readme.txt subset → the HTML the WP plugin-details modal renders."""
    html, in_list = [], False
    for line in text.split('\n'):
        s = line.strip()
        if not s:
            continue
        m = re.match(r'^=\s*(.+?)\s*=$', s)
        if m:
            if in_list: html.append('</ul>'); in_list = False
            html.append('<h4>%s</h4>' % m.group(1)); continue
        m = re.match(r'^\*\s+(.*)$', s)
        if m:
            if not in_list: html.append('<ul>'); in_list = True
            html.append('<li>%s</li>' % inline(m.group(1))); continue
        if in_list: html.append('</ul>'); in_list = False
        html.append('<p>%s</p>' % inline(s))
    if in_list: html.append('</ul>')
    return ''.join(html)

def inline(s):
    s = re.sub(r'\[([^\]]+)\]\(([^)]+)\)', r'<a href="\2">\1</a>', s)
    s = re.sub(r'\*\*(.+?)\*\*', r'<strong>\1</strong>', s)
    s = re.sub(r'`([^`]+)`', r'<code>\1</code>', s)
    return s

# Upgrade notice for THIS version only.
notice = ''
un = section('Upgrade Notice')
m = re.search(r'^=\s*%s\s*=\s*\n(.*?)(?=\n=\s|\Z)' % re.escape(ver), un, re.M | re.S)
if m:
    notice = ' '.join(m.group(1).split())

sha = hashlib.sha256(open(zippath, 'rb').read()).hexdigest()

manifest = {
    'name':            'EmailSendX for WordPress',
    'slug':            slug,
    'version':         ver,
    'download_url':    '%s/%s-%s.zip' % (base, slug, ver),
    'homepage':        'https://emailsendx.com/',
    'author':          'EmailSendX',
    'author_homepage': 'https://emailsendx.com',
    'requires':        header('Requires at least', '6.0'),
    'tested':          header('Tested up to', '7.0'),
    'requires_php':    header('Requires PHP', '7.4'),
    'last_updated':    datetime.datetime.now(datetime.timezone.utc).strftime('%Y-%m-%d %H:%M:%S'),
    'upgrade_notice':  notice,
    'sections': {
        'description': to_html(section('Description')),
        'installation': to_html(section('Installation')),
        'changelog':   to_html(section('Changelog')),
    },
    'icons': {
        '1x':  '%s/assets/icon-256.png' % base,
        'svg': '%s/assets/icon.svg' % base,
    },
    # Not consumed by WordPress — a build fingerprint so you can confirm the
    # zip sitting in R2 is the one this manifest describes. ShaonPro.
    'esx_zip_sha256': sha,
}
open(out, 'w', encoding='utf-8').write(json.dumps(manifest, indent=2, ensure_ascii=False) + '\n')
print('  zip sha256: %s' % sha)
PY

echo "✓ Built $DIST/$SLUG.zip"
echo "✓ Built $DIST/$SLUG-$HEADER_VER.zip"
echo "✓ Built $DIST/$SLUG.json"
echo
echo "── upload ALL THREE to R2 under the same prefix ──────────────────"
echo "    $SLUG-$HEADER_VER.zip   → cache hard (immutable, what updates download)"
echo "    $SLUG.zip               → cache briefly (website download button)"
echo "    $SLUG.json              → cache briefly (the manifest sites poll)"
echo
echo "  Publish the versioned zip FIRST, the manifest LAST — the manifest is"
echo "  what tells every site to go fetch it. See tools/release.sh."
echo
echo "── archive contents ──────────────────────────────────────────────"
unzip -l "$DIST/$SLUG.zip" | tail -3
