#!/usr/bin/env bash
# Runs ON THE SERVER, once. Builds ~/sisi.news/.env from the shipped .env.example plus the database
# credentials that were generated on the server (~/sisi.news-private/db.env). Prints no secret.
#
#   ssh <host> 'bash -s -- <public-app-url>' < deploy/remote-first-env.sh
set -euo pipefail
umask 077

APP=/home/wxr46gn9f7ld/sisi.news
PRIV=/home/wxr46gn9f7ld/sisi.news-private
URL="${1:-https://sisi.news}"

[ -f "$APP/.env.example" ] || { echo "no $APP/.env.example: run remote-install.sh first"; exit 1; }
[ ! -f "$APP/.env" ] || { echo ".env already exists: refusing to overwrite it"; exit 1; }
[ -f "$PRIV/db.env" ] || { echo "no database credentials file"; exit 1; }
# shellcheck disable=SC1091
. "$PRIV/db.env"
[ -n "${DB_PASSWORD:-}" ] || { echo "empty DB_PASSWORD"; exit 1; }

cp "$APP/.env.example" "$APP/.env"
set_env() {
  local k="$1" v="$2"
  if grep -q "^${k}=" "$APP/.env"; then sed -i "s|^${k}=.*|${k}=${v}|" "$APP/.env"; else printf '%s=%s\n' "$k" "$v" >> "$APP/.env"; fi
}

set_env APP_KEY "base64:$(openssl rand -base64 32)"
set_env APP_URL "$URL"
set_env APP_DEBUG false
set_env DB_HOST localhost
set_env DB_DATABASE sisinews_prod
set_env DB_USERNAME sisinews_app
set_env DB_PASSWORD "$DB_PASSWORD"
# Database-backed sessions and cache: bots that send no cookie create a session per request, and file
# storage would eat into the account's shared inode quota (which every other site on it also writes to).
set_env SESSION_DRIVER database
set_env CACHE_STORE database
set_env LOG_STACK daily
set_env LOG_DAILY_DAYS 14
set_env LOG_LEVEL warning
# https switches stay OFF until the certificate exists (deploy/go-live.sh turns them on together).
set_env APP_FORCE_HTTPS false
set_env SESSION_SECURE_COOKIE false

chmod 600 "$APP/.env"
echo ".env created (mode $(stat -c %a "$APP/.env")). Keys set: APP_KEY APP_URL APP_DEBUG DB_* SESSION_DRIVER CACHE_STORE LOG_*"
