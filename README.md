# sisi.news

A bilingual news portal (Arabic first, English second) with a staff-only CMS.

- **Public site**: read-only, modelled on businesshubme.com. Hot-news ticker, section navigation, article pages with share / print / font-size tools, tags, related stories, Most Read and Latest sidebars, a footer newsletter box. Right-to-left done properly for Arabic. A Live page (`/ar/live`, `/en/live`) embeds YouTube or Vimeo broadcasts, filterable by governorate; nothing loads from those services until the reader presses play.
- **Staff CMS** at `/admin` ([Filament](https://filamentphp.com) 5): journalists write in the sections an admin assigns them, the Editor-in-chief reviews, publishes and runs live broadcasts, admins also manage accounts and each journalist's sections.
- **Not in v1, on purpose**: reader accounts, public login or registration, comments, social login, outgoing email (so no password-reset-by-email either). The only login is the staff panel, and an admin creates every account.

Laravel 13, PHP 8.3, Filament 5 on Livewire 4, Tailwind 4 with Vite. MySQL 8.4 locally, MariaDB 10.11 in production. `CLAUDE.md` holds the architecture decisions and is the contract for anyone changing the code.

## Local setup (Windows, Git Bash)

PHP 8.3 is **not** the default `php` on this machine (7.4 is), so name it explicitly. Laragon also needs the `gd` extension with WebP, `intl`, `mbstring`, `fileinfo` and `pdo_mysql` enabled.

```bash
PHP83="/c/laragon/bin/php/php-8.3.30-Win32-vs16-x64/php.exe"

"$PHP83" /c/laragon/bin/composer/composer.phar install
cp .env.example .env
"$PHP83" artisan key:generate
```

`.env.example` is the **production** template (`APP_ENV=production`, `APP_DEBUG=false`; `DemoContentSeeder` refuses to run in production). For local work edit `.env`: `APP_ENV=local`, `APP_DEBUG=true`, `LOG_LEVEL=debug`, `DB_CONNECTION=mysql`, `DB_DATABASE=sisi_news`, `DB_USERNAME=root`, an empty `DB_PASSWORD` (Laragon default), `APP_URL=http://localhost:8000`, `APP_LOCALE=ar`. Create the database first (utf8mb4), for example in HeidiSQL or phpMyAdmin:

```sql
CREATE DATABASE sisi_news CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
```

```bash
"$PHP83" artisan migrate
"$PHP83" artisan db:seed                              # reference data only: the six sections, the five static pages, Egypt's 27 governorates
"$PHP83" artisan db:seed --class=DemoContentSeeder    # DEV ONLY: demo staff, tags and 18 stories with pictures
"$PHP83" artisan storage:link                         # lets the browser see uploaded and demo pictures
npm install
npm run build                                         # or `npm run dev` while working on CSS/JS
"$PHP83" artisan serve
```

Open <http://localhost:8000> (redirects to `/ar`, or `/en` if the browser prefers English) and <http://localhost:8000/admin>.

`DemoContentSeeder` can be re-run safely: it recognises its accounts, tags and stories and creates nothing twice. It refuses to run when `APP_ENV=production`. It draws its own hero pictures with GD (no downloads) into `storage/app/public/articles/demo/`, stamped with the current `public/brand/icon.png`.

### Demo logins (local only)

Password for every account: `password`

| Role | Email | Use it to |
| --- | --- | --- |
| Admin | `admin@sisi.test` | manage accounts and journalists' sections, everything the Editor-in-chief can do |
| Editor-in-chief | `editor@sisi.test` | review the queue, approve, request changes, publish, run live broadcasts |
| Journalist | `journalist@sisi.test` | owns drafts and a story waiting in the review queue |
| Journalist | `journalist2@sisi.test` | owns a story an editor sent back with a note, and shows what one journalist cannot touch of another's |

The demo journalists are given all six sections.

### The first real admin (production)

Production has no registration and no demo accounts. Create the first admin on the server:

```bash
php artisan sisi:create-admin you@example.com --name="Your Name" --name-ar="اسمك"
```

It asks for the password without echoing it (twelve characters or more, not containing the word "password") and never prints it. Passing `--password=...` works too but leaves the password in shell history. If the account already exists the command refuses; add `--reset-password` to give an existing admin a new password (this also reactivates a deactivated admin and never touches non-admin accounts).

## Roles and the editorial workflow

| Role | Can |
| --- | --- |
| Journalist | Write stories only in the sections an admin assigned (none = cannot start a story); edit their own text while it is a draft or has changes requested and the section is still theirs; submit it for review. Never publishes. |
| Editor-in-chief (stored role `editor`) | Everything a journalist can, on any story and in every section, plus approve, request changes, unpublish, reopen, delete, publish in one step ("Publish now"), static pages and live broadcasts |
| Admin | Everything the Editor-in-chief can, plus staff accounts, each journalist's sections, and restoring or permanently deleting stories |

A story is one language-neutral shell (section, picture, featured / breaking flags) with up to two **language versions**. Each version has its **own** status, so the Arabic text can be live while the English one is still a draft.

```
 draft ──submit──▶ in_review ──approve──▶ published ──archive──▶ archived
   ▲  ▲                │                                            │
   │  └─ changes_requested ◀── request_changes ──┘                  │
   └──────────────────────────── reopen ────────────────────────────┘
```

- A reader sees a version only when its status is `published` **and** `published_at` is not in the future. Approving with a future date is how a story is scheduled.
- Status never comes from a form field. Only `App\Editorial\EditorialWorkflow` changes it (table-driven by `App\Editorial\Transition`), `ArticleTranslationPolicy` decides who may act, and every move is written to `article_status_changes`.
- Breaking news = `is_breaking` plus an optional `breaking_until`; the ticker shows only published, unexpired ones.

Writing, reviewing, scheduling and breaking news in plain language: [docs/STAFF-GUIDE.md](docs/STAFF-GUIDE.md).

## Tests

```bash
"$PHP83" artisan test --compact
```

Runs on in-memory SQLite and never touches the dev database. Do not run `migrate:fresh` or `db:wipe` against the dev database, and do not `config:cache` locally.

## Project layout

```
app/Editorial/            EditorialWorkflow, Transition (the only place status changes)
app/Enums/                Locale, Role, TranslationStatus, LiveProvider, LiveStatus
app/Models/               Article, ArticleTranslation, Category, Tag, User, Page, Governorate, LiveStream, ...
app/Policies/             who may act on stories and language versions
app/Services/             Most Read, breaking ticker, view counter, story queries, LiveBroadcasts (Live page, live strip, menu dot)
app/Support/              Slugger, HtmlSanitizer, hero image processing, SEO helpers
app/Http/                 public controllers (Controllers/Site), middleware, requests
app/Filament/             the /admin CMS (resources, pages, widgets)
app/Console/Commands/     sisi:create-admin, sisi:seed-launch-content
config/site.php           brand name, tagline, social links, timezone
config/purify.php         the allow-list for article HTML
database/migrations/      schema
database/seeders/         CategorySeeder, PageSeeder, GovernorateSeeder (reference data, run by db:seed); LaunchContentSeeder, DemoContentSeeder (by name only)
resources/views/          public site and admin tweaks
resources/css, js/        Tailwind 4 entry points built by Vite
routes/web.php            every public route lives under /ar or /en
public/brand/             logo.png and icon.png, the current brand
docs/                     staff guide and deployment notes
tests/                    Feature and Unit
```

## Deployment

See `deploy/README.md` (the scripts and what was actually done on the server) and `docs/DEPLOYMENT.md` (the original step-by-step runbook). The one rule: on the server the app lives outside the web root (`~/sisi.news/`, document root `~/sisi.news/public`), never inside `public_html`.
