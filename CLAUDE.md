# sisi.news — bilingual (Arabic/English) news site + staff CMS

Laravel 13 / PHP 8.3 / Filament 5 (Livewire 4) / Tailwind 4 + Vite. DB: MySQL 8.4 locally, MariaDB 10.11 on the server — keep migrations and queries portable (no MySQL-8-only features).
Do NOT install Laravel Boost (the stock Laravel `CLAUDE.md` asked for it; it was replaced on purpose).

## Local commands (Windows, Git Bash)
- PHP 8.3 is NOT the default `php` (7.4 is). Use: `PHP83="/c/laragon/bin/php/php-8.3.30-Win32-vs16-x64/php.exe"` then `"$PHP83" artisan ...`
- Composer: `"$PHP83" /c/laragon/bin/composer/composer.phar ...`
- Tests (in-memory SQLite, never touches the dev DB): `"$PHP83" artisan test --compact`
- Dev DB: MySQL `sisi_news` on 127.0.0.1, user root, empty password (see `.env`). Reference data: `artisan db:seed` (the six sections, seeded only into an empty table; the five static pages from `database/pages/pages.json`, created only when missing; Egypt's 27 governorates, updated in place by slug). Seeders never change rows an editor owns; data fixes for the installed site go in `deploy/one-off/` scripts piped over ssh.
- Laragon's MySQL may be gone after a session ends; restart it with `C:\laragon\bin\mysql\mysql-8.4.3-winx64\bin\mysqld.exe --defaults-file=...\my.ini` (or from Laragon).
- IDE "syntax error" diagnostics on `?->` / `Enum::Case->value` are false positives (IDE parses as PHP 7.4). Trust `"$PHP83" -l file.php`.

## Product decisions (v1) — recorded in ~/.claude/mentor/decisions/sisi-news.md
- Public site: read-only news portal modelled on businesshubme.com. Arabic is the default language (`/` → `/ar`, or `/en` if the browser prefers English). **No reader accounts, no login/registration, no comments, no social login, no outgoing email in v1.** A `/{locale}/live` page (filter `?g=<governorate slug>`) embeds live broadcasts hosted on YouTube or Vimeo; the player loads only after the visitor presses play.
- Staff only (Journalist / Editor-in-chief / Admin; the Editor-in-chief's stored role value is still `editor`, only the label changed) log in at `/admin` (Filament). Admins create accounts and assign each journalist's sections; no password-reset-by-email. The panel speaks each person's language (`users.locale`, default Arabic via `site.admin_locale`; switch in the user menu, login page or profile); every panel string is `__('English text')` with Arabic in `lang/ar.json` (`AdminTranslationsTest` fails on a missing one). The suite runs the panel in English (`phpunit.xml` `ADMIN_LOCALE=en`).
- Permissions: a role is a ready-made bundle (`Role::permissions()`, which reproduces what each role could do before permissions existed); an admin ticks/unticks single `App\Enums\Permission`s per person on the Team form, stored as the difference from the bundle (`users.permissions_granted` / `permissions_revoked`). Your own role, status and permissions are locked; `TeamGuard` keeps at least one active account with `team.manage`.
- Brand: logo + icon in `public/brand/` (navy river-and-moon mark, serif wordmark). Palette: cream background, navy ink, crimson accent. Newsletter stores addresses only.

## Architecture contract (one-way doors — do not change casually)
- `articles` = language-neutral shell (category, author, hero image, featured/breaking flags). `article_translations` = one row per language with its OWN workflow `status`. An article can be live in Arabic while English is still a draft.
- Public URL: `/{locale}/news/{article_id}-{slug}`. The id is the identity; the slug is cosmetic (Arabic slugs are kept as Unicode via `App\Support\Slugger`).
- `status` and `published_at` are NOT mass-assignable. The ONLY code that changes status is `App\Editorial\EditorialWorkflow` (table-driven by `App\Editorial\Transition`). WHO may act = `ArticlePolicy`/`ArticleTranslationPolicy` (permissions via `User::hasPermission()` — never the role directly —, ownership and, without `stories.all_sections`, the sections assigned in `category_user`, checked by `User::canWriteIn()`; someone with neither cannot start a story, and only `stories.publish` approves); WHICH move is legal = `Transition::allowedFrom()/to()`. Every move writes an `article_status_changes` audit row.
- Visible to readers = status `published` AND `published_at <= now()` (`ArticleTranslation::visible()`); a future `published_at` therefore schedules publication.
- Article bodies are staff-written HTML rendered unescaped: they must pass `App\Support\HtmlSanitizer` on write.
- Live broadcasts: no video is hosted here. Only a provider + validated id is stored (`App\Enums\LiveProvider::fromInput()`), the embed URL comes from a fixed template (`LiveProvider::embedUrl()`: youtube-nocookie.com, Vimeo `dnt=1`; Vimeo event links are refused on purpose), and status changes only through `LiveStream::goLive()/end()`. CSP `frame-src` is added only on the live route.
- App code lives outside the web root on the server (`~/sisi.news/`, docroot `~/sisi.news/public`). Never publish the app inside `public_html` (that mistake exposed `.git` on silkamarket.com).

## Hosting (GoDaddy cPanel, SSH key already authorised from this machine)
Account `wxr46gn9f7ld@92.205.88.46`, PHP 8.3, MariaDB 10.11, no Node on the server (build assets locally), 128 MB PHP memory_limit, 1 vCPU/1 GB shared with other businesses' sites. **Never run write/destructive commands on that server without the user's explicit go-ahead.** `sisi.news` resolves to this server (cPanel set DNS and AutoSSL when the domain was added) and is LIVE; `deploy/README.md` is the operational record (scripts, lessons, rollback). Do not probe the host from outside with scanner-like requests: its firewall blocks SSH from this machine for about an hour.
