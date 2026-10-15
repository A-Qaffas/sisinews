{{--
  One category on the home page: its newest story as a card, the next ones as text rows.
  $wide: the last block of an odd count spans both columns so the grid has no hole; its card lies beside the text.
--}}
<section aria-labelledby="{{ $id }}" @class(['md:col-span-2' => $wide ?? false])>
    <h2 id="{{ $id }}" class="section-title"><a href="{{ $block['url'] }}">{{ $block['name'] }}</a></h2>
    <x-site.article-card :translation="$block['stories']->first()" variant="card" :class="($wide ?? false) ? 'md:flex-row md:items-center md:gap-8 md:[&>.media]:w-1/2 md:[&>.media]:shrink-0' : ''" />
    @if ($block['stories']->count() > 1)
        <ul class="mt-2 divide-y divide-line">
            @foreach ($block['stories']->slice(1) as $story)
                <li class="py-3">
                    <x-site.article-card :translation="$story" variant="compact" />
                </li>
            @endforeach
        </ul>
    @endif
</section>
