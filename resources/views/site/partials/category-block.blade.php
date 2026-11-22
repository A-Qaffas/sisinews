{{--
  One category on the home page: a row of up to four picture cards under the section heading.
--}}
<section aria-labelledby="{{ $id }}">
    <div class="section-head">
        <h2 id="{{ $id }}" class="section-title"><a href="{{ $block['url'] }}">{{ $block['name'] }}</a></h2>
        <a class="section-more" href="{{ $block['url'] }}">{{ __('layout.section_all') }}<span class="sr-only">: {{ $block['name'] }}</span></a>
    </div>
    <div class="section-cards">
        @foreach ($block['stories'] as $story)
            <x-site.article-card :translation="$story" variant="card" />
        @endforeach
    </div>
</section>
