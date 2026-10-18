// Article page tools: native share, text size, copy link and print. The per-network share buttons are
// plain links and need no script. Every control that does need JavaScript ships `hidden` and is revealed
// here (the native share button only where the browser has navigator.share), so a reader never sees a
// button that does nothing.

const SCALE_KEY = 'sisi.textScale';
const SCALES = [0.875, 1, 1.125, 1.25, 1.5];
const DEFAULT_INDEX = 1;
const COPIED_MS = 2000;

function nearestIndex(value) {
    if (!Number.isFinite(value)) return DEFAULT_INDEX;

    return SCALES.reduce(
        (best, scale, index) => (Math.abs(scale - value) < Math.abs(SCALES[best] - value) ? index : best),
        DEFAULT_INDEX,
    );
}

function storedIndex() {
    try {
        const stored = window.localStorage.getItem(SCALE_KEY);

        return stored === null ? DEFAULT_INDEX : nearestIndex(Number.parseFloat(stored));
    } catch {
        // Storage can be blocked (private mode, site data off); the default size still works.
        return DEFAULT_INDEX;
    }
}

let scaleIndex = storedIndex();

function applyScale(index, persist) {
    scaleIndex = Math.min(SCALES.length - 1, Math.max(0, index));
    document.documentElement.style.setProperty('--text-scale', String(SCALES[scaleIndex]));

    document.querySelectorAll('[data-article-tool="text-smaller"]').forEach((button) => {
        button.disabled = scaleIndex === 0;
    });
    document.querySelectorAll('[data-article-tool="text-larger"]').forEach((button) => {
        button.disabled = scaleIndex === SCALES.length - 1;
    });

    if (!persist) return;

    try {
        window.localStorage.setItem(SCALE_KEY, String(SCALES[scaleIndex]));
    } catch {
        // Not remembered, but the size still changed for this page view.
    }
}

async function copyText(text) {
    if (navigator.clipboard?.writeText) {
        await navigator.clipboard.writeText(text);

        return;
    }

    // Older browsers and non-secure origins have no async clipboard.
    const field = document.createElement('textarea');
    field.value = text;
    field.setAttribute('readonly', '');
    field.style.position = 'fixed';
    field.style.insetBlockStart = '-9999px';
    document.body.append(field);
    field.select();
    const copied = document.execCommand('copy');
    field.remove();

    if (!copied) throw new Error('Copy command was refused');
}

const copyTimers = new WeakMap();

async function copyLink(button) {
    // Only the text changes, so the icon beside it stays put.
    const text = button.querySelector('[data-tool-text]') ?? button;
    const original = button.dataset.label ?? text.textContent;
    button.dataset.label = original;

    try {
        await copyText(button.dataset.url ?? window.location.href);
        text.textContent = button.dataset.copied ?? original;
    } catch {
        // The URL stays visible in the address bar; failing quietly beats a broken-looking button.
        return;
    }

    window.clearTimeout(copyTimers.get(button));
    copyTimers.set(
        button,
        window.setTimeout(() => {
            text.textContent = original;
        }, COPIED_MS),
    );
}

async function nativeShare(button) {
    try {
        await navigator.share({ title: button.dataset.title, text: button.dataset.title, url: button.dataset.url });
    } catch (error) {
        // Closing the share sheet is not an error. Anything else (blocked, unsupported data) falls back to copying.
        if (error?.name !== 'AbortError') copyLink(button);
    }
}

document.addEventListener('click', (event) => {
    const tool = event.target instanceof Element ? event.target.closest('[data-article-tool]') : null;
    if (!tool) return;

    switch (tool.dataset.articleTool) {
        case 'text-smaller':
            applyScale(scaleIndex - 1, true);
            break;
        case 'text-larger':
            applyScale(scaleIndex + 1, true);
            break;
        case 'share':
            nativeShare(tool);
            break;
        case 'copy':
            copyLink(tool);
            break;
        case 'print':
            window.print();
            break;
    }
});

function init() {
    if (typeof navigator.share === 'function') {
        document.querySelectorAll('[data-needs-native-share]').forEach((element) => {
            element.hidden = false;
        });
    }

    const revealable = document.querySelectorAll('[data-js-only]');
    if (revealable.length === 0) return;

    revealable.forEach((element) => {
        element.hidden = false;
    });
    applyScale(scaleIndex, false);
}

if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', init);
} else {
    init();
}
