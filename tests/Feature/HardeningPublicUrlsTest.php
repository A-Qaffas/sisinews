<?php

namespace Tests\Feature;

use App\Enums\Locale;
use App\Models\Article;
use App\Providers\AppServiceProvider;
use App\Support\Media\HeroImages;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\DataProvider;
use ReflectionProperty;
use SimpleXMLElement;
use Tests\Feature\Site\Concerns\MakesStories;
use Tests\TestCase;

/**
 * Pictures are linked from the public disk, whose URL used to come from APP_URL alone. With APP_URL saying
 * https while the site is served over http, or the site reached on a second hostname, every picture broke.
 * In a web request the disk now follows the request, like every other URL the app generates.
 */
class HardeningPublicUrlsTest extends TestCase
{
    use MakesStories, RefreshDatabase;

    private const CONFIGURED = 'https://sisi.news';

    private string $diskRoot;

    protected function setUp(): void
    {
        parent::setUp();

        // A real local disk on the real config (so its url really comes from filesystems.disks.public.url),
        // rooted in a scratch folder instead of storage/app/public.
        $this->diskRoot = storage_path('framework/testing/disks/hardening-public-urls');
        (new Filesystem)->deleteDirectory($this->diskRoot);
        (new Filesystem)->ensureDirectoryExists($this->diskRoot);

        config([
            'app.url' => self::CONFIGURED,
            'app.force_https' => false,
            'filesystems.disks.public.root' => $this->diskRoot,
            'filesystems.disks.public.url' => self::CONFIGURED.'/storage',
        ]);
        Storage::forgetDisk('public');
    }

    protected function tearDown(): void
    {
        (new Filesystem)->deleteDirectory($this->diskRoot);

        parent::tearDown();
    }

    /**
     * What the framework does for a web request: the request is bound first, then the providers boot, and the
     * provider asks whether this is a console run. Phpunit is a console run, so that one answer is switched.
     */
    private function bootForWebRequest(string $origin): void
    {
        $this->app->instance('request', Request::create($origin.'/'));
        (new ReflectionProperty($this->app, 'isRunningInConsole'))->setValue($this->app, false);

        (new AppServiceProvider($this->app))->boot();
    }

    private function storeHero(string $path = 'heroes/lead.jpg'): Article
    {
        Storage::disk('public')->put($path, str_repeat('x', 1234));

        return Article::factory()->create(['hero_image' => $path]);
    }

    /** @return array<string, array{string}> */
    public static function origins(): array
    {
        return [
            'plain http while APP_URL says https' => ['http://sisi.news'],
            'the same host over https' => ['https://sisi.news'],
            'the www alias' => ['https://www.sisi.news'],
            'a provider preview hostname' => ['http://preview-1234.hosting.example'],
            'a bare IP address' => ['http://92.205.88.46'],
            'a dev server with a port' => ['http://127.0.0.1:8132'],
        ];
    }

    // --- the disk -------------------------------------------------------------------------------

    #[DataProvider('origins')]
    public function test_the_public_disk_links_from_the_scheme_and_host_of_the_request(string $origin): void
    {
        Storage::disk('public')->url('warm-up.jpg'); // resolved once, as an earlier provider might

        $this->bootForWebRequest($origin);

        $this->assertSame($origin.'/storage', config('filesystems.disks.public.url'));
        $this->assertSame($origin.'/storage/heroes/lead.jpg', Storage::disk('public')->url('heroes/lead.jpg'));
        $this->storeHero();
        $this->assertSame($origin.'/storage/heroes/lead.jpg', HeroImages::url('heroes/lead.jpg', 'full'));
    }

    public function test_a_variant_url_follows_the_request_too_and_is_percent_encoded(): void
    {
        $this->storeHero('heroes/summer story.png');
        Storage::disk('public')->put('heroes/variants/full/summer story.png.webp', 'w');

        $this->bootForWebRequest('http://alias.example');

        $this->assertSame('http://alias.example/storage/heroes/variants/full/summer%20story.png.webp', HeroImages::url('heroes/summer story.png', 'full'));
    }

    public function test_force_https_wins_over_the_scheme_of_the_request(): void
    {
        config(['app.force_https' => true]);

        $this->bootForWebRequest('http://alias.example');

        $this->assertSame('https://alias.example/storage', config('filesystems.disks.public.url'));
    }

    public function test_the_console_keeps_linking_from_app_url_whatever_request_is_around(): void
    {
        // phpunit is a console run: the request below is the one an artisan call inherits, and it is ignored.
        $this->app->instance('request', Request::create('http://alias.example/'));

        (new AppServiceProvider($this->app))->boot();

        $this->assertTrue($this->app->runningInConsole());
        $this->assertSame(self::CONFIGURED.'/storage', config('filesystems.disks.public.url'));
        $this->storeHero();
        $this->assertSame(self::CONFIGURED.'/storage/heroes/lead.jpg', HeroImages::url('heroes/lead.jpg', 'full'));
    }

    public function test_a_seeder_still_runs_in_the_console_and_its_pictures_still_link_from_app_url(): void
    {
        $this->artisan('db:seed')->assertSuccessful();

        $this->assertSame(self::CONFIGURED.'/storage/articles/demo/lead.jpg', Storage::disk('public')->url('articles/demo/lead.jpg'));
    }

    public function test_a_malformed_host_header_keeps_the_configured_url_instead_of_failing(): void
    {
        $request = Request::create('http://sisi.news/up');
        $request->headers->set('HOST', 'bad host!');

        (new AppServiceProvider($this->app))->publicDiskFollowsRequest($request);

        $this->assertSame(self::CONFIGURED.'/storage', config('filesystems.disks.public.url'));
    }

    // --- everything that prints an absolute picture URL -----------------------------------------

    #[DataProvider('origins')]
    public function test_an_article_page_links_its_pictures_and_share_image_from_the_request_host(string $origin): void
    {
        $story = $this->makeStory(Locale::En, article: $this->storeHero());
        $picture = $origin.'/storage/heroes/lead.jpg';

        $this->bootForWebRequest($origin);
        $response = $this->get($origin.parse_url($story->url(), PHP_URL_PATH))->assertOk();
        $html = $response->getContent();

        // Open Graph and Twitter
        $this->assertStringContainsString('<meta property="og:image" content="'.$picture.'">', $html);
        // JSON-LD
        $this->assertSame([$picture], $this->jsonLd($html)['image']);
        // The picture in the page itself
        $this->assertStringContainsString('src="'.$picture.'"', $html);
        $this->assertStringContainsString($picture.' 1280w', $html);
        // Nothing may still point at the address APP_URL names, unless the request came in on it.
        if ($origin !== self::CONFIGURED) {
            $this->assertStringNotContainsString(self::CONFIGURED.'/storage', $html);
        }
    }

    public function test_the_share_image_of_a_story_without_a_picture_follows_the_host_as_well(): void
    {
        $story = $this->makeStory(Locale::En);

        $this->bootForWebRequest('http://alias.example');
        $html = $this->get('http://alias.example'.parse_url($story->url(), PHP_URL_PATH))->assertOk()->getContent();

        $this->assertStringContainsString('<meta property="og:image" content="http://alias.example/brand/og-default.png">', $html);
        $this->assertSame('http://alias.example/brand/logo.png', $this->jsonLd($html)['publisher']['logo']['url']);
    }

    #[DataProvider('origins')]
    public function test_the_feed_enclosure_and_links_follow_the_request_host(string $origin): void
    {
        $story = $this->makeStory(Locale::En, article: $this->storeHero());

        $this->bootForWebRequest($origin);
        $xml = $this->xml($this->get($origin.'/en/feed.xml')->assertOk()->getContent());

        $enclosure = $xml->channel->item[0]->enclosure->attributes();
        $this->assertSame($origin.'/storage/heroes/lead.jpg', (string) $enclosure['url']);
        $this->assertSame('1234', (string) $enclosure['length'], 'the length is still read from the file behind the URL');
        $this->assertSame($origin.parse_url($story->url(), PHP_URL_PATH), (string) $xml->channel->item[0]->link);
        $this->assertSame($origin.'/brand/logo.png', (string) $xml->channel->image->url);
    }

    #[DataProvider('origins')]
    public function test_the_sitemap_follows_the_request_host(string $origin): void
    {
        $story = $this->makeStory(Locale::En, article: $this->storeHero());

        $this->bootForWebRequest($origin);
        $xml = $this->xml($this->get($origin.'/sitemap.xml')->assertOk()->getContent());

        $locations = [];
        foreach ($xml->children('http://www.sitemaps.org/schemas/sitemap/0.9')->url as $url) {
            $locations[] = (string) $url->loc;
        }

        $this->assertContains($origin.parse_url($story->url(), PHP_URL_PATH), $locations);
        foreach ($locations as $location) {
            $this->assertStringStartsWith($origin.'/', $location);
        }
    }

    public function test_cached_feeds_and_sitemaps_never_leak_one_hosts_urls_to_another(): void
    {
        $this->makeStory(Locale::En, article: $this->storeHero());

        foreach (['http://alias.example', self::CONFIGURED, 'http://alias.example', self::CONFIGURED] as $origin) {
            $this->bootForWebRequest($origin);

            $feed = $this->get($origin.'/en/feed.xml')->assertOk()->getContent();
            $this->assertStringContainsString($origin.'/storage/heroes/lead.jpg', $feed);
            $this->assertStringNotContainsString($origin === self::CONFIGURED ? 'alias.example' : 'sisi.news/', $feed);

            $sitemap = $this->get($origin.'/sitemap.xml')->assertOk()->getContent();
            $this->assertStringNotContainsString($origin === self::CONFIGURED ? 'alias.example' : 'sisi.news/', $sitemap);
        }
    }

    private function xml(string $body): SimpleXMLElement
    {
        $previous = libxml_use_internal_errors(true);
        $xml = simplexml_load_string($body);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);
        $this->assertInstanceOf(SimpleXMLElement::class, $xml, 'not well-formed XML');

        return $xml;
    }
}
