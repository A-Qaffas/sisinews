<?php

namespace Tests\Feature\Security;

use Illuminate\Routing\Route as LaravelRoute;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

/**
 * v1 decision: no reader accounts, no login/registration, no comments on the public site.
 * The only login is the staff panel under /admin. This guards against one sneaking in.
 */
class NoPublicAccountsTest extends TestCase
{
    /** @return list<LaravelRoute> */
    private function publicRoutes(): array
    {
        return collect(Route::getRoutes()->getRoutes())
            ->reject(fn (LaravelRoute $route) => preg_match('#^(admin|livewire|filament|storage|up$|_)#', $route->uri()) === 1)
            ->values()
            ->all();
    }

    public function test_the_public_site_exposes_no_account_or_auth_routes(): void
    {
        $forbidden = '/^(login|logout|register|sign-?in|sign-?up|password|[a-z]+-password|forgot|verify|verify-email|account|profile|dashboard|users?|comments?|auth|oauth|socialite|callback)$/i';

        foreach ($this->publicRoutes() as $route) {
            // Every URI segment and every dot-separated part of the route name is checked on its own.
            foreach (preg_split('#[/.]#', $route->uri().'/'.$route->getName(), -1, PREG_SPLIT_NO_EMPTY) as $part) {
                $this->assertDoesNotMatchRegularExpression($forbidden, $part, "public route /{$route->uri()} ({$route->getName()}) looks like an account feature");
            }
        }
    }

    public function test_every_state_changing_public_route_is_a_newsletter_route(): void
    {
        foreach ($this->publicRoutes() as $route) {
            foreach (array_diff($route->methods(), ['GET', 'HEAD']) as $method) {
                $this->assertStringStartsWith('newsletter.', (string) $route->getName(), "{$method} /{$route->uri()} is not part of the newsletter flow");
            }
        }
    }

    public function test_public_routes_never_require_a_logged_in_user(): void
    {
        foreach ($this->publicRoutes() as $route) {
            $this->assertEmpty(
                array_filter($route->gatherMiddleware(), fn ($m) => is_string($m) && (str_starts_with($m, 'auth') || str_starts_with($m, 'verified') || str_starts_with($m, 'can:'))),
                "/{$route->uri()} must not depend on a session login",
            );
        }
    }

    public function test_the_usual_login_urls_are_not_found_on_the_public_site(): void
    {
        foreach (['/login', '/register', '/password/reset', '/forgot-password', '/ar/login', '/en/register', '/user', '/account', '/comments'] as $uri) {
            $this->get($uri)->assertNotFound();
            $this->post($uri)->assertStatus(404);
        }
    }

    public function test_the_staff_login_lives_under_admin_only(): void
    {
        $loginRoutes = collect(Route::getRoutes()->getRoutes())
            ->filter(fn (LaravelRoute $route) => str_contains($route->uri(), 'login'));

        $this->assertNotEmpty($loginRoutes, 'the staff panel should have a login page');
        $loginRoutes->each(fn (LaravelRoute $route) => $this->assertStringStartsWith('admin', $route->uri()));
    }
}
