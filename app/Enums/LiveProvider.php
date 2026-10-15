<?php

namespace App\Enums;

/**
 * The outside video services a live broadcast may be embedded from. Only a validated id is ever stored, and the
 * embed address is built from a fixed template here, so a pasted link can never inject its own markup or host.
 */
enum LiveProvider: string
{
    case YouTube = 'youtube';
    case Vimeo = 'vimeo';

    public function label(): string
    {
        return match ($this) {
            self::YouTube => 'YouTube',
            self::Vimeo => 'Vimeo',
        };
    }

    /** Privacy-enhanced (no-cookie) player addresses; autoplay is safe because it only loads after a visitor presses play. */
    public function embedUrl(string $ref): string
    {
        return match ($this) {
            self::YouTube => 'https://www.youtube-nocookie.com/embed/'.rawurlencode($ref).'?autoplay=1&rel=0&playsinline=1',
            self::Vimeo => 'https://player.vimeo.com/video/'.rawurlencode($ref).'?autoplay=1&dnt=1',
        };
    }

    /** Hosts the player iframe may load from (used for the Content-Security-Policy frame-src). */
    public static function frameHosts(): array
    {
        return ['https://www.youtube-nocookie.com', 'https://player.vimeo.com'];
    }

    /**
     * Accepts a pasted link or a bare id and returns [provider, id], or null when it is not a recognised
     * YouTube/Vimeo video link.
     *
     * @return array{0: self, 1: string}|null
     */
    public static function fromInput(string $input): ?array
    {
        $input = trim($input);

        // Real video links are short. Refusing long, whitespace-, backslash- or control-character-laden input up
        // front keeps hostile pastes away from parse_url, and never parsing the query with parse_str means a link
        // with thousands of parameters cannot trip PHP's max_input_vars warning (which Laravel turns into a 500).
        if ($input === '' || strlen($input) > 2048 || preg_match('/[\s\\\\\x00-\x1F\x7F]/', $input) === 1) {
            return null;
        }

        if (preg_match('/^[A-Za-z0-9_-]{11}$/', $input) === 1) {
            return [self::YouTube, $input];
        }
        if (preg_match('/^\d{6,12}$/', $input) === 1) {
            return [self::Vimeo, $input];
        }

        $parts = parse_url($input);
        if (! is_array($parts) || ($parts['scheme'] ?? '') !== 'https' || ! isset($parts['host'])
            || isset($parts['user']) || isset($parts['pass']) || isset($parts['port'])) {
            return null;
        }

        $host = strtolower((string) preg_replace('/^www\./i', '', $parts['host']));
        $path = $parts['path'] ?? '';
        $query = $parts['query'] ?? '';

        if (in_array($host, ['youtube.com', 'm.youtube.com', 'youtube-nocookie.com'], true)) {
            if ($path === '/watch' && preg_match('/(?:^|&)v=([A-Za-z0-9_-]{11})(?:&|$)/', $query, $v) === 1) {
                return [self::YouTube, $v[1]];
            }
            if (preg_match('~^/(?:live|embed)/([A-Za-z0-9_-]{11})/?$~', $path, $m) === 1) {
                return [self::YouTube, $m[1]];
            }
        }
        if ($host === 'youtu.be' && preg_match('~^/([A-Za-z0-9_-]{11})/?$~', $path, $m) === 1) {
            return [self::YouTube, $m[1]];
        }
        if ($host === 'vimeo.com' && preg_match('~^/(\d{6,12})/?$~', $path, $m) === 1) {
            return [self::Vimeo, $m[1]];
        }
        if ($host === 'player.vimeo.com' && preg_match('~^/video/(\d{6,12})/?$~', $path, $m) === 1) {
            return [self::Vimeo, $m[1]];
        }

        return null;
    }
}
