<?php

namespace App\Http\Controllers\Site;

use App\Enums\Locale;
use App\Http\Controllers\Controller;
use App\Models\ArticleTranslation;
use App\Models\Category;
use App\Services\LiveBroadcasts;
use App\Services\MostRead;
use App\Services\StoryQuery;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;

class HomeController extends Controller
{
    private const SECONDARY = 3;

    private const LATEST_LIST = 6;

    private const SIDEBAR_LATEST = 6;

    private const CATEGORY_BLOCKS = 6;

    private const PER_CATEGORY = 4;

    public function __invoke(string $locale, MostRead $mostRead, LiveBroadcasts $live): View
    {
        $locale = Locale::from($locale);

        // Cached as plain ids: models do not survive the cache (unserialize is disabled), and
        // re-reading them by primary key is cheap next to the queries that pick them.
        $plan = Cache::remember(
            "site:home:{$locale->value}",
            (int) config('site.cache.home'),
            fn () => $this->plan($locale),
        );

        $stories = StoryQuery::hydrate($locale, $this->idsIn($plan))->keyBy('id');
        $pick = fn (array $ids): Collection => (new Collection($ids))->map(fn (int $id) => $stories->get($id))->filter()->values();

        $secondary = $pick($plan['secondary']);
        // Unpublished within the cache window: promote the next story rather than show a hole.
        $lead = $stories->get($plan['lead']) ?? $secondary->shift();

        return view('site.home', [
            'locale' => $locale,
            'lead' => $lead,
            'secondary' => $secondary,
            'latest' => $pick($plan['latest']),
            'sidebarLatest' => $pick($plan['sidebar']),
            'blocks' => $this->blocks($locale, $plan['blocks'], $pick),
            'mostRead' => $mostRead->forLocale($locale),
            'liveNow' => $live->liveNow($locale),
        ]);
    }

    /**
     * @return array{lead: int|null, secondary: list<int>, latest: list<int>, sidebar: list<int>, blocks: array<int, list<int>>}
     */
    private function plan(Locale $locale): array
    {
        $newest = fn ($query) => StoryQuery::newestFirst($query);

        $lead = $newest(StoryQuery::visible($locale, fn ($article) => $article->where('is_featured', true)))->value('id')
            ?? $newest(StoryQuery::visible($locale))->value('id');

        $recent = $newest(StoryQuery::visible($locale))
            ->limit(self::SECONDARY + self::LATEST_LIST + 1)
            ->pluck('id')
            ->map(fn ($id) => (int) $id)
            ->all();

        $pool = array_values(array_diff($recent, [$lead]));
        $secondary = array_slice($pool, 0, self::SECONDARY);

        return [
            'lead' => $lead === null ? null : (int) $lead,
            'secondary' => $secondary,
            'latest' => array_slice($pool, self::SECONDARY, self::LATEST_LIST),
            'sidebar' => array_slice($recent, 0, self::SIDEBAR_LATEST),
            'blocks' => $this->categoryPlan($locale, array_merge([$lead], $secondary)),
        ];
    }

    /**
     * Newest stories per category, skipping those already in the lead area.
     *
     * @param  list<int|null>  $exclude
     * @return array<int, list<int>> category id => translation ids
     */
    private function categoryPlan(Locale $locale, array $exclude): array
    {
        $categoryIds = Category::query()
            ->whereHas('articles.translations', fn ($q) => $q->visible()->inLocale($locale))
            ->orderBy('sort_order')
            ->orderBy('id')
            ->limit(self::CATEGORY_BLOCKS)
            ->pluck('id');

        $blocks = [];
        foreach ($categoryIds as $categoryId) {
            $ids = StoryQuery::newestFirst(
                StoryQuery::visible($locale, fn ($article) => $article->where('category_id', $categoryId))
                    ->whereNotIn('id', array_filter($exclude)),
            )->limit(self::PER_CATEGORY)->pluck('id')->map(fn ($id) => (int) $id)->all();

            if ($ids !== []) {
                $blocks[$categoryId] = $ids;
            }
        }

        return $blocks;
    }

    /**
     * @param  array<int, list<int>>  $blockIds
     * @param  callable(list<int>): Collection<int, ArticleTranslation>  $pick
     * @return list<array{name: string, url: string, stories: Collection<int, ArticleTranslation>}>
     */
    private function blocks(Locale $locale, array $blockIds, callable $pick): array
    {
        $blocks = [];

        foreach ($blockIds as $ids) {
            $stories = $pick($ids);
            $category = $stories->first()?->article->category;
            $slug = $category?->translate($locale)?->slug;

            if ($slug !== null) {
                $blocks[] = [
                    'name' => $category->nameIn($locale),
                    'url' => route('category', ['locale' => $locale->value, 'slug' => $slug]),
                    'stories' => $stories,
                ];
            }
        }

        return $blocks;
    }

    /**
     * @param  array{lead: int|null, secondary: list<int>, latest: list<int>, sidebar: list<int>, blocks: array<int, list<int>>}  $plan
     * @return list<int>
     */
    private function idsIn(array $plan): array
    {
        return array_values(array_unique(array_merge(
            $plan['lead'] === null ? [] : [$plan['lead']],
            $plan['secondary'],
            $plan['latest'],
            $plan['sidebar'],
            ...array_values($plan['blocks']),
        )));
    }
}
