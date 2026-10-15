<style>
    /*
     * Mixed Arabic/English text. Each language tab puts dir/lang on the editor, and every block
     * must simply inherit it: an Arabic sentence that opens with a Latin brand ("Samsung تتوقع ...")
     * has to stay right-to-left. unicode-bidi: plaintext would resolve the block from its first
     * strong letter and flip exactly that sentence to left-to-right, scrambling the word order.
     * text-align: start follows the inherited direction.
     */
    .fi-fo-rich-editor .ProseMirror :is(p, h1, h2, h3, h4, h5, h6, blockquote) {
        unicode-bidi: normal;
        text-align: start;
    }

    /* Slanted Arabic is not a thing in print; the editor's default italic quote would look broken. */
    .fi-fo-rich-editor .ProseMirror:lang(ar) blockquote {
        font-style: normal;
    }

    /*
     * Headline cells of the story and review tables. On a phone they take whatever width is left
     * after the status and the row button; from md up they keep a readable column width.
     */
    .sisi-headline {
        min-width: 7rem;
        max-width: 100%;
    }

    @media (min-width: 48rem) {
        .sisi-headline {
            min-width: 11rem;
            max-width: 15rem;
        }

        .sisi-headline--wide {
            min-width: 12rem;
            max-width: 18rem;
        }
    }

    /*
     * Row buttons (App\Filament\Support\PhoneFriendly): below md only the icon stays, in a 40 px tap target.
     * The label is clipped, not removed, so a screen reader still announces "Approve & publish".
     */
    @media (max-width: 47.99rem) {
        .fi-link.sisi-icon-below-md {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            min-width: 2.5rem;
            min-height: 2.5rem;
        }

        .fi-link.sisi-icon-below-md .fi-link-label {
            position: absolute;
            width: 1px;
            height: 1px;
            margin: -1px;
            overflow: hidden;
            clip-path: inset(50%);
            white-space: nowrap;
        }
    }

    /*
     * Story list status badges: "AR · Scheduled for 15 Oct, 09:30" is wider than a phone can spare next
     * to the headline and the row buttons, so below md that one badge (the custom "scheduled" colour)
     * may break onto several lines. Every other badge stays on one line.
     */
    @media (max-width: 47.99rem) {
        .fi-ta-cell-versions .fi-badge.fi-color-scheduled {
            max-width: 6.5rem;
            overflow: visible;
            text-overflow: clip;
            white-space: normal;
        }
    }

    /*
     * Team list on a phone: no bulk-select column (the row menu does one account at a time), the e-mail
     * under a name is cut short, and the Edit link gives way to tapping the row.
     */
    @media (max-width: 47.99rem) {
        .fi-ta-cell.fi-ta-selection-cell,
        .fi-ta-header-cell.fi-ta-selection-cell {
            display: none;
        }

        .sisi-person .fi-ta-text-description {
            max-width: 7.5rem;
            overflow: hidden;
            text-overflow: ellipsis;
        }

        .sisi-hide-below-md {
            display: none;
        }
    }

    /*
     * A disabled select (a story the person may read but not change) keeps Filament's clear and remove "x" buttons,
     * greyed but still there, which makes a locked field look editable. They do nothing while disabled, so hide them.
     */
    .fi-select-input-value-remove-btn.fi-disabled,
    .fi-badge-delete-btn.fi-disabled {
        display: none;
    }

    /* Per-language workflow panel on the article form. */
    .sisi-status {
        display: flex;
        flex-direction: column;
        gap: 0.75rem;
        padding: 0.75rem 1rem;
        border: 1px solid var(--gray-200);
        border-radius: 0.5rem;
        background: #fff;
        font-size: 0.875rem;
        color: var(--gray-700);
    }

    .sisi-status-head {
        display: flex;
        flex-wrap: wrap;
        align-items: center;
        gap: 0.5rem 0.75rem;
    }

    .sisi-status-hint {
        margin: 0;
        color: var(--gray-600);
    }

    .sisi-status-history summary {
        cursor: pointer;
        font-weight: 600;
        color: var(--gray-950);
    }

    .sisi-status-history table {
        width: 100%;
        margin-top: 0.5rem;
        border-collapse: collapse;
        font-size: 0.8125rem;
    }

    .sisi-status-history th,
    .sisi-status-history td {
        padding: 0.375rem 0.5rem;
        border-top: 1px solid var(--gray-200);
        text-align: start;
        vertical-align: top;
    }

    .sisi-status-history th {
        font-weight: 600;
        color: var(--gray-600);
    }

    .sisi-status-note {
        white-space: pre-line;
        unicode-bidi: plaintext;
    }

    /* Live broadcasts list: "Live now" is the one status that must not be missed, so it is a solid crimson badge. */
    .sisi-live-status .fi-badge.fi-color-danger {
        background-color: var(--color-600);
        color: #fff;
        font-weight: 700;
    }

    .sisi-live-status .fi-badge.fi-color-danger .fi-icon {
        color: inherit;
    }
</style>
