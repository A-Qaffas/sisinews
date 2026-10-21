// The header's "More" sections menu is a <details>, so it opens without JavaScript; this only closes it again on a
// click elsewhere or on Escape, as a dropdown is expected to.
document.querySelectorAll('[data-nav-more]').forEach((details) => {
    document.addEventListener('click', (event) => {
        if (details.open && !details.contains(event.target)) details.open = false;
    });
    document.addEventListener('keydown', (event) => {
        if (event.key === 'Escape' && details.open) {
            details.open = false;
            details.querySelector('summary')?.focus();
        }
    });
});
