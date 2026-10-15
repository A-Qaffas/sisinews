<?php

namespace App\Services;

use App\Enums\Locale;
use App\Models\ArticleTranslation;
use Closure;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;

/**
 * The one definition of "a story a reader may see in a list": visible, in the reader's
 * language, and whose article shell still exists (a soft-deleted article leaves its
 * translations behind, and a card cannot render without its article).
 */
final class StoryQuery
{
    /** Everything a list needs, without the body (a long text column nobody reads on a home page). */
    public const COLUMNS = [
        'id', 'article_id', 'locale', 'title', 'slug', 'excerpt', 'hero_caption',
        'meta_description', 'status', 'published_at', 'created_at', 'updated_at',
    ];

    public const RELATIONS = ['article.category.translations', 'article.author'];

    /** @param  Closure(Builder): mixed|null  $article  extra constraint on the article shell */
    public static function visible(Locale $locale, ?Closure $article = null): Builder
    {
        return ArticleTranslation::query()
            ->select(self::COLUMNS)
            ->visible()
            ->inLocale($locale)
            ->whereHas('article', $article);
    }

    /** Newest first, with a stable tie-break so equal timestamps never reshuffle between requests. */
    public static function newestFirst(Builder $query): Builder
    {
        return $query->orderByDesc('published_at')->orderByDesc('id');
    }

    /**
     * Loads the given translations with their card relations, in the given order.
     * Ids that are no longer visible (unpublished while a cached list was alive) are dropped.
     *
     * @param  list<int>  $ids
     * @return Collection<int, ArticleTranslation>
     */
    public static function hydrate(Locale $locale, array $ids): Collection
    {
        if ($ids === []) {
            return new Collection;
        }

        $byId = self::visible($locale)->with(self::RELATIONS)->whereIn('id', $ids)->get()->keyBy('id');

        return new Collection(array_values(array_filter(array_map(fn (int $id) => $byId->get($id), $ids))));
    }
}
