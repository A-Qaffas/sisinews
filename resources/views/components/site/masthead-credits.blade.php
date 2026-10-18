{{-- Names are typed by an admin: plain text, always escaped, and isolated in <bdi> so a name in the other script cannot reorder the label and its colon. --}}
<dl @class([
    'masthead-credits',
    'masthead-credits--'.$variant,
    'hidden md:grid' => $variant === 'header',
    'container-site md:hidden' => $variant === 'strip',
])>
    @foreach ($credits as $credit)
        <div><dt>{{ $credit['role'] }}:</dt> <dd><bdi lang="{{ $locale->value }}">{{ $credit['name'] }}</bdi></dd></div>
    @endforeach
</dl>
