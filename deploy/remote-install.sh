#!/usr/bin/env bash
# Runs ON THE SERVER (piped over ssh by deploy/deploy.sh). Installs or updates the sisi.news code.
#
#   ssh <host> 'bash -s -- <tarball-file-name>' < deploy/remote-install.sh
#
# Safety properties:
#  - Only ever touches /home/wxr46gn9f7ld/sisi.news, ~/sisi.news-uploads and ~/sisi.news-private. Nothing else on the account.
#  - Never overwrites .env, storage/ (uploads, sessions, logs) or the public/storage symlink.
#  - The previous release tarballs are kept (last 3) for rollback.
set -euo pipefail
umask 022

APP=/home/wxr46gn9f7ld/sisi.news
UP=/home/wxr46gn9f7ld/sisi.news-uploads
REL="${1:?usage: remote-install.sh <tarball-file-name inside ~/sisi.news-uploads>}"
TS="$(date +%Y%m%d-%H%M%S)"
STAGE="$UP/stage-$TS"

[ "$HOME" = "/home/wxr46gn9f7ld" ] || { echo "unexpected HOME: $HOME"; exit 1; }
case "$REL" in */*|*..*) echo "release name must be a plain file name"; exit 1;; esac
[ -f "$UP/$REL" ] || { echo "missing $UP/$REL"; exit 1; }

echo "==> unpacking $REL"
mkdir -p "$STAGE"
tar -xzf "$UP/$REL" -C "$STAGE"
for must in artisan vendor/autoload.php public/index.php public/build/manifest.json database/pages/pages.json; do
  [ -e "$STAGE/$must" ] || { echo "package is incomplete: $must"; rm -rf "${STAGE:?}"; exit 1; }
done
[ ! -e "$STAGE/.env" ] || { echo "package contains .env: refusing"; rm -rf "${STAGE:?}"; exit 1; }

echo "==> syncing code into $APP (keeping .env, storage/, public/storage)"
mkdir -p "$APP"
rsync -a --delete \
  --exclude='/.env' --exclude='/storage/' --exclude='/public/storage' \
  "$STAGE"/ "$APP"/

mkdir -p "$APP/storage/logs" "$APP/storage/framework/cache" "$APP/storage/framework/sessions" \
         "$APP/storage/framework/views" "$APP/storage/app/public" "$APP/bootstrap/cache"

# NOTE: do NOT write a "Require all denied" .htaccess here. Apache merges a parent folder's Require rules into the
# document root below it, so it takes the whole site offline (it did, on the live host). The safety net for a
# mistaken document root ships in the release as /.htaccess and only refuses files outside public/.

echo "==> permissions (755 dirs, 644 files, .env 600)"
find "$APP" -path "$APP/storage" -prune -o -type d -exec chmod 755 {} +
find "$APP" -path "$APP/storage" -prune -o -type f -exec chmod 644 {} +
[ ! -f "$APP/.env" ] || chmod 600 "$APP/.env"

cd "$APP"
if [ ! -f .env ]; then
  echo "no .env yet: code is in place. Run remote-first-env.sh, then re-run this script."
  rm -rf "${STAGE:?}"
  exit 0
fi

echo "==> artisan"
php artisan package:discover --ansi
php artisan migrate --force --no-interaction
php artisan db:seed --force --no-interaction
[ -L public/storage ] || php artisan storage:link
php artisan optimize:clear
php artisan optimize
php artisan filament:optimize 2>/dev/null || true
echo "$REL $TS" > storage/app/.release

echo "==> cleanup (keeping the last 3 tarballs)"
rm -rf "${STAGE:?}"
ls -1t "$UP"/sisi-news-*.tgz 2>/dev/null | tail -n +4 | xargs -r rm -f
echo "DONE $REL"
