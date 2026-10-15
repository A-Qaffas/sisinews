@extends('layouts.site')

@php
    $siteName = config('site.name.'.$locale->value, config('app.name'));
    $focusName = $focus?->nameIn($locale);
    $heading = $focus ? __('live.title_governorate', ['name' => $focusName]) : __('live.title');
    $description = $focus
        ? __('live.description_governorate', ['name' => $focusName, 'site' => $siteName])
        : __('live.description', ['site' => $siteName]);
    ['live' => $live, 'upcoming' => $upcoming, 'ended' => $ended, 'liveGovernorateIds' => $liveGovernorateIds, 'anyLive' => $anyLive] = $data;
@endphp

@section('title', $heading)

@push('head')
    <meta name="description" content="{{ $description }}">
    <link rel="canonical" href="{{ $canonical }}">
    @foreach ($alternates as $hreflang => $href)
        <link rel="alternate" hreflang="{{ $hreflang }}" href="{{ $href }}">
    @endforeach
    <meta property="og:type" content="website">
    <meta property="og:site_name" content="{{ $siteName }}">
    <meta property="og:title" content="{{ $heading }} | {{ $siteName }}">
    <meta property="og:description" content="{{ $description }}">
    <meta property="og:url" content="{{ $canonical }}">
    <meta property="og:image" content="{{ asset(config('site.og_image')) }}">
    <meta property="og:locale" content="{{ $locale === \App\Enums\Locale::Ar ? 'ar_EG' : 'en_US' }}">
    <meta name="twitter:card" content="summary_large_image">
@endpush

@section('alternate_url', $alternateUrl)

@section('content')
    <div class="sn-page live-page">
        <h1 class="section-title sn-page-title">{{ $heading }}</h1>
        <p class="live-intro">{{ __('live.intro') }}</p>

        @include('site.partials.live-filter')

        <section class="live-section" aria-labelledby="live-now-title">
            <h2 id="live-now-title" class="section-title">{{ __('live.live_now') }}</h2>

            @if ($live === [])
                <div class="live-empty">
                    @if ($focus)
                        <h3>{{ __('live.empty.title_governorate', ['name' => $focusName]) }}</h3>
                        <p>{{ __('live.empty.text_governorate') }}</p>
                        <a href="{{ route('live', ['locale' => $locale->value]) }}">{{ __('live.empty.show_all') }}</a>
                    @else
                        <h3>{{ __('live.empty.title') }}</h3>
                        <p>{{ $upcoming === [] ? __('live.empty.text_nothing_scheduled') : __('live.empty.text') }}</p>
                    @endif
                </div>
            @else
                <div @class(['live-grid', 'live-grid--single' => count($live) === 1])>
                    @foreach ($live as $item)
                        @include('site.partials.live-card')
                    @endforeach
                </div>
            @endif
        </section>

        @if ($upcoming !== [])
            <section class="live-section" aria-labelledby="live-upcoming-title">
                <h2 id="live-upcoming-title" class="section-title">{{ __('live.coming_up') }}</h2>
                <ul class="live-rows">
                    @foreach ($upcoming as $item)
                        @include('site.partials.live-upcoming')
                    @endforeach
                </ul>
            </section>
        @endif

        @if ($ended !== [])
            <section class="live-section" aria-labelledby="live-ended-title">
                <h2 id="live-ended-title" class="section-title">{{ __('live.recently_ended') }}</h2>
                <ul class="live-ended">
                    @foreach ($ended as $item)
                        @include('site.partials.live-ended')
                    @endforeach
                </ul>
            </section>
        @endif
    </div>
@endsection
