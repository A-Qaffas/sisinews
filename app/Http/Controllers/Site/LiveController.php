<?php

namespace App\Http\Controllers\Site;

use App\Enums\Locale;
use App\Http\Controllers\Controller;
use App\Services\LiveBroadcasts;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;

class LiveController extends Controller
{
    /**
     * `?g=<governorate slug>` narrows the page to one governorate. Anything else (an unknown slug, an array,
     * a stale link) is ignored and the page shows all of Egypt: a bad filter is not a reason for an error page.
     */
    public function __invoke(Request $request, string $locale, LiveBroadcasts $broadcasts): View
    {
        $locale = Locale::from($locale);

        $governorates = $broadcasts->governorates();
        $requested = $request->query('g');
        $focus = is_string($requested) ? $governorates->first(fn ($g) => $g->slug === $requested) : null;

        // The same view in each language keeps the filter.
        $url = fn (Locale $in) => route('live', ['locale' => $in->value, ...($focus === null ? [] : ['g' => $focus->slug])]);

        $alternates = [];
        foreach (Locale::cases() as $case) {
            $alternates[$case->value] = $url($case);
        }
        ksort($alternates);
        $alternates['x-default'] = $alternates[Locale::default()->value];

        return view('site.live', [
            'locale' => $locale,
            'governorates' => $governorates,
            'focus' => $focus,
            'data' => $broadcasts->page($locale, $governorates, $focus),
            'canonical' => $url($locale),
            'alternateUrl' => $url($locale->other()),
            'alternates' => $alternates,
        ]);
    }
}
