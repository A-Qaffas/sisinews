{{--
  The home slider. Without JavaScript it is a row of slides the reader swipes or scrolls sideways (CSS scroll
  snap); home-slider.js adds the timed rotation, the arrows, the pause button and the progress bars, all of which
  ship hidden. Rotation stops while the pointer or focus is inside, and never starts under reduced motion.
--}}
@php($total = $slides->count())
<div class="slider" data-slider aria-roledescription="carousel" aria-label="{{ __('layout.slider.label') }}">
    <div class="slider-track" data-slider-track>
        @foreach ($slides as $story)
            <div class="slider-slide" role="group" aria-roledescription="slide" aria-label="{{ __('layout.slider.slide', ['n' => $loop->iteration, 'total' => $total]) }}" data-slide="{{ $loop->index }}">
                <x-site.article-card :translation="$story" variant="slide" :show-excerpt="true" :priority="$loop->first" />
            </div>
        @endforeach
    </div>

    @if ($total > 1)
        <div class="slider-controls" data-slider-controls hidden>
            <button type="button" class="slider-btn" data-slider-prev aria-label="{{ __('layout.slider.previous') }}">
                <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false"><path d="m15 18-6-6 6-6"/></svg>
            </button>
            <button type="button" class="slider-btn" data-slider-pause aria-label="{{ __('layout.slider.pause') }}" data-label-pause="{{ __('layout.slider.pause') }}" data-label-play="{{ __('layout.slider.play') }}">
                <svg class="icon-pause" width="16" height="16" viewBox="0 0 24 24" fill="currentColor" aria-hidden="true" focusable="false"><path d="M7 5h3v14H7zM14 5h3v14h-3z"/></svg>
                <svg class="icon-play" width="16" height="16" viewBox="0 0 24 24" fill="currentColor" aria-hidden="true" focusable="false"><path d="M8 5v14l11-7z"/></svg>
            </button>
            <button type="button" class="slider-btn" data-slider-next aria-label="{{ __('layout.slider.next') }}">
                <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false"><path d="m9 18 6-6-6-6"/></svg>
            </button>
        </div>

        <div class="slider-bars" data-slider-bars hidden>
            @foreach ($slides as $story)
                <button type="button" class="slider-bar" data-slide-to="{{ $loop->index }}" aria-label="{{ __('layout.slider.slide', ['n' => $loop->iteration, 'total' => $total]) }}" @if ($loop->first) aria-current="true" @endif><span></span></button>
            @endforeach
        </div>
    @endif
</div>
