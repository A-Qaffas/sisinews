<nav class="sn-crumbs" aria-label="{{ __('article.breadcrumb') }}">
    <ol>
        <li><a href="{{ route('home', ['locale' => $locale->value]) }}">{{ __('article.home') }}</a></li>
        <li>
            @if ($category)
                <a href="{{ route('category', ['locale' => $locale->value, 'slug' => $category->slug]) }}">{{ $categoryName }}</a>
            @else
                <span>{{ $categoryName }}</span>
            @endif
        </li>
    </ol>
</nav>

<header>
    <h1 class="sn-title">{{ $translation->title }}</h1>
    <p class="sn-meta">
        @php($writerUrl = $article->author->writerUrl($locale))
        <span>{{ __('article.by') }} @if ($writerUrl)<a class="sn-byline-link" href="{{ $writerUrl }}" rel="author" title="{{ __('article.writer.link', ['name' => $article->author->displayName($locale)]) }}"><strong>{{ $article->author->displayName($locale) }}</strong></a>@else<strong>{{ $article->author->displayName($locale) }}</strong>@endif</span>
        @if ($publishedAt)
            <time datetime="{{ $publishedAt->toAtomString() }}">
                <span>{{ $publishedAt->translatedFormat('l j F Y') }}</span><span>{{ $publishedAt->translatedFormat('g:i A') }}</span>
            </time>
        @endif
    </p>
</header>
