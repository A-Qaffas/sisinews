<?php

namespace Tests\Feature\Site\Services;

use App\Enums\Locale;
use App\Enums\TranslationStatus;
use App\Models\ArticleTranslation;
use App\Services\MostRead;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class MostReadTest extends TestCase
{
    use MakesStories, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->disableSiteCache();
    }

    public function test_ranks_by_views_summed_over_the_window(): void
    {
        $steady = $this->story(['title' => 'Steady']);
        $spike = $this->story(['title' => 'Spike']);
        $quiet = $this->story(['title' => 'Quiet']);

        // Steady: 3 x 10 = 30 across three days beats Spike's single 25.
        foreach ([0, 1, 2] as $daysAgo) {
            $this->addViews($steady, 10, now()->subDays($daysAgo)->toDateString());
        }
        $this->addViews($spike, 25);
        $this->addViews($quiet, 1);

        $titles = app(MostRead::class)->forLocale(Locale::Ar)->pluck('title')->all();

        $this->assertSame(['Steady', 'Spike', 'Quiet'], $titles);
    }

    public function test_views_older_than_the_window_do_not_count(): void
    {
        $old = $this->story(['title' => 'Old glory']);
        $fresh = $this->story(['title' => 'Fresh']);
        $this->addViews($old, 1000, now()->subDays(7)->toDateString());
        $this->addViews($fresh, 3, now()->subDays(6)->toDateString());

        $titles = app(MostRead::class)->forLocale(Locale::Ar, days: 7)->pluck('title')->all();

        $this->assertSame(['Fresh'], $titles);
    }

    public function test_only_visible_stories_in_the_requested_language_are_ranked(): void
    {
        $visible = $this->story(['title' => 'Visible']);
        $draft = $this->story(['title' => 'Draft', 'status' => TranslationStatus::Draft, 'published_at' => null]);
        $archived = $this->story(['title' => 'Archived', 'status' => TranslationStatus::Archived]);
        $scheduled = $this->story(['title' => 'Scheduled', 'published_at' => now()->addDay()]);
        $english = $this->story(['title' => 'English'], locale: Locale::En);
        $deleted = $this->story(['title' => 'Deleted shell']);
        $deleted->article->delete();

        foreach ([$visible, $draft, $archived, $scheduled, $english, $deleted] as $t) {
            $this->addViews($t, 50);
        }

        $this->assertSame(['Visible'], app(MostRead::class)->forLocale(Locale::Ar)->pluck('title')->all());
        $this->assertSame(['English'], app(MostRead::class)->forLocale(Locale::En)->pluck('title')->all());
    }

    public function test_returns_at_most_the_limit_with_card_relations_loaded(): void
    {
        foreach (range(1, 4) as $i) {
            $this->addViews($this->story(['title' => "Story {$i}"]), $i);
        }

        $top = app(MostRead::class)->forLocale(Locale::Ar, limit: 2);

        $this->assertSame(['Story 4', 'Story 3'], $top->pluck('title')->all());

        $first = $top->first();
        $this->assertTrue($first->relationLoaded('article'));
        $this->assertTrue($first->article->relationLoaded('category'));
        $this->assertTrue($first->article->category->relationLoaded('translations'));
        $this->assertTrue($first->article->relationLoaded('author'));
    }

    public function test_a_tie_is_broken_by_the_newer_story(): void
    {
        $older = $this->story(['title' => 'Older', 'published_at' => now()->subDays(3)]);
        $newer = $this->story(['title' => 'Newer', 'published_at' => now()->subDay()]);
        $this->addViews($older, 5);
        $this->addViews($newer, 5);

        $this->assertSame(['Newer', 'Older'], app(MostRead::class)->forLocale(Locale::Ar)->pluck('title')->all());
    }

    public function test_nothing_viewed_yet_gives_an_empty_list(): void
    {
        $this->story();

        $this->assertTrue(app(MostRead::class)->forLocale(Locale::Ar)->isEmpty());
    }

    public function test_the_ranking_is_cached_but_unpublished_stories_still_drop_out(): void
    {
        config(['site.cache.most_read' => 300]);
        $stays = $this->story(['title' => 'Stays']);
        $goes = $this->story(['title' => 'Goes']);
        $this->addViews($stays, 5);
        $this->addViews($goes, 9);

        $service = app(MostRead::class);
        $service->forLocale(Locale::Ar);

        $rankingQueries = $this->queriesTouching('article_daily_views', function () use ($service, $goes, &$titles) {
            ArticleTranslation::query()->whereKey($goes->id)->update(['status' => TranslationStatus::Archived->value]);
            $titles = $service->forLocale(Locale::Ar)->pluck('title')->all();
        });

        $this->assertSame([], $rankingQueries, 'the aggregate query must not run again inside the cache window');
        $this->assertSame(['Stays'], $titles);
    }
}
