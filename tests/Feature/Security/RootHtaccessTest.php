<?php

namespace Tests\Feature\Security;

use Tests\TestCase;

/**
 * The .htaccess in the application folder is only a safety net for a mistaken document root. Apache applies a parent
 * folder's "Require" rules to the document root below it, so a blanket "Require all denied" here would take the whole
 * site offline (it did, once, on the live host). These tests pin the one shape that is safe.
 */
class RootHtaccessTest extends TestCase
{
    private string $code;

    protected function setUp(): void
    {
        parent::setUp();

        $lines = array_map('trim', explode("\n", (string) file_get_contents(base_path('.htaccess'))));
        $this->code = implode("\n", array_filter($lines, fn (string $l) => $l !== '' && ! str_starts_with($l, '#')));
    }

    public function test_it_never_denies_the_whole_tree(): void
    {
        $this->assertStringNotContainsString('Require all denied', $this->code);
        $this->assertStringNotContainsString('Deny from all', $this->code);
        $this->assertStringNotContainsString('Require all granted', $this->code);
    }

    public function test_it_refuses_every_file_outside_public_and_nothing_else(): void
    {
        $this->assertStringContainsString('<IfModule mod_rewrite.c>', $this->code);
        $this->assertStringContainsString('RewriteCond %{REQUEST_FILENAME} ^.*/sisi\.news/(?!public(/|$)).+', $this->code);
        $this->assertStringContainsString('RewriteRule ^ - [F,L]', $this->code);
        $this->assertSame(1, substr_count($this->code, 'RewriteRule'), 'a second rule could rewrite requests inside public/');
    }

    public function test_the_condition_matches_private_paths_but_not_public_ones(): void
    {
        $pattern = '~^.*/sisi\.news/(?!public(/|$)).+~';

        foreach (['.env', 'vendor/autoload.php', 'storage/logs/laravel.log', 'composer.json', 'artisan', 'database/launch-content/stories.json', 'publicity/x.php'] as $path) {
            $this->assertSame(1, preg_match($pattern, "/home/wxr46gn9f7ld/sisi.news/{$path}"), "{$path} must be refused");
        }
        foreach (['public/index.php', 'public/storage/articles/a.jpg', 'public/build/assets/app.css', 'public/.well-known/acme-challenge/x'] as $path) {
            $this->assertSame(0, preg_match($pattern, "/home/wxr46gn9f7ld/sisi.news/{$path}"), "{$path} must stay reachable");
        }
    }
}
