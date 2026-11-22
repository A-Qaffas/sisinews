{{--
  <x-site.article-card :translation="$t" variant="lead|slide|card|row|compact" :show-excerpt="true" />
  slide: the home slider's picture with the headline laid over it (styled by .story--slide).
  $translation is a visible App\Models\ArticleTranslation with `article.category.translations` and
  `article.author` already eager-loaded by the caller. Logic lives in App\View\Components\Site\ArticleCard.
--}}
@php
    $cat = $category();
    $byline = $author();
    $isRow = $variant === 'row';
    $isSlide = $variant === 'slide';
    $hasMedia = $variant !== 'compact';
    $titleClass = match ($variant) {
        'lead' => 'text-2xl sm:text-3xl lg:text-4xl',
        'slide' => 'text-xl sm:text-3xl lg:text-[2.1rem]',
        'card' => 'text-xl',
        'row' => 'text-lg',
        default => 'text-base',
    };
@endphp
<article {{ $attributes->class(['story', 'story--slide' => $isSlide, 'flex flex-col gap-4' => $variant === 'lead' || $variant === 'card', 'flex gap-4' => $isRow]) }}>
    @if ($hasMedia)
        <div @class(['media', 'aspect-video' => ! $isRow && ! $isSlide, 'aspect-[4/3] w-28 shrink-0 self-start sm:w-40' => $isRow, 'media--placeholder' => ! $hasImage(), 'is-mark' => $isRow && ! $hasImage()])>
            @if ($hasImage())
                <img src="{{ $src() }}" @if ($srcset()) srcset="{{ $srcset() }}" sizes="{{ $sizes() }}" @endif width="{{ $width() }}" height="{{ $height() }}" alt="" decoding="async" @if ($priority) loading="eager" fetchpriority="high" @else loading="lazy" @endif>
            @elseif ($isRow)
                <img src="{{ asset('brand/icon-160.webp') }}" width="160" height="160" alt="" loading="lazy" decoding="async">
            @else
                <img src="{{ asset('brand/logo-380.webp') }}" width="380" height="106" alt="" loading="lazy" decoding="async">
            @endif
        </div>
    @endif

    <div @class(['min-w-0 flex-1' => ! $isSlide, 'story-caption' => $isSlide])>
        <div class="story-meta">
            @if ($cat)
                @if ($cat['url'])
                    <a class="story-cat" href="{{ $cat['url'] }}">{{ $cat['name'] }}</a>
                @else
                    <span class="story-cat">{{ $cat['name'] }}</span>
                @endif
            @endif
            @if ($isoDate())
                <time datetime="{{ $isoDate() }}">{{ $dateLabel() }}</time>
            @endif
        </div>

        <h3 class="story-title mt-1 {{ $titleClass }}">
            <a class="story-link" href="{{ $translation->url() }}">{{ $translation->title }}</a>
        </h3>

        @if ($showExcerpt && filled($translation->excerpt))
            <p @class(['mt-2 text-ink-soft', 'text-lg line-clamp-3' => $variant === 'lead', 'line-clamp-3' => $variant === 'card', 'hidden line-clamp-2 sm:block' => $isRow || $isSlide, 'line-clamp-2' => $variant === 'compact'])>{{ $translation->excerpt }}</p>
        @endif

        @if ($byline && in_array($variant, ['lead', 'card'], true))
            <p class="story-byline mt-2 text-sm text-muted">{{ __('layout.by', ['name' => $byline]) }}</p>
        @endif
    </div>
</article>
