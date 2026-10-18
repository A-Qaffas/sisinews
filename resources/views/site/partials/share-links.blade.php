@php
    // Shared links use the short /news/{id} address (it redirects to the full one): long Arabic slugs break in chat apps.
    $shareUrl = $translation->shortUrl();
    $encodedUrl = rawurlencode($shareUrl);
    $encodedTitle = rawurlencode($translation->title);
    $shareLinks = [
        'whatsapp' => 'https://wa.me/?text='.rawurlencode($translation->title.' '.$shareUrl),
        'x' => "https://x.com/intent/post?text={$encodedTitle}&url={$encodedUrl}",
        'facebook' => "https://www.facebook.com/sharer/sharer.php?u={$encodedUrl}",
        'linkedin' => "https://www.linkedin.com/sharing/share-offsite/?url={$encodedUrl}",
        'telegram' => "https://t.me/share/url?url={$encodedUrl}&text={$encodedTitle}",
        'email' => "mailto:?subject={$encodedTitle}&body={$encodedUrl}",
    ];
@endphp
<div class="sn-tools__group sn-tools__share">
    <span class="sn-tools__label">{{ __('article.share') }}</span>
    {{-- The phone's own share sheet. On phones the per-network links below often just open the app's feed
         (the app swallows the link), so this is the reliable way; revealed only where the browser supports it. --}}
    <button type="button" class="sn-tool sn-tool--native" data-article-tool="share" data-url="{{ $shareUrl }}"
            data-title="{{ $translation->title }}" data-copied="{{ __('article.link_copied') }}"
            data-needs-native-share hidden>{{ __('article.share_native') }}</button>
    @foreach ($shareLinks as $network => $href)
        @php($networkName = __("article.networks.{$network}"))
        <a class="sn-tool" href="{{ $href }}" aria-label="{{ __('article.share_on', ['network' => $networkName]) }}"
           @if ($network !== 'email') target="_blank" rel="noopener noreferrer" @endif>{{ $networkName }}</a>
    @endforeach
</div>
