<?php

namespace App\Observers;

use App\Models\Article;
use App\Support\Media\HeroImages;

/**
 * Keeps the files behind Article::hero_image in step with the row: variants are built
 * when an image is set or replaced, and the files go when the image is replaced or the
 * article is permanently deleted. Soft-deleted articles keep their files.
 */
class ArticleObserver
{
    public function created(Article $article): void
    {
        if (filled($article->hero_image)) {
            HeroImages::process($article->hero_image);
        }
    }

    public function updated(Article $article): void
    {
        if (! $article->wasChanged('hero_image')) {
            return;
        }

        if (filled($article->hero_image)) {
            HeroImages::process($article->hero_image);
        }

        $this->discard($article->getOriginal('hero_image'));
    }

    public function forceDeleted(Article $article): void
    {
        $this->discard($article->hero_image);
    }

    /** Deletes the files unless another article (even a soft-deleted one) still points at them. */
    private function discard(?string $path): void
    {
        if (blank($path) || Article::withTrashed()->where('hero_image', $path)->exists()) {
            return;
        }

        HeroImages::delete($path);
    }
}
