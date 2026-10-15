# Deploying sisi.news to the GoDaddy cPanel host

A runbook for **this** host: GoDaddy cPanel shared hosting, SSH, PHP 8.3, MariaDB 10.11, no Node, a 128 MB PHP `memory_limit`, one vCPU and 1 GB of RAM shared with other businesses' sites.

How to read it:

- **Verified here** means checked against this repository (commands, routes, env keys, behaviour covered by tests).
- **(unverified)** means a fact about the host or about a vendor screen (GoDaddy, cPanel) that nobody has confirmed from this machine. Check it before relying on it; the "Look first" step is read-only and tells you most of it.
- Nothing in this document has been run against the server. Per `CLAUDE.md`, never run a write or destructive command there without the owner's explicit go-ahead; launch day is that go-ahead, step by step.

The shape of the finished install:

```
/home/wxr46gn9f7ld/
├── public_html/            the primary domain's site: never touched by this runbook
├── sisi.news/              the application (outside any web root)
│   ├── public/             <- document root of sisi.news (the ONLY folder the web can reach)
│   ├── .env                secrets, mode 600
│   ├── storage/            logs, sessions, cache, uploaded pictures (storage/app/public)
│   └── vendor/             installed on the server by composer
├── backups/                nightly database dump + uploaded pictures
└── releases/               the last two upload tarballs (for rollback)
```

The app must never live inside `public_html`: that mistake once exposed `.git` on another site of this account.

## 0. Before launch day

1. Pick the admin's email and a password of at least 12 characters (the `sisi:create-admin` rule). Do not reuse one.
2. Write down the **current DNS records** of `sisi.news` at GoDaddy (screenshot of the DNS page). That is the rollback for the DNS flip.
3. Decide which social profiles and contact details the footer should show (`SITE_SOCIAL_*`, `SITE_CONTACT_*` in `.env`); a blank value hides that link.
4. A day before the flip, lower the TTL of the `@` A record to the minimum GoDaddy allows (600 seconds) (unverified), so a rollback propagates fast.

## 1. Build the package on this Windows machine

Node exists only here, so the compiled front end is built here and shipped inside the package. In Git Bash, in the project folder:

```bash
PHP83="/c/laragon/bin/php/php-8.3.30-Win32-vs16-x64/php.exe"

npm run build                                  # writes public/build (Vite assets)
"$PHP83" artisan test --compact                # must be green
STAMP=$(date +%Y%m%d-%H%M)

tar -czf ../sisi-news-$STAMP.tar.gz \
  --exclude='./.env' \
  --exclude='./.env.backup' \
  --exclude='./.env.production' \
  --exclude='./node_modules' \
  --exclude='./vendor' \
  --exclude='./tests' \
  --exclude='./storage/logs/*' \
  --exclude='./storage/framework/cache/*' \
  --exclude='./storage/framework/sessions/*' \
  --exclude='./storage/framework/views/*' \
  --exclude='./storage/framework/testing/*' \
  --exclude='./storage/app/public/*' \
  --exclude='./bootstrap/cache/*.php' \
  --exclude='./public/storage' \
  --exclude='./public/hot' \
  .

tar -tzf ../sisi-news-$STAMP.tar.gz | grep -E '(^|/)\.env(\.backup|\.production)?$|(^|/)node_modules/|^\./vendor/' && echo "STOP: secrets or dependencies are in the package"
```

Why these exclusions:

- `.env`, `storage/logs`, sessions and caches are this machine's, never the server's. The last command must print nothing. It matches only the top-level `vendor/`: `resources/views/vendor/` holds the site's own pagination view and belongs in the package.
- `vendor/` is installed on the server (step 5), so it matches the server's PHP.
- `bootstrap/cache/*.php` holds a package list that includes development-only packages; a stale one breaks a `--no-dev` install.
- `storage/app/public/*` is uploaded pictures and the demo pictures of this machine; the server keeps its own.
- `public/storage` is a symlink that only means something on this machine.

`database/launch-content/` (the launch stories and their pictures) is deliberately **inside** the package: step 9 reads it.

## 2. Look first (read-only)

```bash
ssh wxr46gn9f7ld@92.205.88.46
```

Then:

```bash
php -v                          # CLI version. Need 8.3; if not, use /opt/cpanel/ea-php83/root/usr/bin/php below (unverified path)
ls /opt/cpanel | grep ea-php    # PHP builds the host offers (unverified)
php -m | grep -Ei 'mbstring|intl|gd|pdo_mysql|fileinfo|curl|openssl|tokenizer|xml|zip|bcmath|dom|iconv|ctype'
php -r 'echo (gd_info()["WebP Support"] ?? false) ? "gd+webp ok\n" : "NO WEBP\n";'
php -i | grep -E '^memory_limit|^upload_max_filesize|^post_max_size'
which composer || echo "no composer on the host"
df -h ~ | tail -1               # free disk
crontab -l                      # existing cron jobs (do not overwrite them later)
```

What each answer changes:

- **CLI PHP is not 8.3**: in every command below replace `php` with the 8.3 binary (for example `/opt/cpanel/ea-php83/root/usr/bin/php`) and `composer` with `php83 composer.phar`.
- **A missing extension** (`intl`, `gd` with WebP, `pdo_mysql`, `mbstring`, `dom`, `bcmath`, `zip`): enable it in cPanel, "Select PHP Version" / "MultiPHP Manager" (unverified screen names), before going on. `composer install` and the picture resizing fail without them.
- **No composer**: step 5 installs a local copy. If the host cannot fetch it, use the "ship vendor in the package" fallback in step 5.

## 3. Create the domain and the database in cPanel

1. **Domain.** cPanel, "Domains" (older cPanel: "Addon Domains"), **Create a New Domain** (unverified screen names):
   - Domain: `sisi.news`.
   - Untick "Share document root with ..." and set the **document root** to `sisi.news/public` (relative to the home directory, so `/home/wxr46gn9f7ld/sisi.news/public`).
   - Do **not** point it at `public_html` or `public_html/sisi.news`.
2. **PHP version.** cPanel "MultiPHP Manager": set `sisi.news` to **PHP 8.3**. cPanel does this by writing a handler block into `~/sisi.news/public/.htaccess` (unverified), so do it **after** step 4 has put the files there, and again after any upload that replaces that file (see "Updating later").
3. **PHP limits.** cPanel "MultiPHP INI Editor" for `sisi.news` (unverified): `upload_max_filesize = 8M`, `post_max_size = 10M`. The panel shrinks pictures in the browser to at most 1600 px before upload, so a story picture is normally well under 1 MB; the 8M only avoids a surprise. `memory_limit` stays 128M: the app is built for it.
4. **Database.** cPanel "MySQL Database Wizard":
   - Create the database (for example `sisi`) and a user with a long generated password; give the user **All Privileges** on that database only.
   - cPanel prefixes both names with the account name (so likely `wxr46gn9f7ld_sisi`; unverified). Copy the final names exactly: they go into `.env`.
   - The migrations create every table as `utf8mb4` / `utf8mb4_unicode_ci` themselves, whatever the database default is.

## 4. Upload and unpack

From this machine (Git Bash):

```bash
scp ../sisi-news-$STAMP.tar.gz wxr46gn9f7ld@92.205.88.46:~/
```

On the server:

```bash
mkdir -p ~/releases ~/backups ~/bin
mkdir -p ~/sisi.news
tar -xzf ~/sisi-news-$STAMP.tar.gz -C ~/sisi.news
mv ~/sisi-news-$STAMP.tar.gz ~/releases/
cd ~/sisi.news
mkdir -p storage/logs storage/framework/{cache,sessions,views} storage/app/public bootstrap/cache
chmod -R u+rwX,go-w storage bootstrap/cache
ls -la public/.htaccess public/index.php      # both must be there
```

PHP runs as the account user on this kind of host, so `755` folders and `644` files are enough; do not use `777` (unverified: confirm PHP does not run as `nobody` on this plan; if a later step cannot write to `storage/`, tell support rather than loosening permissions).

## 5. Install the dependencies

```bash
cd ~/sisi.news
# Only if the host has no composer (look-first step 2): fetch a private copy.
# curl -sS https://getcomposer.org/installer | php -- --install-dir="$HOME/sisi.news" --filename=composer.phar

php -d memory_limit=512M "$(which composer)" install --no-dev --optimize-autoloader --no-interaction
# with a private copy:  php -d memory_limit=512M composer.phar install --no-dev --optimize-autoloader --no-interaction
```

- The 128 MB limit applies to web requests. Composer itself needs more while it resolves, hence `-d memory_limit=512M` (unverified whether the host lets the CLI exceed 128M; if it is killed with "Killed" or "Allowed memory size exhausted", use the fallback below).
- `--no-dev` leaves out the test and dev tooling; the package list in `bootstrap/cache` is rebuilt by composer.
- **Fallback: ship `vendor/` in the package.** On this machine, in a clean copy of the project, run `composer install --no-dev --optimize-autoloader` with PHP 8.3, tar that copy **with** `vendor/`, and skip this step on the server.

## 6. Configure `.env`

```bash
cd ~/sisi.news
cp .env.example .env
chmod 600 .env
nano .env        # or vi
```

Fill in (each key is explained in the file):

| Key | Value |
| --- | --- |
| `APP_URL` | `http://sisi.news` **until SSL works**, then `https://sisi.news`. Pages, pictures, the sitemap and the feed link from the scheme and host the visitor actually used, so a wrong scheme here no longer breaks pictures; `APP_URL` still names the canonical host (any other host is served with `noindex`, and only the `APP_URL` host and its `www.` twin get a cached sitemap and feed) and is what command-line runs use. |
| `APP_ENV` / `APP_DEBUG` | `production` / `false` (already the template's values). |
| `APP_FORCE_HTTPS` | `false` for now. |
| `SESSION_SECURE_COOKIE` | `false` for now. `true` on a site that is still on http makes login impossible. |
| `DB_DATABASE`, `DB_USERNAME`, `DB_PASSWORD` | exactly what cPanel created in step 3. Leave `DB_HOST=127.0.0.1`; if the connection is refused, try `localhost`. |
| `SITE_CONTACT_EMAIL` | **set it before launch**, to a mailbox somebody reads every working day. The About, Corrections and Privacy pages tell readers to write to it (their `[[contact]]` token becomes this address); until it is set they say only "the editorial team". |
| `SITE_SOCIAL_*`, `SITE_CONTACT_PHONE` | optional. |
| `SECURITY_HSTS_MAX_AGE` | optional, seconds. Default 604800 (one week); raise it (2592000, then 31536000) once https has been stable for a while. Sent only over https in production. |

Then:

```bash
php artisan key:generate --force
```

Run it once. A second run changes the key and signs every staff member out.

## 7. Create the tables and reference data

```bash
php artisan migrate --force      # creates the tables
php artisan db:seed --force      # reference data only: the six sections (CategorySeeder), the five trust pages (PageSeeder, from database/pages/pages.json) and Egypt's 27 governorates for the Live page (GovernorateSeeder). Safe to repeat: pages are never overwritten, sections are seeded only into an empty table, governorates are updated in place by slug.
php artisan storage:link         # public/storage -> storage/app/public, so pictures can be seen
php artisan filament:assets      # publishes the panel's CSS and JS into public/
```

Do **not** run `db:seed --class=DemoContentSeeder`: it is for development only and refuses to run when `APP_ENV=production`.

`storage:link` needs the host to allow symbolic links (unverified). If it reports an error, stop and ask GoDaddy support to allow symlinks for the account; pictures will not display without it.

## 8. Create the first admin

```bash
php artisan sisi:create-admin you@example.com --name="Your Name" --name-ar="اسمك"
```

It asks for the password twice without echoing it (at least 12 characters, and not containing the word "password"). Do not use `--password=` on the server: the value stays in the shell history. Re-running it for the same email only works with `--reset-password` and gives that admin a new password.

From then on, accounts are managed in the panel under **Team** (see `docs/STAFF-GUIDE.md`).

## 9. Load the launch stories

`php artisan sisi:seed-launch-content` imports the launch stories with their real pictures, credits, tags and Arabic and English text, publishing them as the admin you just created. It was run here end to end (dry run, real import, second run that skips everything) against a scratch MySQL database with the real `database/launch-content` package: 12 stories, 24 published language versions, 48 audit rows by the named admin, the inactive `desk@sisi.news` byline account. It has **not** been run on the server, so check its options there before relying on this section.

```bash
php artisan sisi:seed-launch-content --help
php artisan sisi:seed-launch-content --admin=you@example.com --dry-run    # validates and reports, writes nothing
php artisan sisi:seed-launch-content --admin=you@example.com              # the real import
```

As of this writing its signature is `--admin=<email of an active admin>`, `--path=<content directory>` (default `database/launch-content`) and `--dry-run`; it is meant to be safe to run twice. Look at the pictures it copies into `storage/app/public` with `ls -la storage/app/public | head` and, after the DNS flip, in the browser.

## 10. Cache for production

```bash
php artisan optimize             # caches config, routes, views and events (and the panel's components)
```

After this, **changes to `.env` do nothing until you run**:

```bash
php artisan optimize:clear && php artisan optimize
```

(The app reads the environment only inside `config/*.php`, which is what makes caching safe. Verified here by search. `route:cache` on the Filament and Livewire routes is standard but was not run from this machine.)

## 11. Test the site before DNS changes

The site is already live on the server's IP; only the name still points to GoDaddy's placeholder. Look at it from this machine without touching DNS:

1. Edit `C:\Windows\System32\drivers\etc\hosts` as administrator and add the line `92.205.88.46 sisi.news www.sisi.news`. Remove it when finished.
2. Smoke tests (in a browser, or `curl`; before the DNS flip add `--resolve sisi.news:80:92.205.88.46`):

| Check | Expected |
| --- | --- |
| `http://sisi.news/` | redirects to `/ar` (or `/en` for an English-preferring browser) |
| `http://sisi.news/ar` and `/en` | the home page, with the launch stories and their pictures, not "Airo" |
| a story page, its picture | opens, picture displays (if not: `APP_URL` scheme, or `storage:link`) |
| `/ar/category/<slug>`, `/ar/search?q=...` | work |
| `/sitemap.xml`, `/ar/feed.xml`, `/robots.txt` | 200, XML or text |
| `/ar/page/about`, `/en/page/privacy`, and the "About" links in the footer | the page opens; the footer lists five links; the contact line shows the address from `SITE_CONTACT_EMAIL` |
| `/up` | 200 (health check) |
| `/admin` | redirects to `/admin/login`; the first admin signs in |
| **Team** in the sidebar | present for the admin |
| `/ar/live`, `/en/live`, `/ar/live?g=cairo` | 200; 27 governorate chips plus the all-Egypt chip; the menu on every page has the Live link |
| **Broadcasts** (Newsroom group) | present for the admin and the Editor-in-chief, absent for journalists |
| `/.env`, `/composer.json`, `/storage/logs/laravel.log` | 404 (they are outside the document root) |
| `~/sisi.news/storage/logs/laravel.log` | empty, or no errors |

3. Check the headers: `curl -sI http://sisi.news/ar` should show `X-Content-Type-Options: nosniff`, `X-Frame-Options`, and a `Content-Security-Policy`.
   `curl -sI https://sisi.news/ar/live` must show `frame-src https://www.youtube-nocookie.com https://player.vimeo.com` in the Content-Security-Policy; every other page has no `frame-src` (the `default-src 'self'` applies). (Once https is forced, use `https://` in these checks, or curl only shows the redirect.)

Do not create junk stories or accounts on the live database. Accounts are never deleted, only deactivated.

## 12. Backups (cron)

Create `~/bin/sisi-backup.sh` (`nano ~/bin/sisi-backup.sh`; mode 700):

```bash
#!/bin/bash
# Nightly: database dump + uploaded pictures, keep 14 days. Credentials are read from .env, never typed here.
set -euo pipefail

APP="$HOME/sisi.news"
OUT="$HOME/backups"
STAMP="$(date +%Y%m%d-%H%M)"
mkdir -p "$OUT"

env_value() { grep -E "^$1=" "$APP/.env" | head -1 | cut -d= -f2- | sed -e 's/^"//' -e 's/"$//'; }

MYSQL_PWD="$(env_value DB_PASSWORD)" mysqldump --single-transaction --quick \
  -h "$(env_value DB_HOST)" -u "$(env_value DB_USERNAME)" "$(env_value DB_DATABASE)" \
  | gzip > "$OUT/db-$STAMP.sql.gz"

tar -czf "$OUT/pictures-$STAMP.tgz" -C "$APP/storage/app" public

find "$OUT" -type f \( -name 'db-*.sql.gz' -o -name 'pictures-*.tgz' \) -mtime +14 -delete
```

```bash
chmod 700 ~/bin/sisi-backup.sh
~/bin/sisi-backup.sh && ls -lh ~/backups          # run it once by hand; check the two files are not empty
```

Schedule it (SSH `crontab -e`, or cPanel "Cron Jobs"; add to the existing list, do not replace it):

```
30 3 * * * /bin/bash $HOME/bin/sisi-backup.sh >> $HOME/backups/backup.log 2>&1
```

The Laravel scheduler needs **no** cron entry: the app has no scheduled tasks, and the queue is `sync`.

Backups on the same server do not survive losing the server. Copy them off it now and then: `scp wxr46gn9f7ld@92.205.88.46:backups/db-<stamp>.sql.gz .`

Restore a dump (only with the owner's go-ahead, it overwrites the database):

```bash
gunzip -c ~/backups/db-<stamp>.sql.gz | MYSQL_PWD="..." mysql -h 127.0.0.1 -u <user> <database>
```

## 13. The DNS flip at GoDaddy

Do this only after step 11 is green. The domain's DNS is at GoDaddy and currently serves a GoDaddy "Airo" placeholder. All screen names below are GoDaddy's and (unverified): they change.

1. **Disconnect the Airo website product from the domain first.** GoDaddy, My Products: find the Airo / Websites + Marketing item attached to `sisi.news` and disconnect the domain from it (or cancel that product). While the domain is connected to it, GoDaddy may keep managing the DNS records itself and refuse or undo an edit.
2. **DNS records** (Domain, DNS):
   - `A` record, name `@`: change the value to `92.205.88.46`.
   - `www`: a `CNAME` record named `www` pointing to `@` (or an `A` record to the same IP).
   - Delete any `AAAA` (IPv6) record that points at GoDaddy's hosting: the server's address above is IPv4 only (unverified that it has no IPv6), and a leftover `AAAA` would send some visitors to the placeholder.
   - Remove domain forwarding and any parked-page setting for the domain, if present.
   - Leave nameservers on GoDaddy's defaults. Leave `MX` and `TXT` records alone unless you know what they do.
3. **Check propagation**, from this machine:

```bash
nslookup sisi.news 8.8.8.8        # should answer 92.205.88.46
nslookup sisi.news 1.1.1.1
nslookup www.sisi.news 8.8.8.8
```

   With a 600 second TTL most resolvers follow within minutes; some take up to the old TTL. Remove the line you added to the `hosts` file so you see what visitors see, then repeat the smoke tests.

## 14. SSL (AutoSSL) and the HTTPS switches

AutoSSL needs the name to reach this server first, so it comes after step 13.

1. cPanel, "SSL/TLS Status": tick `sisi.news` and `www.sisi.news` and click **Run AutoSSL** (unverified screen names; the host runs AutoSSL by itself about daily too). Wait until both names show a valid certificate. If it says it cannot validate, DNS has not propagated or `.well-known` is blocked; the app's `.htaccess` serves existing files directly, so a validation file under `public/.well-known/` is reachable.
2. Open `https://sisi.news` in a browser and confirm the padlock and the site.
3. Only now, edit `.env` on the server:

```
APP_URL=https://sisi.news
APP_FORCE_HTTPS=true
SESSION_SECURE_COOKIE=true
```

```bash
php artisan optimize:clear && php artisan optimize
```

   `APP_FORCE_HTTPS=true` makes every generated link https; no code change is needed (Verified here: `config('app.force_https')`, covered by `SecurityHeadersTest`). The site also starts sending `Strict-Transport-Security` and the `upgrade-insecure-requests` policy by itself once a request arrives over https in production.
4. **Redirect http to https.** The switches above change the links the app writes, not what happens when someone types `http://sisi.news`. Turn on cPanel's "Force HTTPS Redirect" for the domain (cPanel "Domains" page; unverified), or add this block at the **top** of `~/sisi.news/public/.htaccess`, above the existing rules:

```apache
# http -> https, except for certificate validation
RewriteEngine On
RewriteCond %{HTTPS} off
RewriteCond %{REQUEST_URI} !^/\.well-known/
RewriteRule ^ https://%{HTTP_HOST}%{REQUEST_URI} [L,R=301]
```

   If you also want one canonical host, add a `www` to apex redirect the same way; the app itself does not redirect `www.sisi.news`.
5. Repeat the smoke tests over https, and `curl -sI https://sisi.news/ar | grep -i strict-transport`.

If anything breaks after step 3 (login loops, mixed content), set the three values back to `http://...`, `false`, `false`, run `php artisan optimize:clear && php artisan optimize`, and look at `storage/logs/laravel.log`.

## Updating later

The scripted way (`deploy/build-release.sh` + `deploy/remote-install.sh`, see `deploy/README.md`) does all of this; this section is the manual fallback.

1. On this machine: tests green, `npm run build` (only if CSS or JS changed), build the package as in step 1 **and add** `--exclude='./public/.htaccess'`, so the PHP-version handler cPanel wrote there survives. If the `.htaccess` itself changed, apply that change by hand.
2. On the server, **before touching anything**: `~/bin/sisi-backup.sh`, and keep the code you are replacing: `tar -czf ~/releases/code-before-$(date +%Y%m%d-%H%M).tgz --exclude=storage --exclude=vendor -C ~ sisi.news`.
3. `php artisan down` (readers see a 503 page), unpack the new package over `~/sisi.news` with `tar -xzf ... -C ~/sisi.news`, then:

```bash
cd ~/sisi.news
php -d memory_limit=512M "$(which composer)" install --no-dev --optimize-autoloader --no-interaction   # only if composer.lock changed
php artisan migrate --force
php artisan db:seed --force          # reference data: sections (only into an empty table), the five pages (never overwritten) and Egypt's 27 governorates
php artisan filament:assets
php artisan optimize:clear && php artisan optimize
php artisan up
```

4. Smoke tests again (step 11 table). Keep the last two tarballs in `~/releases`, delete older ones.

## Rollback

Decide quickly; the sooner the better.

- **A bad release, no migration ran**: `php artisan down`; unpack the previous tarball from `~/releases` over `~/sisi.news` (same command as the upgrade); if `composer.lock` differs, run `composer install --no-dev --optimize-autoloader` again; `php artisan optimize:clear && php artisan optimize`; `php artisan up`.
- **A bad release, a migration ran**: do the same, and also restore the database from the dump taken just before the update (step 12, "Restore a dump"). Do not use `migrate:rollback` as a shortcut: restore the dump instead, which cannot be half done.
- **A bad `.env` change**: put the old value back, `php artisan optimize:clear && php artisan optimize`.
- **Back to the placeholder (the DNS flip itself)**: in GoDaddy DNS put the records back to the values you wrote down in step 0.2 and reconnect the Airo product if you disconnected it. With a 600 second TTL the old site returns within minutes to hours.
- **Site taken down on purpose**: `php artisan down --retry=60` shows a 503 "back soon" page; `php artisan up` ends it. The panel is down as well while in maintenance mode.

## Unverified items in one list

- That the CLI PHP is 8.3, that composer exists on the host, and that the CLI may exceed 128 MB for composer.
- The PHP extensions on the host (check with step 2), especially `intl` and `gd` with WebP.
- That the host lets the app create the `public/storage` symlink.
- That cPanel's MultiPHP Manager writes its handler into `public/.htaccess`, and the exact cPanel and GoDaddy screen names.
- That the cPanel database user and name prefix is the account name.
- That PHP runs as the account user (so `755`/`644` is enough).
- GoDaddy's behaviour when a domain is still connected to the Airo product, and its minimum TTL.
- `sisi:seed-launch-content` against the server (written elsewhere; only its `--help` text was read).
- `php artisan optimize` (route and view caching) on the server: not run from this machine.
