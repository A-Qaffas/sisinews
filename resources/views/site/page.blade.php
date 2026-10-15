@extends('layouts.site')

@section('title', $translation->title)

@push('head')
    @php
        $siteName = config('site.name.'.$locale->value, config('app.name'));
        $ogLocales = ['ar' => 'ar_EG', 'en' => 'en_US'];
    @endphp
    <meta name="description" content="{{ $description }}">
    <link rel="canonical" href="{{ $canonical }}">
    @foreach ($alternates as $hreflang => $href)
        <link rel="alternate" hreflang="{{ $hreflang }}" href="{{ $href }}">
    @endforeach
    <meta property="og:type" content="website">
    <meta property="og:site_name" content="{{ $siteName }}">
    <meta property="og:title" content="{{ $translation->title }}">
    <meta property="og:description" content="{{ $description }}">
    <meta property="og:url" content="{{ $canonical }}">
    <meta property="og:image" content="{{ $image }}">
    <meta property="og:locale" content="{{ $ogLocales[$locale->value] }}">
    @if ($alternate)
        <meta property="og:locale:alternate" content="{{ $ogLocales[$alternate->locale->value] }}">
    @endif
    <meta name="twitter:card" content="summary">
    <meta name="twitter:title" content="{{ $translation->title }}">
    <meta name="twitter:description" content="{{ $description }}">
@endpush

@if ($alternate)
    @section('alternate_url', $page->url($alternate->locale))
@endif

@section('content')
    <div class="sn-page">
        <article class="sn-article">
            <nav class="sn-crumbs" aria-label="{{ __('layout.pages.breadcrumb') }}">
                <ol>
                    <li><a href="{{ route('home', ['locale' => $locale->value]) }}">{{ __('layout.home') }}</a></li>
                    <li><span aria-current="page">{{ $translation->title }}</span></li>
                </ol>
            </nav>

            <header>
                <h1 class="sn-title">{{ $translation->title }}</h1>
                <p class="sn-meta">
                    <time datetime="{{ $updatedAt->toDateString() }}">{{ __('layout.pages.updated', ['date' => $updatedAt->translatedFormat('j F Y')]) }}</time>
                </p>
            </header>

            {{-- Staff-written HTML, sanitized by App\Support\HtmlSanitizer when it is saved; the only unescaped output on the page. --}}
            <div class="sn-prose">{!! $body !!}</div>
        </article>
    </div>
@endsection
