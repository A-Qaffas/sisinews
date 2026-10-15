{{-- "published" can only mean a future publish date here: a live story never takes the preview path. --}}
<p class="sn-preview" role="status">{{ __('article.preview.banner', ['status' => __('article.preview.status.'.$translation->status->value)]) }}</p>
