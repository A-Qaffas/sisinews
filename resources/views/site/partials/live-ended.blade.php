{{-- One broadcast that has finished: title and time only, never a player. --}}
<li class="live-ended__item">
    <p class="live-tag">{{ $item['governorate'] ?? __('live.egypt_wide') }}</p>
    <h3 class="live-ended__title"@if ($item['title_lang']) lang="{{ $item['title_lang'] }}" dir="{{ $item['title_dir'] }}"@endif>{{ $item['title'] }}</h3>
    @if ($item['moment'])
        <p class="live-meta">@include('site.partials.live-moment', ['moment' => $item['moment'], 'key' => 'ended'])</p>
    @endif
</li>
