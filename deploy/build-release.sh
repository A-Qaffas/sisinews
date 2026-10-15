#!/usr/bin/env bash
# Builds a deployable release tarball from this working tree (run in Git Bash on the Windows dev machine).
#
#   deploy/build-release.sh <output-dir> [--skip-assets]
#
# Why it works this way:
#  - The shared host has no Node and little CPU to spare, so the front end (public/build) and the
#    production-only Composer vendor/ are built HERE and shipped inside the tarball.
#  - The tree is copied to a staging folder first, so the dev tree's own vendor/ (which has dev packages) is never touched.
#  - The result is verified: no secrets, no dev packages, no machine-specific files. The script fails instead of packing them.
set -euo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
OUT="${1:?usage: build-release.sh <output-dir> [--skip-assets]}"
SKIP_ASSETS="${2:-}"
PHP83="${PHP83:-/c/laragon/bin/php/php-8.3.30-Win32-vs16-x64/php.exe}"
COMPOSER_PHAR="${COMPOSER_PHAR:-/c/laragon/bin/composer/composer.phar}"
STAMP="$(date +%Y%m%d-%H%M%S)"
STAGE="$OUT/stage-$STAMP"
TARBALL="$OUT/sisi-news-$STAMP.tgz"

mkdir -p "$OUT" "$STAGE"

if [ "$SKIP_ASSETS" != "--skip-assets" ]; then
  echo "==> building front-end assets"
  (cd "$ROOT" && npm run build)
fi

echo "==> copying tree to staging (excluding secrets, dev files, machine-local state)"
(
  cd "$ROOT"
  tar -cf - \
    --exclude='./.git' --exclude='./.idea' --exclude='./.vscode' \
    --exclude='./.env' --exclude='./.env.*' \
    --exclude='./node_modules' --exclude='./vendor' \
    --exclude='./tests' --exclude='./phpunit.xml' --exclude='./.phpunit.cache' --exclude='./.phpunit.result.cache' \
    --exclude='./deploy' --exclude='./docs' --exclude='./CLAUDE.md' --exclude='./AGENTS.md' --exclude='./README.md' \
    --exclude='./storage/logs' --exclude='./storage/framework/cache' --exclude='./storage/framework/sessions' \
    --exclude='./storage/framework/views' --exclude='./storage/framework/testing' --exclude='./storage/app/public' \
    --exclude='./storage/pail' \
    --exclude='./bootstrap/cache/*.php' \
    --exclude='./public/storage' --exclude='./public/hot' \
    --exclude='./database/*.sqlite' --exclude='./database/demo-assets' \
    --exclude='*.log' \
    .
) | (cd "$STAGE" && tar -xf -)

# .env.example is a template (no secrets); keep it. The exclude above also dropped it, so restore it.
cp "$ROOT/.env.example" "$STAGE/.env.example"

# Empty skeleton directories Laravel expects (tar excluded their contents).
mkdir -p "$STAGE/storage/logs" "$STAGE/storage/framework/cache" "$STAGE/storage/framework/sessions" \
         "$STAGE/storage/framework/views" "$STAGE/storage/app/public" "$STAGE/bootstrap/cache"

echo "==> installing production dependencies (no dev packages) into staging"
(
  cd "$STAGE"
  "$PHP83" "$COMPOSER_PHAR" install --no-dev --optimize-autoloader --no-scripts --no-interaction --prefer-dist --no-progress
)

echo "==> verifying the staging tree"
fail=0
# 1. no dev packages in the shipped vendor
for pkg in phpunit/phpunit fakerphp/faker laravel/pail laravel/pao laravel/pint mockery/mockery nunomaduro/collision; do
  if grep -q "\"name\": \"$pkg\"" "$STAGE/vendor/composer/installed.json"; then echo "FAIL: dev package $pkg is in vendor"; fail=1; fi
done
# 2. none of these paths may exist
for p in .env .git node_modules tests public/storage public/hot storage/app/public/articles; do
  if [ -e "$STAGE/$p" ]; then echo "FAIL: $p must not be in the release"; fail=1; fi
done
# 3. no stale package manifest, no logs, no sqlite databases
if ls "$STAGE"/bootstrap/cache/*.php >/dev/null 2>&1; then echo "FAIL: bootstrap/cache/*.php present"; fail=1; fi
if find "$STAGE" -path "$STAGE/vendor" -prune -o \( -name '*.log' -o -name '*.sqlite' \) -print | grep -q .; then echo "FAIL: log or sqlite files present"; fail=1; fi
# 4. things that MUST be there
for p in public/index.php public/.htaccess public/build/manifest.json public/build/fonts-manifest.json public/brand/logo.png \
         database/launch-content/stories.json database/pages/pages.json vendor/autoload.php artisan composer.lock .htaccess; do
  if [ ! -e "$STAGE/$p" ]; then echo "FAIL: missing $p"; fail=1; fi
done
if [ "$fail" -ne 0 ]; then echo "release verification FAILED, not packing"; exit 1; fi

echo "==> packing $TARBALL"
(cd "$STAGE" && tar -czf "$TARBALL" .)
sha256sum "$TARBALL" | tee "$TARBALL.sha256"
ls -la "$TARBALL"
echo "staging kept at $STAGE"
echo "RELEASE=$TARBALL"
