<?php

namespace Tests\Feature\Security;

use App\Enums\LiveProvider;
use App\Enums\LiveStatus;
use App\Http\Middleware\SecurityHeaders;
use App\Models\ArticleTranslation;
use App\Models\Governorate;
use App\Models\LiveStream;
use App\Models\NewsletterSubscriber;
use App\Providers\AppServiceProvider;
use Illuminate\Contracts\Http\Kernel;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Vite;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

class SecurityHeadersTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Route::middleware(SecurityHeaders::class)->group(function () {
            Route::get('/__headers/page', fn () => 'public page');
            Route::get('/__headers/missing', fn () => abort(404));
            Route::get('/admin/__headers/panel', fn () => 'staff page');
            Route::get('/admin/__headers/users/1/edit', fn () => 'staff page');
            Route::post('/livewire-abcd1234/update', fn () => 'livewire');
        });
    }

    /** @return array<string, string> directive => sources */
    private function directives(TestResponse $response): array
    {
        $policy = $response->headers->get('Content-Security-Policy');
        $this->assertNotNull($policy, 'no Content-Security-Policy header');

        $directives = [];
        foreach (explode(';', $policy) as $directive) {
            $directive = trim($directive);
            $directives[strtok($directive, ' ')] = trim((string) strstr($directive, ' '));
        }

        return $directives;
    }

    public function test_a_public_page_gets_the_hardening_headers(): void
    {
        $response = $this->get('/__headers/page');

        $response->assertOk()
            ->assertHeader('X-Content-Type-Options', 'nosniff')
            ->assertHeader('X-Frame-Options', 'SAMEORIGIN')
            ->assertHeader('Referrer-Policy', 'strict-origin-when-cross-origin')
            ->assertHeader('Permissions-Policy', 'camera=(), microphone=(), geolocation=(), payment=(), usb=()')
            ->assertHeaderMissing('Strict-Transport-Security');
    }

    public function test_error_pages_are_covered_too(): void
    {
        $this->get('/__headers/missing')
            ->assertNotFound()
            ->assertHeader('X-Content-Type-Options', 'nosniff')
            ->assertHeader('Content-Security-Policy');
    }

    public function test_the_content_security_policy_allows_only_this_site(): void
    {
        $response = $this->get('/__headers/page');
        $directives = $this->directives($response);

        $this->assertSame("'self'", $directives['default-src']);
        $this->assertMatchesRegularExpression("/^'self' 'nonce-[A-Za-z0-9]{40}' 'sha256-[^']+'/", $directives['script-src'], 'scripts: this site, nonce-stamped tags or known inline hashes');
        $this->assertStringNotContainsString('unsafe', $directives['script-src']);
        $this->assertMatchesRegularExpression("/^'self' 'nonce-[A-Za-z0-9]{40}'$/", $directives['style-src']);
        $this->assertSame("'unsafe-inline'", $directives['style-src-attr'], 'only style="" attributes may be inline');
        $this->assertSame("'self' data: https:", $directives['img-src']);
        $this->assertSame("'self'", $directives['font-src']);
        $this->assertSame("'self'", $directives['connect-src']);
        $this->assertSame("'none'", $directives['object-src']);
        $this->assertSame("'self'", $directives['base-uri']);
        $this->assertSame("'self'", $directives['form-action']);
        $this->assertSame("'self'", $directives['frame-ancestors']);
        $this->assertArrayNotHasKey('upgrade-insecure-requests', $directives, 'plain http (local dev) must keep working');
    }

    public function test_every_response_gets_a_fresh_nonce(): void
    {
        $first = $this->directives($this->get('/__headers/page'))['script-src'];
        $second = $this->directives($this->get('/__headers/page'))['script-src'];

        $this->assertNotSame($first, $second);
    }

    public function test_the_staff_panel_and_livewire_are_left_alone(): void
    {
        foreach (['/admin/__headers/panel', '/admin/__headers/users/1/edit'] as $uri) {
            $this->get($uri)->assertOk()
                ->assertHeaderMissing('Content-Security-Policy')
                ->assertHeaderMissing('X-Frame-Options');
        }

        $this->post('/livewire-abcd1234/update')->assertOk()->assertHeaderMissing('Content-Security-Policy');
    }

    public function test_hsts_is_sent_only_over_https_in_production(): void
    {
        $this->get('https://localhost/__headers/page')->assertHeaderMissing('Strict-Transport-Security');

        $this->app['env'] = 'production';

        $this->get('http://localhost/__headers/page')->assertHeaderMissing('Strict-Transport-Security');

        $secure = $this->get('https://localhost/__headers/page');
        $secure->assertHeader('Strict-Transport-Security', 'max-age=604800');
        $this->assertArrayHasKey('upgrade-insecure-requests', $this->directives($secure));
    }

    public function test_hsts_lasts_one_week_by_default_and_never_covers_subdomains_or_preload(): void
    {
        $this->app['env'] = 'production';

        $header = $this->get('https://localhost/__headers/page')->headers->get('Strict-Transport-Security');

        $this->assertSame('max-age=604800', $header);
        $this->assertStringNotContainsStringIgnoringCase('includeSubDomains', $header);
        $this->assertStringNotContainsStringIgnoringCase('preload', $header);
    }

    public function test_the_hsts_lifetime_comes_from_the_security_hsts_max_age_setting(): void
    {
        $this->app['env'] = 'production';

        config(['app.hsts_max_age' => '2592000']);
        $this->get('https://localhost/__headers/page')->assertHeader('Strict-Transport-Security', 'max-age=2592000');

        config(['app.hsts_max_age' => 31536000]);
        $this->get('https://localhost/__headers/page')->assertHeader('Strict-Transport-Security', 'max-age=31536000');

        config(['app.hsts_max_age' => 0]);
        $this->get('https://localhost/__headers/page')->assertHeader('Strict-Transport-Security', 'max-age=0');
    }

    public function test_a_blank_or_mistyped_hsts_setting_falls_back_to_the_week_instead_of_switching_hsts_off(): void
    {
        $this->app['env'] = 'production';

        foreach ([null, '', 'forever', '-5', 'one week'] as $broken) {
            config(['app.hsts_max_age' => $broken]);
            $this->get('https://localhost/__headers/page')->assertHeader('Strict-Transport-Security', 'max-age=604800');
        }
    }

    public function test_the_config_reads_the_hsts_lifetime_from_env_with_a_week_as_default(): void
    {
        $previous = getenv('SECURITY_HSTS_MAX_AGE');
        $load = function (?string $value): mixed {
            $value === null ? putenv('SECURITY_HSTS_MAX_AGE') : putenv("SECURITY_HSTS_MAX_AGE={$value}");
            unset($_ENV['SECURITY_HSTS_MAX_AGE'], $_SERVER['SECURITY_HSTS_MAX_AGE']);

            return (require base_path('config/app.php'))['hsts_max_age'];
        };

        try {
            $this->assertSame(604800, $load(null));
            $this->assertEquals(2592000, $load('2592000'));
        } finally {
            $previous === false ? putenv('SECURITY_HSTS_MAX_AGE') : putenv("SECURITY_HSTS_MAX_AGE={$previous}");
        }

        $this->assertStringContainsString('SECURITY_HSTS_MAX_AGE=604800', file_get_contents(base_path('.env.example')), 'documented in the production template');
    }

    // --- preview and alias hostnames are never indexed ------------------------------------------

    public function test_a_hostname_other_than_the_canonical_one_is_marked_noindex_in_production(): void
    {
        $this->app['env'] = 'production';
        config(['app.url' => 'https://sisi.news']);

        foreach (['http://preview.example.com', 'https://sisi.news.example.com', 'http://92.205.88.46', 'https://sisi.example', 'https://news.sisi.news'] as $origin) {
            $this->get($origin.'/__headers/page')->assertHeader('X-Robots-Tag', 'noindex, nofollow');
        }
    }

    public function test_the_canonical_host_with_or_without_www_is_indexable(): void
    {
        $this->app['env'] = 'production';
        config(['app.url' => 'https://sisi.news']);

        foreach (['https://sisi.news', 'http://sisi.news', 'https://www.sisi.news', 'http://www.sisi.news:8080', 'https://SISI.NEWS'] as $origin) {
            $this->get($origin.'/__headers/page')->assertHeaderMissing('X-Robots-Tag');
        }

        // The rule also holds the other way round: APP_URL may itself carry the www.
        config(['app.url' => 'https://www.sisi.news']);
        $this->get('https://sisi.news/__headers/page')->assertHeaderMissing('X-Robots-Tag');
        $this->get('https://www.sisi.news/__headers/page')->assertHeaderMissing('X-Robots-Tag');
        $this->get('https://other.example/__headers/page')->assertHeader('X-Robots-Tag', 'noindex, nofollow');
    }

    public function test_noindex_is_never_sent_outside_production(): void
    {
        config(['app.url' => 'https://sisi.news']);

        foreach (['testing', 'local', 'staging'] as $environment) {
            $this->app['env'] = $environment;
            $this->get('http://preview.example.com/__headers/page')->assertHeaderMissing('X-Robots-Tag');
            $this->get('http://localhost:8000/__headers/page')->assertHeaderMissing('X-Robots-Tag');
        }
    }

    public function test_noindex_covers_every_response_on_an_alias_host_including_errors_and_the_staff_area(): void
    {
        $this->app['env'] = 'production';
        config(['app.url' => 'https://sisi.news']);

        $this->get('http://preview.example.com/__headers/missing')->assertNotFound()->assertHeader('X-Robots-Tag', 'noindex, nofollow');
        $this->get('http://preview.example.com/admin/__headers/panel')->assertOk()->assertHeader('X-Robots-Tag', 'noindex, nofollow');
        $this->post('http://preview.example.com/livewire-abcd1234/update')->assertOk()->assertHeader('X-Robots-Tag', 'noindex, nofollow');
        $this->get('http://sisi.news/admin/__headers/panel')->assertOk()->assertHeaderMissing('X-Robots-Tag');
    }

    public function test_an_app_url_without_a_host_never_turns_the_whole_site_noindex(): void
    {
        $this->app['env'] = 'production';

        foreach (['', 'sisi', 'not a url'] as $broken) {
            config(['app.url' => $broken]);
            $this->get('https://sisi.news/__headers/page')->assertHeaderMissing('X-Robots-Tag');
        }
    }

    public function test_the_vite_dev_server_is_allowed_locally_but_never_in_production(): void
    {
        $hot = tempnam(sys_get_temp_dir(), 'hot');
        file_put_contents($hot, 'http://localhost:5173');
        Vite::useHotFile($hot);

        try {
            $local = $this->get('/__headers/page');
            $this->assertStringContainsString('http://localhost:5173', $this->directives($local)['script-src']);
            $this->assertStringContainsString('ws://localhost:5173', $this->directives($local)['connect-src']);

            $this->app['env'] = 'production';
            $this->assertStringNotContainsString('5173', $this->get('/__headers/page')->headers->get('Content-Security-Policy'));
        } finally {
            unlink($hot);
        }
    }

    public function test_https_urls_follow_the_force_https_switch_not_the_environment(): void
    {
        // Production alone does not force https: the site must run over http until the certificate exists.
        config(['app.force_https' => false]);
        $this->app['env'] = 'production';
        (new AppServiceProvider($this->app))->boot();
        $this->assertStringStartsWith('http://', url('/x'));

        config(['app.force_https' => true]);
        (new AppServiceProvider($this->app))->boot();
        $this->assertStringStartsWith('https://', url('/x'));
    }

    public function test_the_force_https_switch_is_off_unless_the_environment_turns_it_on(): void
    {
        $previous = getenv('APP_FORCE_HTTPS');
        $load = function (?string $value): bool {
            $value === null ? putenv('APP_FORCE_HTTPS') : putenv("APP_FORCE_HTTPS={$value}");
            unset($_ENV['APP_FORCE_HTTPS'], $_SERVER['APP_FORCE_HTTPS']);

            // Re-requiring the file shows what a deploy with this .env line would load.
            return (require base_path('config/app.php'))['force_https'];
        };

        try {
            $this->assertFalse($load(null), 'unset: a host without a certificate keeps working over http');
            $this->assertFalse($load('false'));
            $this->assertTrue($load('true'));
        } finally {
            $previous === false ? putenv('APP_FORCE_HTTPS') : putenv("APP_FORCE_HTTPS={$previous}");
        }
    }

    /**
     * This is exactly the change the coordinator makes in bootstrap/app.php:
     * $middleware->append(SecurityHeaders::class). Global, not the "web" group, so that 404s for
     * URLs no route matches (scanners probing /wp-login.php) carry the headers too.
     */
    private function attachGlobally(): void
    {
        $this->app->make(Kernel::class)->pushMiddleware(SecurityHeaders::class);
    }

    /** @return array<string, array{string, int}> label => [url, status] of real pages that render inline scripts or styles */
    private function realPages(): array
    {
        $translation = ArticleTranslation::factory()->published()->arabic()->create();
        $subscriber = NewsletterSubscriber::create(['email' => 'a@example.com', 'locale' => 'ar']);

        return [
            'arabic home' => ['/ar', 200],
            'english home' => ['/en', 200],
            'article' => [$translation->url(), 200],
            'unsubscribe' => [route('newsletter.unsubscribe', $subscriber->unsubscribe_token), 200],
            'missing article' => ['/ar/news/99999-nothing', 404],
            'live page' => ['/ar/live', 200],
            'live page with a broadcast' => ['/en/live?g='.$this->aLiveBroadcast(), 200],
            // No route matches at all: only a global middleware sees this request.
            'unrouted url' => ['/wp-login.php', 404],
        ];
    }

    public function test_attached_to_the_real_site_every_page_is_covered_and_nothing_is_blocked(): void
    {
        $this->attachGlobally();

        foreach ($this->realPages() as $label => [$url, $status]) {
            $response = $this->get($url);
            $this->assertSame($status, $response->getStatusCode(), "{$label} ({$url}) returned an unexpected status");
            $response->assertHeader('X-Content-Type-Options', 'nosniff');
            $html = $response->getContent();
            $csp = $response->headers->get('Content-Security-Policy');
            $this->assertNotNull($csp, "{$label} has no CSP");
            preg_match('/script-src ([^;]+)/', $csp, $scriptSrc);
            preg_match("/'nonce-([A-Za-z0-9]+)'/", $csp, $nonce);

            // Executable inline scripts must carry the nonce or have their hash listed.
            preg_match_all('#<script\b([^>]*)>(.*?)</script>#si', $html, $scripts, PREG_SET_ORDER);
            foreach ($scripts as [, $attributes, $body]) {
                if (str_contains($attributes, 'src=') || preg_match('/type=["\']application\/(ld\+)?json["\']/i', $attributes)) {
                    continue;
                }
                $hash = "'sha256-".base64_encode(hash('sha256', $body, true))."'";
                $this->assertTrue(
                    str_contains($attributes, 'nonce="'.$nonce[1].'"') || str_contains($scriptSrc[1], $hash),
                    "{$label}: an inline script would be blocked by the CSP: ".substr(trim($body), 0, 80)."\nadd nonce=\"{{ Vite::cspNonce() }}\" to it, or list {$hash} in SecurityHeaders::INLINE_SCRIPT_HASHES",
                );
            }

            // <style> blocks need the nonce; style="" attributes are covered by style-src-attr.
            preg_match_all('#<style\b([^>]*)>#i', $html, $styles);
            foreach ($styles[1] as $attributes) {
                $this->assertStringContainsString('nonce="'.$nonce[1].'"', $attributes, "{$label}: an inline <style> without the CSP nonce would be blocked");
            }

            // Nothing the CSP would silently drop: inline handlers, javascript: URLs, third-party scripts.
            $this->assertDoesNotMatchRegularExpression('/<[a-z][^>]*\son[a-z]+\s*=/i', $html, "{$label}: inline event handler attribute");
            $this->assertDoesNotMatchRegularExpression('/(href|src|action)\s*=\s*["\']\s*javascript:/i', $html, "{$label}: javascript: URL");
            preg_match_all('#<script\b[^>]*\ssrc=["\']([^"\']+)#i', $html, $sources);
            foreach ($sources[1] as $src) {
                $this->assertTrue(! preg_match('#^(https?:)?//#', $src) || str_starts_with($src, config('app.url')), "{$label}: third-party script {$src}");
            }
        }
    }

    public function test_attached_globally_the_staff_login_page_is_not_touched(): void
    {
        $this->attachGlobally();

        $this->get('/admin/login')
            ->assertOk()
            ->assertHeaderMissing('Content-Security-Policy');
    }

    // --- the Live page may frame the video players, and nothing else may ---------------------------------

    /** A live Cairo broadcast, so the Live page renders a real player. @return string the governorate slug */
    private function aLiveBroadcast(): string
    {
        $governorate = (new Governorate)->forceFill(['slug' => 'cairo', 'name_en' => 'Cairo', 'name_ar' => 'القاهرة', 'sort_order' => 1]);
        $governorate->save();
        $stream = (new LiveStream)->forceFill([
            'governorate_id' => $governorate->id, 'provider' => LiveProvider::YouTube, 'video_ref' => 'abcdefghijk',
            'status' => LiveStatus::Live, 'started_at' => now(),
        ]);
        $stream->save();
        $stream->translations()->create(['locale' => 'en', 'title' => 'On air']);

        return 'cairo';
    }

    /** The policy with the per-response nonce masked, so two responses can be compared directive by directive. */
    private function maskedDirectives(TestResponse $response): array
    {
        return array_map(fn (string $sources) => preg_replace("/'nonce-[A-Za-z0-9]+'/", "'nonce-X'", $sources), $this->directives($response));
    }

    public function test_only_the_live_page_may_frame_the_video_players_and_only_their_hosts(): void
    {
        $this->attachGlobally();
        $this->aLiveBroadcast();

        foreach (['/ar/live', '/en/live', '/en/live?g=cairo', '/en/live?g=atlantis', '/ar/live?g[]=x'] as $url) {
            $directives = $this->directives($this->get($url)->assertOk());

            $this->assertSame(
                implode(' ', LiveProvider::frameHosts()),
                $directives['frame-src'],
                "{$url}: frame-src names the player hosts and nothing else",
            );
            $this->assertSame('https://www.youtube-nocookie.com https://player.vimeo.com', $directives['frame-src']);
            $this->assertStringNotContainsString('*', $directives['frame-src']);
            $this->assertStringNotContainsString("'self'", $directives['frame-src']);
        }
    }

    public function test_every_other_page_still_has_no_frame_src(): void
    {
        $this->attachGlobally();
        $translation = ArticleTranslation::factory()->published()->arabic()->create();

        foreach (['/ar', '/en', $translation->url(), '/en/search?q=news', '/ar/live-not-a-page', '/ar/news/99999-nothing', '/wp-login.php', '/en/feed.xml'] as $url) {
            $response = $this->get($url);
            $this->assertArrayNotHasKey('frame-src', $this->directives($response), "{$url} must not be allowed to frame anything");
            $this->assertStringNotContainsString('youtube', $response->headers->get('Content-Security-Policy'), $url);
            $this->assertStringNotContainsString('vimeo', $response->headers->get('Content-Security-Policy'), $url);
        }
    }

    public function test_the_live_page_policy_differs_from_the_home_page_policy_by_frame_src_alone(): void
    {
        $this->attachGlobally();
        $this->aLiveBroadcast();

        $home = $this->maskedDirectives($this->get('/en')->assertOk());
        $live = $this->maskedDirectives($this->get('/en/live')->assertOk());

        $this->assertArrayHasKey('frame-src', $live);
        unset($live['frame-src']);

        $this->assertSame($home, $live, 'script-src, style-src, img-src, connect-src and every other directive is exactly as strict as on any other page');
        $this->assertStringNotContainsString('unsafe', $live['script-src']);
        $this->assertStringNotContainsString('youtube', $live['script-src'].$live['connect-src'].$live['img-src'].$live['default-src']);
        $this->assertStringNotContainsString('vimeo', $live['script-src'].$live['connect-src'].$live['img-src'].$live['default-src']);
    }

    public function test_the_hosts_the_policy_allows_are_exactly_the_hosts_the_embed_addresses_use(): void
    {
        $hosts = array_map(fn (string $url) => parse_url($url, PHP_URL_HOST), LiveProvider::frameHosts());
        $embedHosts = array_map(fn (LiveProvider $p) => parse_url($p->embedUrl('abcdefghijk'), PHP_URL_HOST), LiveProvider::cases());

        $this->assertEqualsCanonicalizing($embedHosts, $hosts);
    }

    public function test_the_live_page_is_not_marked_as_the_staff_area(): void
    {
        $this->attachGlobally();

        $this->get('/admin/login')->assertHeaderMissing('Content-Security-Policy');
        $this->get('/en/live')->assertOk()->assertHeader('Content-Security-Policy');
    }
}
