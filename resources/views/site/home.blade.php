@extends('layouts.site')

@php
    $siteName = config('site.name.'.$locale->value);
    $tagline = config('site.tagline.'.$locale->value);
    $homeUrl = route('home', ['locale' => $locale->value]);
@endphp

@push('head')
    <meta name="description" content="{{ __('layout.footer.about') }}">
    <link rel="canonical" href="{{ $homeUrl }}">
    @foreach (\App\Enums\Locale::cases() as $alternate)
        <link rel="alternate" hreflang="{{ $alternate->value }}" href="{{ route('home', ['locale' => $alternate->value]) }}">
    @endforeach
    <link rel="alternate" hreflang="x-default" href="{{ route('root') }}">
    <meta property="og:type" content="website">
    <meta property="og:site_name" content="{{ $siteName }}">
    <meta property="og:title" content="{{ $siteName }} | {{ $tagline }}">
    <meta property="og:description" content="{{ __('layout.footer.about') }}">
    <meta property="og:url" content="{{ $homeUrl }}">
    <meta property="og:image" content="{{ asset(config('site.og_image')) }}">
    <meta property="og:locale" content="{{ $locale === \App\Enums\Locale::Ar ? 'ar_EG' : 'en_US' }}">
    <meta name="twitter:card" content="summary_large_image">
    {{-- Who publishes the site, for Google. The HEX flags keep any text from closing the script element. --}}
    <script type="application/ld+json">{!! json_encode(\App\Support\SiteSeo::homeJsonLd($locale), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) !!}</script>
@endpush

@section('content')
    <h1 class="sr-only">{{ $siteName }} | {{ $tagline }}</h1>

    @if ($lead === null)
        @include('site.partials.live-strip', ['items' => $liveNow])
        @include('site.partials.empty-state')
    @else
        {{-- The navy band: the rotating slider, "top stories" beside it (desktop) and a row of cards under it. --}}
        <section class="home-band on-dark" aria-labelledby="top-stories">
            <h2 id="top-stories" class="sr-only">{{ __('layout.top_stories') }}</h2>

            @include('site.partials.live-strip', ['items' => $liveNow])

            <div class="home-hero">
                @include('site.partials.home-slider', ['slides' => $slides])

                @if ($slides->count() > 1)
                    <div class="hero-side">
                        <p class="hero-side__title">{{ __('layout.top_stories') }}</p>
                        <ol class="hero-side__list">
                            @foreach ($slides as $story)
                                <li data-slide-item="{{ $loop->index }}" @class(['is-current' => $loop->first])>
                                    <x-site.article-card :translation="$story" variant="row" />
                                </li>
                            @endforeach
                        </ol>
                    </div>
                @endif
            </div>

            @if ($strip->isNotEmpty())
                <div class="hero-strip">
                    @foreach ($strip as $story)
                        <x-site.article-card :translation="$story" variant="card" />
                    @endforeach
                </div>
            @endif
        </section>

        {{-- The sidebar's Latest tab would only repeat the list beside it, so it appears once there is reading data to rank. --}}
        @php($hasSidebar = $mostRead->isNotEmpty())
        <div class="mt-10 grid gap-12 lg:grid-cols-12 lg:gap-10">
            <div @class(['min-w-0 space-y-12', 'lg:col-span-8' => $hasSidebar, 'lg:col-span-12' => ! $hasSidebar])>
                @if ($latest->isNotEmpty())
                    @include('site.partials.home-latest', ['stories' => $latest])
                @endif

                @foreach ($blocks as $block)
                    @include('site.partials.category-block', ['block' => $block, 'id' => 'category-'.$loop->iteration])
                @endforeach
            </div>

            @if ($hasSidebar)
                <aside class="lg:col-span-4" aria-label="{{ __('layout.tabs_label') }}">
                    @include('site.partials.home-sidebar', ['mostRead' => $mostRead, 'latest' => $sidebarLatest])
                </aside>
            @endif
        </div>
    @endif
@endsection
