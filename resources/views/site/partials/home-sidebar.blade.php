{{--
  Most Read / Latest. Without JavaScript both panels render stacked, each under its own visible
  heading; app.js reveals the tab list, hides the inactive panel and demotes the headings to
  screen-reader-only text. Rendered only when there is a Most Read list.
--}}
<div class="tabs-stack lg:sticky lg:top-6" data-tabs>
    <div class="tab-list" role="tablist" aria-label="{{ __('layout.tabs_label') }}" hidden>
        <button type="button" class="tab" role="tab" id="tab-most-read" aria-controls="panel-most-read" aria-selected="true">{{ __('layout.most_read') }}</button>
        <button type="button" class="tab" role="tab" id="tab-latest" aria-controls="panel-latest" aria-selected="false">{{ __('layout.latest') }}</button>
    </div>

    <section role="tabpanel" id="panel-most-read" aria-labelledby="tab-most-read">
        <h2 class="section-title" data-tab-heading>{{ __('layout.most_read') }}</h2>
        <ol class="ranked">
            @foreach ($mostRead as $story)
                <li><x-site.article-card :translation="$story" variant="compact" /></li>
            @endforeach
        </ol>
    </section>

    <section role="tabpanel" id="panel-latest" aria-labelledby="tab-latest">
        <h2 class="section-title" data-tab-heading>{{ __('layout.latest') }}</h2>
        <ul class="divide-y divide-line">
            @foreach ($latest as $story)
                <li class="py-3 first:pt-0">
                    <x-site.article-card :translation="$story" variant="compact" />
                </li>
            @endforeach
        </ul>
    </section>
</div>
