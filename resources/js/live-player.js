// Live broadcast players. The page ships a placeholder and a play button only, so opening it sends nothing
// to YouTube or Vimeo; the provider's iframe is created here, when a visitor asks for it. The address is the
// one the server built (LiveStream::embedUrl()) and left in data-embed: it is never assembled from text here.
// Without JavaScript the button stays hidden and the watch-on-provider link under the player is the way in.

function embedUrl(box) {
    try {
        const url = new URL(box.dataset.embed ?? '', window.location.href);

        return url.protocol === 'https:' ? url.href : null;
    } catch {
        return null;
    }
}

function play(box) {
    const src = embedUrl(box);
    const stage = box.querySelector('[data-live-stage]');
    if (!src || !stage) return;

    const frame = document.createElement('iframe');
    frame.className = 'live-player__frame';
    frame.src = src;
    frame.title = box.dataset.title ?? '';
    frame.allow = 'autoplay; encrypted-media; picture-in-picture; fullscreen';
    frame.allowFullscreen = true;
    frame.referrerPolicy = 'strict-origin-when-cross-origin';
    frame.loading = 'lazy';

    stage.replaceChildren(frame);
    box.classList.add('is-playing');
    // The button that had focus is gone; keep keyboard users in the player.
    frame.focus();
}

document.querySelectorAll('[data-live-player]').forEach((box) => {
    box.querySelector('[data-live-play]')?.addEventListener('click', () => play(box));
});
