{{-- One scheduled broadcast: no player yet, just when (in Cairo time) and what. --}}
<li class="live-row">
    <div class="live-row__when">
        @if ($item['moment'])
            <time datetime="{{ $item['moment']['iso'] }}">
                <span class="live-row__date">{{ $item['moment']['date'] }}</span>
                <span class="live-row__time">{{ $item['moment']['time'] }}</span>
            </time>
            <span class="live-row__zone">{{ __('live.cairo_time') }}</span>
        @else
            <span class="live-row__date">{{ __('live.to_be_announced') }}</span>
        @endif
    </div>
    <div class="live-row__body">
        <p class="live-tag">{{ $item['governorate'] ?? __('live.egypt_wide') }}</p>
        <h3 class="live-row__title"@if ($item['title_lang']) lang="{{ $item['title_lang'] }}" dir="{{ $item['title_dir'] }}"@endif>{{ $item['title'] }}</h3>
        @if ($item['description'])
            <p class="live-desc"@if ($item['description_lang']) lang="{{ $item['description_lang'] }}" dir="{{ $item['description_dir'] }}"@endif>{{ $item['description'] }}</p>
        @endif
    </div>
</li>
