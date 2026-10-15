<?php

namespace Tests\Feature\Security;

use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * public/.htaccess is not executed by phpunit, so these tests pin what it says: Laravel's front controller is
 * intact, every addition is behind an <IfModule> guard (a module missing on the host must never make it a 500),
 * the private-file patterns match what they should and nothing more, and pages and feeds are never cached for long.
 * Apache itself was run against the file by hand (see the hardening report); this guards it against edits.
 */
class PublicHtaccessTest extends TestCase
{
    private string $htaccess;

    protected function setUp(): void
    {
        parent::setUp();

        $this->htaccess = (string) file_get_contents(public_path('.htaccess'));
    }

    /** @return list<string> non-blank, non-comment lines, trimmed */
    private function directives(): array
    {
        return array_values(array_filter(
            array_map('trim', explode("\n", $this->htaccess)),
            fn (string $line) => $line !== '' && ! str_starts_with($line, '#'),
        ));
    }

    /** @return list<string> the first quoted pattern of every line that starts with $prefix */
    private function patterns(string $prefix): array
    {
        $found = [];
        foreach ($this->directives() as $line) {
            if (str_starts_with($line, $prefix) && preg_match('/"([^"]+)"/', $line, $match)) {
                $found[] = $match[1];
            }
        }

        return $found;
    }

    public function test_laravels_front_controller_block_is_intact(): void
    {
        foreach ([
            '<IfModule mod_rewrite.c>',
            'Options -MultiViews -Indexes',
            'RewriteEngine On',
            'RewriteCond %{HTTP:Authorization} .',
            'RewriteRule .* - [E=HTTP_AUTHORIZATION:%{HTTP:Authorization}]',
            'RewriteCond %{HTTP:x-xsrf-token} .',
            'RewriteRule .* - [E=HTTP_X_XSRF_TOKEN:%{HTTP:X-XSRF-Token}]',
            'RewriteCond %{REQUEST_URI} (.+)/$',
            'RewriteRule ^ %1 [L,R=301]',
            'RewriteRule ^ index.php [L]',
        ] as $line) {
            $this->assertContains($line, $this->directives(), "Laravel's own line is gone: {$line}");
        }

        // The two conditions that keep real files and folders out of the front controller, in Laravel's order.
        $this->assertMatchesRegularExpression(
            '~RewriteCond %\{REQUEST_FILENAME\} !-d\s+RewriteCond %\{REQUEST_FILENAME\} !-f\s+RewriteRule \^ index\.php \[L\]~',
            $this->htaccess,
        );
    }

    public function test_every_addition_sits_inside_an_ifmodule_guard(): void
    {
        $depth = 0;
        $guarded = 0;
        foreach ($this->directives() as $line) {
            if (preg_match('/^<IfModule\s+!?mod_[a-z_]+\.c>$/', $line)) {
                $depth++;

                continue;
            }
            if ($line === '</IfModule>') {
                $depth--;
                $this->assertGreaterThanOrEqual(0, $depth, 'a closing </IfModule> has no opening tag');

                continue;
            }
            if (preg_match('/^<\/?FilesMatch\b/', $line)) {
                $this->assertGreaterThan(0, $depth, "outside any <IfModule>: {$line}");

                continue;
            }

            $this->assertGreaterThan(0, $depth, "a directive outside every <IfModule> guard: {$line}");
            $guarded++;
        }

        $this->assertSame(0, $depth, 'unbalanced <IfModule> tags');
        $this->assertGreaterThan(20, $guarded);
    }

    public function test_directives_that_need_a_second_module_are_guarded_for_that_module_too(): void
    {
        // AddOutputFilterByType belongs to mod_filter on Apache 2.4; mod_deflate alone is not enough to be safe.
        $this->assertMatchesRegularExpression('~<IfModule mod_deflate\.c>\s*<IfModule mod_filter\.c>\s*AddOutputFilterByType~', $this->htaccess);
        // Header needs mod_headers, SetEnvIf needs mod_setenvif.
        $directives = $this->directives();
        $this->assertSame('<IfModule mod_headers.c>', $directives[array_search('<IfModule mod_headers.c>', $directives, true)]);
        $this->assertStringStartsWith('Header set Cache-Control', $directives[array_search('<IfModule mod_headers.c>', $directives, true) + 1]);
        $this->assertStringStartsWith('SetEnvIfNoCase', $directives[array_search('<IfModule mod_setenvif.c>', $directives, true) + 1]);
        // Apache 2.4 (Require) with a fallback for the older Order/Deny syntax.
        $this->assertStringContainsString('Require all denied', $this->htaccess);
        $this->assertMatchesRegularExpression('~<IfModule !mod_authz_core\.c>\s*<IfModule mod_access_compat\.c>~', $this->htaccess);
    }

    public function test_directory_listings_are_off_outside_laravels_block_too(): void
    {
        $this->assertMatchesRegularExpression('~<IfModule mod_autoindex\.c>\s*Options -Indexes\s*</IfModule>~', $this->htaccess);
    }

    // --- private files -------------------------------------------------------------------------

    public static function privateFiles(): array
    {
        return array_map(fn (string $name) => [$name], [
            'error_log', 'artisan', 'composer.json', 'composer.lock', 'composer.phar',
            '.env', '.env.production', '.env.backup', 'dump.sql', 'site.sql', 'index.php.bak', 'backup.sql.bak', 'DUMP.SQL', 'Error_Log', 'Composer.JSON',
        ]);
    }

    public static function publicFiles(): array
    {
        return array_map(fn (string $name) => [$name], [
            'index.php', 'robots.txt', 'favicon.ico', 'logo.png', 'og-default.png', 'app-B2GmjV4h.css', 'app-sG-jxb7g.js',
            'ibm-plex-sans-400-normal-CDDApCn2.woff2', 'sitemap.xml', 'feed.xml', 'lead.jpg', 'error_log_archive.txt', 'my-composer-notes.txt',
            'sql-guide.html', 'bakery.jpg', 'environment.txt',
        ]);
    }

    #[DataProvider('privateFiles')]
    public function test_private_files_are_denied_by_name(string $file): void
    {
        $this->assertTrue($this->matchesAnyDenyPattern($file), "{$file} would be downloadable");
    }

    #[DataProvider('publicFiles')]
    public function test_public_files_are_not_caught_by_the_deny_patterns(string $file): void
    {
        $this->assertFalse($this->matchesAnyDenyPattern($file), "{$file} would be blocked");
    }

    private function matchesAnyDenyPattern(string $file): bool
    {
        $patterns = array_unique($this->filesMatchPatterns());
        $this->assertNotEmpty($patterns);

        foreach ($patterns as $pattern) {
            if (preg_match('~'.$pattern.'~', $file) === 1) {
                return true;
            }
        }

        return false;
    }

    /** @return list<string> */
    private function filesMatchPatterns(): array
    {
        preg_match_all('/<FilesMatch "([^"]+)">/', $this->htaccess, $match);

        return $match[1];
    }

    public function test_every_dotfile_is_denied(): void
    {
        foreach (['.git', '.gitignore', '.htpasswd', '.DS_Store', '.user.ini', '.hidden', '.env.local'] as $file) {
            $this->assertTrue($this->matchesAnyDenyPattern($file), $file);
        }
    }

    public function test_every_file_denial_has_an_old_apache_twin(): void
    {
        // Each FilesMatch appears once under Require (2.4) and once under Order/Deny (2.2).
        $patterns = $this->filesMatchPatterns();
        $this->assertCount(4, $patterns);
        $this->assertSame($patterns[0], $patterns[2]);
        $this->assertSame($patterns[1], $patterns[3]);
        $this->assertSame(2, substr_count($this->htaccess, 'Require all denied'));
        $this->assertSame(2, substr_count($this->htaccess, 'Deny from all'));
    }

    public static function dotPaths(): array
    {
        return [
            'a dot folder' => ['.git/config', true],
            'a dotfile at the root' => ['.env', true],
            'a dotfile in a folder' => ['storage/.hidden', true],
            'a nested dot folder' => ['a/b/.svn/entries', true],
            'the certificate challenge' => ['.well-known/acme-challenge/token-123', false],
            'the well-known folder itself' => ['.well-known/', false],
            'an article url' => ['ar/news/12-some-headline', false],
            'a url with a dot inside a segment' => ['en/news/12-version-2.0', false],
            'an asset' => ['build/assets/app-B2GmjV4h.css', false],
            'the root' => ['', false],
        ];
    }

    #[DataProvider('dotPaths')]
    public function test_dot_folders_are_refused_before_the_front_controller_except_well_known(string $path, bool $refused): void
    {
        preg_match('~RewriteRule (\S+) - \[F,L\]~', $this->htaccess, $match);
        $this->assertNotEmpty($match, 'no forbidden-rewrite rule for dot folders');

        $this->assertSame($refused, preg_match('~'.$match[1].'~', $path) === 1, $path);
        $this->assertLessThan(strpos($this->htaccess, 'RewriteRule ^ index.php'), strpos($this->htaccess, '- [F,L]'), 'must come before the front controller rule');
    }

    // --- compression and caching ---------------------------------------------------------------

    public function test_text_formats_are_compressed(): void
    {
        $types = collect($this->directives())
            ->filter(fn (string $line) => str_starts_with($line, 'AddOutputFilterByType DEFLATE'))
            ->flatMap(fn (string $line) => explode(' ', trim(substr($line, strlen('AddOutputFilterByType DEFLATE')))))
            ->all();

        foreach (['text/html', 'text/css', 'application/javascript', 'text/javascript', 'application/json', 'application/xml', 'text/xml', 'image/svg+xml', 'application/rss+xml'] as $type) {
            $this->assertContains($type, $types, "{$type} is not compressed");
        }
        // Already compressed formats gain nothing from a second pass.
        foreach (['image/png', 'image/jpeg', 'image/webp', 'font/woff2'] as $type) {
            $this->assertNotContains($type, $types);
        }
    }

    public function test_built_assets_are_cached_for_a_year_as_immutable_and_pictures_for_a_month(): void
    {
        $this->assertStringContainsString('Header set Cache-Control "public, max-age=31536000, immutable" env=SISI_CACHE_IMMUTABLE', $this->htaccess);
        $this->assertStringContainsString('Header set Cache-Control "public, max-age=2592000" env=SISI_CACHE_MONTH', $this->htaccess);
    }

    public static function cachedUrls(): array
    {
        return [
            'a built script' => ['/build/assets/app-sG-jxb7g.js', 'SISI_CACHE_IMMUTABLE'],
            'a built stylesheet' => ['/build/assets/app-B2GmjV4h.css', 'SISI_CACHE_IMMUTABLE'],
            'a built font' => ['/build/assets/ibm-plex-sans-400-normal-CDDApCn2.woff2', 'SISI_CACHE_IMMUTABLE'],
            'the logo' => ['/brand/logo.png', 'SISI_CACHE_MONTH'],
            'the favicon in brand' => ['/brand/favicon-48.png', 'SISI_CACHE_MONTH'],
            'an uploaded picture' => ['/storage/articles/lead.jpg', 'SISI_CACHE_MONTH'],
            'an uploaded webp variant' => ['/storage/articles/variants/full/lead.jpg.webp', 'SISI_CACHE_MONTH'],
            'upper-case extension' => ['/storage/articles/LEAD.JPG', 'SISI_CACHE_MONTH'],
            // Never long-cached:
            'the build manifest (not hashed)' => ['/build/manifest.json', null],
            'the home page' => ['/', null],
            'an article' => ['/ar/news/12-headline', null],
            'a section page' => ['/en/category/economy', null],
            'the english feed' => ['/en/feed.xml', null],
            'the arabic feed' => ['/ar/feed.xml', null],
            'the sitemap' => ['/sitemap.xml', null],
            'robots.txt' => ['/robots.txt', null],
            'the staff area' => ['/admin', null],
            'a document in storage' => ['/storage/notes.txt', null],
            'a page called brand' => ['/brand', null],
            'a news story about storage' => ['/en/news/5-storage-jpg', null],
        ];
    }

    #[DataProvider('cachedUrls')]
    public function test_only_the_intended_urls_get_a_long_cache_lifetime(string $url, ?string $expected): void
    {
        $setenv = collect($this->directives())
            ->filter(fn (string $line) => str_starts_with($line, 'SetEnvIfNoCase Request_URI'))
            ->map(function (string $line): array {
                preg_match('/Request_URI "([^"]+)" (\w+)/', $line, $match);

                return [$match[1], $match[2]];
            });
        $this->assertCount(2, $setenv);

        $matched = $setenv->first(fn (array $rule) => preg_match('~'.$rule[0].'~i', $url) === 1);

        $this->assertSame($expected, $matched[1] ?? null, $url);
    }

    public function test_pages_and_feeds_have_no_blanket_cache_rule(): void
    {
        // Every Cache-Control line is tied to one of the two env flags, so it can only ever hit static files.
        foreach ($this->directives() as $line) {
            if (str_contains($line, 'Cache-Control')) {
                $this->assertMatchesRegularExpression('/ env=SISI_CACHE_(IMMUTABLE|MONTH)$/', $line, $line);
            }
        }

        // Nothing sets a default lifetime (it would cover HTML) or a lifetime per MIME type.
        $code = implode("\n", $this->directives());
        $this->assertStringNotContainsString('ExpiresDefault', $code);
        $this->assertStringNotContainsString('ExpiresByType', $code);
        $this->assertStringNotContainsString('ExpiresActive', $code);
        $this->assertDoesNotMatchRegularExpression('/^Header\s+(always\s+)?(set|append|merge)\s+Cache-Control(?!.* env=)/mi', $code);
    }

    // --- what must not be here -----------------------------------------------------------------

    public function test_the_https_and_www_redirects_only_fire_behind_the_flag_file_and_spare_well_known(): void
    {
        $lines = $this->directives();
        $flag = 'RewriteCond %{DOCUMENT_ROOT}/../storage/app/force-https -f';

        // Laravel's trailing-slash redirect plus exactly the two flag-guarded ones.
        $this->assertSame(3, substr_count(implode("\n", $lines), 'R=301'));

        $redirects = 0;
        foreach ($lines as $i => $line) {
            if (! str_contains($line, 'https://')) {
                continue;
            }
            $redirects++;
            $this->assertSame('RewriteRule ^ https://sisi.news%{REQUEST_URI} [L,R=301]', $line);
            // Walk back through this rule's own conditions: the flag file must be one of them.
            $conditions = [];
            for ($j = $i - 1; $j >= 0 && str_starts_with($lines[$j], 'RewriteCond'); $j--) {
                $conditions[] = $lines[$j];
            }
            $this->assertContains($flag, $conditions, 'a redirect that does not depend on the flag file would fire before a certificate exists');
        }
        $this->assertSame(2, $redirects);

        // Certificate validation over plain http must keep working, and https must not redirect to itself.
        $this->assertContains('RewriteCond %{REQUEST_URI} !^/\.well-known/', $lines);
        $this->assertContains('RewriteCond %{HTTPS} !=on', $lines);

        // The redirect block sits above Laravel's front controller or it would never see a routed URL.
        $this->assertLessThan(strpos($this->htaccess, 'index.php [L]'), strpos($this->htaccess, 'R=301'));
    }

    public function test_there_is_no_unconditional_redirect_directive_and_no_php_handler(): void
    {
        $directives = implode("\n", $this->directives());

        $this->assertStringNotContainsString('%{SERVER_PORT}', $directives);
        $this->assertDoesNotMatchRegularExpression('/^(Redirect|RedirectMatch)\b/mi', $directives);

        // cPanel writes its own handler block when the PHP version is chosen.
        foreach (['AddHandler', 'SetHandler', 'AddType application/x-httpd-php', 'suPHP', 'php_value', 'php_flag', 'FcgidWrapper', 'proxy:fcgi'] as $handler) {
            $this->assertStringNotContainsString($handler, $directives);
        }
    }
}
