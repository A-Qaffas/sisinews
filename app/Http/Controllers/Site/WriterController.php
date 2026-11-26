<?php

namespace App\Http\Controllers\Site;

use App\Enums\Locale;
use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\StoryQuery;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;

/**
 * A writer's page: every story of theirs readers may see in this language, newest first. Reached from the byline.
 * The byline is the article shell's author, so a machine-translated English story credits the Arabic writer and the
 * hidden "Auto-translation" account never gets a page. A writer with nothing visible in this language is a 404:
 * staff accounts that never published stay private.
 */
class WriterController extends Controller
{
    private const PER_PAGE = 12;

    public function __invoke(string $locale, string $idSlug): View|RedirectResponse
    {
        $locale = Locale::from($locale);

        $writer = User::query()->find((int) strtok($idSlug, '-'));
        abort_if($writer === null || $writer->isSystemAccount(), 404);

        $byWriter = fn (Builder $shell) => $shell->where('author_id', $writer->id);

        $stories = StoryQuery::newestFirst(StoryQuery::visible($locale, $byWriter))
            ->with(StoryQuery::RELATIONS)
            ->paginate(self::PER_PAGE)
            ->onEachSide(1);

        abort_if($stories->isEmpty(), 404);

        $url = $writer->writerUrl($locale);
        if ($idSlug !== $writer->writerIdSlug($locale)) {
            $page = $stories->currentPage();

            return redirect()->to($page > 1 ? "{$url}?page={$page}" : $url, 301);
        }

        $page = $stories->currentPage();
        $other = $locale->other();
        $otherUrl = StoryQuery::visible($other, $byWriter)->exists() ? $writer->writerUrl($other) : null;

        return view('site.writer', [
            'locale' => $locale,
            'name' => $writer->displayName($locale),
            'stories' => $stories,
            'canonical' => $page > 1 ? "{$url}?page={$page}" : $url,
            'alternateUrl' => $otherUrl,
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
