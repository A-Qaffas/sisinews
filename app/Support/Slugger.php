<?php

namespace App\Support;

/**
 * Unicode-preserving slugs. Str::slug() transliterates Arabic into ugly Latin
 * ("mrhba"); article URLs carry the numeric id for identity, so the slug is
 * purely cosmetic and may stay in the reader's script.
 */
final class Slugger
{
    public static function make(string $text, int $max = 80): string
    {
        $text = mb_strtolower(trim($text));
        $text = preg_replace('/\x{0640}/u', '', $text);
        $text = preg_replace('/[^\p{L}\p{N}\s-]+/u', '', $text);
        $text = preg_replace('/[\s-]+/u', '-', $text);

        return trim(mb_substr(trim($text, '-'), 0, $max), '-');
    }
}
