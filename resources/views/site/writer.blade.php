@extends('layouts.site')

@section('title', $stories->currentPage() > 1 ? __('article.writer.title', ['name' => $name]).' - '.__('layout.pagination.page', ['page' => $stories->currentPage()]) : __('article.writer.title', ['name' => $name]))

@push('head')
    <meta name="description" content="{{ __('article.writer.description', ['name' => $name, 'site' => config('site.name.'.$locale->value, config('app.name'))]) }}">
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
        <p class="text-sm text-muted">{{ __('article.writer.label') }}</p>
        <h1 class="section-title sn-page-title">{{ $name }}</h1>

        <div class="sn-grid sn-grid--3">
            @foreach ($stories as $story)
                <x-site.article-card :translation="$story" variant="card" :show-excerpt="true" />
            @endforeach
        </div>

        {{ $stories->links('vendor.pagination.site') }}
    </div>
@endsection
