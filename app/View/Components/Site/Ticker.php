<?php

namespace App\View\Components\Site;

use App\Enums\Locale;
use App\Models\ArticleTranslation;
use App\Services\BreakingTicker;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Collection;
use Illuminate\View\Component;

class Ticker extends Component
{
    /** @var Collection<int, ArticleTranslation> */
    public Collection $items;

    public function __construct(public Locale $locale)
    {
        $this->items = app(BreakingTicker::class)->items($locale);
    }

    /** No breaking story, no ticker. */
    public function shouldRender(): bool
    {
        return $this->items->isNotEmpty();
    }

    /** Roughly ten seconds per headline keeps the scroll readable however many there are. */
    public function duration(): int
    {
        return max(30, $this->items->count() * 10);
    }

    public function render(): View
    {
        return view('components.site.ticker');
    }
}
