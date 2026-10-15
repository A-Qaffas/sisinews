<?php

namespace Tests\Feature\Site\Services;

use App\Enums\Locale;
use App\Enums\TranslationStatus;
use App\Models\ArticleTranslation;
use App\Services\BreakingTicker;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class BreakingTickerTest extends TestCase
{
    use MakesStories, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->disableSiteCache();
    }

    public function test_lists_visible_breaking_stories_newest_first(): void
    {
        $this->story(['title' => 'Older', 'published_at' => now()->subHours(3)], ['is_breaking' => true]);
        $this->story(['title' => 'Newest', 'published_at' => now()->subMinutes(5)], ['is_breaking' => true]);
        $this->story(['title' => 'Not breaking']);

        $titles = app(BreakingTicker::class)->items(Locale::Ar)->pluck('title')->all();

        $this->assertSame(['Newest', 'Older'], $titles);
    }

    public function test_an_expired_breaking_flag_is_ignored_and_a_future_expiry_is_kept(): void
    {
        $this->story(['title' => 'Expired'], ['is_breaking' => true, 'breaking_until' => now()->subMinute()]);
        $this->story(['title' => 'Still hot'], ['is_breaking' => true, 'breaking_until' => now()->addHour()]);
        $this->story(['title' => 'No expiry'], ['is_breaking' => true, 'breaking_until' => null]);

        $titles = app(BreakingTicker::class)->items(Locale::Ar)->pluck('title')->sort()->values()->all();

        $this->assertSame(['No expiry', 'Still hot'], $titles);
    }

    public function test_hidden_stories_and_other_languages_never_reach_the_ticker(): void
    {
        $breaking = ['is_breaking' => true];
        $this->story(['title' => 'Draft', 'status' => TranslationStatus::Draft, 'published_at' => null], $breaking);
        $this->story(['title' => 'In review', 'status' => TranslationStatus::InReview, 'published_at' => null], $breaking);
        $this->story(['title' => 'Scheduled', 'published_at' => now()->addHour()], $breaking);
        $this->story(['title' => 'English only'], $breaking, Locale::En);
        $deleted = $this->story(['title' => 'Deleted shell'], $breaking);
        $deleted->article->delete();

        $this->assertTrue(app(BreakingTicker::class)->items(Locale::Ar)->isEmpty());
        $this->assertSame(['English only'], app(BreakingTicker::class)->items(Locale::En)->pluck('title')->all());
    }

    public function test_respects_the_limit(): void
    {
        foreach (range(1, 5) as $i) {
            $this->story(['title' => "Flash {$i}", 'published_at' => now()->subMinutes($i)], ['is_breaking' => true]);
        }

        $this->assertCount(3, app(BreakingTicker::class)->items(Locale::Ar, limit: 3));
    }

    public function test_items_are_real_translations_that_can_build_their_own_url(): void
    {
        $story = $this->story(['title' => 'Flash'], ['is_breaking' => true]);

        $item = app(BreakingTicker::class)->items(Locale::Ar)->first();

        $this->assertInstanceOf(ArticleTranslation::class, $item);
        $this->assertSame($story->url(), $item->url());
    }

    public function test_a_cached_ticker_is_served_without_touching_the_database(): void
    {
        config(['site.cache.ticker' => 60]);
        $this->story(['title' => 'Flash'], ['is_breaking' => true]);
        $service = app(BreakingTicker::class);
        $service->items(Locale::Ar);

        $queries = $this->queriesTouching('article_translations', function () use ($service, &$titles) {
            $titles = $service->items(Locale::Ar)->pluck('title')->all();
        });

        $this->assertSame([], $queries);
        $this->assertSame(['Flash'], $titles);
    }
}
