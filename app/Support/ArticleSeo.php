<?php

namespace App\Support;

use App\Enums\Locale;
use App\Models\ArticleTranslation;
use App\Support\Media\HeroImages;
use Illuminate\Support\Str;

/** Search and social metadata for a public article page, and the plain-text summary the feed shares. */
final class ArticleSeo
{
    /** meta_description (editor-written, kept whole), else excerpt, else the start of the body. */
    public static function description(ArticleTranslation $t, int $limit = 160): string
    {
        $meta = self::plain((string) $t->meta_description);
        if ($meta !== '') {
            return $meta;
        }

        $text = self::plain((string) $t->excerpt);
        if ($text === '') {
            $text = self::plain((string) $t->body);
        }

        return Str::limit($text, $limit, '…', preserveWords: true);
    }

    public static function plain(string $html): string
    {
        // Script/style text is not prose, and block-level closers become spaces so "</p><p>" does not glue two words together.
        $withoutCode = preg_replace('~<script\b.*?</script>|<style\b.*?</style>~is', ' ', $html);
        $spaced = preg_replace('~</(?:p|h[1-6]|li|blockquote|figcaption|div)>|<br\s*/?>~i', ' ', (string) $withoutCode);
        $text = html_entity_decode(strip_tags((string) $spaced), ENT_QUOTES | ENT_HTML5, 'UTF-8');

        return trim((string) preg_replace('/\s+/u', ' ', $text));
    }

    /** The current brand logo (public/brand/logo.png), used as the publisher logo. */
    public static function logoUrl(): string
    {
        return asset('brand/logo.png');
    }

    /** Share image for a story with no hero: the layout's logo-on-cream card when it exists, else the logo itself. */
    private static function fallbackImageUrl(): string
    {
        $configured = (string) config('site.og_image');

        return $configured !== '' && is_file(public_path($configured)) ? asset($configured) : self::logoUrl();
    }

    /**
     * @param  ArticleTranslation  $t  visible, with article.category.translations and article.author loaded
     * @param  ArticleTranslation|null  $alternate  the other language's version, only when it is visible too
     * @return array{description: string, canonical: string, image: string, published: string, modified: string, section: string, alternates: array<string, string>, jsonLd: array<string, mixed>}
     */
    public static function head(ArticleTranslation $t, ?ArticleTranslation $alternate): array
    {
        $locale = $t->locale;
        $article = $t->article;
        $canonical = $t->url();
        $description = self::description($t);
        $image = url(HeroImages::url($article->hero_image, 'full') ?? self::fallbackImageUrl());
        $published = $t->published_at;
        $modified = $t->updated_at->greaterThan($published) ? $t->updated_at : $published;
        $section = $article->category->nameIn($locale);

        $versions = collect([$t, $alternate])->filter()->keyBy(fn (ArticleTranslation $v) => $v->locale->value);
        $alternates = $versions->map(fn (ArticleTranslation $v) => $v->url())->all();
        ksort($alternates);
        // x-default is the Arabic edition (the site's default language) whenever it is live.
        $alternates['x-default'] = $alternates[Locale::Ar->value] ?? $canonical;

        return [
            'description' => $description,
            'canonical' => $canonical,
            'image' => $image,
            'published' => $published->toAtomString(),
            'modified' => $modified->toAtomString(),
            'section' => $section,
            'alternates' => $alternates,
            'jsonLd' => [
                '@context' => 'https://schema.org',
                '@type' => 'NewsArticle',
                'mainEntityOfPage' => ['@type' => 'WebPage', '@id' => $canonical],
                'headline' => Str::limit($t->title, 110, '…', preserveWords: true),
                'description' => $description,
                'datePublished' => $published->toAtomString(),
                'dateModified' => $modified->toAtomString(),
                'author' => [['@type' => 'Person', 'name' => $article->author->displayName($locale)]],
                'image' => [$image],
                'articleSection' => $section,
                'inLanguage' => $locale->value,
                'publisher' => [
                    '@type' => 'Organization',
                    'name' => config('site.name.'.$locale->value, config('app.name')),
                    'url' => url('/'),
                    'logo' => ['@type' => 'ImageObject', 'url' => self::logoUrl()],
                ],
            ],
        ];
    }
}
