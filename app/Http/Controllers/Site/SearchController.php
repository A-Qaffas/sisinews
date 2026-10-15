<?php

namespace App\Http\Controllers\Site;

use App\Enums\Locale;
use App\Http\Controllers\Controller;
use App\Services\StoryQuery;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;

class SearchController extends Controller
{
    private const MIN_LENGTH = 2;

    private const MAX_LENGTH = 80;

    private const PER_PAGE = 10;

    /** The character LIKE treats as an escape. Unlike backslash it means the same thing in MySQL, MariaDB and SQLite. */
    private const ESCAPE = '!';

    public function __invoke(Request $request, string $locale): View
    {
        $locale = Locale::from($locale);
        $term = $this->term($request->query('q'));

        $problem = null;
        $results = null;

        if ($term !== '') {
            $length = mb_strlen($term);

            if ($length < self::MIN_LENGTH) {
                $problem = 'short';
            } elseif ($length > self::MAX_LENGTH) {
                $problem = 'long';
            } else {
                $results = $this->search($locale, $term);
            }
        }

        return view('site.search', [
            'term' => $term,
            'problem' => $problem,
            'results' => $results,
            'minLength' => self::MIN_LENGTH,
            'maxLength' => self::MAX_LENGTH,
        ]);
    }

    /** Trimmed, with control characters and runs of whitespace collapsed to one space; anything that is not text becomes empty. */
    private function term(mixed $raw): string
    {
        if (! is_string($raw)) {
            return '';
        }

        return trim((string) preg_replace('/[\p{Cc}\s]+/u', ' ', $raw));
    }

    private function search(Locale $locale, string $term): LengthAwarePaginator
    {
        // The user's text is only ever a bound parameter; escaping makes % and _ literal instead of wildcards.
        $like = '%'.str_replace(['!', '%', '_'], ['!!', '!%', '!_'], $term).'%';

        return StoryQuery::newestFirst(StoryQuery::visible($locale))
            ->with(StoryQuery::RELATIONS)
            ->where(function (Builder $query) use ($like) {
                foreach (['title', 'excerpt', 'body'] as $column) {
                    $query->orWhereRaw("{$column} like ? escape '".self::ESCAPE."'", [$like]);
                }
            })
            ->paginate(self::PER_PAGE)
            ->onEachSide(1)
            ->appends(['q' => $term]);
    }
}
