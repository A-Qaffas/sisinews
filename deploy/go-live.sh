#!/usr/bin/env bash
# Finishes the launch AFTER the two human-only steps are done:
#   1. cPanel  -> Domains -> Create A New Domain: sisi.news, document root  sisi.news/public  (untick "share document root")
#   2. GoDaddy -> DNS for sisi.news: A record "@" -> 92.205.88.46 (and www pointing at the same place), Airo website product disconnected
#
#   deploy/go-live.sh            checks everything, waits for the certificate, then turns https on
#   deploy/go-live.sh --check    only reports readiness, changes nothing
#
# Order matters: https is switched on only AFTER https://sisi.news answers with a valid certificate. If it
# were switched on earlier, visitors would be redirected to a certificate error.
set -euo pipefail

HOST="wxr46gn9f7ld@92.205.88.46"
IP="92.205.88.46"
DOMAIN="sisi.news"
CHECK_ONLY="${1:-}"
SSH=(ssh -o BatchMode=yes -o ConnectTimeout=20 "$HOST")

say() { printf '\n==> %s\n' "$*"; }
fail() { printf 'NOT READY: %s\n' "$*"; exit 1; }

doh() { # type name -> data lines (public DNS over HTTPS, so a stale local cache cannot fool us)
  curl -s -m 15 -H 'accept: application/dns-json' "https://cloudflare-dns.com/dns-query?name=$2&type=$1" \
    | node -e 'let s="";process.stdin.on("data",d=>s+=d).on("end",()=>{try{(JSON.parse(s).Answer||[]).forEach(a=>console.log(a.type+" "+a.data))}catch(e){}})'
}

say "1. DNS (as seen by Cloudflare's public resolver)"
apex="$(doh A "$DOMAIN" | awk '$1==1{print $2}' | sort -u | tr '\n' ' ')"
echo "   $DOMAIN A      -> ${apex:-<none>}"
[ "$apex" = "$IP " ] || fail "$DOMAIN must resolve to exactly $IP (found: ${apex:-nothing}). Change the A record at GoDaddy and wait for it to propagate."
www="$(doh A "www.$DOMAIN" | awk '$1==1{print $2}' | sort -u | tr '\n' ' ')"
echo "   www.$DOMAIN A  -> ${www:-<none>}"
[ "$www" = "$IP " ] || fail "www.$DOMAIN must resolve to $IP as well (a CNAME to $DOMAIN is fine)."

say "2. Is the domain attached to the new site on the server?"
root="$("${SSH[@]}" "uapi DomainInfo single_domain_data domain=$DOMAIN 2>/dev/null | grep -m1 documentroot" | sed 's/^ *//')"
echo "   $root"
case "$root" in *"/sisi.news/public") ;; *) fail "$DOMAIN is not set up in cPanel with document root sisi.news/public (see step 1 at the top of this script).";; esac

say "3. Does the application answer for that host? (ordinary requests from here; loopback tests on the server are meaningless on this host)"
for p in /ar /en /ar/page/about; do
  code="$(curl -s -o /dev/null -m 40 -w '%{http_code}' --resolve "$DOMAIN:80:$IP" "http://$DOMAIN$p")"
  printf '   %-16s %s\n' "$p" "$code"
  [ "$code" = "200" ] || [ "$code" = "301" ] || fail "$p returned $code"
done

if [ "$CHECK_ONLY" = "--check" ]; then say "check only: everything above is ready. Run without --check to finish."; exit 0; fi

say "4. Ask cPanel to issue the certificate (AutoSSL)"
"${SSH[@]}" 'for c in "/usr/local/cpanel/bin/autossl_check --user=wxr46gn9f7ld" "uapi SSL start_autossl_check"; do $c >/dev/null 2>&1 && { echo "   requested with: $c"; exit 0; }; done; echo "   could not trigger it from the shell: it will run by itself, or use cPanel > SSL/TLS Status > Run AutoSSL"' || true

say "5. Waiting for a VALID certificate on https://$DOMAIN (up to 30 minutes)"
ok=0
for i in $(seq 1 60); do
  if curl -s -o /dev/null -m 20 --resolve "$DOMAIN:443:$IP" "https://$DOMAIN/ar" 2>/dev/null; then ok=1; break; fi
  printf '   attempt %s: not yet\n' "$i"; sleep 30
done
[ "$ok" = "1" ] || fail "no valid certificate after 30 minutes. Nothing was switched on; the site still works over http. Run AutoSSL in cPanel, then re-run this script."
echo "   https answers with a certificate that validates."
curl -sI -m 20 "https://$DOMAIN/ar" | grep -iE '^(HTTP|server|content-type)' | tr -d '\r' | sed 's/^/   /'

say "6. Switching https on (APP_FORCE_HTTPS, SESSION_SECURE_COOKIE, APP_URL, redirect flag)"
"${SSH[@]}" bash -s <<'EOS'
set -euo pipefail
APP=/home/wxr46gn9f7ld/sisi.news
cd "$APP"
cp .env ".env.before-https.$(date +%Y%m%d-%H%M%S)"; chmod 600 .env.before-https.*
set_env() { if grep -q "^$1=" .env; then sed -i "s|^$1=.*|$1=$2|" .env; else printf '%s=%s\n' "$1" "$2" >> .env; fi; }
set_env APP_URL https://sisi.news
set_env APP_FORCE_HTTPS true
set_env SESSION_SECURE_COOKIE true
touch storage/app/force-https
php artisan config:cache
php artisan cache:clear
echo "   done; the old .env was kept as $(ls .env.before-https.* | tail -1)"
EOS

say "7. Verifying from outside"
printf '   http://%s/        -> ' "$DOMAIN"; curl -s -o /dev/null -m 20 -w '%{http_code} %{redirect_url}\n' "http://$DOMAIN/"
printf '   http://www.%s/    -> ' "$DOMAIN"; curl -s -o /dev/null -m 20 -w '%{http_code} %{redirect_url}\n' "http://www.$DOMAIN/"
printf '   https://%s/ar     -> ' "$DOMAIN"; curl -s -o /dev/null -m 30 -w '%{http_code}\n' "https://$DOMAIN/ar"
printf '   https://%s/en     -> ' "$DOMAIN"; curl -s -o /dev/null -m 30 -w '%{http_code}\n' "https://$DOMAIN/en"
printf '   https://%s/admin  -> ' "$DOMAIN"; curl -s -o /dev/null -m 30 -w '%{http_code} %{redirect_url}\n' "https://$DOMAIN/admin"
printf '   HSTS header: '; curl -sI -m 20 "https://$DOMAIN/ar" | grep -i '^strict-transport-security' | tr -d '\r' || echo "(missing)"
say "LIVE. To undo: delete storage/app/force-https on the server and set APP_FORCE_HTTPS=false, SESSION_SECURE_COOKIE=false in .env, then php artisan config:cache."
