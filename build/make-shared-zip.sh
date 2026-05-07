#!/usr/bin/env bash
#
# Build the shared-hosting distribution zip (typedock-shared.zip).
#
# Produces a split layout with two sibling folders at the archive root:
#
#   typedock-shared.zip
#   ├── public_html/   <- upload to the hosting provider's DocumentRoot
#   │   ├── index.php
#   │   ├── install.php
#   │   ├── .htaccess
#   │   ├── admin/{assets,dist}/
#   │   ├── uploads/             (runtime — user media)
#   │   ├── themes/<name>/assets/   (published static)
#   │   └── plugins/<slug>/assets/  (published static)
#   └── typedock/      <- place OUTSIDE the webroot (sibling of public_html)
#       ├── src/, vendor/, config/, admin/, cli/, migrations/
#       ├── plugins/<slug>/   (full source incl. assets master)
#       ├── themes/<name>/    (full source incl. assets master)
#       └── storage/{cache,logs,sessions,tmp,backups,media}/
#
# The entry-point PHP files are patched so:
#   TYPEDOCK_ROOT       = dirname(__DIR__) . '/typedock'
#   TYPEDOCK_PUBLIC_DIR = __DIR__
#
# Config/filesystems.php and AssetPublisher already honour TYPEDOCK_PUBLIC_DIR,
# so uploads and asset publishing land inside public_html even though the code
# lives outside DocumentRoot.
#
# This is a pragmatic MVP. It is expected to move to GitHub Actions later.

set -euo pipefail

# -----------------------------------------------------------------------------
# Config
# -----------------------------------------------------------------------------

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
REPO_ROOT="$(cd "$SCRIPT_DIR/.." && pwd)"

OUT_DIR="${OUT_DIR:-$REPO_ROOT/dist}"
STAGE_NAME="typedock-shared"
STAGE_DIR="$OUT_DIR/$STAGE_NAME"
APP_DIR="$STAGE_DIR/typedock"
WEB_DIR="$STAGE_DIR/public_html"
ZIP_PATH="$OUT_DIR/$STAGE_NAME.zip"

VERSION="$(
    awk -F"'" '/define\(.TYPEDOCK_VERSION./ {print $4; exit}' \
        "$REPO_ROOT/public/index.php" 2>/dev/null || true
)"
VERSION="${VERSION:-0.0.0-dev}"

log() { printf '[make-shared-zip] %s\n' "$*"; }
die() { printf '[make-shared-zip] ERROR: %s\n' "$*" >&2; exit 1; }

# -----------------------------------------------------------------------------
# Preflight
# -----------------------------------------------------------------------------

for bin in php composer rsync zip; do
    command -v "$bin" >/dev/null 2>&1 || die "required command not found: $bin"
done

[ -f "$REPO_ROOT/public/index.php" ] || die "public/index.php not found — run from a TypeDock checkout"
[ -f "$REPO_ROOT/composer.json" ]   || die "composer.json not found"

log "building typedock-shared $VERSION (split layout)"

# -----------------------------------------------------------------------------
# Clean staging
# -----------------------------------------------------------------------------

mkdir -p "$OUT_DIR"
rm -rf "$STAGE_DIR" "$ZIP_PATH"
mkdir -p "$APP_DIR" "$WEB_DIR"

# -----------------------------------------------------------------------------
# Copy source tree into the app dir
# -----------------------------------------------------------------------------

log "copying source tree → typedock/"
# Theme images: legitimate runtime files (theme picker screenshots) live under
# `themes/<name>/assets/`. Anything at the theme root (`themes/<name>/*.png`)
# is documentation / marketing and excluded — see ThemeLoader::screenshotUrl()
# for the runtime lookup path.
rsync -a \
    --exclude='.git/' \
    --exclude='.github/' \
    --exclude='.vscode/' \
    --exclude='.idea/' \
    --exclude='.claude/' \
    --exclude='.codex/' \
    --exclude='.codex' \
    --exclude='.devcontainer/' \
    --exclude='.dockerignore' \
    --exclude='.gitignore' \
    --exclude='.gitattributes' \
    --exclude='.editorconfig' \
    --exclude='.DS_Store' \
    --exclude='node_modules/' \
    --exclude='/vendor/' \
    --exclude='/dist/' \
    --exclude='/tests/' \
    --exclude='docs-internal/' \
    --exclude='docker/' \
    --exclude='docker-compose.yml' \
    --exclude='docker.env' \
    --exclude='docker.env.example' \
    --exclude='Dockerfile' \
    --exclude='Makefile' \
    --exclude='esbuild.config.mjs' \
    --exclude='package.json' \
    --exclude='package-lock.json' \
    --exclude='phpunit.xml.dist' \
    --exclude='.phpunit.result.cache' \
    --exclude='.phpunit.cache/' \
    --exclude='/phpstan/' \
    --exclude='phpstan.neon' \
    --exclude='phpstan.neon.dist' \
    --exclude='CLAUDE.md' \
    --exclude='AGENTS.md' \
    --exclude='MAINTAINERS.md' \
    --exclude='/bin/' \
    --exclude='/build/' \
    --exclude='/config.php' \
    --exclude='/resources/' \
    --exclude='/schema/' \
    --exclude='/storage/cache/' \
    --exclude='/storage/logs/' \
    --exclude='/storage/sessions/' \
    --exclude='/storage/tmp/' \
    --exclude='/storage/uploads/' \
    --exclude='/storage/backups/' \
    --exclude='/storage/media/' \
    --exclude='/storage/zap-reports/' \
    --exclude='/storage/database.sqlite' \
    --exclude='/storage/.installed' \
    --exclude='/storage/.installing' \
    --exclude='*.map' \
    --exclude='/public/uploads/' \
    --exclude='/public/themes/' \
    --exclude='/public/plugins/' \
    --exclude='/public/cache/' \
    "$REPO_ROOT/" "$APP_DIR/"

# -----------------------------------------------------------------------------
# Install production Composer dependencies into the app dir
# -----------------------------------------------------------------------------

log "installing composer dependencies (--no-dev --optimize-autoloader)"
(
    cd "$APP_DIR"
    composer install \
        --no-dev \
        --optimize-autoloader \
        --no-interaction \
        --no-progress \
        --prefer-dist
)

# -----------------------------------------------------------------------------
# Install production Composer dependencies for bundled drop-in plugins
# -----------------------------------------------------------------------------

log "installing bundled plugin composer dependencies (--no-dev)"

for plugin_composer in "$APP_DIR"/plugins/*/composer.json; do
    [ -f "$plugin_composer" ] || continue
    plugin_dir="$(dirname "$plugin_composer")"
    plugin_slug="$(basename "$plugin_dir")"
    log "  plugins/$plugin_slug"
    (
        cd "$plugin_dir"
        composer install \
            --no-dev \
            --optimize-autoloader \
            --no-interaction \
            --no-progress \
            --prefer-dist
    )
done

# -----------------------------------------------------------------------------
# Split public/ → public_html/ (webroot) + leave the rest in typedock/
# -----------------------------------------------------------------------------

log "splitting public/ → public_html/"

SRC_PUBLIC="$APP_DIR/public"
[ -d "$SRC_PUBLIC" ] || die "staging public/ missing after rsync"

# Move the entry scripts and root .htaccess to the webroot.
mv -f "$SRC_PUBLIC/index.php"   "$WEB_DIR/index.php"
mv -f "$SRC_PUBLIC/install.php" "$WEB_DIR/install.php"
mv -f "$SRC_PUBLIC/.htaccess"   "$WEB_DIR/.htaccess.orig"  # replaced below

# Move static admin assets (CSS/JS/esbuild output).
if [ -d "$SRC_PUBLIC/admin" ]; then
    mkdir -p "$WEB_DIR/admin"
    rsync -a "$SRC_PUBLIC/admin/" "$WEB_DIR/admin/"
fi

# The source public/ dir no longer contributes anything; drop it.
rm -rf "$SRC_PUBLIC"

# -----------------------------------------------------------------------------
# Patch entry points to point at the sibling typedock/ dir
# -----------------------------------------------------------------------------

log "patching entry points for split layout"

patch_entry() {
    local file="$1"
    php -r '
        $file = $argv[1];
        $src  = file_get_contents($file);
        if ($src === false) { fwrite(STDERR, "cannot read $file\n"); exit(1); }

        // Match:  define("TYPEDOCK_ROOT", dirname(__DIR__))  (either quote style)
        $patched = preg_replace(
            "#define\\(\\s*([\"\x27])TYPEDOCK_ROOT\\1\\s*,\\s*dirname\\(__DIR__\\)\\s*\\)#",
            "define(\$1TYPEDOCK_ROOT\$1, dirname(__DIR__) . \$1/typedock\$1)",
            $src,
            -1,
            $count1
        );
        // Match:  $root = dirname(__DIR__);
        $patched = preg_replace(
            "#\\\$root\\s*=\\s*dirname\\(__DIR__\\)\\s*;#",
            "\$root = dirname(__DIR__) . \"/typedock\";",
            $patched,
            -1,
            $count2
        );
        if ($count1 + $count2 === 0) {
            fwrite(STDERR, "ERROR: TYPEDOCK_ROOT pattern not matched in $file\n");
            exit(1);
        }

        // Insert TYPEDOCK_PUBLIC_DIR alongside TYPEDOCK_ROOT if not already there.
        if (strpos($patched, "TYPEDOCK_PUBLIC_DIR") === false) {
            // Place it immediately after the first TYPEDOCK_ROOT define/assignment.
            $patched = preg_replace(
                "#(define\\(\\s*[\"\x27]TYPEDOCK_ROOT[\"\x27][^;]*;\\s*\\n|\\\$root\\s*=\\s*dirname\\(__DIR__\\)[^;]*;\\s*\\n|\\\$root\\s*=\\s*dirname\\(__DIR__\\)\\s*\\.\\s*[\"\x27][^\"\x27]+[\"\x27]\\s*;\\s*\\n)#",
                "\$1define(\"TYPEDOCK_PUBLIC_DIR\", __DIR__);\n",
                $patched,
                1,
                $count3
            );
            if ($count3 === 0) {
                fwrite(STDERR, "ERROR: could not insert TYPEDOCK_PUBLIC_DIR in $file\n");
                exit(1);
            }
        }

        file_put_contents($file, $patched);
    ' "$file"
}

patch_entry "$WEB_DIR/index.php"
patch_entry "$WEB_DIR/install.php"

# -----------------------------------------------------------------------------
# Publish theme + plugin static assets into public_html
# -----------------------------------------------------------------------------

log "publishing theme/plugin assets → public_html/"

publish_assets() {
    local bucket="$1"   # themes | plugins
    local src_base="$APP_DIR/$bucket"
    local dst_base="$WEB_DIR/$bucket"

    [ -d "$src_base" ] || return 0

    for pkg_dir in "$src_base"/*/; do
        [ -d "$pkg_dir" ] || continue
        local pkg_name
        pkg_name=$(basename "$pkg_dir")
        local src_assets="$pkg_dir/assets"
        [ -d "$src_assets" ] || continue

        local dst_assets="$dst_base/$pkg_name/assets"
        mkdir -p "$dst_assets"
        rsync -a "$src_assets/" "$dst_assets/"
        log "  $bucket/$pkg_name/assets → public_html/$bucket/$pkg_name/assets"
    done
}

publish_assets themes
publish_assets plugins

# -----------------------------------------------------------------------------
# Write .htaccess files
# -----------------------------------------------------------------------------

log "writing .htaccess files"

# public_html/.htaccess — simpler than the flat layout: no need to block
# src/, config/, vendor/, etc. because they're not in the webroot at all.
cat > "$WEB_DIR/.htaccess" <<'HTACCESS'
# TypeDock shared-hosting .htaccess (public_html)

Options -Indexes -ExecCGI

# Block dotfiles and stray config/log files in the webroot.
<FilesMatch "^(\.env.*|\.ht.*|composer\.(json|lock)|config\.php(\.example)?|.*\.(sql|sh|log|ini|bak|swp|md))$">
    <IfModule mod_authz_core.c>
        Require all denied
    </IfModule>
    <IfModule !mod_authz_core.c>
        Order allow,deny
        Deny from all
    </IfModule>
</FilesMatch>

<IfModule mod_rewrite.c>
    RewriteEngine On
    RewriteBase /

    # Uncomment in production
    # RewriteCond %{HTTPS} off
    # RewriteRule ^ https://%{HTTP_HOST}%{REQUEST_URI} [L,R=301]

    # Serve real files and directories directly (CSS/JS/images/fonts).
    RewriteCond %{REQUEST_FILENAME} !-f
    RewriteCond %{REQUEST_FILENAME} !-d

    # Everything else → index.php front controller.
    RewriteRule ^ index.php [QSA,L]
</IfModule>
HTACCESS
rm -f "$WEB_DIR/.htaccess.orig"

# public_html/uploads/.htaccess — runtime user uploads, no PHP execution.
mkdir -p "$WEB_DIR/uploads"
cat > "$WEB_DIR/uploads/.htaccess" <<'EOF'
# TypeDock: user uploads — never execute PHP here.
<FilesMatch "\.(php|phtml|phar)$">
    <IfModule mod_authz_core.c>
        Require all denied
    </IfModule>
    <IfModule !mod_authz_core.c>
        Order allow,deny
        Deny from all
    </IfModule>
</FilesMatch>
Options -ExecCGI -Indexes
RemoveHandler .php .phtml .php3 .php4 .php5 .php7 .pht .phar
<IfModule mod_php7.c>
    php_flag engine off
</IfModule>
<IfModule mod_php8.c>
    php_flag engine off
</IfModule>
EOF

# public_html/themes/.htaccess & plugins/.htaccess — pure static asset dirs.
# Source files (.php, .latte, .json manifests, .sql migrations) are NOT here
# — they live under typedock/. But add a belt-and-braces PHP-off anyway in
# case someone drops something weird under there.
for bucket in themes plugins; do
    [ -d "$WEB_DIR/$bucket" ] || mkdir -p "$WEB_DIR/$bucket"
    cat > "$WEB_DIR/$bucket/.htaccess" <<'EOF'
# TypeDock: static theme/plugin assets only. Source PHP lives in typedock/.
<FilesMatch "\.(php|phtml|phar)$">
    <IfModule mod_authz_core.c>
        Require all denied
    </IfModule>
    <IfModule !mod_authz_core.c>
        Order allow,deny
        Deny from all
    </IfModule>
</FilesMatch>
Options -Indexes -ExecCGI
RemoveHandler .php .phtml .phar
<IfModule mod_php7.c>
    php_flag engine off
</IfModule>
<IfModule mod_php8.c>
    php_flag engine off
</IfModule>
EOF
done

# typedock/.htaccess — defence in depth. The whole dir is supposed to sit
# OUTSIDE DocumentRoot, but if the user puts it inside by mistake, at least
# deny everything.
cat > "$APP_DIR/.htaccess" <<'EOF'
# TypeDock application dir: must NOT be web-accessible.
# If you can read this message, your `typedock/` directory is inside
# DocumentRoot — move it one level up (next to public_html/) immediately.
<IfModule mod_authz_core.c>
    Require all denied
</IfModule>
<IfModule !mod_authz_core.c>
    Order allow,deny
    Deny from all
</IfModule>
Options -Indexes -ExecCGI
RemoveHandler .php .phtml .phar
<IfModule mod_php7.c>
    php_flag engine off
</IfModule>
<IfModule mod_php8.c>
    php_flag engine off
</IfModule>
EOF

# -----------------------------------------------------------------------------
# Seed runtime dirs inside typedock/
# -----------------------------------------------------------------------------

log "seeding runtime directory skeleton"

for sub in cache logs sessions tmp backups media; do
    mkdir -p "$APP_DIR/storage/$sub"
    : > "$APP_DIR/storage/$sub/.gitkeep"
done

# -----------------------------------------------------------------------------
# Mirror LICENSE + NOTICE at the archive root so the Apache 2.0 attribution
# requirements are visible without having to drill into typedock/ first.
# -----------------------------------------------------------------------------

for f in LICENSE NOTICE; do
    if [ -f "$REPO_ROOT/$f" ]; then
        cp "$REPO_ROOT/$f" "$STAGE_DIR/$f"
    fi
done

# -----------------------------------------------------------------------------
# Ship an installation README at the archive root
# -----------------------------------------------------------------------------

cat > "$STAGE_DIR/README.txt" <<'EOF'
TypeDock — Shared Hosting Distribution
======================================

This archive ships TypeDock in a split layout that keeps application code
OUTSIDE your web server's DocumentRoot, matching WordPress's recommended
"above the webroot" pattern.

Layout after you unzip:

    public_html/   <- upload INTO your hosting provider's public_html (webroot)
    typedock/      <- upload as a SIBLING of public_html, one level up

So your server should end up with:

    /home/<you>/public_html/     (from this archive's public_html/)
    /home/<you>/typedock/        (from this archive's typedock/)

Then open your site in a browser — you will be taken to the installer
automatically.

If your hosting provider does not let you place directories above the webroot,
keep the `typedock/` directory next to `public_html/` anywhere that PHP can
read — just remember to edit `public_html/index.php` and `install.php` to
point TYPEDOCK_ROOT at the actual location.

Support: https://github.com/ku-suke/typedock
EOF

# -----------------------------------------------------------------------------
# Sanity checks
# -----------------------------------------------------------------------------

log "verifying split layout"

check_exists()    { [ -e "$1" ]  || die "missing after build: ${1#$STAGE_DIR/}"; }
check_absent()    { [ ! -e "$1" ] || die "should not exist: ${1#$STAGE_DIR/}"; }
check_no_phpsyn() { php -l "$1" >/dev/null || die "php -l failed: $1"; }

check_exists "$WEB_DIR/index.php"
check_exists "$WEB_DIR/install.php"
check_exists "$WEB_DIR/.htaccess"
check_exists "$WEB_DIR/admin/assets"
check_exists "$WEB_DIR/admin/dist/editor.bundle.js"
check_exists "$WEB_DIR/uploads/.htaccess"
check_exists "$APP_DIR/.htaccess"
check_exists "$APP_DIR/vendor/autoload.php"
check_exists "$APP_DIR/src/Core/App.php"
check_exists "$APP_DIR/admin/layouts/admin-base.latte"
check_exists "$APP_DIR/config.php.example"
for plugin_composer in "$APP_DIR"/plugins/*/composer.json; do
    [ -f "$plugin_composer" ] || continue
    plugin_dir="$(dirname "$plugin_composer")"
    check_exists "$plugin_dir/vendor/autoload.php"
done
check_absent "$APP_DIR/public"
check_absent "$APP_DIR/tests"
check_absent "$APP_DIR/node_modules"
check_absent "$APP_DIR/docs-internal"
check_absent "$APP_DIR/.git"

check_no_phpsyn "$WEB_DIR/index.php"
check_no_phpsyn "$WEB_DIR/install.php"

# Confirm both patches landed.
grep -Eq "define\(['\"]TYPEDOCK_ROOT['\"], dirname\(__DIR__\) \. ['\"]/typedock['\"]\)" \
    "$WEB_DIR/index.php" \
    || die "index.php TYPEDOCK_ROOT not patched to sibling typedock/"
grep -q "TYPEDOCK_PUBLIC_DIR" "$WEB_DIR/index.php" \
    || die "index.php missing TYPEDOCK_PUBLIC_DIR definition"
grep -q "\$root = dirname(__DIR__) . \"/typedock\";" "$WEB_DIR/install.php" \
    || die "install.php \$root not patched to sibling typedock/"
grep -q "TYPEDOCK_PUBLIC_DIR" "$WEB_DIR/install.php" \
    || die "install.php missing TYPEDOCK_PUBLIC_DIR definition"

# -----------------------------------------------------------------------------
# Zip it
# -----------------------------------------------------------------------------

log "creating $ZIP_PATH"
(
    cd "$OUT_DIR"
    zip -qr "$ZIP_PATH" "$STAGE_NAME"
)

size=$(du -h "$ZIP_PATH" | awk '{print $1}')
log "done: $ZIP_PATH ($size)"
log "staging dir kept at $STAGE_DIR for inspection"
