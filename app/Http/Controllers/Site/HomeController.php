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
    /** Stories in the rotating slider at the top, the lead first. */
    private const SLIDES = 5;

    /** Cards in the row under the slider. */
    private const STRIP = 4;

    /** Featured stories older than this leave the slider to the news of the day (the lead keeps its own rule). */
    private const FEATURED_DAYS = 7;

    /** The "latest news" timeline: always the newest stories, even those also in the slider or the strip (owner, 2026-11-26). */
    private const LATEST_LIST = 10;

    private const SIDEBAR_LATEST = 6;

    private const CATEGORY_BLOCKS = 6;

    private const PER_CATEGORY = 4;

    public function __invoke(string $locale, MostRead $mostRead, LiveBroadcasts $live): View
    {
        $locale = Locale::from($locale);

        // Cached as plain ids: models do not survive the cache (unserialize is disabled), and
        // re-reading them by primary key is cheap next to the queries that pick them.
        $plan = Cache::remember(
            // v3: the latest list became the plain newest stories (2026-11-26); a plan cached by older code is never read.
            "site:home:v3:{$locale->value}",
            (int) config('site.cache.home'),
            fn () => $this->plan($locale),
        );

        $stories = StoryQuery::hydrate($locale, $this->idsIn($plan))->keyBy('id');
        $pick = fn (array $ids): Collection => (new Collection($ids))->map(fn (int $id) => $stories->get($id))->filter()->values();

        // Unpublished within the cache window: the next slide moves up rather than leaving a hole.
        $slides = $pick($plan['slides']);

        return view('site.home', [
            'locale' => $locale,
            'lead' => $slides->first(),
            'slides' => $slides,
            'strip' => $pick($plan['strip']),
            'latest' => $pick($plan['latest']),
            'sidebarLatest' => $pick($plan['sidebar']),
            'blocks' => $this->blocks($locale, $plan['blocks'], $pick),
            'mostRead' => $mostRead->forLocale($locale),
            'liveNow' => $live->liveNow($locale),
        ]);
    }

    /**
     * The slider: the lead (newest featured story, otherwise the newest), then the week's other featured stories,
     * then the newest stories. The strip continues with the newest stories not in the slider; the latest list is
     * simply the newest stories, so a story published a minute ago is always at its top.
     *
     * @return array{slides: list<int>, strip: list<int>, latest: list<int>, sidebar: list<int>, blocks: array<int, list<int>>}
     */
    private function plan(Locale $locale): array
    {
        $newest = fn ($query) => StoryQuery::newestFirst($query);

        $lead = $newest(StoryQuery::visible($locale, fn ($article) => $article->where('is_featured', true)))->value('id')
            ?? $newest(StoryQuery::visible($locale))->value('id');

        $ids = fn ($query) => $query->pluck('id')->map(fn ($id) => (int) $id)->all();

        $featured = $lead === null ? [] : $ids($newest(
            StoryQuery::visible($locale, fn ($article) => $article->where('is_featured', true))
                ->where('published_at', '>=', now()->subDays(self::FEATURED_DAYS))
                ->whereKeyNot($lead),
        )->limit(self::SLIDES - 1));

        $recent = $ids(StoryQuery::liveFirst(StoryQuery::visible($locale))
            ->limit(max(self::SLIDES + self::STRIP, self::LATEST_LIST, self::SIDEBAR_LATEST)));

        $slides = array_slice(array_values(array_unique(array_merge(
            $lead === null ? [] : [(int) $lead],
            $featured,
            $recent,
        ))), 0, self::SLIDES);
        $pool = array_values(array_diff($recent, $slides));

        return [
            'slides' => $slides,
            'strip' => array_slice($pool, 0, self::STRIP),
            'latest' => array_slice($recent, 0, self::LATEST_LIST),
            'sidebar' => array_slice($recent, 0, self::SIDEBAR_LATEST),
            'blocks' => $this->categoryPlan($locale, $slides),
        ];
    }

    /**
     * Newest stories per category, skipping those already in the slider.
     *
     * @param  list<int>  $exclude
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
                    ->whereNotIn('id', $exclude),
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
     * @param  array{slides: list<int>, strip: list<int>, latest: list<int>, sidebar: list<int>, blocks: array<int, list<int>>}  $plan
     * @return list<int>
     */
    private function idsIn(array $plan): array
    {
        return array_values(array_unique(array_merge(
            $plan['slides'],
            $plan['strip'],
            $plan['latest'],
            $plan['sidebar'],
            ...array_values($plan['blocks']),
        )));
    }
}
