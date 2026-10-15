<?php

namespace App\Support;

use Stevebauman\Purify\Facades\Purify;

/**
 * Article bodies are rich-text HTML from staff accounts and are rendered
 * unescaped on the public site, so they are sanitized on write (see the
 * ArticleTranslation::body mutator). The allow-list lives in config/purify.php.
 */
final class HtmlSanitizer
{
    /** Null and blank input yield an empty string; the result is stable under a second pass. */
    public static function clean(?string $html): string
    {
        if ($html === null || trim($html) === '') {
            return '';
        }

        return trim((string) Purify::config('article')->clean($html));
    }
}
