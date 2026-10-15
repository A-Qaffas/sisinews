<?php

namespace App\Support;

use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Exception\SuspiciousOperationException;

/**
 * Which hostnames are "ours": the host in APP_URL, with or without a leading "www.".
 *
 * The Host header is chosen by whoever sends the request, so nothing keyed on it may be stored without
 * limit. Cached XML (sitemap, feed) is therefore keyed only on the site's own origins, which are few,
 * and any other host gets a fresh render that is never stored for anyone else.
 */
final class CanonicalHost
{
    /** The APP_URL host without "www.", or '' when APP_URL names none (then nothing can be called canonical). */
    public static function configured(): string
    {
        return self::bare((string) parse_url((string) config('app.url'), PHP_URL_HOST));
    }

    /** Whether the request arrived on the site's own host. False for a malformed Host header. */
    public static function matches(Request $request): bool
    {
        $configured = self::configured();
        if ($configured === '') {
            return false;
        }

        try {
            return self::bare($request->getHost()) === $configured;
        } catch (SuspiciousOperationException) {
            return false;
        }
    }

    /**
     * The scheme and host to key a cached, host-specific document on, or null when it must not be cached.
     * Only the site's own host on its default port (or on the port APP_URL itself names, as on a dev machine)
     * qualifies, so the set of keys stays small however many Host headers or ports a client invents.
     */
    public static function cacheOrigin(Request $request): ?string
    {
        if (! self::matches($request)) {
            return null;
        }

        $port = (int) $request->getPort();
        $allowed = [$request->isSecure() ? 443 : 80, (int) parse_url((string) config('app.url'), PHP_URL_PORT)];

        return in_array($port, $allowed, true) ? $request->getSchemeAndHttpHost() : null;
    }

    private static function bare(string $host): string
    {
        return (string) preg_replace('/^www\./', '', strtolower(rtrim($host, '.')));
    }
}
