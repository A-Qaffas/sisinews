@if ($hero)
    <figure class="sn-hero">
        {{-- alt="" on purpose: the headline right above and the caption right below already say what this is. --}}
        <img src="{{ $hero['src'] }}" srcset="{{ $hero['srcset'] }}" sizes="(min-width: 48rem) 48rem, 100vw"
             width="1280" height="720" alt="" fetchpriority="high" decoding="async">
        @if (filled($translation->hero_caption))
            <figcaption>{{ $translation->hero_caption }}</figcaption>
        @endif
    </figure>
@else
    {{-- No hero image: the current brand logo on a cream panel, the same placeholder the story cards use. --}}
    <div class="media media--placeholder mt-5 aspect-video">
        <img src="{{ asset('brand/logo-380.webp') }}" width="380" height="106" alt="" decoding="async">
    </div>
@endif
