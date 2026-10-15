@php($tagNames = $article->tags->map(fn ($tag) => $tag->nameIn($locale))->filter()->unique()->values())

@if ($tagNames->isNotEmpty())
    {{-- Tag pages are out of scope for v1, so tags are labels, not links. --}}
    <ul class="sn-tags" aria-label="{{ __('article.tags') }}">
        @foreach ($tagNames as $name)
            <li>{{ $name }}</li>
        @endforeach
    </ul>
@endif

@if ($alternate)
    <aside class="sn-alt">
        <p>{{ __('article.also_available.'.$alternate->locale->value) }}</p>
        <a href="{{ $alternate->url() }}" hreflang="{{ $alternate->locale->value }}" lang="{{ $alternate->locale->value }}"
           dir="{{ $alternate->locale->dir() }}">{{ $alternate->title }}</a>
    </aside>
@endif
