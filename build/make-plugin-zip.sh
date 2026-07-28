#!/usr/bin/env bash
#
# Build a self-contained TypeDock plugin zip with production Composer
# dependencies. The archive keeps one top-level plugin directory so it can be
# installed through Settings -> Modules or uploaded directly over FTP.

set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
REPO_ROOT="$(cd "$SCRIPT_DIR/.." && pwd)"
PLUGIN_SLUG="${1:-}"
OUT_DIR="${OUT_DIR:-$REPO_ROOT/dist/plugins}"

die() { printf '[make-plugin-zip] ERROR: %s\n' "$*" >&2; exit 1; }
log() { printf '[make-plugin-zip] %s\n' "$*"; }

[[ "$PLUGIN_SLUG" =~ ^[a-z][a-z0-9_-]{1,63}$ ]] \
    || die "usage: $0 <plugin-slug>"

PLUGIN_SOURCE="$REPO_ROOT/plugins/$PLUGIN_SLUG"
[ -f "$PLUGIN_SOURCE/plugin.json" ] || die "plugin not found: plugins/$PLUGIN_SLUG"

for bin in php composer rsync zip; do
    command -v "$bin" >/dev/null 2>&1 || die "required command not found: $bin"
done

VERSION="$(
    php -r '
        $manifest = json_decode((string) file_get_contents($argv[1]), true);
        echo (string) ($manifest["version"] ?? "0.0.0");
    ' "$PLUGIN_SOURCE/plugin.json"
)"
ARCHIVE="$OUT_DIR/typedock-$PLUGIN_SLUG-$VERSION.zip"

mkdir -p "$OUT_DIR"
BUILD_ROOT="$(mktemp -d "$OUT_DIR/.plugin-build-XXXXXX")"
trap 'rm -rf "$BUILD_ROOT"' EXIT
STAGED_PLUGIN="$BUILD_ROOT/$PLUGIN_SLUG"

log "staging plugins/$PLUGIN_SLUG"
mkdir -p "$STAGED_PLUGIN"
rsync -a \
    --filter=':- .gitignore' \
    --exclude='/vendor/' \
    --exclude='/tests/' \
    --exclude='phpunit.xml*' \
    --exclude='phpstan.neon*' \
    "$PLUGIN_SOURCE/" "$STAGED_PLUGIN/"

if [ -f "$STAGED_PLUGIN/composer.json" ]; then
    log "installing production Composer dependencies"
    (
        cd "$STAGED_PLUGIN"
        composer install \
            --no-dev \
            --optimize-autoloader \
            --no-interaction \
            --no-progress \
            --prefer-dist
    )
fi

if [ -d "$STAGED_PLUGIN/vendor" ]; then
    find "$STAGED_PLUGIN/vendor" -type f -iname '*.md' -delete
fi

rm -f "$ARCHIVE"
(
    cd "$BUILD_ROOT"
    zip -qr "$ARCHIVE" "$PLUGIN_SLUG"
)

bytes="$(stat -c %s "$ARCHIVE")"
sha256="$(sha256sum "$ARCHIVE" | awk '{print $1}')"
log "done: $ARCHIVE ($bytes bytes)"
log "sha256: $sha256"
