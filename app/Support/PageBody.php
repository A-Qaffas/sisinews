<?php

namespace App\Support;

use App\Enums\Locale;

/**
 * Turns a stored page body (already sanitized by HtmlSanitizer) into the HTML a reader gets: the
 * [[contact]] token becomes a mailto link to the configured contact address, or the plain phrase
 * "the editorial team" while no address is configured. The token is the only thing replaced, and only
 * in text, so nothing a body contains can reach an attribute or open a tag.
 */
final class PageBody
{
    public const CONTACT_TOKEN = '[[contact]]';

    /** Deliberately narrower than RFC 5322: the value ends up in an href, so exotic-but-legal characters are refused. */
    private const SAFE_EMAIL = '/^[A-Za-z0-9._%+-]+@[A-Za-z0-9-]+(?:\.[A-Za-z0-9-]+)*\.[A-Za-z]{2,}$/';

    /** The configured contact address when it is usable, else null. */
    public static function contactEmail(): ?string
    {
        $email = config('site.contact.email');
        if (! is_string($email)) {
            return null;
        }

        $email = trim($email);

        return filter_var($email, FILTER_VALIDATE_EMAIL) !== false && preg_match(self::SAFE_EMAIL, $email) === 1
            ? $email
            : null;
    }

    /** The token in plain text (meta descriptions): the bare address, or the phrase. The caller escapes it. */
    public static function renderText(string $text, Locale $locale): string
    {
        return str_replace(
            self::CONTACT_TOKEN,
            self::contactEmail() ?? trans('layout.pages.editorial_team', [], $locale->value),
            $text,
        );
    }

    public static function render(string $html, Locale $locale): string
    {
        if (! str_contains($html, self::CONTACT_TOKEN)) {
            return $html;
        }

        $email = self::contactEmail();
        $phrase = e(trans('layout.pages.editorial_team', [], $locale->value));
        $link = $email === null ? null : '<a href="mailto:'.e($email).'" dir="ltr">'.e($email).'</a>';
        $inAnchor = 0;

        // Odd indexes are tags (purified output encodes any ">" inside an attribute), even ones are text.
        $parts = preg_split('/(<[^>]*>)/', $html, -1, PREG_SPLIT_DELIM_CAPTURE);
        foreach ($parts as $index => $part) {
            if ($index % 2 === 1) {
                if (preg_match('~^<a[\s>]~i', $part) === 1) {
                    $inAnchor++;
                } elseif (preg_match('~^</a\s*>$~i', $part) === 1) {
                    $inAnchor = max(0, $inAnchor - 1);
                }

                continue;
            }

            // A link inside a link is invalid HTML, so inside one the token is plain text.
            $replacement = $inAnchor === 0 && $link !== null ? $link : ($email === null ? $phrase : e($email));
            $parts[$index] = str_replace(self::CONTACT_TOKEN, $replacement, $part);
        }

        return implode('', $parts);
    }
}
