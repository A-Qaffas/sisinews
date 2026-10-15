@if ($related->isNotEmpty())
    <section class="sn-related" aria-labelledby="related-heading">
        <h2 class="section-title" id="related-heading">{{ __('article.related') }}</h2>
        <div class="sn-grid sn-grid--4">
            @foreach ($related as $story)
                <x-site.article-card :translation="$story" variant="card" />
            @endforeach
        </div>
    </section>
@endif
