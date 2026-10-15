<?php

namespace App\Services;

use App\Enums\Locale;
use App\Models\ArticleTranslation;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;

/**
 * Visible translations in $locale whose article isBreakingNow(), newest first. An empty
 * collection hides the ticker. It renders on every page, so the rows themselves are cached
 * (raw attributes, no body) and rebuilt without touching the database. Items carry only
 * their own columns: reading `$item->article` would lazy-load.
 */
final class BreakingTicker
{
    /** @return Collection<int, ArticleTranslation> */
    public function items(Locale $locale, int $limit = 8): Collection
    {
        $rows = Cache::remember(
            "site:ticker:{$locale->value}:{$limit}",
            (int) config('site.cache.ticker'),
            fn () => StoryQuery::newestFirst(StoryQuery::visible($locale, $this->breakingNow(...)))
                ->limit($limit)
                ->get()
                ->map(fn (ArticleTranslation $t) => $t->getAttributes())
                ->all(),
        );

        return ArticleTranslation::hydrate($rows);
    }

    private function breakingNow(Builder $article): void
    {
        $article->where('is_breaking', true)
            ->where(fn (Builder $q) => $q->whereNull('breaking_until')->orWhere('breaking_until', '>', now()));
    }
}
