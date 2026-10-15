<?php

namespace Tests\Feature\Site\Concerns;

use App\Enums\Locale;
use App\Enums\TranslationStatus;
use App\Models\Article;
use App\Models\ArticleTranslation;

trait MakesStories
{
    /**
     * One language version of a story. Pass an existing $article to add the sibling language to it.
     * The factory force-fills status and published_at, so any state can be built directly.
     *
     * @param  array<string, mixed>  $attributes  translation columns, e.g. ['title' => ..., 'published_at' => now()->addDay()]
     */
    protected function makeStory(
        Locale $locale = Locale::En,
        TranslationStatus $status = TranslationStatus::Published,
        array $attributes = [],
        ?Article $article = null,
    ): ArticleTranslation {
        $article ??= Article::factory()->create();

        $factory = ArticleTranslation::factory()->status($status);
        if ($locale === Locale::Ar) {
            $factory = $factory->arabic();
        }

        return $factory->create(['article_id' => $article->id, ...$attributes])->fresh();
    }

    /** The JSON-LD block of a rendered page, decoded. */
    protected function jsonLd(string $html): array
    {
        $this->assertSame(1, preg_match('#<script type="application/ld\+json">(.*?)</script>#s', $html, $match), 'JSON-LD block missing');

        return json_decode($match[1], true, flags: JSON_THROW_ON_ERROR);
    }
}
