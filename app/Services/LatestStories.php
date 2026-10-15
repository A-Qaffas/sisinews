<?php

namespace App\Services;

use App\Enums\Locale;
use App\Models\ArticleTranslation;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Cache;

/**
 * The newest visible stories in $locale for sidebars. Like MostRead, only the ids are cached
 * (models do not survive the cache) and the rows are re-read, so an unpublished story drops out at once.
 */
final class LatestStories
{
    /** @return Collection<int, ArticleTranslation> */
    public function forLocale(Locale $locale, int $limit = 6): Collection
    {
        $ids = Cache::remember(
            "site:latest:{$locale->value}:{$limit}",
            (int) config('site.cache.home'),
            fn () => StoryQuery::newestFirst(StoryQuery::visible($locale))
                ->limit($limit)
                ->pluck('id')
                ->map(fn ($id) => (int) $id)
                ->all(),
        );

        return StoryQuery::hydrate($locale, $ids);
    }
}
