@php
    $home = route('home', ['locale' => $locale->value]);
    $other = $locale->other();
    $activeSlug = $currentCategorySlug();
@endphp
<header>
    <div class="topbar on-dark">
        <div class="container-site flex items-center justify-between gap-4 py-1.5">
            <time datetime="{{ now(config('site.timezone'))->toDateString() }}">{{ $today() }}</time>
            <a class="lang-switch" href="{{ $switchUrl() }}" lang="{{ $other->value }}" hreflang="{{ $other->value }}">{{ $other->label() }}</a>
        </div>
    </div>

    <div class="container-site flex items-center justify-between gap-4 py-4 lg:py-5">
        <a href="{{ $home }}" class="shrink-0">
            <img src="{{ asset('brand/logo-380.webp') }}" width="380" height="106" alt="{{ config('site.name.'.$locale->value) }}" class="h-9 w-auto md:h-12 lg:h-14">
        </a>
        <p class="masthead-tagline hidden md:block">{{ config('site.tagline.'.$locale->value) }}</p>
        @unless ($minimal)
            {{-- Below md the Live link folds into the menu, so while something is on air a short one waits beside it. --}}
            <div class="flex items-center gap-1 md:hidden">
                @if ($liveNow() && ! $onLive())
                    <a class="live-quick" href="{{ route('live', ['locale' => $locale->value]) }}"><span class="live-dot live-dot--pulse" aria-hidden="true"></span>{{ __('layout.live') }}<span class="sr-only">({{ __('live.now_streaming') }})</span></a>
                @endif
                <button type="button" class="menu-toggle" data-nav-toggle aria-expanded="false" aria-controls="site-nav">
                    <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" aria-hidden="true" focusable="false"><path d="M4 7h16M4 12h16M4 17h16"/></svg>
                    <span>{{ __('layout.menu') }}</span>
                </button>
            </div>
        @endunless
    </div>

    @unless ($minimal)
        <nav id="site-nav" class="nav-bar nav-panel" aria-label="{{ __('layout.primary_nav') }}">
            <div class="container-site flex flex-col lg:flex-row lg:items-center lg:justify-between lg:gap-6">
                <ul class="nav-list md:flex md:flex-wrap">
                    <li><a class="nav-link" href="{{ $home }}" @if ($onHome()) aria-current="page" @endif>{{ __('layout.home') }}</a></li>
                    @foreach ($categories() as $category)
                        <li><a class="nav-link" href="{{ route('category', ['locale' => $locale->value, 'slug' => $category['slug']]) }}" @if ($activeSlug === $category['slug']) aria-current="page" @endif>{{ $category['name'] }}</a></li>
                    @endforeach
                    <li>
                        <a class="nav-link nav-link--live" href="{{ route('live', ['locale' => $locale->value]) }}" @if ($onLive()) aria-current="page" @endif>
                            {{ __('layout.live') }}
                            @if ($liveNow())<span class="live-dot live-dot--pulse" aria-hidden="true"></span><span class="sr-only">({{ __('live.now_streaming') }})</span>@endif
                        </a>
                    </li>
                </ul>

                <form class="search-form order-first my-3 md:max-w-sm lg:order-last lg:my-0 lg:w-64" role="search" method="get" action="{{ route('search', ['locale' => $locale->value]) }}">
                    <label class="sr-only" for="site-search">{{ __('layout.search_label') }}</label>
                    <input id="site-search" type="search" name="q" required minlength="2" maxlength="100" autocomplete="off" placeholder="{{ __('layout.search_placeholder') }}">
                    <button type="submit" aria-label="{{ __('layout.search') }}">
                        <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" aria-hidden="true" focusable="false"><circle cx="11" cy="11" r="7"/><path d="m20 20-3.5-3.5"/></svg>
                    </button>
                </form>
            </div>
        </nav>

        <x-site.ticker :locale="$locale" />
    @endunless
</header>
