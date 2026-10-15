// Scripts for a single feature (e.g. the article page tools) live beside this file as
// resources/js/<feature>.js and are bundled here automatically, so nobody has to edit this entry.
import.meta.glob(['./*.js', '!./app.js'], { eager: true });

const root = document.documentElement;

function initMenu() {
    const button = document.querySelector('[data-nav-toggle]');
    const panel = button && document.getElementById(button.getAttribute('aria-controls'));
    if (!panel) return;

    const setOpen = (open) => {
        panel.classList.toggle('is-open', open);
        button.setAttribute('aria-expanded', String(open));
    };

    button.addEventListener('click', () => setOpen(button.getAttribute('aria-expanded') !== 'true'));
    document.addEventListener('keydown', (event) => {
        if (event.key === 'Escape' && button.getAttribute('aria-expanded') === 'true') {
            setOpen(false);
            button.focus();
        }
    });
}

// Without JS both panels render stacked, each under its own heading. With JS the headings
// become screen-reader-only and the tab list switches between the panels.
function initTabs(container) {
    const list = container.querySelector('[role="tablist"]');
    const tabs = [...container.querySelectorAll('[role="tab"]')];
    const panels = tabs.map((tab) => document.getElementById(tab.getAttribute('aria-controls')));
    if (!list || tabs.length < 2 || panels.includes(null)) return;

    list.hidden = false;
    container.querySelectorAll('[data-tab-heading]').forEach((heading) => heading.classList.add('sr-only'));

    const select = (index, focus = false) => {
        tabs.forEach((tab, i) => {
            const active = i === index;
            tab.setAttribute('aria-selected', String(active));
            tab.tabIndex = active ? 0 : -1;
            panels[i].hidden = !active;
        });
        if (focus) tabs[index].focus();
    };

    tabs.forEach((tab, i) => {
        tab.addEventListener('click', () => select(i));
        tab.addEventListener('keydown', (event) => {
            const rtl = root.dir === 'rtl';
            const step = { ArrowRight: rtl ? -1 : 1, ArrowLeft: rtl ? 1 : -1 }[event.key];
            let target = null;

            if (step !== undefined) target = (i + step + tabs.length) % tabs.length;
            else if (event.key === 'Home') target = 0;
            else if (event.key === 'End') target = tabs.length - 1;

            if (target !== null) {
                event.preventDefault();
                select(target, true);
            }
        });
    });

    select(0);
}

initMenu();
document.querySelectorAll('[data-tabs]').forEach(initTabs);
