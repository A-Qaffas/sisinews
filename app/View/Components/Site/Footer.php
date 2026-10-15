<?php

namespace App\View\Components\Site;

use App\Enums\Locale;
use App\Services\SiteNavigation;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Str;
use Illuminate\View\Component;

class Footer extends Component
{
    private const SOCIAL_LABELS = ['facebook' => 'Facebook', 'x' => 'X', 'instagram' => 'Instagram', 'youtube' => 'YouTube'];

    public function __construct(public Locale $locale, public bool $minimal = false) {}

    /** @return list<array{name: string, slug: string}> */
    public function categories(): array
    {
        return $this->minimal ? [] : app(SiteNavigation::class)->categories($this->locale);
    }

    /** @return array<string, string> label => url, blank or non-http(s) entries dropped */
    public function social(): array
    {
        $links = [];

        foreach (self::SOCIAL_LABELS as $key => $label) {
            $url = config("site.social.{$key}");

            if (is_string($url) && Str::startsWith($url, ['https://', 'http://'])) {
                $links[$label] = $url;
            }
        }

        return $links;
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
