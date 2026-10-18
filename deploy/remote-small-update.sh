#!/usr/bin/env bash
# Applies a small update (templates + built assets, no PHP code, no migrations) to ~/sisi.news.
#   ssh host 'bash -s -- <name>' < deploy/remote-small-update.sh      (expects ~/sisi.news-uploads/<name>.tgz)
set -euo pipefail
NAME="${1:?usage: remote-small-update.sh <name>}"
APP="$HOME/sisi.news"
UP="$HOME/sisi.news-uploads"
STAGE="$UP/$NAME"
PREV="$UP/$NAME-prev"

[ -f "$UP/$NAME.tgz" ] || { echo "ABORT: $UP/$NAME.tgz not found"; exit 1; }
rm -rf "$STAGE"; mkdir -p "$STAGE"
tar -xzf "$UP/$NAME.tgz" -C "$STAGE"

echo "==> verifying checksums (nothing is changed if one fails)"
(cd "$STAGE" && sha256sum -c --quiet SHA256SUMS) || { echo "ABORT: checksum mismatch"; exit 1; }
FILES=$(cd "$STAGE" && sed 's/^[0-9a-f]* [* ]//' SHA256SUMS)

echo "==> saving the current versions to $PREV"
mkdir -p "$PREV"
for f in $FILES; do
  if [ -e "$APP/$f" ]; then mkdir -p "$PREV/$(dirname "$f")"; cp -p "$APP/$f" "$PREV/$f"; fi
done

echo "==> new built assets first (unused until the manifest points at them)"
for f in $FILES; do
  case "$f" in public/build/assets/*) cp -p "$STAGE/$f" "$APP/$f" ;; esac
done

echo "==> templates and sources (each replaced in one rename)"
for f in $FILES; do
  case "$f" in
    public/build/*) ;;
    *) mkdir -p "$APP/$(dirname "$f")"; cp -p "$STAGE/$f" "$APP/$f.new"; mv "$APP/$f.new" "$APP/$f" ;;
  esac
done

echo "==> switching the asset manifest"
cp -p "$APP/public/build/manifest.json" "$APP/public/build/manifest.json.prev"
cp -p "$STAGE/public/build/manifest.json" "$APP/public/build/manifest.json.new"
mv "$APP/public/build/manifest.json.new" "$APP/public/build/manifest.json"

echo "==> recompiling the cached templates"
cd "$APP"
php artisan view:clear
php artisan view:cache

echo "DONE $NAME (undo: copy $PREV/* back over $APP, then php artisan view:clear && php artisan view:cache)"
