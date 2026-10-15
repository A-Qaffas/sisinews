@extends('layouts.site')

@section('title', $term !== '' ? __('article.search.title').': '.$term : __('article.search.title'))

@push('head')
    {{-- Result pages are endless near-duplicates; keep them out of search engines but let crawlers follow the story links. --}}
    <meta name="robots" content="noindex, follow">
@endpush

@section('content')
    <div class="sn-page">
        <h1 class="section-title sn-page-title">{{ __('article.search.title') }}</h1>

        <form class="sn-search" role="search" method="get" action="{{ route('search', ['locale' => $locale->value]) }}">
            <label class="sn-visually-hidden" for="sn-search-input">{{ __('article.search.label') }}</label>
            <input id="sn-search-input" type="search" name="q" value="{{ $term }}" placeholder="{{ __('article.search.placeholder') }}"
                   minlength="{{ $minLength }}" maxlength="{{ $maxLength }}" required enterkeyhint="search" autocomplete="off">
            <button type="submit">{{ __('article.search.button') }}</button>
        </form>

        @if ($problem === 'short')
            <p class="sn-status" role="status">{{ __('article.search.too_short') }}</p>
        @elseif ($problem === 'long')
            <p class="sn-status" role="status">{{ __('article.search.too_long', ['max' => $maxLength]) }}</p>
        @elseif ($results === null)
            <p class="sn-status">{{ __('article.search.idle') }}</p>
        @else
            <p class="sn-status" role="status">{{ trans_choice('article.search.summary', $results->total(), ['query' => $term]) }}</p>

            @if ($results->isEmpty())
                <div class="sn-empty">
                    <h2>{{ __('article.search.empty_title') }}</h2>
                    <p>{{ __('article.search.empty_text') }}</p>
                    <a href="{{ route('home', ['locale' => $locale->value]) }}">{{ __('article.home') }}</a>
                </div>
            @else
                <div class="sn-results">
                    <div class="sn-rows">
                        @foreach ($results as $story)
                            <x-site.article-card :translation="$story" variant="row" :show-excerpt="true" />
                        @endforeach
                    </div>

                    {{ $results->links('vendor.pagination.site') }}
                </div>
            @endif
        @endif
    </div>
@endsection
