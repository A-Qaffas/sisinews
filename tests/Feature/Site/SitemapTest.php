<?php

namespace Tests\Feature\Site;

use App\Enums\Locale;
use App\Enums\TranslationStatus;
use App\Models\Article;
use App\Models\Category;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use SimpleXMLElement;
use Tests\Feature\Site\Concerns\MakesStories;
use Tests\TestCase;

class SitemapTest extends TestCase
{
    use MakesStories, RefreshDatabase;

    private const SITEMAP = 'http://www.sitemaps.org/schemas/sitemap/0.9';

    private const XHTML = 'http://www.w3.org/1999/xhtml';

    /** @return array<string, array{lastmod: ?string, alternates: array<string, string>}> keyed by <loc> */
    private function sitemap(): array
    {
        $response = $this->get('/sitemap.xml')->assertOk();
        $this->assertSame('application/xml; charset=utf-8', $response->headers->get('Content-Type'));

        $previous = libxml_use_internal_errors(true);
        $xml = simplexml_load_string($response->getContent());
        libxml_clear_errors();
        libxml_use_internal_errors($previous);
        $this->assertInstanceOf(SimpleXMLElement::class, $xml, 'sitemap is not well-formed XML');
        $this->assertSame('urlset', $xml->getName());

        $entries = [];
        foreach ($xml->children(self::SITEMAP)->url as $url) {
            $alternates = [];
            foreach ($url->children(self::XHTML)->link as $link) {
                $alternates[(string) $link->attributes()['hreflang']] = (string) $link->attributes()['href'];
            }
            $entries[(string) $url->loc] = [
                'lastmod' => isset($url->lastmod) ? (string) $url->lastmod : null,
                'alternates' => $alternates,
            ];
        }

        return $entries;
    }

    public function test_it_lists_both_home_pages_with_hreflang_alternates(): void
    {
        $entries = $this->sitemap();

        $ar = route('home', ['locale' => 'ar']);
        $en = route('home', ['locale' => 'en']);
        $this->assertArrayHasKey($ar, $entries);
        $this->assertArrayHasKey($en, $entries);
        $this->assertSame(['ar' => $ar, 'en' => $en, 'x-default' => $ar], $entries[$en]['alternates']);
        $this->assertSame($entries[$ar]['alternates'], $entries[$en]['alternates']);
    }

    public function test_it_lists_category_pages_in_both_languages(): void
    {
        $category = Category::factory()->create();
        $en = route('category', ['locale' => 'en', 'slug' => "section-{$category->id}"]);
        $ar = route('category', ['locale' => 'ar', 'slug' => "قسم-{$category->id}"]);

        $entries = $this->sitemap();

        $this->assertSame(['ar' => $ar, 'en' => $en, 'x-default' => $ar], $entries[$en]['alternates']);
        $this->assertArrayHasKey($ar, $entries);
    }

    public function test_it_lists_visible_stories_with_alternates_to_the_live_sibling_and_lastmod_from_updated_at(): void
    {
        $article = Article::factory()->create();
        $en = $this->makeStory(Locale::En, attributes: ['updated_at' => '2026-10-11 10:00:00'], article: $article);
        $ar = $this->makeStory(Locale::Ar, attributes: ['updated_at' => '2026-10-12 09:00:00'], article: $article);

        $entries = $this->sitemap();

        $this->assertSame('2026-10-11T10:00:00+00:00', $entries[$en->url()]['lastmod']);
        $this->assertSame('2026-10-12T09:00:00+00:00', $entries[$ar->url()]['lastmod']);
        $this->assertSame(['ar' => $ar->url(), 'en' => $en->url(), 'x-default' => $ar->url()], $entries[$en->url()]['alternates']);
        $this->assertSame($entries[$en->url()]['alternates'], $entries[$ar->url()]['alternates']);
    }

    public function test_a_story_live_in_one_language_has_only_itself_as_alternate(): void
    {
        $article = Article::factory()->create();
        $en = $this->makeStory(Locale::En, article: $article);
        $this->makeStory(Locale::Ar, TranslationStatus::Draft, article: $article);

        $entries = $this->sitemap();

        $this->assertSame(['en' => $en->url(), 'x-default' => $en->url()], $entries[$en->url()]['alternates']);
    }

    public function test_it_excludes_every_invisible_story(): void
    {
        $live = $this->makeStory(Locale::En);
        $hidden = [
            $this->makeStory(Locale::En, TranslationStatus::Draft),
            $this->makeStory(Locale::En, TranslationStatus::InReview),
            $this->makeStory(Locale::En, TranslationStatus::ChangesRequested),
            $this->makeStory(Locale::En, TranslationStatus::Archived),
            $this->makeStory(Locale::En, attributes: ['published_at' => now()->addDay()]),
            tap($this->makeStory(Locale::Ar), fn ($t) => $t->article->delete()),
        ];

        $entries = $this->sitemap();

        $this->assertArrayHasKey($live->url(), $entries);
        foreach ($hidden as $t) {
            $this->assertArrayNotHasKey($t->url(), $entries);
        }
    }

    public function test_stories_are_listed_newest_first(): void
    {
        $old = $this->makeStory(Locale::En, attributes: ['published_at' => now()->subDays(3)]);
        $new = $this->makeStory(Locale::En, attributes: ['published_at' => now()->subHour()]);

        $urls = array_keys($this->sitemap());

        $this->assertLessThan(array_search($old->url(), $urls, true), array_search($new->url(), $urls, true));
    }

    public function test_it_caps_the_story_list_at_five_thousand_newest_first(): void
    {
        $category = Category::factory()->create();
        $author = Article::factory()->create()->author_id;
        $now = now()->toDateTimeString();

        foreach (array_chunk(range(1, 5002), 500) as $chunk) {
            DB::table('articles')->insert(array_map(fn ($i) => [
                'category_id' => $category->id, 'author_id' => $author, 'created_at' => $now, 'updated_at' => $now,
            ], $chunk));
        }
        $ids = DB::table('articles')->where('id', '>', 1)->pluck('id');
        foreach ($ids->chunk(500) as $chunk) {
            DB::table('article_translations')->insert($chunk->map(fn ($id) => [
                'article_id' => $id, 'locale' => 'en', 'title' => "Bulk {$id}", 'slug' => "bulk-{$id}",
                'status' => 'published', 'published_at' => now()->subMinutes(10000 - $id)->toDateTimeString(),
                'created_at' => $now, 'updated_at' => $now,
            ])->all());
        }

        $entries = $this->sitemap();

        $stories = array_filter(array_keys($entries), fn ($url) => str_contains($url, '/news/'));
        $this->assertCount(5000, $stories);
        $this->assertArrayHasKey(route('article', ['locale' => 'en', 'idSlug' => '5003-bulk-5003']), $entries);
        $this->assertArrayNotHasKey(route('article', ['locale' => 'en', 'idSlug' => '2-bulk-2']), $entries, 'the oldest stories fall off first');
    }

    public function test_the_sitemap_is_cached_for_ten_minutes(): void
    {
        $first = $this->makeStory(Locale::En);
        $this->assertArrayHasKey($first->url(), $this->sitemap());

        $second = $this->makeStory(Locale::En);
        $this->assertArrayNotHasKey($second->url(), $this->sitemap(), 'served from cache');

        $this->travel(11)->minutes();
        $this->assertArrayHasKey($second->url(), $this->sitemap(), 'cache expired');
    }

    public function test_robots_txt_points_crawlers_at_the_sitemap_and_keeps_them_out_of_the_admin(): void
    {
        $robots = file_get_contents(public_path('robots.txt'));

        $this->assertStringContainsString("User-agent: *\n", $robots);
        $this->assertStringContainsString("Disallow: /admin\n", $robots);
        $this->assertStringContainsString("Disallow: /newsletter\n", $robots);
        $this->assertStringContainsString("Sitemap: https://sisi.news/sitemap.xml\n", $robots);
        $this->assertStringNotContainsString("Disallow: /\n", $robots);
    }
}
