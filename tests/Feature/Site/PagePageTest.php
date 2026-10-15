<?php

namespace Tests\Feature\Site;

use App\Enums\Locale;
use App\Models\Page;
use App\Models\PageTranslation;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class PagePageTest extends TestCase
{
    use RefreshDatabase;

    private function page(string $slug = 'about', array $en = [], array $ar = []): Page
    {
        $page = Page::factory()->create(['slug' => $slug]);
        PageTranslation::factory()->for($page)->create([
            'title' => 'About sisi.news',
            'body' => '<p>First paragraph.</p><h2>A subheading</h2><p>Second paragraph.</p>',
            ...$en,
        ]);
        PageTranslation::factory()->for($page)->arabic()->create(['title' => 'عن سيسي نيوز', ...$ar]);

        return $page;
    }

    public function test_an_english_page_renders_with_its_title_body_breadcrumb_and_update_date(): void
    {
        $page = $this->page();
        PageTranslation::query()->where('page_id', $page->id)->where('locale', 'en')->update(['updated_at' => '2026-10-05 10:00:00']);

        $this->get('/en/page/about')
            ->assertOk()
            ->assertSee('<html lang="en" dir="ltr">', false)
            ->assertSee('<title>About sisi.news | sisi.news</title>', false)
            ->assertSee('<h1 class="sn-title">About sisi.news</h1>', false)
            ->assertSee('<div class="sn-prose"><p>First paragraph.</p><h2>A subheading</h2><p>Second paragraph.</p></div>', false)
            ->assertSee('<nav class="sn-crumbs" aria-label="Breadcrumb">', false)
            ->assertSee('<li><a href="'.route('home', ['locale' => 'en']).'">Home</a></li>', false)
            ->assertSee('<span aria-current="page">About sisi.news</span>', false)
            ->assertSee('Last updated 5 October 2026');
    }

    public function test_an_arabic_page_renders_right_to_left_with_arabic_labels(): void
    {
        $this->page();

        $this->get('/ar/page/about')
            ->assertOk()
            ->assertSee('<html lang="ar" dir="rtl">', false)
            ->assertSee('<h1 class="sn-title">عن سيسي نيوز</h1>', false)
            ->assertSee('الرئيسية')
            ->assertSee('مسار التنقل')
            ->assertSee('آخر تحديث')
            ->assertSee('هذا نص تجريبي لصفحة ثابتة باللغة العربية.');
    }

    public function test_the_head_has_description_canonical_hreflang_alternates_and_share_tags(): void
    {
        $this->page(en: ['meta_description' => 'Who we are & how we work.']);
        $en = route('page', ['locale' => 'en', 'slug' => 'about']);
        $ar = route('page', ['locale' => 'ar', 'slug' => 'about']);

        $this->get('/en/page/about')
            ->assertOk()
            ->assertSee('<meta name="description" content="Who we are &amp; how we work.">', false)
            ->assertSee('<link rel="canonical" href="'.$en.'">', false)
            ->assertSee('<link rel="alternate" hreflang="ar" href="'.$ar.'">', false)
            ->assertSee('<link rel="alternate" hreflang="en" href="'.$en.'">', false)
            ->assertSee('<link rel="alternate" hreflang="x-default" href="'.$ar.'">', false)
            ->assertSee('<meta property="og:type" content="website">', false)
            ->assertSee('<meta property="og:title" content="About sisi.news">', false)
            ->assertSee('<meta property="og:url" content="'.$en.'">', false)
            ->assertSee('<meta property="og:locale" content="en_US">', false)
            ->assertSee('<meta property="og:locale:alternate" content="ar_EG">', false);
    }

    public function test_without_a_meta_description_the_start_of_the_text_is_used(): void
    {
        $this->page(en: ['body' => '<p>'.str_repeat('Reliable reporting. ', 20).'</p><h2>Next</h2>', 'meta_description' => null]);

        $html = $this->get('/en/page/about')->assertOk()->getContent();

        $this->assertSame(1, preg_match('/<meta name="description" content="([^"]*)">/', $html, $match));
        $this->assertStringStartsWith('Reliable reporting. Reliable reporting.', $match[1]);
        $this->assertLessThanOrEqual(161, mb_strlen($match[1]));
        $this->assertStringNotContainsString('<', $match[1]);
    }

    public function test_the_language_switcher_points_at_the_other_language_of_the_same_page(): void
    {
        $this->page();

        $this->get('/en/page/about')
            ->assertSee('class="lang-switch" href="'.route('page', ['locale' => 'ar', 'slug' => 'about']).'"', false);
        $this->get('/ar/page/about')
            ->assertSee('class="lang-switch" href="'.route('page', ['locale' => 'en', 'slug' => 'about']).'"', false);
    }

    public function test_an_unpublished_other_language_is_neither_an_alternate_nor_a_switcher_target(): void
    {
        $page = $this->page();
        PageTranslation::query()->where('page_id', $page->id)->where('locale', 'ar')->update(['is_published' => false]);
        $en = route('page', ['locale' => 'en', 'slug' => 'about']);

        $this->get('/en/page/about')
            ->assertOk()
            ->assertDontSee('<link rel="alternate" hreflang="ar"', false)
            ->assertDontSee('og:locale:alternate', false)
            // With no Arabic edition x-default is this page itself.
            ->assertSee('<link rel="alternate" hreflang="x-default" href="'.$en.'">', false)
            ->assertSee('class="lang-switch" href="'.route('home', ['locale' => 'ar']).'"', false);
    }

    public function test_an_unknown_slug_is_a_404(): void
    {
        $this->page();

        $this->get('/en/page/nope')->assertNotFound();
        $this->get('/ar/page/nope')->assertNotFound();
    }

    public function test_a_page_with_no_version_in_this_language_is_a_404(): void
    {
        $page = Page::factory()->create(['slug' => 'only-english']);
        PageTranslation::factory()->for($page)->create();

        $this->get('/en/page/only-english')->assertOk();
        $this->get('/ar/page/only-english')->assertNotFound();
    }

    public function test_an_unpublished_version_is_a_404_and_its_text_never_leaks(): void
    {
        $page = $this->page(en: ['body' => '<p>Secret draft text.</p>']);
        PageTranslation::query()->where('page_id', $page->id)->where('locale', 'en')->update(['is_published' => false]);

        $this->get('/en/page/about')->assertNotFound()->assertDontSee('Secret draft text.');
        $this->get('/ar/page/about')->assertOk()->assertDontSee('Secret draft text.');
    }

    public function test_a_slug_that_is_not_kebab_case_or_a_language_other_than_arabic_and_english_never_reaches_a_page(): void
    {
        $this->page();
        Page::factory()->create(['slug' => 'Mixed']);

        $this->get('/en/page/About')->assertNotFound();
        $this->get('/en/page/Mixed')->assertNotFound();
        $this->get('/en/page/about%20')->assertNotFound();
        $this->get('/fr/page/about')->assertNotFound();
    }

    public function test_a_hostile_body_is_sanitized_when_stored_and_nothing_executable_is_rendered(): void
    {
        $this->page(en: ['body' => implode('', [
            '<p onclick="steal()">Hello <script>alert("xss1")</script>world</p>',
            '<img src="x" onerror="alert(\'xss2\')">',
            '<a href="javascript:alert(\'xss3\')">click</a>',
            '<iframe src="https://evil.example/"></iframe>',
            '<style>body{display:none}</style>',
            '<p style="color:red" class="x" id="y">Styled</p>',
            '<a href="data:text/html;base64,PHNjcmlwdD4=">data link</a>',
            '<svg onload="alert(\'xss4\')"></svg>',
        ])]);

        $stored = PageTranslation::query()->where('locale', 'en')->firstOrFail()->body;
        foreach (['<script', 'alert(', 'onclick', 'onerror', 'javascript:', '<iframe', '<style', 'style=', 'class=', 'id=', 'data:text', '<svg', 'onload'] as $needle) {
            $this->assertStringNotContainsString($needle, $stored, "{$needle} survived sanitization");
        }
        $this->assertStringContainsString('Hello', $stored);

        $html = $this->get('/en/page/about')->assertOk()->getContent();
        foreach (['xss1', 'xss2', 'xss3', 'xss4', 'evil.example', 'steal()', 'display:none'] as $needle) {
            $this->assertStringNotContainsString($needle, $html, "{$needle} reached the page");
        }
    }

    public function test_a_body_written_straight_to_the_model_is_also_sanitized(): void
    {
        $page = $this->page();
        $translation = PageTranslation::query()->where('page_id', $page->id)->where('locale', 'en')->firstOrFail();

        $translation->body = '<p>ok</p><script>bad()</script>';
        $translation->save();

        $this->assertSame('<p>ok</p>', $translation->fresh()->body);
    }

    public function test_an_edit_shows_immediately_because_nothing_about_the_page_is_cached(): void
    {
        $page = $this->page();
        $this->get('/en/page/about')->assertOk()->assertSee('About sisi.news');

        PageTranslation::query()->where('page_id', $page->id)->where('locale', 'en')
            ->firstOrFail()->update(['title' => 'About the newsroom', 'body' => '<p>Rewritten text.</p>']);

        $this->get('/en/page/about')->assertOk()->assertSee('About the newsroom')->assertSee('Rewritten text.');
    }

    public function test_the_page_runs_a_fixed_number_of_queries_however_many_pages_the_footer_lists(): void
    {
        $this->page('about');
        Cache::flush();
        $few = $this->queries('/en/page/about');

        foreach (range(1, 8) as $n) {
            $this->page("extra-{$n}");
        }
        Cache::flush();
        $many = $this->queries('/en/page/about');

        $this->assertSame($few, $many);
        $this->assertLessThanOrEqual(8, $many);
    }

    public function test_the_locale_enum_is_cast_on_both_models(): void
    {
        $page = $this->page()->load('translations');

        $this->assertSame(Locale::En, $page->translate(Locale::En)->locale);
        $this->assertSame(Locale::Ar, $page->translate(Locale::Ar)->locale);
        $this->assertNull($page->translate(Locale::En)->meta_description);
    }

    private function queries(string $url): int
    {
        DB::enableQueryLog();
        DB::flushQueryLog();
        $this->get($url)->assertOk();
        $count = count(DB::getQueryLog());
        DB::disableQueryLog();

        return $count;
    }
}
