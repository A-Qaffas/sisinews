{{-- Applies a reader's saved text size before first paint (article-tools.js owns the same key). --}}
<script>try{var s=localStorage.getItem('sisi.textScale');if(s){document.documentElement.style.setProperty('--text-scale',String(Math.min(1.5,Math.max(0.875,Number(s)||1))))}}catch(e){}</script>
@if ($preview)
    <meta name="robots" content="noindex, nofollow">
@else
    @php
        $siteName = config('site.name.'.$locale->value, config('app.name'));
        $ogLocales = ['ar' => 'ar_EG', 'en' => 'en_US'];
    @endphp
    <meta name="description" content="{{ $seo['description'] }}">
    <link rel="canonical" href="{{ $seo['canonical'] }}">
    @foreach ($seo['alternates'] as $hreflang => $href)
        <link rel="alternate" hreflang="{{ $hreflang }}" href="{{ $href }}">
    @endforeach
    <meta property="og:type" content="article">
    <meta property="og:site_name" content="{{ $siteName }}">
    <meta property="og:title" content="{{ $translation->title }}">
    <meta property="og:description" content="{{ $seo['description'] }}">
    <meta property="og:url" content="{{ $seo['canonical'] }}">
    <meta property="og:image" content="{{ $seo['image'] }}">
    <meta property="og:locale" content="{{ $ogLocales[$locale->value] }}">
    @if ($alternate)
        <meta property="og:locale:alternate" content="{{ $ogLocales[$alternate->locale->value] }}">
    @endif
    <meta property="article:published_time" content="{{ $seo['published'] }}">
    <meta property="article:modified_time" content="{{ $seo['modified'] }}">
    <meta property="article:section" content="{{ $seo['section'] }}">
    <meta name="twitter:card" content="summary_large_image">
    <meta name="twitter:title" content="{{ $translation->title }}">
    <meta name="twitter:description" content="{{ $seo['description'] }}">
    <meta name="twitter:image" content="{{ $seo['image'] }}">
    {{-- The HEX flags turn < > & ' " into \u00XX escapes, so no story text can close the script element. --}}
    <script type="application/ld+json">{!! json_encode($seo['jsonLd'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) !!}</script>
@endif
