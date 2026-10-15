@extends('layouts.site')

@section('title', $stories->currentPage() > 1 ? $category->name.' - '.__('layout.pagination.page', ['page' => $stories->currentPage()]) : $category->name)

@push('head')
    <meta name="description" content="{{ __('article.category.description', ['name' => $category->name, 'site' => config('site.name.'.$locale->value, config('app.name'))]) }}">
    <link rel="canonical" href="{{ $canonical }}">
    @foreach ($alternates as $hreflang => $href)
        <link rel="alternate" hreflang="{{ $hreflang }}" href="{{ $href }}">
    @endforeach
@endpush

@if ($alternateUrl)
    @section('alternate_url', $alternateUrl)
@endif

@section('content')
    <div class="sn-page">
        <h1 class="section-title sn-page-title">{{ $category->name }}</h1>

        @if ($stories->isEmpty())
            <div class="sn-empty">
                <h2>{{ __('article.category.empty_title') }}</h2>
                <p>{{ __('article.category.empty_text') }}</p>
                <a href="{{ route('home', ['locale' => $locale->value]) }}">{{ __('article.home') }}</a>
            </div>
        @else
            {{-- Page one opens with a lead story; later pages are a plain grid. --}}
            @php($lead = $stories->currentPage() === 1 ? $stories->first() : null)

            @if ($lead)
                <div class="sn-lead">
                    {{-- Beside its text on desktop: a full-width 16:9 picture is taller than the screen. --}}
                    <x-site.article-card :translation="$lead" variant="lead" :show-excerpt="true" class="lg:flex-row lg:items-center lg:gap-8 lg:[&>.media]:w-3/5 lg:[&>.media]:shrink-0" />
                </div>
            @endif

            @if ($stories->count() > ($lead ? 1 : 0))
                <div class="sn-grid sn-grid--3">
                    @foreach ($stories->slice($lead ? 1 : 0) as $story)
                        <x-site.article-card :translation="$story" variant="card" :show-excerpt="true" />
                    @endforeach
                </div>
            @endif

            {{ $stories->links('vendor.pagination.site') }}
        @endif
    </div>
@endsection
