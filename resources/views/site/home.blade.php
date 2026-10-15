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
@endpush

@section('content')
    <h1 class="sr-only">{{ $siteName }} | {{ $tagline }}</h1>

    @include('site.partials.live-strip', ['items' => $liveNow])

    @if ($lead === null)
        @include('site.partials.empty-state')
    @else
        <section class="grid gap-8 lg:grid-cols-12 lg:gap-10" aria-labelledby="top-stories">
            <h2 id="top-stories" class="sr-only">{{ __('layout.top_stories') }}</h2>

            <div class="lg:col-span-8">
                <x-site.article-card :translation="$lead" variant="lead" :show-excerpt="true" />
            </div>

            @if ($secondary->isNotEmpty())
                <div class="flex flex-col gap-6 border-t border-line pt-6 lg:col-span-4 lg:border-t-0 lg:border-s lg:pt-0 lg:ps-8 [&>*+*]:border-t [&>*+*]:border-line [&>*+*]:pt-6">
                    @foreach ($secondary as $story)
                        {{-- On tablets the first card lies down beside its image so a full-width picture does not dwarf the page. --}}
                        <x-site.article-card :translation="$story" :variant="$loop->first ? 'card' : 'row'" :class="$loop->first ? 'md:flex-row md:gap-6 md:[&>.media]:w-2/5 md:[&>.media]:shrink-0 lg:flex-col lg:gap-4 lg:[&>.media]:w-auto' : 'lg:[&>.media]:w-28 lg:[&_.story-title]:text-base xl:[&_.story-title]:text-lg'" />
                    @endforeach
                </div>
            @endif
        </section>

        {{-- The sidebar's Latest tab would only repeat the list beside it, so it appears once there is reading data to rank. --}}
        @php($hasSidebar = $mostRead->isNotEmpty())
        <div class="mt-12 grid gap-12 lg:grid-cols-12 lg:gap-10">
            <div @class(['space-y-12', 'lg:col-span-8' => $hasSidebar, 'lg:col-span-12' => ! $hasSidebar])>
                @if ($latest->isNotEmpty())
                    <section aria-labelledby="latest-title">
                        <h2 id="latest-title" class="section-title">{{ __('layout.latest') }}</h2>
                        <div class="divide-y divide-line">
                            @foreach ($latest as $story)
                                <div class="py-5 first:pt-0 last:pb-0">
                                    <x-site.article-card :translation="$story" variant="row" :show-excerpt="true" />
                                </div>
                            @endforeach
                        </div>
                    </section>
                @endif

                @if ($blocks !== [])
                    <div @class(['grid gap-x-10 gap-y-12 md:grid-cols-2', 'lg:grid-cols-3' => ! $hasSidebar])>
                        @foreach ($blocks as $block)
                            @include('site.partials.category-block', ['block' => $block, 'id' => 'category-'.$loop->iteration, 'wide' => $hasSidebar && $loop->last && $loop->count % 2 === 1])
                        @endforeach
                    </div>
                @endif
            </div>

            @if ($hasSidebar)
                <aside class="lg:col-span-4" aria-label="{{ __('layout.tabs_label') }}">
                    @include('site.partials.home-sidebar', ['mostRead' => $mostRead, 'latest' => $sidebarLatest])
                </aside>
            @endif
        </div>
    @endif
@endsection
