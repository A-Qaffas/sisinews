<div class="sn-tools" role="group" aria-label="{{ __('article.tools') }}">
    {{-- A story the public cannot open has nothing to share. --}}
    @unless ($preview)
        @include('site.partials.share-links')
    @endunless

    <div class="sn-tools__utils">
        <div class="sn-tools__group" role="group" aria-label="{{ __('article.text_size') }}" data-js-only hidden>
            <span class="sn-tools__label" aria-hidden="true">{{ __('article.text_size') }}</span>
            <button type="button" class="sn-tool" data-article-tool="text-smaller" aria-label="{{ __('article.text_smaller') }}">
                <span aria-hidden="true">{{ __('article.text_glyph') }}&minus;</span>
            </button>
            <button type="button" class="sn-tool" data-article-tool="text-larger" aria-label="{{ __('article.text_larger') }}">
                <span aria-hidden="true">{{ __('article.text_glyph') }}+</span>
            </button>
        </div>

        <div class="sn-tools__group" data-js-only hidden>
            @unless ($preview)
                <button type="button" class="sn-tool" data-article-tool="copy" data-url="{{ $translation->shortUrl() }}"
                        data-copied="{{ __('article.link_copied') }}" aria-live="polite" data-js-only hidden>{{ __('article.copy_link') }}</button>
            @endunless
            <button type="button" class="sn-tool" data-article-tool="print" data-js-only hidden>{{ __('article.print') }}</button>
        </div>
    </div>
</div>
