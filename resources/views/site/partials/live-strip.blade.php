{{-- Home page banner while at least one broadcast is live; $items is LiveBroadcasts::liveNow(), already cached. --}}
@if ($items !== [])
    @php($liveUrl = route('live', ['locale' => $locale->value]))
    <section class="live-strip" aria-label="{{ __('live.strip.label') }}">
        <p class="live-strip__label"><span class="live-dot live-dot--pulse" aria-hidden="true"></span>{{ __('live.live_badge') }}</p>
        <ul class="live-strip__list">
            @foreach (array_slice($items, 0, 3) as $item)
                <li>
                    <a href="{{ $liveUrl }}#stream-{{ $item['id'] }}">
                        <span class="live-strip__where">{{ $item['governorate'] ?? __('live.egypt_wide') }}</span>
                        <span class="live-strip__title"@if ($item['title_lang']) lang="{{ $item['title_lang'] }}" dir="{{ $item['title_dir'] }}"@endif>{{ $item['title'] }}</span>
                    </a>
                </li>
            @endforeach
        </ul>
        <a class="live-strip__all" href="{{ $liveUrl }}">{{ __('live.strip.all') }}</a>
    </section>
@endif
