<?php

namespace Tests\Feature\Site;

use App\Enums\Locale;
use App\Enums\TranslationStatus;
use App\Models\Article;
use App\Models\Category;
use App\View\Components\Site\Header;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\View;
use Tests\Feature\Site\Services\MakesStories;
use Tests\TestCase;

class HomePageTest extends TestCase
{
    use MakesStories, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->disableSiteCache();
    }

    public function test_the_home_page_renders_in_both_languages_with_the_right_direction(): void
    {
        $this->get('/ar')->assertOk()->assertSee('<html lang="ar" dir="rtl">', false);
        $this->get('/en')->assertOk()->assertSee('<html lang="en" dir="ltr">', false);
    }

    public function test_an_empty_site_shows_a_friendly_empty_state(): void
    {
        $this->get('/ar')->assertOk()->assertSee(__('layout.empty.title', [], 'ar'));
        $this->get('/en')->assertOk()->assertSee(__('layout.empty.title', [], 'en'));
    }

    public function test_only_visible_stories_in_the_page_language_are_listed(): void
    {
        $this->story(['title' => 'القصة المنشورة']);
        $this->story(['title' => 'مسودة سرية', 'status' => TranslationStatus::Draft, 'published_at' => null]);
        $this->story(['title' => 'قيد المراجعة', 'status' => TranslationStatus::InReview, 'published_at' => null]);
        $this->story(['title' => 'قصة مؤرشفة', 'status' => TranslationStatus::Archived]);
        $this->story(['title' => 'قصة مجدولة', 'published_at' => now()->addDay()]);
        $this->story(['title' => 'English only story'], locale: Locale::En);
        $this->story(['title' => 'قصة محذوفة'])->article->delete();

        $this->get('/ar')
            ->assertOk()
            ->assertSee('القصة المنشورة')
            ->assertDontSee('مسودة سرية')
            ->assertDontSee('قيد المراجعة')
            ->assertDontSee('قصة مؤرشفة')
            ->assertDontSee('قصة مجدولة')
            ->assertDontSee('قصة محذوفة')
            ->assertDontSee('English only story');

        $this->get('/en')->assertOk()->assertSee('English only story')->assertDontSee('القصة المنشورة');
    }

    public function test_the_lead_is_the_latest_featured_story_otherwise_the_latest(): void
    {
        $this->story(['title' => 'Old featured', 'published_at' => now()->subDays(5)], ['is_featured' => true]);
        $this->story(['title' => 'Newer featured', 'published_at' => now()->subDays(2)], ['is_featured' => true]);
        $this->story(['title' => 'Newest plain', 'published_at' => now()->subHour()]);

        $this->assertSame('Newer featured', $this->get('/ar')->viewData('lead')->title);

        Article::query()->update(['is_featured' => false]);

        $this->assertSame('Newest plain', $this->get('/ar')->viewData('lead')->title);
    }

    public function test_stories_are_not_repeated_between_the_lead_the_secondary_and_the_latest_list(): void
    {
        foreach (range(1, 14) as $i) {
            $this->story(['title' => "Story {$i}", 'published_at' => now()->subMinutes($i)]);
        }

        $response = $this->get('/ar')->assertOk();

        $lead = $response->viewData('lead');
        $secondary = $response->viewData('secondary');
        $latest = $response->viewData('latest');

        $this->assertSame('Story 1', $lead->title);
        $this->assertCount(3, $secondary);
        $this->assertCount(6, $latest);
        $ids = array_merge([$lead->id], $secondary->pluck('id')->all(), $latest->pluck('id')->all());
        $this->assertSame($ids, array_values(array_unique($ids)));
        $this->assertCount(6, $response->viewData('sidebarLatest'));
    }

    public function test_each_category_with_remaining_stories_gets_a_block_of_at_most_four(): void
    {
        $politics = Category::factory()->create(['sort_order' => 2]);
        $business = Category::factory()->create(['sort_order' => 1]);
        Category::factory()->create(['sort_order' => 3]); // no stories: no block

        foreach (range(1, 6) as $i) {
            $this->story(['title' => "Politics {$i}", 'published_at' => now()->subHours($i)], ['category_id' => $politics->id]);
        }
        $this->story(['title' => 'Business 1', 'published_at' => now()->subDays(2)], ['category_id' => $business->id]);

        $blocks = $this->get('/ar')->assertOk()->viewData('blocks');

        $this->assertCount(2, $blocks);
        $this->assertSame($business->nameIn(Locale::Ar), $blocks[0]['name'], 'blocks follow category sort order');
        $this->assertLessThanOrEqual(4, $blocks[1]['stories']->count());
        $this->assertSame(
            route('category', ['locale' => 'ar', 'slug' => $politics->translate(Locale::Ar)->slug]),
            $blocks[1]['url'],
        );
    }

    public function test_the_last_category_block_of_an_odd_count_spans_both_columns_so_the_grid_has_no_hole(): void
    {
        $popular = $this->story(['title' => 'Popular lead', 'published_at' => now()->subDays(1)]);
        $this->addViews($popular, 10);
        foreach (range(1, 3) as $i) {
            $category = Category::factory()->create(['sort_order' => $i]);
            foreach (range(1, 4) as $n) {
                $this->story(['title' => "Block {$i} story {$n}", 'published_at' => now()->subHours($i * 10 + $n)], ['category_id' => $category->id]);
            }
        }

        $html = $this->get('/ar')->assertOk()->getContent();

        $this->assertCount(3, $this->get('/ar')->viewData('blocks'));
        $this->assertSame(1, substr_count($html, 'md:col-span-2'), 'only the odd last block is widened');

        $category = Category::factory()->create(['sort_order' => 4]);
        $this->story(['title' => 'Fourth block story'], ['category_id' => $category->id]);

        $this->assertSame(0, substr_count($this->get('/ar')->getContent(), 'md:col-span-2'), 'an even count needs no widening');
    }

    public function test_the_arabic_copyright_keeps_its_full_stop_after_the_latin_site_name(): void
    {
        // Without the left-to-right mark the stop renders to the left of the name (".sisi.news").
        $this->get('/ar')->assertOk()->assertSee(config('site.name.ar').".\u{200E} جميع الحقوق محفوظة.", false);
    }

    public function test_navigation_lists_nav_categories_in_order_with_localised_names_and_slugs(): void
    {
        $second = Category::factory()->create(['sort_order' => 2]);
        $first = Category::factory()->create(['sort_order' => 1]);
        $hidden = Category::factory()->create(['sort_order' => 3, 'show_in_nav' => false]);

        $this->get('/en')
            ->assertSeeInOrder([__('layout.home', [], 'en'), $first->nameIn(Locale::En), $second->nameIn(Locale::En)])
            ->assertDontSee($hidden->nameIn(Locale::En));

        $this->get('/ar')
            ->assertSee(route('category', ['locale' => 'ar', 'slug' => $first->translate(Locale::Ar)->slug]), false)
            ->assertSee($first->nameIn(Locale::Ar));
    }

    public function test_the_ticker_appears_only_when_a_story_is_breaking(): void
    {
        $this->story(['title' => 'Calm day']);

        $this->get('/ar')->assertOk()->assertDontSee('class="ticker"', false);

        $this->story(['title' => 'عاجل: حدث كبير'], ['is_breaking' => true]);

        $this->get('/ar')->assertOk()->assertSee('class="ticker"', false)->assertSee('عاجل: حدث كبير');
        // An Arabic breaking story says nothing in the English edition.
        $this->get('/en')->assertOk()->assertDontSee('class="ticker"', false);
    }

    public function test_an_expired_breaking_story_no_longer_shows_in_the_ticker(): void
    {
        $this->story(['title' => 'Old news flash'], ['is_breaking' => true, 'breaking_until' => now()->subMinute()]);

        $this->get('/ar')->assertOk()->assertDontSee('class="ticker"', false);
    }

    public function test_the_language_switcher_links_to_the_other_language_home(): void
    {
        $this->get('/ar')->assertSee('class="lang-switch" href="'.route('home', ['locale' => 'en']).'" lang="en"', false);
        $this->get('/en')->assertSee('class="lang-switch" href="'.route('home', ['locale' => 'ar']).'" lang="ar"', false);
    }

    public function test_the_language_switcher_prefers_the_alternate_url_a_page_declares(): void
    {
        $dir = sys_get_temp_dir().'/sisi-probe-'.uniqid();
        mkdir($dir);
        file_put_contents($dir.'/page.blade.php', "@extends('layouts.site')\n@section('alternate_url', 'https://example.test/en/news/7-same-story')\n@section('content')\nprobe\n@endsection\n");
        View::addNamespace('probe', $dir);
        Route::middleware(['web', 'locale'])->get('/{locale}/probe', fn () => view('probe::page'))->where('locale', 'ar|en');

        try {
            $this->get('/ar/probe')
                ->assertOk()
                ->assertSee('class="lang-switch" href="https://example.test/en/news/7-same-story"', false);
        } finally {
            unlink($dir.'/page.blade.php');
            rmdir($dir);
        }
    }

    public function test_the_language_switcher_keeps_the_search_query(): void
    {
        $request = Request::create('/ar/search', 'GET', ['q' => 'مصر']);
        $request->setRouteResolver(fn () => app('router')->getRoutes()->match($request));
        $this->app->instance('request', $request);

        $this->assertSame(
            route('search', ['locale' => 'en', 'q' => 'مصر']),
            (new Header(Locale::Ar))->switchUrl(),
        );
    }

    public function test_the_footer_has_the_newsletter_form_and_no_login_or_registration(): void
    {
        $response = $this->get('/ar')->assertOk();

        $response
            ->assertSee('action="'.route('newsletter.subscribe').'"', false)
            ->assertSee('id="newsletter"', false)
            ->assertSee('name="email"', false)
            ->assertSee('name="locale" value="ar"', false)
            ->assertSee('name="_token"', false)
            ->assertSee('name="website"', false)
            ->assertSee('aria-hidden="true"', false);

        $response
            ->assertDontSee('type="password"', false)
            ->assertDontSee('/login', false)
            ->assertDontSee('/register', false)
            ->assertDontSee('/admin', false);
    }

    public function test_the_newsletter_form_reports_flash_status_and_validation_errors(): void
    {
        $this->withSession(['newsletter_status' => 'Thanks, you are on the list.'])
            ->get('/en')
            ->assertSee('Thanks, you are on the list.');

        // The session stores the error bag in this array form (json serialization) between requests.
        $this->withSession(['errors' => ['default' => ['format' => ':message', 'messages' => ['email' => ['The email must be a valid email address.']]]]])
            ->get('/en')
            ->assertSee('The email must be a valid email address.')
            ->assertSee('aria-invalid="true"', false);
    }

    public function test_only_the_social_links_that_are_configured_are_shown(): void
    {
        config(['site.social.facebook' => 'https://facebook.com/sisinews', 'site.social.x' => 'javascript:alert(1)']);

        $this->get('/en')
            ->assertSee('href="https://facebook.com/sisinews"', false)
            ->assertDontSee('javascript:alert', false)
            ->assertDontSee('Instagram');
    }

    public function test_story_text_is_escaped(): void
    {
        $this->story(['title' => '<script>alert(1)</script> headline', 'excerpt' => '<img src=x onerror=alert(2)>']);

        $this->get('/ar')
            ->assertOk()
            ->assertDontSee('<script>alert(1)</script>', false)
            ->assertDontSee('<img src=x onerror', false)
            ->assertSee('&lt;script&gt;alert(1)&lt;/script&gt; headline', false);
    }

    public function test_cards_use_the_brand_logo_when_there_is_no_hero_image_and_load_the_lead_first(): void
    {
        $this->story(['title' => 'No picture']);
        $this->story(['title' => 'Has picture', 'published_at' => now()->subDay()], ['hero_image' => 'heroes/a.jpg', 'is_featured' => true]);
        $this->story(['title' => 'Also has picture', 'published_at' => now()->subDays(2)], ['hero_image' => 'heroes/b.jpg']);

        $html = $this->get('/ar')->assertOk()->getContent();

        $this->assertStringContainsString('brand/logo-380.webp', $html);
        $this->assertSame(1, substr_count($html, 'fetchpriority="high"'), 'only the lead image is high priority');
        $this->assertStringContainsString('loading="lazy"', $html);
        $this->assertMatchesRegularExpression('/<img src="[^"]*heroes\/a\.jpg"[^>]*width="1280" height="720"/', $html);
    }

    public function test_the_number_of_queries_does_not_grow_with_the_number_of_stories(): void
    {
        $categories = Category::factory()->count(2)->create();
        $publish = function (int $count) use ($categories) {
            foreach (range(1, $count) as $i) {
                $story = $this->story(['published_at' => now()->subMinutes($i)], ['category_id' => $categories[$i % 2]->id]);
                $this->addViews($story, $i);
            }
        };
        $countQueries = function () {
            $queries = 0;
            DB::listen(function () use (&$queries) {
                $queries++;
            });
            $this->get('/ar')->assertOk();

            return $queries;
        };

        $publish(8);
        $few = $countQueries();
        $publish(20);

        $this->assertSame($few, $countQueries(), 'cards must use eager-loaded relations (no N+1)');
    }

    public function test_most_read_and_latest_panels_are_both_in_the_markup_without_javascript(): void
    {
        $popular = $this->story(['title' => 'Popular one']);
        $this->story(['title' => 'Another one', 'published_at' => now()->subHour()]);
        $this->addViews($popular, 10);

        $html = $this->get('/ar')->assertOk()->getContent();

        $this->assertStringContainsString('id="panel-most-read"', $html);
        $this->assertStringContainsString('id="panel-latest"', $html);
        $this->assertStringContainsString('role="tablist"', $html);
        // The tab list ships hidden and panels ship visible: JS flips that, so no-JS readers see everything.
        $this->assertMatchesRegularExpression('/role="tablist"[^>]*\bhidden\b/', $html);
        $this->assertDoesNotMatchRegularExpression('/id="panel-(most-read|latest)"[^>]*\bhidden\b/', $html);
    }

    public function test_without_any_views_there_is_no_sidebar_repeating_the_latest_list(): void
    {
        $this->story();

        $this->get('/ar')->assertOk()->assertDontSee('id="panel-most-read"', false)->assertDontSee('id="panel-latest"', false);
    }

    public function test_head_has_canonical_hreflang_and_the_current_logo_as_the_share_image(): void
    {
        $this->get('/en')
            ->assertSee('<link rel="canonical" href="'.route('home', ['locale' => 'en']).'">', false)
            ->assertSee('hreflang="ar" href="'.route('home', ['locale' => 'ar']).'"', false)
            ->assertSee('property="og:image" content="'.asset('brand/og-default.png').'"', false);
    }

    public function test_an_unmatched_url_renders_the_404_page_in_both_languages(): void
    {
        $this->get('/ar/no-such-page')
            ->assertNotFound()
            ->assertSee('<html lang="ar" dir="rtl">', false)
            ->assertSee(__('layout.errors.404.title', [], 'ar'))
            ->assertSee(__('layout.errors.404.title', [], 'en'))
            ->assertSee(route('home', ['locale' => 'ar']), false)
            ->assertSee(route('home', ['locale' => 'en']), false);

        $this->get('/en/no-such-page')
            ->assertNotFound()
            ->assertSee('<html lang="en" dir="ltr">', false)
            ->assertSee('Page not found')
            ->assertSee('الصفحة غير موجودة');

        // Not under a language prefix at all: falls back to the default language.
        $this->get('/nothing-here')->assertNotFound()->assertSee('<html lang="ar" dir="rtl">', false);
    }

    public function test_error_pages_render_in_both_languages_without_touching_the_database(): void
    {
        $queries = [];
        DB::listen(function ($query) use (&$queries) {
            $queries[] = $query->sql;
        });

        $this->get('/en/no-such-page')
            ->assertNotFound()
            ->assertSee('Page not found')
            ->assertDontSee('action="'.route('newsletter.subscribe').'"', false);

        foreach ([404, 419, 429, 500, 503] as $status) {
            $html = view("errors.{$status}")->render();

            $this->assertStringContainsString(__("layout.errors.{$status}.title", [], 'ar'), $html);
            $this->assertStringContainsString(__("layout.errors.{$status}.title", [], 'en'), $html);
        }

        $this->assertSame([], $queries);
    }

    public function test_the_page_has_a_skip_link_landmarks_and_an_accessible_mobile_menu(): void
    {
        $this->get('/ar')
            ->assertSee('class="skip-link" href="#main"', false)
            ->assertSee('<main id="main"', false)
            ->assertSee('data-nav-toggle aria-expanded="false" aria-controls="site-nav"', false)
            ->assertSee('<nav id="site-nav"', false)
            ->assertSee('role="search"', false);
    }
}
