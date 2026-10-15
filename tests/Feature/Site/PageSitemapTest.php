<?php

namespace Tests\Feature\Site;

use App\Models\Page;
use App\Models\PageTranslation;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use SimpleXMLElement;
use Tests\TestCase;

class PageSitemapTest extends TestCase
{
    use RefreshDatabase;

    private const SITEMAP = 'http://www.sitemaps.org/schemas/sitemap/0.9';

    private const XHTML = 'http://www.w3.org/1999/xhtml';

    /** @return array<string, array{lastmod: ?string, alternates: array<string, string>}> keyed by <loc> */
    private function sitemap(): array
    {
        Cache::flush();
        $response = $this->get('/sitemap.xml')->assertOk();

        $xml = simplexml_load_string($response->getContent());
        $this->assertInstanceOf(SimpleXMLElement::class, $xml);

        $entries = [];
        foreach ($xml->children(self::SITEMAP)->url as $url) {
            $alternates = [];
            foreach ($url->children(self::XHTML)->link as $link) {
                $alternates[(string) $link->attributes()['hreflang']] = (string) $link->attributes()['href'];
            }
            $entries[(string) $url->loc] = ['lastmod' => isset($url->lastmod) ? (string) $url->lastmod : null, 'alternates' => $alternates];
        }

        return $entries;
    }

    private function page(string $slug, bool $en = true, bool $ar = true, array $shell = []): Page
    {
        $page = Page::factory()->create(['slug' => $slug, ...$shell]);
        PageTranslation::factory()->for($page)->create(['is_published' => $en, 'updated_at' => '2026-10-10 08:00:00']);
        PageTranslation::factory()->for($page)->arabic()->create(['is_published' => $ar, 'updated_at' => '2026-10-11 09:30:00']);

        return $page;
    }

    private function url(string $locale, string $slug): string
    {
        return route('page', ['locale' => $locale, 'slug' => $slug]);
    }

    public function test_published_pages_are_listed_in_both_languages_with_alternates_and_lastmod(): void
    {
        $this->page('about');

        $entries = $this->sitemap();
        $en = $this->url('en', 'about');
        $ar = $this->url('ar', 'about');

        $this->assertArrayHasKey($en, $entries);
        $this->assertArrayHasKey($ar, $entries);
        $this->assertSame(['ar' => $ar, 'en' => $en, 'x-default' => $ar], $entries[$en]['alternates']);
        $this->assertSame($entries[$en]['alternates'], $entries[$ar]['alternates']);
        $this->assertSame('2026-10-10T08:00:00+00:00', $entries[$en]['lastmod']);
        $this->assertSame('2026-10-11T09:30:00+00:00', $entries[$ar]['lastmod']);
    }

    public function test_an_unpublished_language_is_left_out_and_is_not_offered_as_an_alternate(): void
    {
        $this->page('about', ar: false);

        $entries = $this->sitemap();
        $en = $this->url('en', 'about');

        $this->assertArrayHasKey($en, $entries);
        $this->assertArrayNotHasKey($this->url('ar', 'about'), $entries);
        $this->assertSame(['en' => $en, 'x-default' => $en], $entries[$en]['alternates']);
    }

    public function test_a_page_unpublished_in_both_languages_is_absent(): void
    {
        $this->page('privacy', en: false, ar: false);

        $entries = $this->sitemap();

        $this->assertArrayNotHasKey($this->url('en', 'privacy'), $entries);
        $this->assertArrayNotHasKey($this->url('ar', 'privacy'), $entries);
    }

    public function test_pages_hidden_from_the_footer_are_still_listed(): void
    {
        $this->page('terms', shell: ['show_in_footer' => false]);

        $this->assertArrayHasKey($this->url('en', 'terms'), $this->sitemap());
    }

    public function test_a_page_with_one_language_row_only_is_listed_for_that_language(): void
    {
        $page = Page::factory()->create(['slug' => 'only-arabic']);
        PageTranslation::factory()->for($page)->arabic()->create();

        $entries = $this->sitemap();
        $ar = $this->url('ar', 'only-arabic');

        $this->assertSame(['ar' => $ar, 'x-default' => $ar], $entries[$ar]['alternates']);
        $this->assertArrayNotHasKey($this->url('en', 'only-arabic'), $entries);
    }

    public function test_the_existing_entries_are_still_there(): void
    {
        $this->page('about');

        $entries = $this->sitemap();

        $this->assertArrayHasKey(route('home', ['locale' => 'ar']), $entries);
        $this->assertArrayHasKey(route('home', ['locale' => 'en']), $entries);
    }

    public function test_the_sitemap_is_still_cached(): void
    {
        $this->page('about');
        $this->get('/sitemap.xml')->assertOk();

        Page::factory()->create(['slug' => 'added-later']);
        PageTranslation::query()->create(['page_id' => Page::query()->where('slug', 'added-later')->value('id'), 'locale' => 'en', 'title' => 'Late', 'body' => '<p>x</p>']);

        $this->get('/sitemap.xml')->assertOk()->assertDontSee('added-later');
    }
}
