<?php

namespace Tests\Feature\Security;

use App\Enums\Locale;
use App\Support\CanonicalHost;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use PHPUnit\Framework\Attributes\DataProvider;
use ReflectionProperty;
use Tests\Feature\Site\Concerns\MakesStories;
use Tests\TestCase;

/**
 * The sitemap and the feed print absolute URLs built from the request's host, and the Host header is chosen by
 * whoever sends the request. Their cache is keyed on the host so one host never receives another's URLs; these
 * tests pin the other half: a host the site does not own can neither poison a shared entry nor make the cache
 * grow with keys of its own choosing.
 */
class HostHeaderCacheTest extends TestCase
{
    use MakesStories, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config(['app.url' => 'https://sisi.news', 'app.force_https' => false]);
        Cache::flush();
    }

    /** @return list<string> every sitemap/feed key the cache holds right now */
    private function cachedDocuments(): array
    {
        $storage = (new ReflectionProperty(Cache::store('array')->getStore(), 'storage'))->getValue(Cache::store('array')->getStore());

        return array_values(array_filter(array_keys($storage), fn (string $key) => preg_match('/site\.(sitemap|feed)\./', $key) === 1));
    }

    // --- a host the site does not own is never stored -------------------------------------------

    public function test_a_forged_host_is_rendered_for_its_sender_and_never_cached(): void
    {
        $first = $this->makeStory(Locale::En);

        foreach (['/sitemap.xml', '/en/feed.xml'] as $path) {
            $body = $this->get('http://evil.example'.$path)->assertOk()->getContent();
            $this->assertStringContainsString('http://evil.example/en/news/'.$first->article_id, $body);
            // The feed's channel title is the site name, so look for the address, not the name.
            $this->assertStringNotContainsString('://sisi.news', $body);
        }
        $this->assertSame([], $this->cachedDocuments(), 'nothing is stored for a host the site does not own');

        // A story added a moment later shows at once: there is no stale copy to be served to anyone.
        $second = $this->makeStory(Locale::En);
        $this->get('http://evil.example/sitemap.xml')->assertOk()->assertSee('/en/news/'.$second->article_id, false);
        $this->get('http://evil.example/en/feed.xml')->assertOk()->assertSee('/en/news/'.$second->article_id, false);
    }

    public function test_the_real_host_never_receives_urls_built_from_a_forged_one(): void
    {
        $this->makeStory(Locale::En);

        $this->get('http://evil.example/sitemap.xml')->assertOk();
        $this->get('http://evil.example/en/feed.xml')->assertOk();

        $this->get('https://sisi.news/sitemap.xml')->assertOk()->assertDontSee('evil.example', false)->assertSee('https://sisi.news/en/news/', false);
        $this->get('https://sisi.news/en/feed.xml')->assertOk()->assertDontSee('evil.example', false)->assertSee('https://sisi.news/en/news/', false);

        // And the other way round: the real host's cached copy is not handed to a forged host either.
        $this->get('http://evil.example/sitemap.xml')->assertOk()->assertDontSee('://sisi.news', false);
    }

    public function test_a_flood_of_invented_hosts_and_ports_leaves_the_cache_untouched(): void
    {
        $this->makeStory(Locale::En);

        foreach (range(1, 25) as $n) {
            $this->get("http://host-{$n}.example/sitemap.xml")->assertOk();
            $this->get("http://host-{$n}.example/ar/feed.xml")->assertOk();
            // The real hostname on an invented port is not one of the site's addresses either.
            $this->get('https://sisi.news:'.(9000 + $n).'/sitemap.xml')->assertOk();
            $this->get('https://sisi.news:'.(9000 + $n).'/en/feed.xml')->assertOk();
        }

        $this->assertSame([], $this->cachedDocuments());
    }

    // --- the site's own addresses are still cached, and the set of keys is small ------------------

    public function test_the_sites_own_host_is_still_cached_per_origin(): void
    {
        $this->makeStory(Locale::En);

        foreach (['https://sisi.news', 'http://sisi.news', 'https://www.sisi.news', 'http://www.sisi.news'] as $origin) {
            $this->get($origin.'/sitemap.xml')->assertOk()->assertSee($origin.'/en/news/', false);
            $this->get($origin.'/en/feed.xml')->assertOk()->assertSee($origin.'/en/news/', false);
        }
        $this->assertCount(8, $this->cachedDocuments());

        $this->makeStory(Locale::En);
        $stale = $this->get('https://www.sisi.news/sitemap.xml')->assertOk()->getContent();
        $this->assertSame(1, substr_count($stale, '<loc>https://www.sisi.news/en/news/'), 'served from the cache, so the new story is not in it yet');
        $this->assertCount(8, $this->cachedDocuments(), 'asking again adds no keys');
    }

    public function test_the_port_named_by_app_url_is_cached_for_a_development_machine(): void
    {
        config(['app.url' => 'http://localhost:8000']);
        $this->makeStory(Locale::En);

        $this->get('http://localhost:8000/sitemap.xml')->assertOk();
        $this->get('http://localhost:8000/en/feed.xml')->assertOk();
        $this->assertCount(2, $this->cachedDocuments());

        $this->get('http://localhost:8001/sitemap.xml')->assertOk();
        $this->assertCount(2, $this->cachedDocuments(), 'another port is not the site');
    }

    public function test_nothing_is_cached_when_app_url_names_no_host(): void
    {
        config(['app.url' => 'not a url']);
        $this->makeStory(Locale::En);

        $this->get('http://sisi.news/sitemap.xml')->assertOk();
        $this->get('http://sisi.news/en/feed.xml')->assertOk();

        $this->assertSame([], $this->cachedDocuments());
    }

    // --- the rule itself ------------------------------------------------------------------------

    /** @return array<string, array{string, ?string}> */
    public static function origins(): array
    {
        return [
            'https' => ['https://sisi.news/sitemap.xml', 'https://sisi.news'],
            'plain http' => ['http://sisi.news/sitemap.xml', 'http://sisi.news'],
            'the www alias' => ['https://www.sisi.news/sitemap.xml', 'https://www.sisi.news'],
            'upper case is folded' => ['https://WWW.SISI.NEWS/sitemap.xml', 'https://www.sisi.news'],
            'an invented port' => ['https://sisi.news:8443/sitemap.xml', null],
            'a port on plain http' => ['http://sisi.news:8080/sitemap.xml', null],
            'a longer name that ends the same way' => ['https://evilsisi.news/sitemap.xml', null],
            'a sub-domain of the real name' => ['https://cdn.sisi.news/sitemap.xml', null],
            'the real name inside another domain' => ['https://sisi.news.evil.example/sitemap.xml', null],
            'www twice' => ['https://www.www.sisi.news/sitemap.xml', null],
            'an unrelated host' => ['http://evil.example/sitemap.xml', null],
            'an IP address' => ['http://92.205.88.46/sitemap.xml', null],
        ];
    }

    #[DataProvider('origins')]
    public function test_only_the_sites_own_origins_get_a_cache_key(string $url, ?string $expected): void
    {
        $this->assertSame($expected, CanonicalHost::cacheOrigin(Request::create($url)));
    }

    public function test_a_malformed_host_header_is_not_the_site(): void
    {
        $request = Request::create('https://sisi.news/sitemap.xml');
        $request->headers->set('HOST', 'bad host!');

        $this->assertFalse(CanonicalHost::matches($request));
        $this->assertNull(CanonicalHost::cacheOrigin($request));
    }
}
