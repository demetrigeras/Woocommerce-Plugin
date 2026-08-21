#!/bin/bash

# Plugin Package Creator
#
# Partner-specific values: ONLY in sp-whitelabel-config.php (and this script
# reading that file). To build a partner zip, set environment_id + plugin_name + zip_name
# in the config, then run this script. For Stablecoin Pay (default), omit the config
# or set environment_id null.

# Always run from the directory where this script lives
cd "$(dirname "$0")"

echo "🚀 Creating Plugin Package..."
echo "📂 Working directory: $(pwd)"

# Resolve zip name early so we can remove old build artifacts
ZIP_NAME="stablecoin-pay.zip"
if [ -f "sp-whitelabel-config.php" ]; then
    EXTRACTED=$(grep -E "'zip_name'|\"zip_name\"" sp-whitelabel-config.php | sed -n "s/.*['\"]zip_name['\"][^'\"]*['\"]\\([^'\"]*\\)['\"].*/\1/p" | tr -d ' ')
    [ -n "$EXTRACTED" ] && ZIP_NAME="$EXTRACTED"
fi

# Derive the staging folder name from the zip name so the folder INSIDE the
# zip matches what WordPress expects when extracting. Without this, the zip
# is flat and WP fabricates a folder from the zip filename and appends
# "-2", "-3", ... on each upload (creating duplicate installs instead of
# updating the existing one).
PACKAGE_DIR="${ZIP_NAME%.zip}"
[ -z "$PACKAGE_DIR" ] && PACKAGE_DIR="stablecoin-pay-plugin"
rm -rf "$PACKAGE_DIR"
rm -f "$ZIP_NAME"
echo "🧹 Cleaned previous build (fresh package)"
echo "📦 Plugin folder name inside zip: $PACKAGE_DIR/"

mkdir -p "$PACKAGE_DIR"

# Copy main plugin file
echo "📦 Copying main plugin file..."
cp stablecoin-pay.php "$PACKAGE_DIR/"

# Auto-bump version on every build so WordPress recognizes uploaded zips as
# a newer build than what's already installed. This stamps the BUILT file
# only — the source `Version: 1.0.0` in the repo stays untouched.
#
# Build version format: <base>.<UTCYYYYMMDDHHMM>   e.g. 1.0.0.202605211342
SOURCE_VERSION=$(grep -E "^[[:space:]]*\*[[:space:]]*Version:" stablecoin-pay.php | head -n 1 | sed -E "s/^[[:space:]]*\*[[:space:]]*Version:[[:space:]]*(.*)[[:space:]]*$/\1/" | tr -d '\r')
[ -z "$SOURCE_VERSION" ] && SOURCE_VERSION="1.0.0"
BUILD_STAMP=$(date -u +"%Y%m%d%H%M")
BUILT_VERSION="${SOURCE_VERSION}.${BUILD_STAMP}"

if [[ "$(uname)" = "Darwin" ]]; then
    sed -i '' "s#^ \* Version: .*# * Version: ${BUILT_VERSION}#" "$PACKAGE_DIR/stablecoin-pay.php"
    sed -i '' "s#define('SP_VERSION',[^)]*)#define('SP_VERSION', '${BUILT_VERSION}')#" "$PACKAGE_DIR/stablecoin-pay.php"
else
    sed -i "s#^ \* Version: .*# * Version: ${BUILT_VERSION}#" "$PACKAGE_DIR/stablecoin-pay.php"
    sed -i "s#define('SP_VERSION',[^)]*)#define('SP_VERSION', '${BUILT_VERSION}')#" "$PACKAGE_DIR/stablecoin-pay.php"
fi
echo "📦 Built version: ${BUILT_VERSION} (source kept at ${SOURCE_VERSION})"

# Whitelabel build: config file is the single source (zip name, plugin name in header, etc.)
WHITELABEL_BUILD=0

if [ -f "sp-whitelabel-config.php" ]; then
    echo "📦 Copying whitelabel config (partner build)..."
    cp sp-whitelabel-config.php "$PACKAGE_DIR/"
    WHITELABEL_BUILD=1
    echo "📦 Partner zip name: $ZIP_NAME"
    # Rewrite Plugin Name in main file header so it shows whitelabel name when plugin is inactive/deactivated
    PLUGIN_NAME=$(grep -E "'plugin_name'|\"plugin_name\"" sp-whitelabel-config.php | sed -n "s/.*=> *['\"]\\([^'\"]*\\)['\"].*/\1/p" | sed 's/^ *//;s/ *$//')
    if [ -n "$PLUGIN_NAME" ]; then
        # Use # delimiter so plugin names containing / are safe.
        # Rewrite Plugin Name + Author + Description so the WP Plugins
        # admin screen shows the partner brand end-to-end, including when
        # the plugin is deactivated (WP reads these headers without
        # executing PHP, so any runtime branding filter doesn't help here).
        WL_DESCRIPTION="Accept cryptocurrency payments with $PLUGIN_NAME. Simple crypto payments for WooCommerce."
        if [[ "$(uname)" = "Darwin" ]]; then
            sed -i '' "s#^ \* Plugin Name: .*# * Plugin Name: $PLUGIN_NAME#" "$PACKAGE_DIR/stablecoin-pay.php"
            sed -i '' "s#^ \* Author: .*# * Author: $PLUGIN_NAME#" "$PACKAGE_DIR/stablecoin-pay.php"
            sed -i '' "s#^ \* Description: .*# * Description: $WL_DESCRIPTION#" "$PACKAGE_DIR/stablecoin-pay.php"
        else
            sed -i "s#^ \* Plugin Name: .*# * Plugin Name: $PLUGIN_NAME#" "$PACKAGE_DIR/stablecoin-pay.php"
            sed -i "s#^ \* Author: .*# * Author: $PLUGIN_NAME#" "$PACKAGE_DIR/stablecoin-pay.php"
            sed -i "s#^ \* Description: .*# * Description: $WL_DESCRIPTION#" "$PACKAGE_DIR/stablecoin-pay.php"
        fi
        echo "📦 Plugin header rebranded: Name/Author/Description → $PLUGIN_NAME"
    fi
else
    echo "📦 No whitelabel config - package will run as Stablecoin Pay (default)"
fi

# Build the WooCommerce Blocks JS bundle before packaging.
#
# The block-checkout integration ships a pre-built `build/index.js` so the
# plugin works out-of-the-box on the merchant's server (no Node required
# there). We only need Node + npm during packaging.
#
# This step is optional: if Node isn't installed locally, we still ship the
# zip — the block integration just stays disabled until someone builds it.
if [ -f "package.json" ] && [ -d "src/blocks" ]; then
    if command -v npm >/dev/null 2>&1; then
        echo "📦 Building WooCommerce Blocks JS bundle (npm run build)..."
        if [ ! -d "node_modules" ]; then
            echo "📦   First-time setup: running npm install (this is a one-time ~30s)..."
            npm install --no-audit --no-fund --loglevel=error || {
                echo "⚠️  npm install failed — block-checkout bundle will NOT be in this zip." >&2
            }
        fi
        npm run build --silent || {
            echo "⚠️  npm run build failed — block-checkout bundle will NOT be in this zip." >&2
        }
    else
        echo "⚠️  npm not found on PATH — skipping block-checkout JS build."
        echo "    Install Node 18+ if you want the block integration shipped in the zip."
    fi
fi

if [ -d "build" ]; then
    echo "📦 Copying built block-checkout JS bundle..."
    cp -r build "$PACKAGE_DIR/"
fi

# Copy includes directory
echo "📦 Copying includes directory..."
cp -r includes "$PACKAGE_DIR/"

# Copy images directory (if it exists)
if [ -d "images" ]; then
    echo "📦 Copying images directory..."
    cp -r images "$PACKAGE_DIR/"
else
    echo "📦 Creating images directory..."
    mkdir -p "$PACKAGE_DIR/images"
fi

# Copy bundled plugins (required dependencies)
if [ -d "bundled-plugins" ]; then
    echo "📦 Copying bundled required plugins..."
    cp -r bundled-plugins "$PACKAGE_DIR/"
fi

# Copy README
echo "📦 Copying documentation..."
cp README.md "$PACKAGE_DIR/"

# Create uninstall.php
echo "📦 Creating uninstall script..."
cat > "$PACKAGE_DIR/uninstall.php" << 'EOF'
<?php
/**
 * Uninstall script for Stablecoin Pay
 */

// Prevent direct access
if (!defined('WP_UNINSTALL_PLUGIN')) {
    exit;
}

// Clean up plugin data
delete_option('woocommerce_sp_settings');
delete_option('sp_legacy_migration_version');

// Options left behind by builds that used the legacy key prefix
delete_option('woocommerce_coinsub_settings');
delete_option('coinsub_checkout_page_id');
delete_option('coinsub_whitelabel_branding');
delete_option('coinsub_webhook_secret');

// Flush rewrite rules
flush_rewrite_rules();
EOF

# Create languages directory
echo "📦 Creating languages directory..."
mkdir -p "$PACKAGE_DIR/languages"

# Build info (so you can verify you have the latest when extracting)
echo "📦 Adding build info..."
BUILD_DATE=$(date -u +"%Y-%m-%d %H:%M:%S UTC")
GIT_REV=$(git rev-parse --short HEAD 2>/dev/null || echo "n/a")
echo "Build date: $BUILD_DATE" > "$PACKAGE_DIR/build-info.txt"
echo "Git commit: $GIT_REV" >> "$PACKAGE_DIR/build-info.txt"

# Create .gitignore
echo "📦 Creating .gitignore..."
cat > "$PACKAGE_DIR/.gitignore" << 'EOF'
# WordPress
wp-config.php
wp-content/uploads/
wp-content/blogs.dir/
wp-content/upgrade/
wp-content/backup-db/
wp-content/advanced-cache.php
wp-content/wp-cache-config.php
wp-content/cache/
wp-content/cache/supercache/

# Logs
*.log

# OS
.DS_Store
Thumbs.db

# IDE
.vscode/
.idea/
*.swp
*.swo

# Node
node_modules/
npm-debug.log

# Composer
vendor/
composer.lock
EOF

# Create plugin .zip: prefer `zip` (Unix/macOS). Git for Windows Bash often has no `zip` — use PowerShell.
create_plugin_zip() {
    local pkg_dir="$1"
    local zip_name="$2"
    local root_dir
    root_dir="$(pwd)"

    if command -v zip >/dev/null 2>&1; then
        # Zip the folder ITSELF (not just its contents) so the archive has a
        # proper top-level "<pkg_dir>/" entry — WordPress requires this to
        # install/replace cleanly without creating "-2" duplicate folders.
        zip -r "$zip_name" "$pkg_dir" -x "*.DS_Store" "*.git*" >/dev/null || return 1
        [ -f "$zip_name" ] || return 1
        return 0
    fi

    if command -v powershell.exe >/dev/null 2>&1 && command -v cygpath >/dev/null 2>&1; then
        local src_win dst_win ps1_win
        src_win=$(cygpath -w "$root_dir/$pkg_dir")
        dst_win=$(cygpath -w "$root_dir/$zip_name")
        ps1_win=$(cygpath -w "$root_dir/zip-package.ps1")
        if [ ! -f "$root_dir/zip-package.ps1" ]; then
            echo "❌ Missing zip-package.ps1 next to create-plugin-package.sh" >&2
            return 1
        fi
        # Do not use Compress-Archive: it stores \ in entry names; Linux hosts then lack includes/foo.php
        MSYS2_ARG_CONV_EXCL='*' powershell.exe -NoProfile -ExecutionPolicy Bypass -File "$ps1_win" \
            -SourceDir "$src_win" -DestinationZip "$dst_win" \
            || return 1
        [ -f "$zip_name" ] || return 1
        return 0
    fi

    echo "❌ No zip tool: install zip in Git Bash (e.g. pacman -S zip) or run on Windows with PowerShell." >&2
    return 1
}

# Create zip package
echo "📦 Creating ZIP package: $ZIP_NAME"
if ! create_plugin_zip "$PACKAGE_DIR" "$ZIP_NAME"; then
    echo "❌ ZIP creation failed." >&2
    exit 1
fi

# Clean up
echo "🧹 Cleaning up..."
rm -rf "$PACKAGE_DIR"

echo "✅ Plugin package created successfully!"
echo "📁 Package: $ZIP_NAME"
echo "📋 Size: $(du -h "$ZIP_NAME" | cut -f1)"

# Copy fixed-name zip to Downloads (overwrites previous - saves storage; no zips left in repo)
DOWNLOADS_DIR="$HOME/Downloads"
if [ -d "$DOWNLOADS_DIR" ]; then
    echo "📥 Copying to Downloads folder (overwrites previous)..."
    cp "$ZIP_NAME" "$DOWNLOADS_DIR/"
    echo "✅ Saved: $DOWNLOADS_DIR/$ZIP_NAME"
    rm -f "$ZIP_NAME"
else
    echo "⚠️  Downloads folder not found - zip left in project"
fi

echo ""
echo "🚀 Ready for deployment!"
echo "If installing for the first time:"
echo "  1. WordPress → Plugins → Add New → Upload Plugin → choose the zip"
echo "  2. Activate, then configure under WooCommerce → Settings → Payments"
echo ""
echo "If updating an already-installed copy (KEEPS settings, no need to delete):"
echo "  1. WordPress → Plugins → Add New → Upload Plugin → choose the zip"
echo "  2. WordPress will say 'Plugin already installed' — click"
echo "     'Replace current with uploaded'. That's it."
echo "  (Version bumped to ${BUILT_VERSION} so WP shows it as a newer build.)"
