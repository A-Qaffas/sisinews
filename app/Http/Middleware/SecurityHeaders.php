<?php

namespace App\Http\Middleware;

use App\Enums\LiveProvider;
use App\Support\CanonicalHost;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Vite;
use Symfony\Component\HttpFoundation\Response;

/**
 * Hardening headers for the public site. The staff area (Filament + Livewire) is skipped:
 * its UI relies on inline scripts and eval, which this Content-Security-Policy forbids.
 *
 * The public pages load nothing from third parties: no external scripts, styles or fonts.
 * Share buttons are plain links, so they need no CSP entry.
 * The one exception is the Live page, which may embed a YouTube/Vimeo player after a visitor presses play:
 * only there does the policy gain a frame-src naming exactly those player hosts. No other page may frame anything
 * but this site, and no script, style or other source list ever widens.
 *
 * In production, any hostname other than the one in APP_URL (ignoring a leading "www.") gets
 * X-Robots-Tag: noindex, nofollow, staff area included, so a preview or alias address
 * (a hosting-provider temporary URL, a second domain pointed at the same files) can never be indexed.
 */
class SecurityHeaders
{
    /**
     * Inline <script> blocks the layout ships, allowed by hash because they are static.
     * A block that is edited, or a new one, is blocked until it either gets
     * nonce="{{ Vite::cspNonce() }}" (preferred) or its hash is listed here;
     * SecurityHeadersTest fails when a rendered page contains an uncovered script.
     * - layouts/site.blade.php: document.documentElement.classList.add('js')
     * - site/partials/article-head.blade.php: restores the reader's saved text size
     */
    private const INLINE_SCRIPT_HASHES = [
        'sha256-Du+OJKJSbdUgz5nrHeWWINvez6XKDDU/tyj/5c2uvwo=',
        'sha256-WILtFMHMKe4rzS7ii3Avgq5uY9202hkDxmNhSZz4RJQ=',
    ];

    /** One week: a certificate mistake must not lock browsers out for a year. SECURITY_HSTS_MAX_AGE raises it. */
    private const DEFAULT_HSTS_MAX_AGE = 604800;

    public function handle(Request $request, Closure $next): Response
    {
        if ($request->is('admin', 'admin/*', 'livewire', 'livewire/*', 'livewire-*', 'filament/*')) {
            return $this->keepAliasHostsOutOfSearch($request, $next($request));
        }

        // Laravel stamps this nonce on every <script>, <style> and <link> that @vite and @fonts emit.
        $nonce = Vite::useCspNonce();

        $response = $next($request);

        $response->headers->add([
            'X-Content-Type-Options' => 'nosniff',
            'X-Frame-Options' => 'SAMEORIGIN',
            'Referrer-Policy' => 'strict-origin-when-cross-origin',
            'Permissions-Policy' => 'camera=(), microphone=(), geolocation=(), payment=(), usb=()',
            'Content-Security-Policy' => $this->contentSecurityPolicy($request, $nonce),
        ]);

        if ($request->isSecure() && app()->isProduction()) {
            // No includeSubDomains/preload: other subdomains of the domain are not ours to commit to HTTPS.
            $response->headers->set('Strict-Transport-Security', 'max-age='.$this->hstsMaxAge());
        }

        return $this->keepAliasHostsOutOfSearch($request, $response);
    }

    private function hstsMaxAge(): int
    {
        $configured = config('app.hsts_max_age');

        // A blank or mistyped env value falls back to the default instead of silently sending max-age=0.
        return is_numeric($configured) && $configured >= 0 ? (int) $configured : self::DEFAULT_HSTS_MAX_AGE;
    }

    private function keepAliasHostsOutOfSearch(Request $request, Response $response): Response
    {
        if ($this->isAliasHost($request)) {
            $response->headers->set('X-Robots-Tag', 'noindex, nofollow');
        }

        return $response;
    }

    /** Production only, so local and test hosts are never touched. */
    private function isAliasHost(Request $request): bool
    {
        if (! app()->isProduction()) {
            return false;
        }

        if (CanonicalHost::configured() === '') {
            // An APP_URL without a host cannot say what is canonical; noindex on the real site would be worse than none.
            return false;
        }

        // A malformed Host header is not the site's own host either.
        return ! CanonicalHost::matches($request);
    }

    private function contentSecurityPolicy(Request $request, string $nonce): string
    {
        $directives = [
            'default-src' => ["'self'"],
            'script-src' => ["'self'", "'nonce-{$nonce}'", ...array_map(fn (string $hash) => "'{$hash}'", self::INLINE_SCRIPT_HASHES)],
            'style-src' => ["'self'", "'nonce-{$nonce}'"],
            // style="" attributes (the ticker speed) cannot carry a nonce; <style> blocks still need one.
            'style-src-attr' => ["'unsafe-inline'"],
            // https: because staff may embed pictures hosted elsewhere in a story body; data: for small inline icons.
            'img-src' => ["'self'", 'data:', 'https:'],
            'font-src' => ["'self'"],
            'connect-src' => ["'self'"],
            'media-src' => ["'self'"],
            'object-src' => ["'none'"],
            'base-uri' => ["'self'"],
            'form-action' => ["'self'"],
            'frame-ancestors' => ["'self'"],
        ];

        // The route is resolved by the time the response comes back, so this is false for unrouted URLs and 404s.
        if ($request->routeIs('live')) {
            $directives['frame-src'] = LiveProvider::frameHosts();
        }

        if ($dev = $this->viteDevServer()) {
            foreach (['script-src', 'style-src', 'font-src', 'img-src', 'connect-src'] as $directive) {
                $directives[$directive][] = $dev['http'];
            }
            $directives['connect-src'][] = $dev['ws'];
        }

        if ($request->isSecure()) {
            $directives['upgrade-insecure-requests'] = [];
        }

        return collect($directives)
            ->map(fn (array $sources, string $name) => trim($name.' '.implode(' ', $sources)))
            ->implode('; ');
    }

    /** `npm run dev` serves assets from another origin; allow it locally, never in production. */
    private function viteDevServer(): ?array
    {
        if (app()->isProduction() || ! Vite::isRunningHot()) {
            return null;
        }

        $url = parse_url(trim((string) file_get_contents(Vite::hotFile())));
        if (! isset($url['host'])) {
            return null;
        }

        $authority = $url['host'].(isset($url['port']) ? ':'.$url['port'] : '');

        return ['http' => ($url['scheme'] ?? 'http').'://'.$authority, 'ws' => 'ws://'.$authority];
    }
}
