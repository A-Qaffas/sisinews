// The home slider (resources/views/site/partials/home-slider.blade.php). The markup already works as a
// swipeable scroll-snap row; this adds timed rotation, arrows, a pause button and progress bars, and keeps
// the "top stories" list beside it in step. Rotation pauses while the pointer, a finger or focus is inside,
// while the tab is hidden, and never starts for readers who ask for reduced motion.
const INTERVAL = 6000;

function initSlider(slider) {
    const track = slider.querySelector('[data-slider-track]');
    const slides = [...slider.querySelectorAll('[data-slide]')];
    if (!track || slides.length < 2) return;

    const controls = slider.querySelector('[data-slider-controls]');
    const bars = [...slider.querySelectorAll('[data-slide-to]')];
    const pauseButton = slider.querySelector('[data-slider-pause]');
    const sideItems = [...document.querySelectorAll('[data-slide-item]')];
    const reduced = window.matchMedia('(prefers-reduced-motion: reduce)');

    let current = 0;
    let timer = null;
    let stopped = reduced.matches; // the reader's own choice (pause button or reduced motion)
    let held = false; // pointer, finger or focus inside

    controls.hidden = false;
    slider.querySelector('[data-slider-bars]').hidden = false;
    slider.classList.add('is-enhanced');

    const mark = (index) => {
        current = index;
        bars.forEach((bar, i) => {
            if (i === index) bar.setAttribute('aria-current', 'true');
            else bar.removeAttribute('aria-current');
        });
        sideItems.forEach((item) => item.classList.toggle('is-current', Number(item.dataset.slideItem) === index));
        // Restart the progress animation on the active bar.
        slider.classList.remove('is-ticking');
        void slider.offsetWidth;
        slider.classList.toggle('is-ticking', timer !== null);
    };

    const goTo = (index, smooth = true) => {
        const target = slides[(index + slides.length) % slides.length];
        const delta = target.getBoundingClientRect().left - track.getBoundingClientRect().left;
        track.scrollBy({ left: delta, behavior: smooth && !reduced.matches ? 'smooth' : 'auto' });
        mark(Number(target.dataset.slide));
    };

    const schedule = () => {
        clearInterval(timer);
        timer = null;
        if (!stopped && !held && !document.hidden) {
            timer = setInterval(() => goTo(current + 1), INTERVAL);
        }
        slider.classList.toggle('is-ticking', timer !== null);
        slider.classList.toggle('is-paused', stopped);
    };

    // The slide that fills most of the track is the current one, however it got there (swipe, arrow, timer).
    const observer = new IntersectionObserver(
        (entries) => {
            entries.forEach((entry) => {
                if (entry.isIntersecting && entry.intersectionRatio >= 0.6) {
                    const index = Number(entry.target.dataset.slide);
                    if (index !== current) {
                        mark(index);
                        schedule();
                    }
                }
            });
        },
        { root: track, threshold: [0.6] },
    );
    slides.forEach((slide) => observer.observe(slide));

    const rtl = () => document.documentElement.dir === 'rtl';
    slider.querySelector('[data-slider-prev]').addEventListener('click', () => {
        goTo(current - 1);
        schedule();
    });
    slider.querySelector('[data-slider-next]').addEventListener('click', () => {
        goTo(current + 1);
        schedule();
    });
    bars.forEach((bar) =>
        bar.addEventListener('click', () => {
            goTo(Number(bar.dataset.slideTo));
            schedule();
        }),
    );

    pauseButton.addEventListener('click', () => {
        stopped = !stopped;
        pauseButton.setAttribute('aria-label', stopped ? pauseButton.dataset.labelPlay : pauseButton.dataset.labelPause);
        schedule();
    });

    track.tabIndex = -1;
    track.addEventListener('keydown', (event) => {
        const step = { ArrowRight: rtl() ? -1 : 1, ArrowLeft: rtl() ? 1 : -1 }[event.key];
        if (step !== undefined) {
            event.preventDefault();
            goTo(current + step);
            schedule();
        }
    });

    const hold = (value) => () => {
        held = value;
        schedule();
    };
    slider.addEventListener('pointerenter', hold(true));
    slider.addEventListener('pointerleave', hold(false));
    slider.addEventListener('focusin', hold(true));
    slider.addEventListener('focusout', (event) => {
        if (!slider.contains(event.relatedTarget)) hold(false)();
    });
    track.addEventListener('touchstart', hold(true), { passive: true });
    track.addEventListener('touchend', () => setTimeout(hold(false), INTERVAL), { passive: true });
    document.addEventListener('visibilitychange', schedule);
    reduced.addEventListener?.('change', () => {
        stopped = reduced.matches;
        schedule();
    });

    if (stopped) {
        pauseButton.setAttribute('aria-label', pauseButton.dataset.labelPlay);
    }
    schedule();
    mark(0);
}

document.querySelectorAll('[data-slider]').forEach(initSlider);
