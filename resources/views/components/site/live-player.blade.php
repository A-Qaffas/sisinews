{{--
  Click-to-load player for one live broadcast. The page ships only this placeholder: nothing is requested from
  YouTube or Vimeo until a visitor presses play, when resources/js/live-player.js swaps the stage for the
  provider's iframe. The embed address is LiveStream::embedUrl() (a fixed template over a validated id); the
  link below it is the way to watch without JavaScript.
--}}
@props(['stream', 'title'])
@php
    $provider = $stream->provider;
    $watchUrl = match ($provider) {
        \App\Enums\LiveProvider::YouTube => 'https://www.youtube.com/watch?v='.rawurlencode($stream->video_ref),
        \App\Enums\LiveProvider::Vimeo => 'https://vimeo.com/'.rawurlencode($stream->video_ref),
    };
@endphp
<div {{ $attributes->class('live-player') }} data-live-player data-embed="{{ $stream->embedUrl() }}" data-title="{{ $title }}">
    <div class="live-player__stage" data-live-stage>
        <img class="live-player__mark" src="{{ asset('brand/icon-160.webp') }}" width="160" height="160" alt="" loading="lazy" decoding="async">
        <span class="live-badge"><span class="live-dot" aria-hidden="true"></span>{{ __('live.live_badge') }}</span>
        {{-- Only useful with JavaScript, so CSS shows it under html.js; without JS the link below is the way in. --}}
        <button type="button" class="live-player__play" data-live-play aria-label="{{ __('live.play', ['title' => $title]) }}">
            <svg width="28" height="28" viewBox="0 0 24 24" fill="currentColor" aria-hidden="true" focusable="false"><path d="M8 5.14v13.72a1 1 0 0 0 1.52.85l11.1-6.86a1 1 0 0 0 0-1.7L9.52 4.29A1 1 0 0 0 8 5.14Z"/></svg>
        </button>
    </div>
    <a class="live-player__watch" href="{{ $watchUrl }}" target="_blank" rel="noopener noreferrer">{{ __('live.watch_on', ['provider' => $provider->label()]) }}</a>
</div>
