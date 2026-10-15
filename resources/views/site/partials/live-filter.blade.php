{{-- Governorate chips: plain links, so they work without JavaScript and by keyboard. A dot marks where something is live now. --}}
<nav class="live-filter" aria-label="{{ __('live.filter_label') }}">
    <ul class="live-chips">
        <li>
            <a class="live-chip" href="{{ route('live', ['locale' => $locale->value]) }}"@if ($focus === null) aria-current="page"@endif>
                {{ __('live.all_egypt') }}
                @if ($anyLive)<span class="live-dot" aria-hidden="true"></span><span class="sr-only">({{ __('live.now_streaming') }})</span>@endif
            </a>
        </li>
        @foreach ($governorates as $governorate)
            <li>
                <a class="live-chip" href="{{ route('live', ['locale' => $locale->value, 'g' => $governorate->slug]) }}"@if ($focus?->id === $governorate->id) aria-current="page"@endif>
                    {{ $governorate->nameIn($locale) }}
                    @if (in_array($governorate->id, $liveGovernorateIds, true))<span class="live-dot" aria-hidden="true"></span><span class="sr-only">({{ __('live.now_streaming') }})</span>@endif
                </a>
            </li>
        @endforeach
    </ul>
</nav>
