<?php

namespace App\Services;

use App\Enums\Locale;
use App\Models\CategoryTranslation;
use Illuminate\Support\Facades\Cache;

/** Primary navigation / footer section links. Plain arrays so the cached value never holds models. */
final class SiteNavigation
{
    /** @return list<array{name: string, slug: string}> */
    public function categories(Locale $locale): array
    {
        return Cache::remember("site:nav:{$locale->value}", (int) config('site.cache.navigation'), function () use ($locale) {
            return CategoryTranslation::query()
                ->join('categories', 'categories.id', '=', 'category_translations.category_id')
                ->where('category_translations.locale', $locale->value)
                ->where('categories.show_in_nav', true)
                ->orderBy('categories.sort_order')
                ->orderBy('categories.id')
                ->get(['category_translations.name', 'category_translations.slug'])
                ->map(fn (CategoryTranslation $row) => ['name' => $row->name, 'slug' => $row->slug])
                ->all();
        });
    }
}
