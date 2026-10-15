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
        <span>{{ __('article.by') }} <strong>{{ $article->author->displayName($locale) }}</strong></span>
        @if ($publishedAt)
            <time datetime="{{ $publishedAt->toAtomString() }}">
                <span>{{ $publishedAt->translatedFormat('l j F Y') }}</span><span>{{ $publishedAt->translatedFormat('g:i A') }}</span>
            </time>
        @endif
    </p>
</header>
