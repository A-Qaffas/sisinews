<?php

namespace App\View\Components\Site;

use App\Enums\Locale;
use Illuminate\Contracts\View\View;
use Illuminate\View\Component;
use InvalidArgumentException;

/**
 * The masthead credits (chairman, editor-in-chief) in one of three places:
 *   header  two stacked lines beside the logo, from md up
 *   strip   one slim line under the header row, on phones (below md)
 *   footer  the imprint line in the footer's bottom bar
 * The layout reads the lines once per page (App\Support\Masthead::credits(), none on error pages) and hands them down.
 */
class MastheadCredits extends Component
{
    public const VARIANTS = ['header', 'strip', 'footer'];

    /** @param  list<array{role: string, name: string}>  $credits */
    public function __construct(public Locale $locale, public array $credits = [], public string $variant = 'header')
    {
        if (! in_array($variant, self::VARIANTS, true)) {
            throw new InvalidArgumentException("Unknown masthead credits variant [{$variant}].");
        }
    }

    /** No name set in this language, no markup at all (not even an empty strip). */
    public function shouldRender(): bool
    {
        return $this->credits !== [];
    }

    public function render(): View
    {
        return view('components.site.masthead-credits');
    }
}
