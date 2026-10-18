{{-- Names are typed by an admin: plain text, always escaped, and isolated in <bdi> so a name in the other script cannot reorder the label and its colon. --}}
<dl @class([
    'masthead-credits',
    'masthead-credits--'.$variant,
    'hidden md:grid' => $variant === 'header',
    'container-site md:hidden' => $variant === 'strip',
])>
    @foreach ($credits as $credit)
        @php($fitted = $variant === 'footer' ? null : ($credit['fitted'] ?? null))
        <div><dt data-role="{{ $credit['key'] ?? '' }}">@if ($fitted)<span aria-hidden="true">{{ $fitted }}:</span><span class="sr-only">{{ $credit['role'] }}:</span>@else{{ $credit['role'] }}:@endif</dt> <dd><bdi lang="{{ $locale->value }}">{{ $credit['name'] }}</bdi></dd></div>
    @endforeach
</dl>
