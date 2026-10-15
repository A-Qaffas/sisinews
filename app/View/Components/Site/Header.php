<?php

namespace App\View\Components\Site;

use App\Enums\Locale;
use App\Services\LiveBroadcasts;
use App\Services\SiteNavigation;
use Illuminate\Contracts\View\View;
use Illuminate\View\Component;

class Header extends Component
{
    /**
     * @param  bool  $minimal  error pages: no navigation, search or ticker, so rendering needs no database
     * @param  string|null  $alternateUrl  the same page in the other language, when the page knows it
     */
    public function __construct(
        public Locale $locale,
        public bool $minimal = false,
        public ?string $alternateUrl = null,
    ) {}

    /** @return list<array{name: string, slug: string}> */
    public function categories(): array
    {
        return $this->minimal ? [] : app(SiteNavigation::class)->categories($this->locale);
    }

    private ?bool $live = null;

    /**
     * True while a broadcast is live (the Live link then shows its pulsing dot, and phones get a Live link beside the
     * Menu button). Same 30-second cache as the home strip; asked once per page however often the view calls it.
     */
    public function liveNow(): bool
    {
        return $this->live ??= ! $this->minimal && app(LiveBroadcasts::class)->liveNow($this->locale) !== [];
    }

    public function onLive(): bool
    {
        return request()->routeIs('live');
    }

    public function today(): string
    {
        return now(config('site.timezone'))->locale($this->locale->value)->translatedFormat('l j F Y');
    }

    /** Same page in the other language when it exists, otherwise that language's home page. */
    public function switchUrl(): string
    {
        if (filled($this->alternateUrl)) {
            return $this->alternateUrl;
        }

        $other = $this->locale->other()->value;
        $request = request();

        if ($request->routeIs('search') && is_string($query = $request->query('q')) && $query !== '') {
            return route('search', ['locale' => $other, 'q' => $query]);
        }

        return route($request->routeIs('search') ? 'search' : 'home', ['locale' => $other]);
    }

    public function onHome(): bool
    {
        return request()->routeIs('home');
    }

    public function currentCategorySlug(): ?string
    {
        $slug = request()->routeIs('category') ? request()->route('slug') : null;

        return is_string($slug) ? $slug : null;
    }

    public function render(): View
    {
        return view('components.site.header');
    }
}
