<?php

namespace App\Services;

use App\Enums\Locale;
use App\Enums\TranslationStatus;
use App\Models\ArticleTranslation;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * Sums article_daily_views over the last $days for visible translations in $locale and
 * returns the top $limit, most viewed first, each with `article.category.translations`
 * already eager-loaded. The ranking (the expensive part) is cached as plain ids; the
 * models are re-read so an article unpublished meanwhile drops out immediately.
 */
final class MostRead
{
    /** @return Collection<int, ArticleTranslation> */
    public function forLocale(Locale $locale, int $days = 7, int $limit = 5): Collection
    {
        $ids = Cache::remember(
            "site:most-read:{$locale->value}:{$days}:{$limit}",
            (int) config('site.cache.most_read'),
            fn () => $this->rankedIds($locale, $days, $limit),
        );

        return StoryQuery::hydrate($locale, $ids);
    }

    /** @return list<int> */
    private function rankedIds(Locale $locale, int $days, int $limit): array
    {
        return DB::table('article_daily_views as v')
            ->join('article_translations as t', 't.id', '=', 'v.article_translation_id')
            ->join('articles as a', 'a.id', '=', 't.article_id')
            ->where('v.day', '>=', now()->subDays(max($days, 1) - 1)->toDateString())
            ->where('t.locale', $locale->value)
            ->where('t.status', TranslationStatus::Published->value)
            ->where('t.published_at', '<=', now())
            ->whereNull('a.deleted_at')
            ->groupBy('v.article_translation_id')
            ->orderByRaw('SUM(v.views) DESC')
            ->orderByRaw('MAX(t.published_at) DESC')
            ->orderByDesc('v.article_translation_id')
            ->limit($limit)
            ->pluck('v.article_translation_id')
            ->map(fn ($id) => (int) $id)
            ->all();
    }
}
