# Deploying sisi.news (what was actually done, and how to repeat it)

This is the operational truth for the GoDaddy cPanel account `wxr46gn9f7ld@92.205.88.46`. It supersedes
`docs/DEPLOYMENT.md` wherever the two differ (that file was written before the host was touched).

## Where things live on the server

| Path | What |
|---|---|
| `~/sisi.news/` | the application (outside any web root). Document root of the domain is `~/sisi.news/public` |
| `~/sisi.news/.env` | secrets, mode 600, written once by `remote-first-env.sh`; never overwritten by a deploy |
| `~/sisi.news/storage/app/public/` | uploaded and imported pictures (a deploy never touches `storage/`) |
| `~/sisi.news/storage/app/force-https` | flag file: while it exists `public/.htaccess` redirects http to https and `www` to the bare domain |
| `~/sisi.news-uploads/` | the last three release tarballs (rollback) |
| `~/sisi.news-private/db.env` | the database password generated ON the server |
| `~/sisi.news-backups/` | nightly backup (`backup.sh`, cron 17:41 server time = 03:41 Cairo), 14-day retention, `backup.log` |
| database `sisinews_prod`, user `sisinews_app` | privileges on that one database only |

## The scripts (run from Git Bash in the project folder)

1. `deploy/build-release.sh <out-dir>` builds assets and a production-only `vendor/` HERE (the host has no Node and little CPU)
   and refuses to pack a release that contains secrets, dev packages or machine-local files.
2. `scp` the tarball to `~/sisi.news-uploads/`, compare `sha256sum` both sides.
3. `ssh host 'bash -s -- <tarball-name>' < deploy/remote-install.sh` unpacks, syncs (keeping `.env`, `storage/`, `public/storage`),
   runs `migrate --force`, `db:seed --force` (the six sections, the five trust pages and Egypt's 27 governorates for the Live
   page; pages are created only when missing, sections only into an empty table, governorates are updated in place by slug),
   `storage:link`, `optimize`. Safe to run again for every update.
4. First install only: `remote-first-env.sh https://sisi.news` (before the second run of step 3), then
   `php artisan sisi:create-admin <email> --name="..."` and `php artisan sisi:seed-launch-content --admin=<email>` (`--dry-run` first).
5. `deploy/remote-cron.sh` appends the backup job to the crontab WITHOUT touching the existing line (it verifies that).
6. `deploy/go-live.sh` switches https on only after a valid certificate answers (`--check` reports readiness only).
7. Style-only change (CSS/JS under `resources/`, nothing in PHP, Blade, `lang/` or migrations): a full release takes ~15 min
   on this connection, so ship only the built files. `npm run build`, then diff the server's `public/build/manifest.json`
   against the local one: every differing line must be an asset filename. Upload the new `public/build/assets/*` files
   first, the manifest as `manifest.json.new` second, compare `sha256sum` both sides, then on the server
   `cp -p manifest.json manifest.json.prev && mv manifest.json.new manifest.json` (the swap is atomic; pages read the
   manifest per request, and old asset files are left in place so a page rendered a second earlier still loads).
   The working tree keeps the change, so the next full release carries it. First used 2026-10-18 for the masthead restyle.
8. Small update (Blade templates and/or CSS/JS; nothing under `app/`, `config/`, `routes/`, `lang/`, `composer.*` or
   migrations): `deploy/remote-small-update.sh`, ~2 minutes instead of ~15. Commit first, `npm run build`, then pack ONLY
   the changed files at their project paths plus the new `public/build/assets/*` files and `public/build/manifest.json`,
   with a `SHA256SUMS` file (`sha256sum <files> > SHA256SUMS`), into `<name>.tgz`; `scp` it to `~/sisi.news-uploads/`;
   `ssh host 'bash -s -- <name>' < deploy/remote-small-update.sh`. It refuses to change anything if a checksum fails,
   saves every file it replaces to `~/sisi.news-uploads/<name>-prev/`, puts new assets in place before the manifest
   switch, renames each template into place, and recompiles the template cache (`view:clear` + `view:cache`).
   Before packing, compare the server's copies of the files you replace with the last deployed commit
   (`sha256sum` both sides) so nothing unexpected is overwritten. First used 2026-10-18 for the share-bar icons.

## Hard-won lessons (all happened on 2026-10-12/13)

- **Never probe the live host from outside with scanner-like requests** (`/.env`, `/.git/config`, ...). The host's security layer
  treats it as an attack and blocked SSH from this machine for about an hour, twice. Test private paths on a local Apache
  instead (Laragon has one) and keep SSH connections few.
- **Loopback tests on the server are meaningless.** `curl http://127.0.0.1` inside CageFS reaches a stub that answers 404 for every
  site. Test through the public address (`curl --resolve host:443:92.205.88.46 https://host/...`) from outside.
- **Never put `Require all denied` in `~/sisi.news/.htaccess`.** Apache merges a parent folder's access rules into the document root
  below it and the whole site goes offline. The root `.htaccess` that ships in the release only refuses files OUTSIDE `public/`
  (it protects against a mistaken document root, where cPanel's form pre-fills `sisi.news` instead of `sisi.news/public`).
- **cPanel's "Create A New Domain" also updates DNS and AutoSSL by itself** for a domain whose DNS is at GoDaddy: `sisi.news`
  pointed at the server and got a Let's Encrypt certificate within hours, with no GoDaddy action. Local resolvers keep the old
  answer until its TTL expires, so a machine can keep seeing the old site for a while.
- **The preview subdomain `newspreview.silka-group.com` has no DNS and is meant to be removed** (cPanel Domains > Manage > Remove
  Domain; cPanel states it does not delete the document root, which is the real site's `public/` folder).
- **A `.env` backup beside a live Laravel app is a leak** unless the web server blocks it by name pattern. Keep backups out of the web folder.
- The admin password is generated on the server and shown once; to reset it: `php artisan sisi:create-admin <email> --reset-password`.

## After deploying the sections + live-broadcasts update (2026-10-13)

1. Before the deploy (at the latest before the first Go live): add the "Live video" section to the privacy page in both
   languages. `db:seed` never changes an existing page, so this is a one-off. It writes to the live database, so only with
   the owner's go-ahead. Report first, then write (it works on the old code too, and changes nothing a second time):
   `ssh wxr46gn9f7ld@92.205.88.46 'cd ~/sisi.news && DRY_RUN=1 php' < deploy/one-off/2026-10-13-privacy-live-embeds.php`, then
   the same command without `DRY_RUN=1`. Every printed line must end in "Saved." or "Nothing to change."; a "CHECK BY HAND"
   line means the page was edited in a way the script does not recognise: paste the text from `database/pages/pages.json`
   by hand in the panel (Pages > Privacy policy, both language tabs). `pages.json` already carries it for fresh installs.
2. Sections: from this update `CategorySeeder` only seeds an empty table, so this and every later deploy leaves the
   sections' names and slugs as the panel has them. Nothing to check.
3. Deploy as usual (step 3). A few `Table '...live_streams' doesn't exist` lines may appear in `storage/logs/laravel.log`
   from the seconds between the code sync and `migrate`, and `/ar/live` can answer 500 in those seconds. They are expected.
4. Check: `/ar/live` and `/en/live` answer 200 and show 27 governorate chips; every page's menu has the Live link
   (مباشر / Live); `curl -sI https://sisi.news/ar/live` shows `frame-src https://www.youtube-nocookie.com https://player.vimeo.com`
   and `/ar` shows no `frame-src`; `/sitemap.xml` lists both Live pages; `/en/page/privacy` has the "Live video" section; in the
   panel, **Broadcasts** appears under Newsroom and Team shows the editor role as "Editor-in-chief".
5. Sections: nothing to assign today (the only journalist is the inactive, hidden desk@sisi.news byline). Every journalist
   created from now on needs at least one section in Team, or they cannot start a story.
6. Before the first real broadcast, try its link on a local copy, or press Go live only once the stream is really running
   and check that it plays on a phone and on a computer.

## After deploying the masthead-credits update (2026-10-18)

1. Deploy as usual (step 3). Its `migrate` creates the `site_settings` table. In the seconds between the code sync and
   `migrate`, pages render without the credits (they do not fail) and `storage/logs/laravel.log` may show
   `Table '...site_settings' doesn't exist` lines. They are expected.
2. Then, with the owner's go-ahead, fill in the two Arabic names (report first, then write; it never overwrites a name an
   admin has typed in the panel and changes nothing a second time):
   `ssh wxr46gn9f7ld@92.205.88.46 'cd ~/sisi.news && DRY_RUN=1 php' < deploy/one-off/2026-10-18-masthead.php`, then the
   same command without `DRY_RUN=1`. Each line ends in "Saved." or "left as it is."; a "CHECK BY HAND" line means the
   table is missing (the deploy's `migrate` did not run). Done on the live site 2026-10-18: Arabic names first, the English
   spellings (Taghreed Refaay, Nagy Abu Moghanem) by a second run the same day.
3. Check: `/ar` shows "رئيس مجلس الإدارة: تغريد رفاعي" and "رئيس التحرير: ناجي أبو مغنم" beside the logo, roles in crimson and
   names in bold ink (on a phone, two centred lines under the logo row), and in the footer's bottom bar. `/en` shows the English names the same way. Admins change any of the four
   names in the panel (Site > Site settings, admins only).

## Rollback

- Code: re-run step 3 with the previous tarball from `~/sisi.news-uploads/`.
- Style-only change (step 7): `cd ~/sisi.news/public/build && mv manifest.json.prev manifest.json`.
- Small update (step 8): `cp -a ~/sisi.news-uploads/<name>-prev/. ~/sisi.news/ && cd ~/sisi.news && php artisan view:clear && php artisan view:cache`
  (a file that was new in that update stays behind, unused, unless deleted by hand).
- https: delete `storage/app/force-https`, set `APP_FORCE_HTTPS=false` and `SESSION_SECURE_COOKIE=false` in `.env`, `php artisan config:cache`.
- Data: restore from `~/sisi.news-backups/sisi-db-*.sql.gz` and `sisi-files-*.tgz`.
