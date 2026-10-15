@extends('layouts.site')

@section('title', $translation->title)

@push('head')
    @include('site.partials.article-head')
@endpush

@if ($alternate)
    @section('alternate_url', $alternate->url())
@endif

@section('content')
    {{-- Same rule as the home page: the sidebar appears once there is reading data to rank. --}}
    @php($hasSidebar = $mostRead->isNotEmpty())
    <div class="sn-page">
        @if ($preview)
            @include('site.partials.article-preview-banner')
        @endif

        <div @class(['sn-layout', 'sn-layout--aside' => $hasSidebar])>
            <article class="sn-article">
                @include('site.partials.article-header')
                @include('site.partials.article-hero')
                @include('site.partials.article-tools')

                {{-- Staff-written HTML, sanitized by App\Support\HtmlSanitizer when it is saved; the only unescaped output on the page. --}}
                <div class="sn-prose">{!! $translation->body !!}</div>

                @include('site.partials.article-footer')
            </article>

            @if ($hasSidebar)
                <aside class="sn-aside" aria-label="{{ __('layout.tabs_label') }}">
                    @include('site.partials.home-sidebar', ['mostRead' => $mostRead, 'latest' => $latest])
                </aside>
            @endif

            @include('site.partials.article-related')
        </div>
    </div>
@endsection
