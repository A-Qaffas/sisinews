<?php

namespace App\Http\Controllers\Site;

use App\Enums\Locale;
use App\Http\Controllers\Controller;
use App\Models\CategoryTranslation;
use App\Services\StoryQuery;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class CategoryController extends Controller
{
    private const PER_PAGE = 12;

    public function __invoke(Request $request, string $locale, string $slug): View|RedirectResponse
    {
        $locale = Locale::from($locale);

        $category = CategoryTranslation::query()
            ->where('locale', $locale->value)
            ->where('slug', $slug)
            ->with('category.translations')
            ->first();

        abort_if($category === null, 404);

        // Database collations are case/accent-insensitive; send near-miss slugs to the one canonical URL.
        if ($category->slug !== $slug) {
            return redirect()->route('category', ['locale' => $locale->value, 'slug' => $category->slug], 301);
        }

        $stories = StoryQuery::newestFirst(
            StoryQuery::visible($locale, fn (Builder $shell) => $shell->where('category_id', $category->category_id)),
        )->with(StoryQuery::RELATIONS)->paginate(self::PER_PAGE)->onEachSide(1);

        // Past the last page there is nothing to show; a 404 beats a blank page with a working pager.
        abort_if($stories->isEmpty() && $stories->currentPage() > 1, 404);

        $page = $stories->currentPage();
        $url = route('category', ['locale' => $locale->value, 'slug' => $category->slug]);
        $other = $category->category->translate($locale->other());
        $otherUrl = $other ? route('category', ['locale' => $other->locale->value, 'slug' => $other->slug]) : null;

        return view('site.category', [
            'category' => $category,
            'stories' => $stories,
            'canonical' => $page > 1 ? "{$url}?page={$page}" : $url,
            'alternateUrl' => $otherUrl,
            // hreflang describes the section as a whole, so only its first page carries it.
            'alternates' => $page === 1 && $otherUrl ? $this->alternates($locale, $url, $otherUrl) : [],
        ]);
    }

    /** @return array<string, string> hreflang => URL, with x-default on the Arabic edition */
    private function alternates(Locale $locale, string $url, string $otherUrl): array
    {
        $alternates = [$locale->value => $url, $locale->other()->value => $otherUrl];
        ksort($alternates);
        $alternates['x-default'] = $alternates[Locale::Ar->value];

        return $alternates;
    }
}
