<?php

namespace Tests\Feature\Site;

use App\Models\Page;
use App\Models\PageTranslation;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class PageFooterTest extends TestCase
{
    use RefreshDatabase;

    /** @param  array<string, mixed>  $shell */
    private function page(string $slug, string $en, ?string $ar, array $shell = []): Page
    {
        $page = Page::factory()->create(['slug' => $slug, ...$shell]);
        PageTranslation::factory()->for($page)->create(['title' => $en]);
        if ($ar !== null) {
            PageTranslation::factory()->for($page)->arabic()->create(['title' => $ar]);
        }

        return $page;
    }

    private function translation(Page $page, string $locale): PageTranslation
    {
        return PageTranslation::query()->where('page_id', $page->id)->where('locale', $locale)->firstOrFail();
    }

    /** @return list<string> the link texts of the footer's About group, '' markers dropped */
    private function aboutLinks(string $html): array
    {
        if (preg_match('#<nav[^>]*aria-labelledby="footer-about">(.*?)</nav>#s', $html, $group) !== 1) {
            return [];
        }
        preg_match_all('#<li><a href="([^"]*)">([^<]*)</a></li>#', $group[1], $links, PREG_SET_ORDER);

        return array_map(fn (array $link): string => html_entity_decode($link[2]).' => '.html_entity_decode($link[1]), $links);
    }

    public function test_the_footer_lists_footer_pages_in_sort_order_with_links_in_the_readers_language(): void
    {
        $this->page('terms', 'Terms of use', 'شروط الاستخدام', ['sort_order' => 5]);
        $this->page('about', 'About us', 'من نحن', ['sort_order' => 1]);
        $this->page('privacy', 'Privacy', 'الخصوصية', ['sort_order' => 4]);

        $en = $this->get('/en')->assertOk()->assertSee('About', false)->getContent();
        $this->assertSame([
            'About us => '.route('page', ['locale' => 'en', 'slug' => 'about']),
            'Privacy => '.route('page', ['locale' => 'en', 'slug' => 'privacy']),
            'Terms of use => '.route('page', ['locale' => 'en', 'slug' => 'terms']),
        ], $this->aboutLinks($en));

        $ar = $this->get('/ar')->assertOk()->assertSee('عن الموقع')->getContent();
        $this->assertSame([
            'من نحن => '.route('page', ['locale' => 'ar', 'slug' => 'about']),
            'الخصوصية => '.route('page', ['locale' => 'ar', 'slug' => 'privacy']),
            'شروط الاستخدام => '.route('page', ['locale' => 'ar', 'slug' => 'terms']),
        ], $this->aboutLinks($ar));
    }

    public function test_pages_with_equal_positions_keep_creation_order(): void
    {
        $this->page('b-page', 'Second', 'ثاني');
        $this->page('a-page', 'Third', 'ثالث');

        $this->assertSame(['Second', 'Third'], array_map(fn ($l) => explode(' => ', $l)[0], $this->aboutLinks($this->get('/en')->getContent())));
    }

    public function test_the_footer_leaves_out_pages_that_are_flagged_off_unpublished_or_missing_in_the_language(): void
    {
        $this->page('about', 'About us', 'من نحن');
        $this->page('terms', 'Terms of use', 'شروط الاستخدام', ['show_in_footer' => false]);
        $hidden = $this->page('privacy', 'Privacy', 'الخصوصية');
        $this->translation($hidden, 'en')->update(['is_published' => false]);
        $this->page('english-only', 'English only', null);

        $this->assertSame(
            ['About us => '.route('page', ['locale' => 'en', 'slug' => 'about']), 'English only => '.route('page', ['locale' => 'en', 'slug' => 'english-only'])],
            $this->aboutLinks($this->get('/en')->getContent()),
        );
        $this->assertSame(
            ['من نحن => '.route('page', ['locale' => 'ar', 'slug' => 'about']), 'الخصوصية => '.route('page', ['locale' => 'ar', 'slug' => 'privacy'])],
            $this->aboutLinks($this->get('/ar')->getContent()),
        );
    }

    public function test_the_footer_still_renders_with_no_pages(): void
    {
        $response = $this->get('/en')->assertOk()->assertSee('Sections')->assertSee('RSS feed');

        $this->assertSame([], $this->aboutLinks($response->getContent()));
        $response->assertDontSee('footer-about', false);
        $this->get('/ar')->assertOk()->assertDontSee('footer-about', false);
    }

    public function test_the_footer_still_renders_when_every_page_is_hidden(): void
    {
        $page = $this->page('about', 'About us', 'من نحن');
        $this->translation($page, 'en')->update(['is_published' => false]);

        $this->get('/en')->assertOk()->assertDontSee('footer-about', false)->assertDontSee('About us');
    }

    public function test_the_footer_links_are_cached_but_a_second_render_is_free_of_page_queries(): void
    {
        $this->page('about', 'About us', 'من نحن');
        $this->get('/en')->assertSee('About us');

        DB::enableQueryLog();
        DB::flushQueryLog();
        $this->get('/en')->assertSee('About us');
        $pageQueries = collect(DB::getQueryLog())->filter(fn (array $q) => str_contains($q['query'], 'page_translations'))->count();
        DB::disableQueryLog();

        $this->assertSame(0, $pageQueries);
    }

    public function test_the_footer_runs_the_same_queries_for_one_page_or_many(): void
    {
        $this->page('about', 'About us', 'من نحن');
        $count = function (): int {
            Cache::flush();
            DB::enableQueryLog();
            DB::flushQueryLog();
            $this->get('/en')->assertOk();
            $queries = count(DB::getQueryLog());
            DB::disableQueryLog();

            return $queries;
        };
        $one = $count();

        foreach (range(1, 6) as $n) {
            $this->page("extra-{$n}", "Extra {$n}", "إضافي {$n}");
        }

        $this->assertSame($one, $count());
    }

    public function test_editing_a_title_shows_in_the_footer_at_once(): void
    {
        $page = $this->page('about', 'About us', 'من نحن');
        $this->get('/en')->assertSee('About us');

        $this->translation($page, 'en')->update(['title' => 'About the newsroom']);

        $this->get('/en')->assertSee('About the newsroom')->assertDontSee('About us');
    }

    public function test_unpublishing_and_republishing_a_language_updates_the_footer_at_once(): void
    {
        $page = $this->page('about', 'About us', 'من نحن');
        $this->get('/en')->assertSee('About us');
        $this->get('/ar')->assertSee('من نحن');

        $this->translation($page, 'en')->update(['is_published' => false]);
        $this->get('/en')->assertDontSee('About us');
        $this->get('/ar')->assertSee('من نحن');

        $this->translation($page, 'en')->update(['is_published' => true]);
        $this->get('/en')->assertSee('About us');
    }

    public function test_changing_the_footer_flag_or_the_order_updates_the_footer_at_once(): void
    {
        $about = $this->page('about', 'About us', 'من نحن', ['sort_order' => 1]);
        $terms = $this->page('terms', 'Terms of use', 'شروط الاستخدام', ['sort_order' => 2]);
        $this->assertCount(2, $this->aboutLinks($this->get('/en')->getContent()));

        $terms->update(['sort_order' => 0]);
        $this->assertStringStartsWith('Terms of use', $this->aboutLinks($this->get('/en')->getContent())[0]);

        $about->update(['show_in_footer' => false]);
        $this->assertCount(1, $this->aboutLinks($this->get('/en')->getContent()));
    }

    public function test_deleting_a_page_removes_it_from_the_footer_at_once(): void
    {
        $page = $this->page('about', 'About us', 'من نحن');
        $this->get('/en')->assertSee('About us');
        $this->get('/ar')->assertSee('من نحن');

        $page->delete();

        $this->get('/en')->assertDontSee('About us');
        $this->get('/ar')->assertDontSee('من نحن');
        $this->assertSame(0, PageTranslation::query()->count(), 'translations go with the page');
    }

    public function test_adding_a_page_shows_in_the_footer_at_once(): void
    {
        $this->get('/en')->assertDontSee('footer-about', false);

        $this->page('about', 'About us', 'من نحن');

        $this->get('/en')->assertSee('About us');
    }

    public function test_the_contact_email_line_appears_only_when_an_address_is_configured(): void
    {
        config(['site.contact.email' => null]);
        $this->get('/en')->assertDontSee('mailto:', false)->assertDontSee('Contact');

        config(['site.contact.email' => 'desk@example.org']);
        $this->get('/en')->assertSee('<a href="mailto:desk@example.org">desk@example.org</a>', false)->assertSee('Contact');
    }

    public function test_error_pages_render_a_minimal_footer_without_touching_the_page_tables(): void
    {
        $this->page('about', 'About us', 'من نحن');

        DB::enableQueryLog();
        DB::flushQueryLog();
        $this->get('/en/not-a-real-page')->assertNotFound();
        $touched = collect(DB::getQueryLog())->filter(fn (array $q) => str_contains($q['query'], 'page'))->count();
        DB::disableQueryLog();

        $this->assertSame(0, $touched);
    }
}
