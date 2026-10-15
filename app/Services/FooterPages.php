<?php

namespace App\Services;

use App\Enums\Locale;
use App\Models\PageTranslation;
use Illuminate\Support\Facades\Cache;

/**
 * The "About" links in the footer: pages flagged for the footer that are published in the reader's
 * language. Plain arrays so the cached value never holds models. The cache is dropped by the Page and
 * PageTranslation models whenever one is saved or deleted, so an editor's change shows at once.
 */
final class FooterPages
{
    /** @return list<array{title: string, slug: string}> */
    public function forLocale(Locale $locale): array
    {
        return Cache::remember(self::key($locale), (int) config('site.cache.navigation'), function () use ($locale): array {
            return PageTranslation::query()
                ->join('pages', 'pages.id', '=', 'page_translations.page_id')
                ->where('page_translations.locale', $locale->value)
                ->where('page_translations.is_published', true)
                ->where('pages.show_in_footer', true)
                ->orderBy('pages.sort_order')
                ->orderBy('pages.id')
                ->get(['page_translations.title', 'pages.slug'])
                ->map(fn (PageTranslation $row): array => ['title' => $row->title, 'slug' => $row->slug])
                ->all();
        });
    }

    public static function forget(): void
    {
        foreach (Locale::cases() as $locale) {
            Cache::forget(self::key($locale));
        }
    }

    private static function key(Locale $locale): string
    {
        return "site:footer-pages:{$locale->value}";
    }
}
