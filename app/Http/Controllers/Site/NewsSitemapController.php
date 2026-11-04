<?php

namespace App\Http\Controllers\Site;

use App\Http\Controllers\Controller;
use App\Models\ArticleTranslation;
use App\Support\CanonicalHost;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Cache;

/**
 * Google News sitemap: only the stories published in the last two days, which is all Google reads from one. The
 * main sitemap still lists every story; this one exists so fresh news is found within minutes, not days.
 */
class NewsSitemapController extends Controller
{
    /** Google's ceiling for one news sitemap. */
    private const MAX_ARTICLES = 1000;

    /** Google ignores news-sitemap entries older than two days. */
    private const WINDOW_HOURS = 48;

    /** Seconds: short, so a story is listed within minutes of going live. */
    private const CACHE_TTL = 300;

    public function __invoke(Request $request): Response
    {
        $render = fn () => view('site.news-sitemap', ['entries' => $this->entries()])->render();

        // Per host, and only the site's own host is cached, exactly as the main sitemap (see CanonicalHost).
        $origin = CanonicalHost::cacheOrigin($request);
        $xml = $origin === null ? $render() : Cache::remember('site.news-sitemap.'.md5($origin), self::CACHE_TTL, $render);

        return response($xml, 200, ['Content-Type' => 'application/xml; charset=utf-8']);
    }

    /** @return list<array{loc: string, name: string, language: string, published: string, title: string}> */
    private function entries(): array
    {
        return ArticleTranslation::query()
            ->select(['id', 'article_id', 'locale', 'slug', 'title', 'published_at'])
            ->visible()
            ->whereHas('article')
            ->where('published_at', '>=', now()->subHours(self::WINDOW_HOURS))
            ->orderByDesc('published_at')
            ->orderByDesc('id')
            ->limit(self::MAX_ARTICLES)
            ->get()
            ->map(fn (ArticleTranslation $t) => [
                'loc' => $t->url(),
                // The publication name must be the same on every story of a language (Google matches on it).
                'name' => (string) config('site.name.'.$t->locale->value, config('app.name')),
                'language' => $t->locale->value,
                'published' => $t->published_at->toAtomString(),
                'title' => $t->title,
            ])
            ->all();
    }
}
