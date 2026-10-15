<?php

namespace Database\Seeders;

use App\Enums\Locale;
use App\Models\Page;
use App\Models\PageTranslation;
use App\Support\HtmlSanitizer;
use App\Support\PageBody;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use JsonException;
use RuntimeException;

/**
 * The launch static pages (About, Standards, Corrections, Privacy, Terms), loaded from database/pages/pages.json.
 * Reference data: safe to run on every deploy, because a page is created ONLY when its slug does not exist yet.
 * An existing page is never touched, so whatever an editor has changed since is never overwritten.
 *
 * Contract: { "pages": [ { "slug", "sort_order", "show_in_footer", "en": {title, meta_description, body},
 * "ar": { same keys } } ] }. Bodies are clean HTML; the token [[contact]] stands for the contact address
 * (see App\Support\PageBody), so a real e-mail address never belongs in the data. The whole file is checked
 * before anything is written, and a missing or invalid file fails loudly with every problem listed.
 */
class PageSeeder extends Seeder
{
    /** Longer than this is cut off by search engines; it is advice, so it only warns. The column's 300 is a hard limit. */
    private const META_ADVISED = 160;

    private const META_MAX = 300;

    /** @param  string|null  $path  content file; defaults to database/pages/pages.json */
    public function __construct(private readonly ?string $path = null) {}

    public function run(): void
    {
        $path = $this->path ?? database_path('pages/pages.json');
        $pages = $this->load($path);

        $created = 0;
        $kept = 0;

        DB::transaction(function () use ($pages, &$created, &$kept): void {
            foreach ($pages as $data) {
                if (Page::query()->where('slug', $data['slug'])->exists()) {
                    $kept++;

                    continue;
                }

                $page = Page::query()->create([
                    'slug' => $data['slug'],
                    'sort_order' => $data['sort_order'],
                    'show_in_footer' => $data['show_in_footer'],
                ]);

                foreach (Locale::cases() as $locale) {
                    PageTranslation::query()->create([
                        'page_id' => $page->getKey(),
                        'locale' => $locale,
                        'title' => $data[$locale->value]['title'],
                        'body' => $data[$locale->value]['body'],
                        'meta_description' => $data[$locale->value]['meta_description'],
                        'is_published' => true,
                    ]);
                }

                $created++;
            }
        });

        $this->command?->info("Pages: {$created} created, {$kept} already present and left untouched.");
    }

    /**
     * @return list<array{slug: string, sort_order: int, show_in_footer: bool, en: array<string, mixed>, ar: array<string, mixed>}>
     */
    private function load(string $path): array
    {
        if (! is_file($path) || ! is_readable($path)) {
            throw new RuntimeException("The pages content file was not found or cannot be read: {$path}. Expected database/pages/pages.json.");
        }

        $raw = (string) file_get_contents($path);

        try {
            // A byte-order mark (some Windows editors add one) is not valid JSON.
            $data = json_decode(ltrim($raw, "\xEF\xBB\xBF"), true, 64, JSON_THROW_ON_ERROR);
        } catch (JsonException $e) {
            throw new RuntimeException("The pages content file is not valid JSON ({$e->getMessage()}): {$path}");
        }

        $problems = [];
        $pages = $this->validate($data, $problems);

        if ($problems !== []) {
            throw new RuntimeException(
                "The pages content file is invalid and nothing was written ({$path}):\n - ".implode("\n - ", $problems)
            );
        }

        return $pages;
    }

    /**
     * @param  list<string>  $problems  filled with every problem found, each naming the page it is about
     * @return list<array<string, mixed>>
     */
    private function validate(mixed $data, array &$problems): array
    {
        if (! is_array($data) || ! isset($data['pages']) || ! is_array($data['pages']) || ! array_is_list($data['pages']) || $data['pages'] === []) {
            $problems[] = 'The file must be an object with a non-empty "pages" list.';

            return [];
        }

        $pages = [];
        $seen = [];

        foreach ($data['pages'] as $index => $page) {
            $label = 'pages['.$index.']';
            if (! is_array($page)) {
                $problems[] = "{$label} must be an object.";

                continue;
            }

            $slug = $page['slug'] ?? null;
            if (! is_string($slug) || strlen($slug) > 80 || preg_match('/^'.Page::SLUG_PATTERN.'$/', $slug) !== 1) {
                $problems[] = "{$label}: \"slug\" must be lowercase kebab-case (letters, numbers, hyphens) of at most 80 characters.";
            } else {
                $label = "page \"{$slug}\"";
                if (isset($seen[$slug])) {
                    $problems[] = "{$label} appears more than once.";
                }
                $seen[$slug] = true;
            }

            $sort = $page['sort_order'] ?? null;
            if (! is_int($sort) || $sort < 0 || $sort > 65535) {
                $problems[] = "{$label}: \"sort_order\" must be an integer from 0 to 65535.";
            }

            if (! is_bool($page['show_in_footer'] ?? null)) {
                $problems[] = "{$label}: \"show_in_footer\" must be true or false.";
            }

            foreach (Locale::cases() as $locale) {
                $this->validateVersion($label, $locale, $page[$locale->value] ?? null, $problems);
            }

            $pages[] = $page;
        }

        return $pages;
    }

    /** @param  list<string>  $problems */
    private function validateVersion(string $label, Locale $locale, mixed $version, array &$problems): void
    {
        $where = "{$label} [{$locale->value}]";
        if (! is_array($version)) {
            $problems[] = "{$where}: the \"{$locale->value}\" block is missing.";

            return;
        }

        $title = $version['title'] ?? null;
        if (! is_string($title) || trim($title) === '' || mb_strlen($title) > 255) {
            $problems[] = "{$where}: \"title\" must be text of 1 to 255 characters.";
        }

        $meta = $version['meta_description'] ?? null;
        if (! is_string($meta) || trim($meta) === '' || mb_strlen($meta) > self::META_MAX) {
            $problems[] = "{$where}: \"meta_description\" must be text of 1 to ".self::META_MAX.' characters.';
        } elseif (mb_strlen($meta) > self::META_ADVISED) {
            $this->command?->warn("{$where}: \"meta_description\" is ".mb_strlen($meta).' characters; search engines show about '.self::META_ADVISED.'.');
        }

        $body = $version['body'] ?? null;
        if (! is_string($body) || trim(strip_tags(HtmlSanitizer::clean($body))) === '') {
            $problems[] = "{$where}: \"body\" must be HTML with some text in it.";
        } elseif (preg_match('/[A-Za-z0-9._%+-]+@[A-Za-z0-9-]+\.[A-Za-z]{2,}/', $body) === 1) {
            $problems[] = "{$where}: \"body\" contains an e-mail address; write ".PageBody::CONTACT_TOKEN.' instead and set SITE_CONTACT_EMAIL.';
        }
    }
}
