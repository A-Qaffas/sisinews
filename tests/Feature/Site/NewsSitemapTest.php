<?php

namespace Tests\Feature\Site;

use App\Enums\Locale;
use App\Enums\TranslationStatus;
use Illuminate\Foundation\Testing\RefreshDatabase;
use SimpleXMLElement;
use Tests\Feature\Site\Concerns\MakesStories;
use Tests\TestCase;

class NewsSitemapTest extends TestCase
{
    use MakesStories, RefreshDatabase;

    private const SITEMAP = 'http://www.sitemaps.org/schemas/sitemap/0.9';

    private const NEWS = 'http://www.google.com/schemas/sitemap-news/0.9';

    /** @return array<string, array{name: string, language: string, published: string, title: string}> keyed by <loc> */
    private function newsSitemap(): array
    {
        $response = $this->get('/news-sitemap.xml')->assertOk();
        $this->assertSame('application/xml; charset=utf-8', $response->headers->get('Content-Type'));

        $previous = libxml_use_internal_errors(true);
        $xml = simplexml_load_string($response->getContent());
        libxml_clear_errors();
        libxml_use_internal_errors($previous);
        $this->assertInstanceOf(SimpleXMLElement::class, $xml, 'news sitemap is not well-formed XML');
        $this->assertSame('urlset', $xml->getName());

        $entries = [];
        foreach ($xml->children(self::SITEMAP)->url as $url) {
            $news = $url->children(self::NEWS)->news;
            $entries[(string) $url->children(self::SITEMAP)->loc] = [
                'name' => (string) $news->publication->name,
                'language' => (string) $news->publication->language,
                'published' => (string) $news->publication_date,
                'title' => (string) $news->title,
            ];
        }

        return $entries;
    }

    public function test_it_lists_stories_from_the_last_two_days_with_their_news_details(): void
    {
        $en = $this->makeStory(Locale::En, attributes: ['title' => 'Cairo metro line opens', 'published_at' => now()->subHours(3)]);
        $ar = $this->makeStory(Locale::Ar, attributes: ['title' => 'افتتاح خط المترو', 'published_at' => now()->subDay()]);

        $entries = $this->newsSitemap();

        $this->assertSame([
            'name' => config('site.name.en'),
            'language' => 'en',
            'published' => $en->published_at->toAtomString(),
            'title' => 'Cairo metro line opens',
        ], $entries[$en->url()]);
        $this->assertSame('ar', $entries[$ar->url()]['language']);
        $this->assertSame('افتتاح خط المترو', $entries[$ar->url()]['title']);
    }

    public function test_it_leaves_out_older_and_invisible_stories(): void
    {
        $fresh = $this->makeStory(Locale::En, attributes: ['published_at' => now()->subHours(47)]);
        $left = [
            $this->makeStory(Locale::En, attributes: ['published_at' => now()->subHours(49)]),
            $this->makeStory(Locale::En, TranslationStatus::Draft, ['published_at' => now()->subHour()]),
            $this->makeStory(Locale::En, attributes: ['published_at' => now()->addHour()]),
            tap($this->makeStory(Locale::En, attributes: ['published_at' => now()->subHour()]), fn ($t) => $t->article->delete()),
        ];

        $entries = $this->newsSitemap();

        $this->assertArrayHasKey($fresh->url(), $entries);
        foreach ($left as $t) {
            $this->assertArrayNotHasKey($t->url(), $entries);
        }
    }

    public function test_titles_are_escaped_so_the_xml_stays_valid(): void
    {
        $t = $this->makeStory(Locale::En, attributes: ['title' => 'Q&A: "Egypt" <now>', 'published_at' => now()->subHour()]);

        $this->assertSame('Q&A: "Egypt" <now>', $this->newsSitemap()[$t->url()]['title']);
    }

    public function test_it_is_cached_for_five_minutes(): void
    {
        $first = $this->makeStory(Locale::En, attributes: ['published_at' => now()->subHour()]);
        $this->assertArrayHasKey($first->url(), $this->newsSitemap());

        $second = $this->makeStory(Locale::En, attributes: ['published_at' => now()->subMinute()]);
        $this->assertArrayNotHasKey($second->url(), $this->newsSitemap(), 'served from cache');

        $this->travel(6)->minutes();
        $this->assertArrayHasKey($second->url(), $this->newsSitemap(), 'cache expired');
    }

    public function test_robots_txt_points_crawlers_at_the_news_sitemap(): void
    {
        $this->assertStringContainsString(
            "Sitemap: https://sisi.news/news-sitemap.xml\n",
            file_get_contents(public_path('robots.txt')),
        );
    }
}
