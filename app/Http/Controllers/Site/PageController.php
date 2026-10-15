<?php

namespace App\Http\Controllers\Site;

use App\Enums\Locale;
use App\Http\Controllers\Controller;
use App\Models\Page;
use App\Support\ArticleSeo;
use App\Support\PageBody;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Http\Response;
use Illuminate\Support\Str;

/** A static page (About, Privacy...) in one language. A language that is missing or unpublished is a 404. */
class PageController extends Controller
{
    public function __invoke(string $locale, string $slug): Response
    {
        $locale = Locale::from($locale);

        // Only published versions are loaded, so a hidden language can neither render nor be offered as an alternate.
        $page = Page::query()
            ->where('slug', $slug)
            ->with(['translations' => fn (HasMany $translations) => $translations->where('is_published', true)])
            ->first();

        $translation = $page?->translate($locale);
        abort_if($translation === null, 404);

        $alternate = $page->translate($locale->other());
        $canonical = $page->url($locale);

        $alternates = collect([$translation, $alternate])->filter()
            ->mapWithKeys(fn ($version) => [$version->locale->value => $page->url($version->locale)])
            ->sortKeys()
            ->all();
        // x-default is the Arabic edition (the site's default language) whenever it is live.
        $alternates['x-default'] = $alternates[Locale::Ar->value] ?? $canonical;

        $body = PageBody::render($translation->body, $locale);

        return response()->view('site.page', [
            'page' => $page,
            'translation' => $translation,
            'body' => $body,
            'alternate' => $alternate,
            'canonical' => $canonical,
            'alternates' => $alternates,
            'image' => $this->shareImage(),
            // Either text may hold the [[contact]] token, so both are read after it is filled in.
            'description' => filled($translation->meta_description)
                ? ArticleSeo::plain(PageBody::renderText($translation->meta_description, $locale))
                : Str::limit(ArticleSeo::plain($body), 160, '…', preserveWords: true),
            'updatedAt' => $translation->updated_at->copy()->timezone(config('site.timezone', 'Africa/Cairo')),
        ]);
    }

    /** The layout's logo-on-cream card when it exists (as for stories without a hero), else the logo itself. */
    private function shareImage(): string
    {
        $configured = (string) config('site.og_image');

        return $configured !== '' && is_file(public_path($configured)) ? asset($configured) : ArticleSeo::logoUrl();
    }
}
