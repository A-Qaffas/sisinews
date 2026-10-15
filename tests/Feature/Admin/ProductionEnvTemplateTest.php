<?php

namespace Tests\Feature\Admin;

use Tests\TestCase;

/** .env.example is what a new server starts from, so it must be safe and complete for production. */
class ProductionEnvTemplateTest extends TestCase
{
    /** @return array<string, string> KEY => raw value of every uncommented line */
    private function template(): array
    {
        $values = [];
        foreach (file(base_path('.env.example'), FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
            if (str_starts_with(ltrim($line), '#') || ! str_contains($line, '=')) {
                continue;
            }
            [$key, $value] = explode('=', $line, 2);
            $values[trim($key)] = trim($value);
        }

        return $values;
    }

    public function test_the_template_is_production_oriented(): void
    {
        $env = $this->template();

        $this->assertSame('production', $env['APP_ENV']);
        $this->assertSame('false', $env['APP_DEBUG']);
        $this->assertSame('https://sisi.news', $env['APP_URL']);
        $this->assertSame('ar', $env['APP_LOCALE']);
        $this->assertSame('en', $env['APP_FALLBACK_LOCALE']);
        $this->assertSame('warning', $env['LOG_LEVEL']);
        $this->assertSame('file', $env['SESSION_DRIVER']);
        $this->assertSame('file', $env['CACHE_STORE']);
        $this->assertSame('sync', $env['QUEUE_CONNECTION']);
        $this->assertSame('log', $env['MAIL_MAILER']);
        $this->assertSame('mysql', $env['DB_CONNECTION']);
    }

    public function test_the_https_switches_start_off_so_a_host_without_a_certificate_works(): void
    {
        $env = $this->template();

        $this->assertSame('false', $env['APP_FORCE_HTTPS']);
        $this->assertSame('false', $env['SESSION_SECURE_COOKIE']);
    }

    public function test_it_contains_no_secrets(): void
    {
        $env = $this->template();

        foreach (['APP_KEY', 'DB_DATABASE', 'DB_USERNAME', 'DB_PASSWORD'] as $secret) {
            $this->assertSame('', $env[$secret], "{$secret} must be blank in the template");
        }
        foreach (array_keys($env) as $key) {
            $this->assertDoesNotMatchRegularExpression('/^(AWS_|REDIS_PASSWORD|MAIL_PASSWORD|MAIL_USERNAME)/', $key, "{$key} has no business in a v1 template");
        }
    }

    public function test_every_site_setting_the_layout_reads_is_in_the_template(): void
    {
        $env = $this->template();

        foreach (['SITE_SOCIAL_FACEBOOK', 'SITE_SOCIAL_X', 'SITE_SOCIAL_INSTAGRAM', 'SITE_SOCIAL_YOUTUBE', 'SITE_CONTACT_EMAIL', 'SITE_CONTACT_PHONE'] as $key) {
            $this->assertArrayHasKey($key, $env);
            $this->assertSame('', $env[$key], 'blank hides the link');
        }

        // And the other way round: nothing in config/site.php is read from an env key the template forgot.
        preg_match_all("/env\('(SITE_[A-Z_]+)'/", file_get_contents(base_path('config/site.php')), $read);
        foreach ($read[1] as $key) {
            $this->assertArrayHasKey($key, $env, "config/site.php reads {$key}");
        }
    }

    public function test_the_force_https_key_is_the_one_the_config_reads(): void
    {
        $this->assertStringContainsString("env('APP_FORCE_HTTPS'", file_get_contents(base_path('config/app.php')));
    }
}
