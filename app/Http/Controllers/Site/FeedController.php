<?php

namespace App\Http\Controllers\Site;

use App\Enums\Locale;
use App\Http\Controllers\Controller;
use App\Models\ArticleTranslation;
use App\Services\StoryQuery;
use App\Support\ArticleSeo;
use App\Support\CanonicalHost;
use App\Support\Media\HeroImages;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Storage;

class FeedController extends Controller
{
    private const ITEMS = 30;

    /** Seconds. */
    private const CACHE_TTL = 300;

    private const IMAGE_TYPES = ['jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg', 'png' => 'image/png', 'webp' => 'image/webp'];

    public function __invoke(Request $request, string $locale): Response
    {
        $locale = Locale::from($locale);

        $render = fn () => view('site.feed', $this->channel($locale))->render();

        // The XML is full of absolute URLs, so it is cached per host, and only for the site's own host (see
        // CanonicalHost): a forged Host header is rendered for its sender alone, never stored under a key of its choosing.
        $origin = CanonicalHost::cacheOrigin($request);
        $xml = $origin === null
            ? $render()
            : Cache::remember('site.feed.'.$locale->value.'.'.md5($origin), self::CACHE_TTL, $render);

        return response($xml, 200, ['Content-Type' => 'application/rss+xml; charset=utf-8']);
    }

    /** @return array<string, mixed> */
    private function channel(Locale $locale): array
    {
        $stories = StoryQuery::newestFirst(StoryQuery::visible($locale))
            ->with('article.category.translations')
            ->limit(self::ITEMS)
            ->get();

        return [
            'language' => $locale->value,
            'title' => $this->xmlSafe((string) config('site.name.'.$locale->value, config('app.name'))),
            'description' => $this->xmlSafe((string) config('site.tagline.'.$locale->value)),
            'link' => route('home', ['locale' => $locale->value]),
            'selfLink' => route('feed', ['locale' => $locale->value]),
            'logo' => ArticleSeo::logoUrl(),
            'lastBuild' => ($stories->first()?->published_at ?? now())->toRssString(),
            'items' => $stories->map(fn (ArticleTranslation $t) => [
                'title' => $this->xmlSafe($t->title),
                'link' => $t->url(),
                'pubDate' => $t->published_at->toRssString(),
                'category' => $this->xmlSafe($t->article->category->nameIn($locale)),
                'description' => $this->xmlSafe(ArticleSeo::plain((string) ($t->excerpt ?: $t->meta_description))),
                'enclosure' => $this->enclosure($t->article->hero_image),
            ])->all(),
        ];
    }

    /**
     * RSS wants the enclosure's byte length and MIME type, so both are read from the very file
     * HeroImages::url() serves (the WebP variant when it exists, else the original).
     *
     * @return array{url: string, length: int, type: string}|null
     */
    private function enclosure(?string $heroImage): ?array
    {
        $path = HeroImages::safePath($heroImage);
        $url = HeroImages::url($heroImage, 'full');
        if ($path === null || $url === null) {
            return null;
        }

        $disk = Storage::disk('public');
        $variant = HeroImages::variantPath($path, 'full');
        $file = $disk->exists($variant) ? $variant : $path;

        return [
            'url' => url($url),
            'length' => (int) rescue(fn () => $disk->size($file), 0, report: false),
            'type' => self::IMAGE_TYPES[strtolower(pathinfo($file, PATHINFO_EXTENSION))] ?? 'image/jpeg',
        ];
    }

    /** A control character pasted from a word processor would make the whole feed ill-formed XML. */
    private function xmlSafe(string $text): string
    {
        return (string) preg_replace('/[^\x{9}\x{A}\x{D}\x{20}-\x{D7FF}\x{E000}-\x{FFFD}\x{10000}-\x{10FFFF}]/u', '', $text);
    }
}
