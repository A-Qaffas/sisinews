<?php

namespace Tests\Feature\Site;

use App\Enums\LiveStatus;
use App\Enums\Locale;
use App\Models\Category;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Facades\Schema;
use Tests\Feature\Site\Concerns\BuildsLiveBroadcasts;
use Tests\Feature\Site\Services\MakesStories;
use Tests\TestCase;

class LiveHomeStripTest extends TestCase
{
    use BuildsLiveBroadcasts, MakesStories, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo(Carbon::parse('2026-01-15 12:00:00', 'UTC'));
        $this->disableSiteCache();
    }

    /** @return string the header link to the Live page */
    private function navLink(string $html): string
    {
        $this->assertSame(1, preg_match('#<a class="nav-link nav-link--live"[^>]*>.*?</a>#s', $html, $match), 'the Live link is missing from the navigation');

        return $match[0];
    }

    // --- the strip ---------------------------------------------------------------------------------

    public function test_with_nothing_live_there_is_no_strip(): void
    {
        $this->story(['title' => 'A normal story']);
        $this->broadcast(LiveStatus::Scheduled, ['en' => 'Later', 'ar' => 'لاحقًا']);
        $this->broadcast(LiveStatus::Ended, ['en' => 'Earlier', 'ar' => 'سابقًا']);

        foreach (['/ar', '/en'] as $url) {
            $this->get($url)->assertOk()
                ->assertDontSee('live-strip', false)
                ->assertDontSee('Later')->assertDontSee('Earlier');
        }
    }

    public function test_a_live_broadcast_shows_a_strip_above_the_lead_story_linking_to_the_live_page(): void
    {
        $this->story(['title' => 'Lead story headline'], locale: Locale::En);
        $stream = $this->broadcast(LiveStatus::Live, ['en' => 'Flood coverage'], $this->governorate('aswan', 'Aswan', 'أسوان'));

        $response = $this->get('/en')->assertOk();

        $response->assertSeeInOrder(['<section class="live-strip"', 'Aswan', 'Flood coverage', 'All live broadcasts', 'Lead story headline'], false);
        $response->assertSee('aria-label="Live broadcasts now"', false)
            ->assertSee('<a href="'.route('live', ['locale' => 'en']).'#stream-'.$stream->id.'">', false)
            ->assertSee('<a class="live-strip__all" href="'.route('live', ['locale' => 'en']).'">', false);
    }

    public function test_the_strip_shows_even_before_the_first_story_is_published(): void
    {
        $this->broadcast(LiveStatus::Live, ['en' => 'Day one coverage']);

        $this->get('/en')->assertOk()->assertSee('live-strip', false)->assertSee('Day one coverage');
    }

    public function test_the_strip_lists_at_most_three_titles_newest_first(): void
    {
        foreach ([5, 4, 3, 2, 1] as $minutes) {
            $this->broadcast(LiveStatus::Live, ['en' => "Started {$minutes} minutes ago"], attributes: ['started_at' => now()->subMinutes($minutes)]);
        }

        $html = $this->get('/en')->assertOk()->getContent();
        preg_match('#<ul class="live-strip__list">(.*?)</ul>#s', $html, $list);

        $this->assertSame(3, substr_count($list[1], '<li>'));
        $this->assertStringContainsString('Started 1 minutes ago', $list[1]);
        $this->assertStringContainsString('Started 3 minutes ago', $list[1]);
        $this->assertStringNotContainsString('Started 4 minutes ago', $list[1]);
        $this->assertLessThan(strpos($list[1], 'Started 2 minutes ago'), strpos($list[1], 'Started 1 minutes ago'));
    }

    public function test_the_strip_names_the_governorate_or_says_egypt_wide(): void
    {
        $this->broadcast(LiveStatus::Live, ['en' => 'Local one', 'ar' => 'محلي'], $this->governorate('luxor', 'Luxor', 'الأقصر'), ['started_at' => now()->subMinutes(2)]);
        $this->broadcast(LiveStatus::Live, ['en' => 'National one', 'ar' => 'قومي'], attributes: ['started_at' => now()->subMinute()]);

        $this->get('/en')->assertOk()
            ->assertSee('<span class="live-strip__where">Luxor</span>', false)
            ->assertSee('<span class="live-strip__where">Egypt-wide</span>', false);
        $this->get('/ar')->assertOk()
            ->assertSee('<span class="live-strip__where">الأقصر</span>', false)
            ->assertSee('<span class="live-strip__where">على مستوى مصر</span>', false);
    }

    public function test_the_arabic_strip_is_arabic_and_right_to_left(): void
    {
        $this->broadcast(LiveStatus::Live, ['ar' => 'تغطية مباشرة'], $this->governorate('giza', 'Giza', 'الجيزة'));

        $this->get('/ar')->assertOk()
            ->assertSee('<html lang="ar" dir="rtl">', false)
            ->assertSee('<section class="live-strip" aria-label="البث المباشر الآن">', false)
            ->assertSee('<span class="live-dot live-dot--pulse" aria-hidden="true"></span>مباشر</p>', false)
            ->assertSee('تغطية مباشرة')
            ->assertSee('الجيزة')
            ->assertSee('كل البث المباشر');
    }

    public function test_a_title_missing_in_the_page_language_is_shown_in_the_other_and_marked(): void
    {
        $this->broadcast(LiveStatus::Live, ['en' => 'English only']);

        $this->get('/ar')->assertOk()->assertSee('<span class="live-strip__title" lang="en" dir="ltr">English only</span>', false);
    }

    public function test_strip_text_is_escaped(): void
    {
        $this->broadcast(LiveStatus::Live, ['en' => '<script>alert(1)</script>'], $this->governorate('odd', '<b>Odd</b>', 'غريب'));

        $html = $this->get('/en')->assertOk()->getContent();

        $this->assertStringNotContainsString('<script>alert(1)</script>', $html);
        $this->assertStringNotContainsString('<b>Odd</b>', $html);
        $this->assertStringContainsString('&lt;script&gt;alert(1)&lt;/script&gt;', $html);
    }

    public function test_the_strip_is_only_on_the_home_page(): void
    {
        $this->broadcast(LiveStatus::Live, ['en' => 'On air']);

        $this->get('/en/live')->assertOk()->assertDontSee('live-strip', false);
    }

    // --- the 30 second cache -----------------------------------------------------------------------

    public function test_the_default_cache_lifetime_is_thirty_seconds(): void
    {
        $this->assertSame(30, (include base_path('config/site.php'))['cache']['live']);
    }

    public function test_a_broadcast_that_ended_disappears_within_thirty_seconds_and_a_new_one_appears_as_fast(): void
    {
        config(['site.cache.live' => 30]);
        $stream = $this->broadcast(LiveStatus::Live, ['en' => 'On air now']);

        $this->get('/en')->assertSee('live-strip', false);
        $this->assertStringContainsString('live-dot--pulse', $this->navLink($this->get('/en')->getContent()));

        $stream->forceFill(['status' => LiveStatus::Ended, 'ended_at' => now()])->save();

        $this->travel(20)->seconds();
        $this->get('/en')->assertSee('live-strip', false);

        $this->travel(11)->seconds();
        $after = $this->get('/en')->assertOk();
        $after->assertDontSee('live-strip', false);
        $this->assertStringNotContainsString('live-dot', $this->navLink($after->getContent()));

        $this->broadcast(LiveStatus::Live, ['en' => 'Another one']);
        $this->get('/en')->assertDontSee('live-strip', false);
        $this->travel(31)->seconds();
        $this->get('/en')->assertSee('live-strip', false)->assertSee('Another one');
    }

    public function test_the_two_languages_are_cached_separately(): void
    {
        config(['site.cache.live' => 30]);
        $this->broadcast(LiveStatus::Live, ['en' => 'English name', 'ar' => 'اسم عربي']);

        $this->get('/en')->assertSee('English name');
        $this->get('/ar')->assertSee('اسم عربي')->assertDontSee('English name');
    }

    // --- the navigation link -----------------------------------------------------------------------

    public function test_the_nav_always_has_a_live_link_and_the_dot_only_while_something_is_live(): void
    {
        foreach (['/ar' => 'مباشر', '/en' => 'Live'] as $url => $label) {
            $quiet = $this->navLink($this->get($url)->assertOk()->getContent());
            $this->assertStringContainsString('href="'.route('live', ['locale' => ltrim($url, '/')]).'"', $quiet);
            $this->assertStringContainsString($label, $quiet);
            $this->assertStringNotContainsString('live-dot', $quiet);
        }

        $this->broadcast(LiveStatus::Live, ['en' => 'On air', 'ar' => 'على الهواء']);

        foreach (['/ar', '/en'] as $url) {
            $busy = $this->navLink($this->get($url)->assertOk()->getContent());
            $this->assertStringContainsString('<span class="live-dot live-dot--pulse" aria-hidden="true"></span>', $busy);
            $this->assertStringContainsString('<span class="sr-only">(', $busy, 'screen readers hear that something is live');
        }
    }

    public function test_the_live_link_comes_after_the_section_links_and_before_the_search(): void
    {
        $this->get('/en')->assertOk()->assertSeeInOrder(['nav-link" href', 'nav-link nav-link--live', 'role="search"'], false);

        Category::factory()->create();
        $this->get('/en')->assertOk()->assertSeeInOrder(['Section', 'nav-link nav-link--live'], false);
    }

    public function test_the_nav_dot_follows_a_stream_going_live_on_every_page(): void
    {
        $this->broadcast(LiveStatus::Live, ['en' => 'On air']);

        foreach (['/en/live', '/en/search?q=news', '/ar'] as $url) {
            $html = $this->get($url)->getContent();
            $this->assertStringContainsString('live-dot--pulse', $this->navLink($html), $url);
        }
    }

    public function test_phones_get_a_live_link_beside_the_menu_button_while_something_is_on_air(): void
    {
        $quick = '#<a class="live-quick" href="([^"]+)">(.*?)</a>#s';
        $this->assertSame(0, preg_match($quick, $this->get('/en/search?q=news')->getContent()), 'nothing live: no extra link');

        $this->broadcast(LiveStatus::Live, ['en' => 'On air', 'ar' => 'على الهواء']);

        foreach (['/en/search?q=news' => ['en', 'Live'], '/ar' => ['ar', 'مباشر']] as $url => [$locale, $label]) {
            $html = $this->get($url)->assertOk()->getContent();
            $this->assertSame(1, preg_match($quick, $html, $match), $url);
            $this->assertSame(route('live', ['locale' => $locale]), html_entity_decode($match[1]));
            $this->assertStringContainsString('<span class="live-dot live-dot--pulse" aria-hidden="true"></span>'.$label, $match[2]);
            $this->assertStringContainsString('<span class="sr-only">(', $match[2]);
            // It sits in the header row with the Menu button, which only exists below md.
            $this->assertSame(1, preg_match('#<div class="flex items-center gap-1 md:hidden">\s*<a class="live-quick".*?data-nav-toggle#s', $html), $url);
        }

        $this->assertStringNotContainsString('class="live-quick"', $this->get('/en/live')->getContent(), 'not on the Live page itself');
    }

    public function test_scheduled_and_ended_streams_do_not_light_the_dot(): void
    {
        $this->broadcast(LiveStatus::Scheduled, ['en' => 'Later']);
        $this->broadcast(LiveStatus::Ended, ['en' => 'Earlier']);

        $this->assertStringNotContainsString('live-dot', $this->navLink($this->get('/en')->getContent()));
    }

    public function test_the_live_link_is_marked_as_the_current_page_on_the_live_page_only(): void
    {
        $this->assertStringContainsString('aria-current="page"', $this->navLink($this->get('/en/live')->getContent()));
        $this->assertStringNotContainsString('aria-current', $this->navLink($this->get('/en')->getContent()));
    }

    public function test_error_pages_render_without_the_live_query(): void
    {
        $queries = [];
        DB::listen(function ($query) use (&$queries) {
            $queries[] = $query->sql;
        });

        $this->get('/ar/news/99999-nothing')->assertNotFound();

        $this->assertSame([], array_filter($queries, fn ($sql) => str_contains($sql, 'live_stream')), 'the minimal error layout never touches the live tables');
    }

    // --- a broken live query must not break the news --------------------------------------------------

    public function test_the_news_pages_survive_a_missing_live_table_during_a_deploy(): void
    {
        Exceptions::fake();
        Schema::drop('live_stream_translations');
        Schema::drop('live_streams');
        $this->story(['title' => 'Still readable']);

        $this->get('/ar')->assertOk()->assertSee('Still readable')->assertDontSee('live-strip', false);
        $this->get('/en')->assertOk();

        Exceptions::assertReported(QueryException::class);
    }
}
