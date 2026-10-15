<?php

namespace Tests\Feature\Site;

use App\Enums\LiveProvider;
use App\Enums\LiveStatus;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\Feature\Site\Concerns\BuildsLiveBroadcasts;
use Tests\TestCase;

class LivePageTest extends TestCase
{
    use BuildsLiveBroadcasts, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // Winter, so Cairo is plainly UTC+2, and a fixed clock so "now" never drifts inside a test.
        $this->travelTo(Carbon::parse('2026-01-15 12:00:00', 'UTC'));
        config(['site.cache.live' => 0]);
    }

    /** @return string the inner HTML of the filter chip whose link ends with $href */
    private function chip(string $html, ?string $slug): string
    {
        $pattern = $slug === null
            ? '#<a class="live-chip" href="[^"?]*/live"[^>]*>(.*?)</a>#s'
            : '#<a class="live-chip" href="[^"]*[?]g='.preg_quote($slug, '#').'"[^>]*>(.*?)</a>#s';
        $this->assertSame(1, preg_match($pattern, $html, $match), 'no filter chip for '.($slug ?? 'All Egypt'));

        return $match[1];
    }

    // --- the page ----------------------------------------------------------------------------------

    public function test_the_page_renders_in_both_languages_with_the_right_direction_and_heading(): void
    {
        $this->get('/ar/live')->assertOk()
            ->assertSee('<html lang="ar" dir="rtl">', false)
            ->assertSee('<h1 class="section-title sn-page-title">'.__('live.title', [], 'ar').'</h1>', false)
            ->assertSee(__('live.intro', [], 'ar'));
        $this->get('/en/live')->assertOk()
            ->assertSee('<html lang="en" dir="ltr">', false)
            ->assertSee('<h1 class="section-title sn-page-title">Live</h1>', false)
            ->assertSee(__('live.intro', [], 'en'));
    }

    public function test_the_unknown_locale_is_a_404(): void
    {
        $this->get('/fr/live')->assertNotFound();
    }

    // --- the three sections ------------------------------------------------------------------------

    public function test_each_status_lands_in_its_own_section_and_only_live_streams_get_a_player(): void
    {
        $this->broadcast(LiveStatus::Live, ['en' => 'Alpha is on air']);
        $this->broadcast(LiveStatus::Scheduled, ['en' => 'Beta is coming']);
        $this->broadcast(LiveStatus::Ended, ['en' => 'Gamma has finished']);

        $html = $this->get('/en/live')->assertOk()->getContent();

        $this->assertSame(1, substr_count($html, 'data-live-player'), 'one player: the live broadcast');
        $this->assertStringContainsString('aria-label="Play live broadcast: Alpha is on air"', $html);
        $this->assertStringNotContainsString('Play live broadcast: Beta', $html);
        $this->assertStringNotContainsString('Play live broadcast: Gamma', $html);

        $this->get('/en/live')->assertSeeInOrder([
            'Live now', 'Alpha is on air', 'Coming up', 'Beta is coming', 'Recently ended', 'Gamma has finished',
        ]);
    }

    public function test_the_same_sections_have_arabic_headings(): void
    {
        $this->broadcast(LiveStatus::Live, ['ar' => 'بث الأول']);
        $this->broadcast(LiveStatus::Scheduled, ['ar' => 'بث قادم']);
        $this->broadcast(LiveStatus::Ended, ['ar' => 'بث منتهٍ']);

        $this->get('/ar/live')->assertOk()->assertSeeInOrder([
            'مباشر الآن', 'بث الأول', 'قريبًا', 'بث قادم', 'انتهى مؤخرًا', 'بث منتهٍ',
        ]);
    }

    public function test_coming_up_is_soonest_first_with_unscheduled_last_and_drops_long_missed_ones(): void
    {
        $this->broadcast(LiveStatus::Scheduled, ['en' => 'Third'], attributes: ['scheduled_at' => now()->addDays(3)]);
        $this->broadcast(LiveStatus::Scheduled, ['en' => 'No time set'], attributes: ['scheduled_at' => null]);
        $this->broadcast(LiveStatus::Scheduled, ['en' => 'First'], attributes: ['scheduled_at' => now()->addHour()]);
        $this->broadcast(LiveStatus::Scheduled, ['en' => 'Running late'], attributes: ['scheduled_at' => now()->subHours(2)]);
        $this->broadcast(LiveStatus::Scheduled, ['en' => 'Forgotten long ago'], attributes: ['scheduled_at' => now()->subHours(7)]);
        $this->broadcast(LiveStatus::Scheduled, ['en' => 'Second'], attributes: ['scheduled_at' => now()->addDay()]);

        $this->get('/en/live')->assertOk()
            ->assertSeeInOrder(['Running late', 'First', 'Second', 'Third', 'No time set'])
            ->assertDontSee('Forgotten long ago');
    }

    public function test_recently_ended_is_newest_first_limited_to_six_and_to_the_last_seven_days(): void
    {
        foreach (range(1, 8) as $i) {
            $this->broadcast(LiveStatus::Ended, ['en' => "Finished number {$i}"], attributes: ['ended_at' => now()->subHours($i * 10)]);
        }
        $this->broadcast(LiveStatus::Ended, ['en' => 'Finished last month'], attributes: ['ended_at' => now()->subDays(7)->subHour()]);
        $this->broadcast(LiveStatus::Ended, ['en' => 'Finished without a time'], attributes: ['ended_at' => null]);

        $response = $this->get('/en/live')->assertOk();

        $response->assertSeeInOrder(array_map(fn ($i) => "Finished number {$i}", range(1, 6)));
        $response->assertDontSee('Finished number 7')
            ->assertDontSee('Finished number 8')
            ->assertDontSee('Finished last month')
            ->assertDontSee('Finished without a time');
    }

    public function test_a_live_stream_started_earlier_comes_after_a_newer_one(): void
    {
        $this->broadcast(LiveStatus::Live, ['en' => 'Started long ago'], attributes: ['started_at' => now()->subHours(5)]);
        $this->broadcast(LiveStatus::Live, ['en' => 'Started just now'], attributes: ['started_at' => now()->subMinute()]);

        $this->get('/en/live')->assertSeeInOrder(['Started just now', 'Started long ago']);
    }

    public function test_each_broadcast_shows_its_governorate_title_description_and_times_in_cairo_time(): void
    {
        $cairo = $this->governorate('cairo', 'Cairo', 'القاهرة');
        $this->broadcast(LiveStatus::Live, ['en' => ['title' => 'Nile corniche', 'description' => "First line\nSecond line"]], $cairo, ['started_at' => '2026-01-15 09:05:00']);
        $this->broadcast(LiveStatus::Scheduled, ['en' => 'Evening press conference'], $cairo, ['scheduled_at' => '2026-01-16 19:30:00']);
        $this->broadcast(LiveStatus::Ended, ['en' => 'Morning briefing'], $cairo, ['ended_at' => '2026-01-14 15:15:00']);

        $this->get('/en/live')->assertOk()
            ->assertSee('Nile corniche')
            ->assertSee("First line\nSecond line", false)
            // 09:05 UTC is 11:05 in Cairo in January.
            ->assertSee('Started 15 January, 11:05 AM')
            ->assertSee('<time datetime="2026-01-15T09:05:00+00:00">', false)
            // 19:30 UTC is 21:30 in Cairo; the weekday and month come in the page language.
            ->assertSee('Friday 16 January')
            ->assertSee('9:30 PM')
            ->assertSee('Cairo time')
            ->assertSee('Ended 14 January, 5:15 PM');
    }

    public function test_times_use_arabic_month_and_day_names_on_the_arabic_page(): void
    {
        $this->broadcast(LiveStatus::Scheduled, ['ar' => 'مؤتمر المساء'], attributes: ['scheduled_at' => '2026-01-16 19:30:00']);

        $this->get('/ar/live')->assertOk()
            ->assertSee('الجمعة 16 يناير')
            ->assertSee('9:30 م')
            ->assertSee('بتوقيت القاهرة');
    }

    public function test_a_scheduled_stream_without_a_start_time_says_it_will_be_announced(): void
    {
        $this->broadcast(LiveStatus::Scheduled, ['en' => 'Someday'], attributes: ['scheduled_at' => null]);

        $this->get('/en/live')->assertOk()->assertSee('Time to be announced');
    }

    // --- governorate filter --------------------------------------------------------------------------

    private function threeRegions(): void
    {
        $cairo = $this->governorate('cairo', 'Cairo', 'القاهرة', 1);
        $alex = $this->governorate('alexandria', 'Alexandria', 'الإسكندرية', 2);
        $this->broadcast(LiveStatus::Live, ['en' => 'Cairo live', 'ar' => 'مباشر القاهرة'], $cairo);
        $this->broadcast(LiveStatus::Live, ['en' => 'Alexandria live', 'ar' => 'مباشر الإسكندرية'], $alex);
        $this->broadcast(LiveStatus::Live, ['en' => 'Egypt live', 'ar' => 'مباشر مصر']);
        $this->broadcast(LiveStatus::Scheduled, ['en' => 'Cairo later'], $cairo);
        $this->broadcast(LiveStatus::Scheduled, ['en' => 'Alexandria later'], $alex);
        $this->broadcast(LiveStatus::Ended, ['en' => 'Cairo earlier'], $cairo);
        $this->broadcast(LiveStatus::Ended, ['en' => 'Alexandria earlier'], $alex);
    }

    public function test_without_a_filter_every_governorate_and_egypt_wide_broadcast_is_listed(): void
    {
        $this->threeRegions();

        $this->get('/en/live')->assertOk()
            ->assertSee('Cairo live')->assertSee('Alexandria live')->assertSee('Egypt live')
            ->assertSee('Cairo later')->assertSee('Alexandria later')
            ->assertSee('Cairo earlier')->assertSee('Alexandria earlier');
    }

    public function test_a_governorate_filter_keeps_its_own_and_the_egypt_wide_broadcasts_in_every_section(): void
    {
        $this->threeRegions();
        $this->broadcast(LiveStatus::Scheduled, ['en' => 'Egypt later']);
        $this->broadcast(LiveStatus::Ended, ['en' => 'Egypt earlier']);

        $this->get('/en/live?g=cairo')->assertOk()
            ->assertSee('Cairo live')->assertSee('Cairo later')->assertSee('Cairo earlier')
            ->assertSee('Egypt live')->assertSee('Egypt later')->assertSee('Egypt earlier')
            ->assertDontSee('Alexandria live')->assertDontSee('Alexandria later')->assertDontSee('Alexandria earlier')
            ->assertSee('<h1 class="section-title sn-page-title">Live: Cairo</h1>', false);
    }

    public function test_egypt_wide_broadcasts_are_labelled_and_governorate_ones_carry_their_name(): void
    {
        $cairo = $this->governorate('cairo', 'Cairo', 'القاهرة');
        $this->broadcast(LiveStatus::Live, ['en' => 'Local'], $cairo);
        $this->broadcast(LiveStatus::Live, ['en' => 'National']);

        $html = $this->get('/en/live')->assertOk()->getContent();

        $this->assertMatchesRegularExpression('#<p class="live-tag">Cairo</p>\s*<h3 class="live-card__title"[^>]*>Local</h3>#', $html);
        $this->assertMatchesRegularExpression('#<p class="live-tag">Egypt-wide</p>\s*<h3 class="live-card__title"[^>]*>National</h3>#', $html);

        $this->get('/ar/live')->assertOk()->assertSee('على مستوى مصر');
    }

    public function test_in_a_governorate_view_its_own_live_streams_come_before_the_egypt_wide_ones(): void
    {
        $cairo = $this->governorate('cairo', 'Cairo', 'القاهرة');
        $this->broadcast(LiveStatus::Live, ['en' => 'Egypt wide newest'], attributes: ['started_at' => now()->subMinute()]);
        $this->broadcast(LiveStatus::Live, ['en' => 'Cairo older'], $cairo, ['started_at' => now()->subHour()]);

        $this->get('/en/live?g=cairo')->assertSeeInOrder(['Cairo older', 'Egypt wide newest']);
        $this->get('/en/live')->assertSeeInOrder(['Egypt wide newest', 'Cairo older']);
    }

    public function test_an_unknown_governorate_slug_is_ignored_and_shows_all_of_egypt(): void
    {
        $this->threeRegions();

        foreach (['/en/live?g=atlantis', '/en/live?g=', '/en/live?g[]=cairo', '/en/live?g[a]=b', '/en/live?g=CAIRO', '/en/live?g='.str_repeat('x', 500)] as $url) {
            $html = $this->get($url)->assertOk()->getContent();
            $this->assertStringContainsString('Alexandria live', $html, $url);
            $this->assertStringContainsString('Cairo live', $html, $url);
            $this->assertMatchesRegularExpression('#<a class="live-chip" href="[^"?]*/live" aria-current="page">#', $html, $url);
            $this->assertStringContainsString('<link rel="canonical" href="'.route('live', ['locale' => 'en']).'">', $html, $url);
        }
    }

    public function test_the_chips_are_all_egypt_plus_the_governorates_in_sort_order_with_the_current_one_marked(): void
    {
        $this->governorate('luxor', 'Luxor', 'الأقصر', 3);
        $this->governorate('cairo', 'Cairo', 'القاهرة', 1);
        $this->governorate('aswan', 'Aswan', 'أسوان', 2);

        $html = $this->get('/en/live?g=aswan')->assertOk()->getContent();

        preg_match_all('#<a class="live-chip" href="([^"]*)"( aria-current="page")?[^>]*>\s*([^<]*?)\s*(?:<|</a>)#s', $html, $chips, PREG_SET_ORDER);
        $this->assertSame(['All Egypt', 'Cairo', 'Aswan', 'Luxor'], array_map(fn ($c) => trim($c[3]), $chips));
        $this->assertSame(['Aswan'], array_values(array_map(fn ($c) => trim($c[3]), array_filter($chips, fn ($c) => ($c[2] ?? '') !== ''))), 'only the current chip is marked');
        $this->assertStringContainsString('href="'.route('live', ['locale' => 'en', 'g' => 'luxor']).'"', $html);
        $this->assertStringContainsString('<nav class="live-filter" aria-label="Governorate">', $html);

        $this->get('/ar/live')->assertSee('كل مصر')->assertSee('القاهرة')->assertSee('أسوان');
    }

    public function test_a_dot_marks_the_chips_that_have_a_live_broadcast_now(): void
    {
        $cairo = $this->governorate('cairo', 'Cairo', 'القاهرة', 1);
        $alex = $this->governorate('alexandria', 'Alexandria', 'الإسكندرية', 2);
        $this->governorate('luxor', 'Luxor', 'الأقصر', 3);
        $this->broadcast(LiveStatus::Live, ['en' => 'Cairo live'], $cairo);
        $this->broadcast(LiveStatus::Scheduled, ['en' => 'Alexandria later'], $alex);
        $this->broadcast(LiveStatus::Ended, ['en' => 'Luxor earlier'], $this->governorate('minya', 'Minya', 'المنيا', 4));

        $html = $this->get('/en/live')->assertOk()->getContent();

        $this->assertStringContainsString('live-dot', $this->chip($html, 'cairo'));
        $this->assertStringContainsString('live-dot', $this->chip($html, null), 'All Egypt has a dot while anything is live');
        $this->assertStringNotContainsString('live-dot', $this->chip($html, 'alexandria'), 'scheduled is not live');
        $this->assertStringNotContainsString('live-dot', $this->chip($html, 'luxor'));
        $this->assertStringNotContainsString('live-dot', $this->chip($html, 'minya'), 'ended is not live');
    }

    public function test_the_dots_do_not_depend_on_the_selected_filter(): void
    {
        $cairo = $this->governorate('cairo', 'Cairo', 'القاهرة', 1);
        $this->governorate('luxor', 'Luxor', 'الأقصر', 2);
        $this->broadcast(LiveStatus::Live, ['en' => 'Cairo live'], $cairo);

        $html = $this->get('/en/live?g=luxor')->assertOk()->getContent();

        $this->assertStringContainsString('live-dot', $this->chip($html, 'cairo'));
        $this->assertStringNotContainsString('live-dot', $this->chip($html, 'luxor'));
    }

    public function test_an_egypt_wide_broadcast_puts_a_dot_on_all_egypt_only(): void
    {
        $this->governorate('cairo', 'Cairo', 'القاهرة', 1);
        $this->broadcast(LiveStatus::Live, ['en' => 'Nationwide']);

        $html = $this->get('/en/live')->assertOk()->getContent();

        $this->assertStringContainsString('live-dot', $this->chip($html, null));
        $this->assertStringNotContainsString('live-dot', $this->chip($html, 'cairo'));
    }

    // --- language fallback ---------------------------------------------------------------------------

    public function test_a_missing_translation_falls_back_to_the_other_language_and_says_so(): void
    {
        $this->broadcast(LiveStatus::Live, ['en' => ['title' => 'English only title', 'description' => 'English only text']]);

        $html = $this->get('/ar/live')->assertOk()->getContent();

        $this->assertStringContainsString('<h3 class="live-card__title" lang="en" dir="ltr">English only title</h3>', $html);
        $this->assertStringContainsString('<p class="live-desc" lang="en" dir="ltr">English only text</p>', $html);
        // The player label, read aloud by a screen reader, carries the same title.
        $this->assertStringContainsString('English only title', $html);

        $this->get('/en/live')->assertOk()->assertSee('<h3 class="live-card__title">English only title</h3>', false);
    }

    public function test_a_blank_title_or_description_falls_back_field_by_field(): void
    {
        $this->broadcast(LiveStatus::Live, [
            'ar' => ['title' => 'عنوان عربي', 'description' => ''],
            'en' => ['title' => 'English title', 'description' => 'English description'],
        ]);
        $this->broadcast(LiveStatus::Scheduled, [
            'ar' => ['title' => ''],
            'en' => ['title' => 'Only English upcoming'],
        ]);

        $html = $this->get('/ar/live')->assertOk()->getContent();

        $this->assertStringContainsString('<h3 class="live-card__title">عنوان عربي</h3>', $html, 'own title wins');
        $this->assertStringContainsString('<p class="live-desc" lang="en" dir="ltr">English description</p>', $html);
        $this->assertStringContainsString('<h3 class="live-row__title" lang="en" dir="ltr">Only English upcoming</h3>', $html);
    }

    public function test_a_broadcast_with_no_title_in_any_language_still_shows_with_a_generic_name(): void
    {
        $this->broadcast(LiveStatus::Live, []);

        $this->get('/en/live')->assertOk()->assertSee('Live broadcast')->assertSee('data-live-player', false);
        $this->get('/ar/live')->assertOk()->assertSee('بث مباشر');
    }

    // --- the privacy facade --------------------------------------------------------------------------

    public function test_the_initial_html_loads_nothing_from_the_video_services(): void
    {
        $this->broadcast(LiveStatus::Live, ['en' => 'First stream']);
        $this->broadcast(LiveStatus::Live, ['en' => 'Second stream'], attributes: ['provider' => LiveProvider::Vimeo, 'video_ref' => '123456789']);

        $html = $this->get('/en/live')->assertOk()->getContent();

        $this->assertStringNotContainsString('<iframe', $html);
        $this->assertDoesNotMatchRegularExpression('#<(img|script|link|source|video|audio)\b[^>]*(youtube|ytimg|vimeo)#i', $html, 'no remote thumbnail, script or stylesheet');

        // The provider hosts may only appear in the data attributes and in the watch-on-provider links.
        $rest = preg_replace(['#\sdata-embed="[^"]*"#', '#<a class="live-player__watch"[^>]*>.*?</a>#s'], '', $html);
        $this->assertDoesNotMatchRegularExpression('#youtube|youtu\.be|ytimg|vimeo#i', $rest);
    }

    public function test_the_embed_address_is_exactly_the_one_the_model_builds(): void
    {
        $youtube = $this->broadcast(LiveStatus::Live, ['en' => 'First video']);
        $vimeo = $this->broadcast(LiveStatus::Live, ['en' => 'Second video'], attributes: ['provider' => LiveProvider::Vimeo, 'video_ref' => '123456789']);

        $html = $this->get('/en/live')->assertOk()->getContent();

        preg_match_all('#data-embed="([^"]*)"#', $html, $found);
        $embeds = array_map(fn ($value) => html_entity_decode($value, ENT_QUOTES | ENT_HTML5), $found[1]);

        $this->assertEqualsCanonicalizing([$youtube->embedUrl(), $vimeo->embedUrl()], $embeds);
        $this->assertContains('https://www.youtube-nocookie.com/embed/'.$youtube->video_ref.'?autoplay=1&rel=0&playsinline=1', $embeds);
        $this->assertContains('https://player.vimeo.com/video/123456789?autoplay=1&dnt=1', $embeds);
    }

    public function test_a_hostile_video_reference_cannot_break_out_of_the_attribute(): void
    {
        $this->broadcast(LiveStatus::Live, ['en' => 'Hostile'], attributes: ['video_ref' => '"><script>alert(1)</script>']);

        $html = $this->get('/en/live')->assertOk()->getContent();

        $this->assertStringNotContainsString('<script>alert(1)</script>', $html);
        $this->assertStringNotContainsString('"><script', $html);
        preg_match('#data-embed="([^"]*)"#', $html, $found);
        $this->assertStringStartsWith('https://www.youtube-nocookie.com/embed/%22%3E%3Cscript%3E', html_entity_decode($found[1]));
    }

    public function test_each_player_is_an_accessible_button_with_a_no_javascript_link_to_the_provider(): void
    {
        $youtube = $this->broadcast(LiveStatus::Live, ['en' => 'Watch me']);
        $vimeo = $this->broadcast(LiveStatus::Live, ['en' => 'Watch me too'], attributes: ['provider' => LiveProvider::Vimeo, 'video_ref' => '123456789']);

        $html = $this->get('/en/live')->assertOk()->getContent();

        $this->assertStringContainsString('<button type="button" class="live-player__play" data-live-play aria-label="Play live broadcast: Watch me">', $html);
        $this->assertStringContainsString('<button type="button" class="live-player__play" data-live-play aria-label="Play live broadcast: Watch me too">', $html);
        $this->assertStringContainsString('data-title="Watch me"', $html);
        $this->assertStringContainsString('<a class="live-player__watch" href="https://www.youtube.com/watch?v='.$youtube->video_ref.'" target="_blank" rel="noopener noreferrer">Watch on YouTube</a>', $html);
        $this->assertStringContainsString('<a class="live-player__watch" href="https://vimeo.com/123456789" target="_blank" rel="noopener noreferrer">Watch on Vimeo</a>', $html);
        $this->assertStringContainsString('src="'.asset('brand/icon-160.webp').'"', $html, 'the branded placeholder is ours');
    }

    public function test_the_arabic_player_labels_are_arabic(): void
    {
        $this->broadcast(LiveStatus::Live, ['ar' => 'بث الجسر']);

        $this->get('/ar/live')->assertOk()
            ->assertSee('aria-label="تشغيل البث المباشر: بث الجسر"', false)
            ->assertSee('شاهد على YouTube');
    }

    public function test_titles_and_descriptions_are_escaped(): void
    {
        $this->broadcast(LiveStatus::Live, ['en' => ['title' => '<img src=x onerror=alert(1)>', 'description' => '<script>alert(2)</script>']]);
        $this->broadcast(LiveStatus::Scheduled, ['en' => '<b>bold</b>']);
        $this->governorate('odd', '<i>Odd</i>', 'غريب');

        $html = $this->get('/en/live')->assertOk()->getContent();

        $this->assertStringNotContainsString('<img src=x', $html);
        $this->assertStringNotContainsString('<script>alert', $html);
        $this->assertStringNotContainsString('<b>bold</b>', $html);
        $this->assertStringNotContainsString('<i>Odd</i>', $html);
        $this->assertStringContainsString('&lt;img src=x onerror=alert(1)&gt;', $html);
    }

    // --- empty states --------------------------------------------------------------------------------

    public function test_the_empty_state_is_shown_in_both_languages_when_nothing_is_live(): void
    {
        $this->governorate('cairo', 'Cairo', 'القاهرة');

        $this->get('/en/live')->assertOk()
            ->assertSee('No broadcast is live right now')
            ->assertSee('Please come back soon')
            ->assertDontSee('data-live-player', false)
            ->assertDontSee('Coming up')->assertDontSee('Recently ended');
        $this->get('/ar/live')->assertOk()
            ->assertSee('لا يوجد بث مباشر الآن')
            ->assertSee('عُد إلينا بعد قليل')
            ->assertDontSee('data-live-player', false);
    }

    public function test_the_empty_state_points_at_the_schedule_when_something_is_coming_up(): void
    {
        $this->broadcast(LiveStatus::Scheduled, ['en' => 'Later today']);

        $this->get('/en/live')->assertOk()
            ->assertSee('No broadcast is live right now')
            ->assertSee('Check the schedule below')
            ->assertSee('Later today');
    }

    public function test_only_scheduled_and_ended_streams_still_show_the_empty_live_section(): void
    {
        $this->broadcast(LiveStatus::Ended, ['en' => 'Done already']);

        $this->get('/en/live')->assertOk()
            ->assertSee('No broadcast is live right now')
            ->assertSee('Done already')
            ->assertDontSee('data-live-player', false);
    }

    public function test_a_governorate_with_nothing_live_gets_its_own_empty_state_and_a_way_back(): void
    {
        $this->governorate('cairo', 'Cairo', 'القاهرة');
        $this->broadcast(LiveStatus::Live, ['en' => 'Elsewhere'], $this->governorate('luxor', 'Luxor', 'الأقصر', 2));

        $this->get('/en/live?g=cairo')->assertOk()
            ->assertSee('No broadcast is live right now in Cairo')
            ->assertSee('Show all of Egypt')
            ->assertDontSee('Elsewhere');
        $this->get('/ar/live?g=cairo')->assertOk()
            ->assertSee('لا يوجد بث مباشر الآن في القاهرة')
            ->assertSee('عرض كل مصر');
    }

    // --- SEO and the language switch -----------------------------------------------------------------

    public function test_the_page_has_a_title_description_canonical_and_hreflang_alternates(): void
    {
        $html = $this->get('/en/live')->assertOk()->getContent();

        $this->assertStringContainsString('<title>Live | sisi.news</title>', $html);
        $this->assertStringContainsString('<meta name="description" content="'.e(__('live.description', ['site' => 'sisi.news'], 'en')).'">', $html);
        $this->assertStringContainsString('<link rel="canonical" href="'.route('live', ['locale' => 'en']).'">', $html);
        $this->assertStringContainsString('<link rel="alternate" hreflang="ar" href="'.route('live', ['locale' => 'ar']).'">', $html);
        $this->assertStringContainsString('<link rel="alternate" hreflang="en" href="'.route('live', ['locale' => 'en']).'">', $html);
        $this->assertStringContainsString('<link rel="alternate" hreflang="x-default" href="'.route('live', ['locale' => 'ar']).'">', $html);
        $this->assertStringContainsString('<meta property="og:url" content="'.route('live', ['locale' => 'en']).'">', $html);

        $this->get('/ar/live')->assertOk()->assertSee('<title>مباشر | sisi.news</title>', false);
    }

    public function test_a_governorate_view_has_its_own_title_and_canonical_and_the_language_switch_keeps_it(): void
    {
        $this->governorate('cairo', 'Cairo', 'القاهرة');

        $en = $this->get('/en/live?g=cairo')->assertOk()->getContent();
        $ar = route('live', ['locale' => 'ar', 'g' => 'cairo']);

        $this->assertStringContainsString('<title>Live: Cairo | sisi.news</title>', $en);
        $this->assertStringContainsString('<link rel="canonical" href="'.route('live', ['locale' => 'en', 'g' => 'cairo']).'">', $en);
        $this->assertStringContainsString('<link rel="alternate" hreflang="ar" href="'.$ar.'">', $en);
        $this->assertMatchesRegularExpression('#<a class="lang-switch" href="'.preg_quote($ar, '#').'"#', $en);
        $this->assertStringContainsString(__('live.description_governorate', ['name' => 'Cairo', 'site' => 'sisi.news'], 'en'), $en);

        $this->get('/ar/live?g=cairo')->assertOk()
            ->assertSee('<title>مباشر: القاهرة | sisi.news</title>', false)
            ->assertSee('<a class="lang-switch" href="'.route('live', ['locale' => 'en', 'g' => 'cairo']).'"', false);
    }

    public function test_the_language_switch_on_the_unfiltered_page_points_at_the_other_live_page(): void
    {
        $this->get('/en/live')->assertSee('<a class="lang-switch" href="'.route('live', ['locale' => 'ar']).'"', false);
        $this->get('/ar/live')->assertSee('<a class="lang-switch" href="'.route('live', ['locale' => 'en']).'"', false);
    }
}
