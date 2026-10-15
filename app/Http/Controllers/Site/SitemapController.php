<?php

namespace App\Http\Controllers\Site;

use App\Enums\Locale;
use App\Http\Controllers\Controller;
use App\Models\ArticleTranslation;
use App\Models\CategoryTranslation;
use App\Models\PageTranslation;
use App\Support\CanonicalHost;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Cache;

class SitemapController extends Controller
{
    /** The protocol allows 50,000 URLs per file; 5,000 keeps the build light on a shared host. */
    private const MAX_ARTICLES = 5000;

    /** Seconds. */
    private const CACHE_TTL = 600;

    public function __invoke(Request $request): Response
    {
        $render = fn () => view('site.sitemap', ['entries' => $this->entries()])->render();

        // Absolute URLs differ per host, so the cache is per host. Only the site's own host is cached (see
        // CanonicalHost): a forged Host header is rendered for its sender alone and never stored.
        $origin = CanonicalHost::cacheOrigin($request);
        $xml = $origin === null ? $render() : Cache::remember('site.sitemap.'.md5($origin), self::CACHE_TTL, $render);

        return response($xml, 200, ['Content-Type' => 'application/xml; charset=utf-8']);
    }

    /** @return list<array{loc: string, lastmod: string|null, alternates: array<string, string>}> */
    private function entries(): array
    {
        return [...$this->homePages(), ...$this->livePages(), ...$this->categoryPages(), ...$this->staticPages(), ...$this->articlePages()];
    }

    /** The Live page in both languages (the governorate filters are views of it, not separate pages to list). */
    private function livePages(): array
    {
        $alternates = [];
        foreach (Locale::cases() as $locale) {
            $alternates[$locale->value] = route('live', ['locale' => $locale->value]);
        }

        return array_map(
            fn (string $url) => ['loc' => $url, 'lastmod' => null, 'alternates' => $this->withDefault($alternates)],
            array_values($alternates),
        );
    }

    /** Static pages (About, Privacy...): every published language, with the other language as its alternate when that is live too. */
    private function staticPages(): array
    {
        return PageTranslation::query()
            ->join('pages', 'pages.id', '=', 'page_translations.page_id')
            ->where('page_translations.is_published', true)
            ->orderBy('pages.sort_order')
            ->orderBy('pages.id')
            ->orderBy('page_translations.locale')
            ->get(['page_translations.page_id', 'page_translations.locale', 'page_translations.updated_at', 'pages.slug'])
            ->groupBy('page_id')
            ->flatMap(function ($versions) {
                $alternates = $versions
                    ->mapWithKeys(fn (PageTranslation $t) => [$t->locale->value => route('page', ['locale' => $t->locale->value, 'slug' => $t->slug])])
                    ->all();

                return $versions->map(fn (PageTranslation $t) => [
                    'loc' => $alternates[$t->locale->value],
                    'lastmod' => $t->updated_at->toAtomString(),
                    'alternates' => $this->withDefault($alternates),
                ])->all();
            })
            ->all();
    }

    private function homePages(): array
    {
        $alternates = [];
        foreach (Locale::cases() as $locale) {
            $alternates[$locale->value] = route('home', ['locale' => $locale->value]);
        }

        return array_map(
            fn (string $url) => ['loc' => $url, 'lastmod' => null, 'alternates' => $this->withDefault($alternates)],
            array_values($alternates),
        );
    }

    private function categoryPages(): array
    {
        return CategoryTranslation::query()
            ->orderBy('category_id')
            ->get(['category_id', 'locale', 'slug'])
            ->groupBy('category_id')
            ->flatMap(function ($versions) {
                $alternates = $versions
                    ->mapWithKeys(fn (CategoryTranslation $c) => [$c->locale->value => route('category', ['locale' => $c->locale->value, 'slug' => $c->slug])])
                    ->all();

                return array_map(
                    fn (string $url) => ['loc' => $url, 'lastmod' => null, 'alternates' => $this->withDefault($alternates)],
                    array_values($alternates),
                );
            })
            ->all();
    }

    private function articlePages(): array
    {
        $translations = ArticleTranslation::query()
            ->select(['id', 'article_id', 'locale', 'slug', 'updated_at'])
            ->visible()
            ->whereHas('article')
            ->orderByDesc('published_at')
            ->orderByDesc('id')
            ->limit(self::MAX_ARTICLES)
            ->get();

        $alternatesByArticle = $translations->groupBy('article_id')->map(
            fn ($versions) => $versions->mapWithKeys(fn (ArticleTranslation $t) => [$t->locale->value => $t->url()])->all(),
        );

        return $translations->map(fn (ArticleTranslation $t) => [
            'loc' => $alternatesByArticle[$t->article_id][$t->locale->value],
            'lastmod' => $t->updated_at->toAtomString(),
            'alternates' => $this->withDefault($alternatesByArticle[$t->article_id]),
        ])->all();
    }

    /**
     * x-default is the Arabic edition (the site's default language) whenever it exists.
     *
     * @param  array<string, string>  $alternates
     * @return array<string, string>
     */
    private function withDefault(array $alternates): array
    {
        ksort($alternates);
        $alternates['x-default'] = $alternates[Locale::default()->value] ?? reset($alternates);

        return $alternates;
    }
}
