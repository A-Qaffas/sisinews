{{-- One broadcast that is live now: the click-to-load player, then where, what and since when. --}}
<article id="stream-{{ $item['id'] }}" class="live-card">
    <x-site.live-player :stream="$item['stream']" :title="$item['title']" />
    <div class="live-card__body">
        <p class="live-tag">{{ $item['governorate'] ?? __('live.egypt_wide') }}</p>
        <h3 class="live-card__title"@if ($item['title_lang']) lang="{{ $item['title_lang'] }}" dir="{{ $item['title_dir'] }}"@endif>{{ $item['title'] }}</h3>
        @if ($item['moment'])
            <p class="live-meta">@include('site.partials.live-moment', ['moment' => $item['moment'], 'key' => 'started'])</p>
        @endif
        @if ($item['description'])
            <p class="live-desc"@if ($item['description_lang']) lang="{{ $item['description_lang'] }}" dir="{{ $item['description_dir'] }}"@endif>{{ $item['description'] }}</p>
        @endif
    </div>
</article>
