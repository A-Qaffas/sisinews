<?php

namespace App\Filament\Resources\Pages\Support;

use App\Enums\Locale;
use App\Models\Page;
use App\Models\PageTranslation;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;

/**
 * Reads and writes a page and its language rows for the staff form. The slug is written on creation only:
 * `update()` never looks at it, so a crafted request cannot rename a page and break its public links.
 */
final class PageWriter
{
    private const SHELL_FIELDS = ['sort_order', 'show_in_footer'];

    /** @return array<string, array{title: string, body: string, meta_description: ?string, is_published: bool}> locale value => form state */
    public static function formState(Page $page): array
    {
        $state = [];
        foreach ($page->translations as $translation) {
            $state[$translation->locale->value] = [
                'title' => $translation->title,
                'body' => $translation->body,
                'meta_description' => $translation->meta_description,
                'is_published' => $translation->is_published,
            ];
        }

        return $state;
    }

    /** @param  array<string, mixed>  $data  form state: slug, sort_order, show_in_footer and one array per locale */
    public static function create(array $data): Page
    {
        return DB::transaction(function () use ($data): Page {
            $page = Page::query()->create(Arr::only($data, ['slug', ...self::SHELL_FIELDS]));
            self::saveTranslations($page, $data);

            return $page;
        });
    }

    /** @param  array<string, mixed>  $data */
    public static function update(Page $page, array $data): Page
    {
        return DB::transaction(function () use ($page, $data): Page {
            $page->update(Arr::only($data, self::SHELL_FIELDS));
            self::saveTranslations($page, $data);

            return $page;
        });
    }

    /**
     * One row per language that has a title. A language without one is simply not written (the form refuses a
     * blank title for any language that already exists), so nothing is ever deleted from here.
     *
     * @param  array<string, mixed>  $data
     */
    private static function saveTranslations(Page $page, array $data): void
    {
        foreach (Locale::cases() as $locale) {
            $version = $data[$locale->value] ?? [];
            $title = trim((string) ($version['title'] ?? ''));
            if ($title === '') {
                continue;
            }

            $meta = trim((string) ($version['meta_description'] ?? ''));
            $attributes = [
                'title' => $title,
                // The model sanitizes the body on assignment.
                'body' => (string) ($version['body'] ?? ''),
                'meta_description' => $meta === '' ? null : $meta,
                'is_published' => (bool) ($version['is_published'] ?? true),
            ];

            $existing = PageTranslation::query()->where('page_id', $page->getKey())->where('locale', $locale->value)->first();

            $existing === null
                ? PageTranslation::query()->create([...$attributes, 'page_id' => $page->getKey(), 'locale' => $locale])
                : $existing->update($attributes);
        }
    }
}
