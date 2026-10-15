<div class="ticker" role="region" aria-label="{{ __('layout.breaking_label') }}" style="--ticker-duration: {{ $duration() }}s">
    <span class="ticker-label">{{ __('layout.breaking') }}</span>
    <div class="ticker-viewport">
        <div class="ticker-track">
            <ul class="ticker-group">
                @foreach ($items as $item)
                    <li><a href="{{ $item->url() }}">{{ $item->title }}</a></li>
                @endforeach
            </ul>
            {{-- Second copy makes the loop seamless; hidden from assistive tech and the tab order. --}}
            <ul class="ticker-group" aria-hidden="true">
                @foreach ($items as $item)
                    <li><a href="{{ $item->url() }}" tabindex="-1">{{ $item->title }}</a></li>
                @endforeach
            </ul>
        </div>
    </div>
</div>
