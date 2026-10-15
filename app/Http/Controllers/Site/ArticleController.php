<?php

namespace App\Http\Controllers\Site;

use App\Enums\Locale;
use App\Http\Controllers\Controller;
use App\Models\ArticleTranslation;
use App\Services\LatestStories;
use App\Services\MostRead;
use App\Services\StoryQuery;
use App\Services\ViewCounter;
use App\Support\ArticleSeo;
use App\Support\Media\HeroImages;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

class ArticleController extends Controller
{
    private const RELATED = 4;

    public function __construct(private readonly ViewCounter $views) {}

    public function __invoke(Request $request, string $locale, string $idSlug, MostRead $mostRead, LatestStories $latestStories): Response|RedirectResponse
    {
        $locale = Locale::from($locale);

        $translation = ArticleTranslation::query()
            ->where('article_id', $this->articleId($idSlug))
            ->inLocale($locale)
            ->with(['article.category.translations', 'article.author', 'article.tags.translations'])
            ->first();

        // A soft-deleted shell loads as null, so a trashed story is a 404 for everyone.
        abort_if($translation === null || $translation->article === null, 404);

        $preview = ! $translation->isVisible();
        if ($preview) {
            // Same answer as "does not exist" for everyone who may not proof it, so drafts never leak.
            abort_unless($request->user()?->can('view', $translation), 404);
        }

        if ($idSlug !== $this->canonicalIdSlug($translation)) {
            return redirect()->to($translation->url(), 301);
        }

        $article = $translation->article;
        $alternate = StoryQuery::visible($locale->other(), fn (Builder $shell) => $shell->whereKey($article->id))->first();

        $related = $preview ? new Collection : StoryQuery::newestFirst(
            StoryQuery::visible($locale, fn (Builder $shell) => $shell
                ->where('category_id', $article->category_id)
                ->whereKeyNot($article->id)),
        )->with(StoryQuery::RELATIONS)->limit(self::RELATED)->get();

        // A staff preview is a proof of one page, not the live site, so it gets no sidebar.
        // The page's own story is left out of both lists, and without reading data there is no sidebar at all.
        $mostReadStories = $preview ? new Collection : $mostRead->forLocale($locale)
            ->reject(fn (ArticleTranslation $story) => $story->article_id === $article->id);
        $latest = $preview || $mostReadStories->isEmpty() ? new Collection : $latestStories->forLocale($locale)
            ->reject(fn (ArticleTranslation $story) => $story->article_id === $article->id);

        if (! $preview) {
            $this->views->record($translation, $request);
        }

        $response = response()->view('site.article', [
            'mostRead' => $mostReadStories,
            'latest' => $latest,
            'translation' => $translation,
            'article' => $article,
            'category' => $article->category->translate($locale),
            'categoryName' => $article->category->nameIn($locale),
            'hero' => $this->hero($article->hero_image),
            'alternate' => $alternate,
            'related' => $related,
            'preview' => $preview,
            'seo' => $preview ? null : ArticleSeo::head($translation, $alternate),
            // Readers are in Egypt; the app clock is UTC.
            'publishedAt' => $translation->published_at?->copy()->timezone(config('site.timezone', 'Africa/Cairo')),
        ]);

        if ($preview) {
            $response->header('X-Robots-Tag', 'noindex, nofollow')->header('Cache-Control', 'no-store, private');
        }

        return $response;
    }

    /** @return array{src: string, srcset: string}|null */
    private function hero(?string $storedPath): ?array
    {
        $full = HeroImages::url($storedPath, 'full');
        if ($full === null) {
            return null;
        }

        $widths = HeroImages::SIZES;

        return [
            'src' => $full,
            'srcset' => implode(', ', [
                HeroImages::url($storedPath, 'thumb')." {$widths['thumb']}w",
                HeroImages::url($storedPath, 'card')." {$widths['card']}w",
                "{$full} {$widths['full']}w",
            ]),
        ];
    }

    /** The route constraint guarantees leading digits; reject ids that overflow a bigint rather than cast them. */
    private function articleId(string $idSlug): int
    {
        preg_match('/^\d+/', $idSlug, $match);
        abort_if(! isset($match[0]) || strlen($match[0]) > 18, 404);

        return (int) $match[0];
    }

    private function canonicalIdSlug(ArticleTranslation $t): string
    {
        return $t->slug !== '' ? "{$t->article_id}-{$t->slug}" : (string) $t->article_id;
    }
}
