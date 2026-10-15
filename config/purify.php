<?php

use App\Support\Html\ArticleHtmlDefinition;
use Stevebauman\Purify\Cache\CacheDefinitionCache;

/*
|--------------------------------------------------------------------------
| HTML Purifier (article body allow-list)
|--------------------------------------------------------------------------
|
| Article bodies are written by staff and rendered unescaped on the public
| site, so every body passes the "article" set below through App\Support\HtmlSanitizer
| before it is stored. This list is the single definition of "what a story may
| contain": anything not listed (scripts, iframes, svg, style/class/id/on*
| attributes, data: URLs) is dropped. Keep the CMS editor's toolbar inside this
| list, otherwise the formatting it offers would be silently stripped on save.
|
| The compiled definition is cached in the application cache, keyed by the
| settings below, so editing them takes effect on its own. Changing the code in
| App\Support\Html needs `php artisan cache:clear` on the server.
|
*/

return [

    'default' => 'article',

    'configs' => [

        'article' => [
            'Core.Encoding' => 'utf-8',
            'HTML.Doctype' => 'HTML 4.01 Transitional',
            // dir/lang let an editor mark an English quote inside an Arabic paragraph (and vice versa).
            'HTML.Allowed' => implode(',', [
                'p[dir|lang]',
                'br',
                'h2[dir|lang]',
                'h3[dir|lang]',
                'h4[dir|lang]',
                'strong',
                'b',
                'em',
                'i',
                'u',
                's',
                'ul',
                'ol',
                'li',
                'blockquote[dir|lang]',
                'hr',
                'a[href|title|rel|target]',
                'img[src|alt|width|height]',
                'figure',
                'figcaption',
                'span[dir|lang]',
            ]),
            // data:, javascript:, vbscript:, ftp: and friends are not on this list, so they are dropped.
            'URI.AllowedSchemes' => ['http' => true, 'https' => true, 'mailto' => true, 'tel' => true],
            // Links to this host are "internal" and are not forced to rel=nofollow (see ExternalLinkRel).
            'URI.Host' => parse_url((string) env('APP_URL', ''), PHP_URL_HOST) ?: null,
            'Attr.AllowedFrameTargets' => ['_blank'],
            'Attr.AllowedRel' => ['noopener', 'noreferrer', 'nofollow'],
            // An image without alt is decorative: an empty alt beats the file name HTMLPurifier would invent.
            'Attr.DefaultImageAlt' => '',
            'AutoFormat.AutoParagraph' => false,
            'AutoFormat.RemoveEmpty' => false,
        ],

    ],

    'definitions' => ArticleHtmlDefinition::class,

    'css-definitions' => null,

    'serializer' => [
        'driver' => env('CACHE_STORE', 'database'),
        'cache' => CacheDefinitionCache::class,
    ],

];
