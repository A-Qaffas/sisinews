{{-- Latest news as a timeline: the Cairo clock time for today's stories, the date for older ones. --}}
<section aria-labelledby="latest-title">
    <h2 id="latest-title" class="section-title">{{ __('layout.latest') }}</h2>
    <ol class="timeline">
        @foreach ($stories as $story)
            @php
                $at = $story->published_at?->copy()->locale($locale->value)->timezone(config('site.timezone'));
                $today = $at?->isSameDay(now(config('site.timezone')));
                $category = $story->article->category;
                $categoryName = $category?->nameIn($locale);
            @endphp
            <li class="timeline-item">
                @if ($at)
                    <time class="timeline-time" datetime="{{ $at->toIso8601String() }}">{{ $today ? $at->format('H:i') : $at->translatedFormat('j M') }}</time>
                @endif
                <div class="story">
                    @if (filled($categoryName))
                        <p class="story-meta"><span class="story-cat">{{ $categoryName }}</span></p>
                    @endif
                    <h3 class="story-title text-lg"><a class="story-link" href="{{ $story->url() }}">{{ $story->title }}</a></h3>
                    @if (filled($story->excerpt))
                        <p class="mt-1 hidden text-ink-soft line-clamp-2 sm:block">{{ $story->excerpt }}</p>
                    @endif
                </div>
            </li>
        @endforeach
    </ol>
</section>
