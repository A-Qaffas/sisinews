<?php

namespace App\View\Components\Site;

use App\Enums\Locale;
use App\Services\SiteNavigation;
use App\Support\SocialProfiles;
use Illuminate\Contracts\View\View;
use Illuminate\View\Component;

class Footer extends Component
{
    /** @param  list<array{role: string, name: string}>  $credits  masthead credits (App\Support\Masthead::credits()) */
    public function __construct(public Locale $locale, public bool $minimal = false, public array $credits = []) {}

    /** @return list<array{name: string, slug: string}> */
    public function categories(): array
    {
        return $this->minimal ? [] : app(SiteNavigation::class)->categories($this->locale);
    }

    /** @return array<string, string> label => url, blank or non-http(s) entries dropped */
    public function social(): array
    {
        return SocialProfiles::links();
    }

    /** A form posted without a session would only ever fail its CSRF check (e.g. on a 404 for an unmatched URL). */
    public function showNewsletter(): bool
    {
        return ! $this->minimal && session()->isStarted();
    }

    public function render(): View
    {
        return view('components.site.footer');
    }
}
