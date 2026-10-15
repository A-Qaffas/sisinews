// Governorate chips on the Live page. On a phone they are one row that scrolls sideways (resources/css/live.css),
// so a reader who picked "Luxor" would otherwise land on a row that starts at "All Egypt" with Luxor out of sight.
// Only the row moves, by the physical distance between the two centres, which is right in Arabic (rtl) and English
// alike; the page itself never scrolls.

const current = document.querySelector('.live-chips [aria-current="page"]');
const row = current?.closest('.live-chips');

if (current && row && row.scrollWidth > row.clientWidth) {
    const rowBox = row.getBoundingClientRect();
    const chipBox = current.getBoundingClientRect();

    row.scrollBy({ left: chipBox.left + chipBox.width / 2 - (rowBox.left + rowBox.width / 2) });
}
