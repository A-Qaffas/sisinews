{{--
  Public site shell. Contract every page view relies on:
    @extends('layouts.site')
    @section('title', 'Page title')           plain text; the layout appends the site name
    @push('head') ... @endpush                optional: meta description, canonical, hreflang, Open Graph, JSON-LD
    @section('alternate_url', $url)           optional: this same page in the other language (language switcher)
    @section('content') ... @endsection       rendered inside <main class="container-site">: do not add another container
  $locale (App\Enums\Locale) is shared by the SetLocale middleware. Error pages pass ['minimal' => true]
  to @extends so they render without touching the database.
--}}
@php
    use App\Enums\Locale;

    // Unmatched URLs never reach SetLocale, so error pages resolve the language from the path here.
    $locale ??= Locale::tryFrom((string) request()->segment(1)) ?? Locale::default();
    app()->setLocale($locale->value);
    \Carbon\Carbon::setLocale($locale->value);

    $minimal ??= false;
    $siteName = config('site.name.'.$locale->value);
    $tagline = config('site.tagline.'.$locale->value);
    // @section('x', $value) HTML-escapes $value on the way in; decode so each use below escapes exactly once.
    $pageTitle = html_entity_decode(trim($__env->yieldContent('title')), ENT_QUOTES | ENT_HTML5, 'UTF-8');
    $alternateUrl = html_entity_decode(trim($__env->yieldContent('alternate_url')), ENT_QUOTES | ENT_HTML5, 'UTF-8') ?: null;
    $fontAliases = $locale === Locale::En ? ['ibm-plex-sans', 'playfair-display'] : ['ibm-plex-sans-arabic', 'ibm-plex-sans-arabic-latin'];
    // A missing build must not turn every page, error pages included, into a Vite exception.
    $hasAssets = is_file(public_path('build/manifest.json')) || is_file(public_path('hot'));
@endphp
<!DOCTYPE html>
<html lang="{{ $locale->value }}" dir="{{ $locale->dir() }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ $pageTitle !== '' ? $pageTitle.' | '.$siteName : $siteName.' | '.$tagline }}</title>
    <meta name="theme-color" content="{{ config('site.theme_color') }}">
    <link rel="icon" type="image/png" sizes="48x48" href="{{ asset('brand/favicon-48.png') }}">
    <link rel="apple-touch-icon" href="{{ asset('brand/apple-touch-icon.png') }}">
    @unless ($minimal)
        <link rel="alternate" type="application/rss+xml" title="{{ $siteName }}" href="{{ route('feed', ['locale' => $locale->value]) }}">
    @endunless
    <script>document.documentElement.classList.add('js')</script>
    @if ($hasAssets)
        @fonts($fontAliases)
        @vite(['resources/css/app.css', 'resources/js/app.js'])
    @endif
    @stack('head')
</head>
<body>
    <a class="skip-link" href="#main">{{ __('layout.skip') }}</a>

    <x-site.header :locale="$locale" :minimal="$minimal" :alternate-url="$alternateUrl" />

    <main id="main" tabindex="-1" class="container-site pt-6 md:pt-8">
        @yield('content')
    </main>

    <x-site.footer :locale="$locale" :minimal="$minimal" />
</body>
</html>
