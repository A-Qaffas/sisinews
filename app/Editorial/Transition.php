<?php

namespace App\Editorial;

use App\Enums\TranslationStatus as S;

/**
 * The complete, table-driven list of legal status changes. If a move is not in
 * this enum it cannot happen, whatever a form or API request tries to submit.
 *
 *   draft ──submit──▶ in_review ──approve──▶ published ──archive──▶ archived
 *     ▲  ▲               │                                             │
 *     │  └─changes_requested ◀─request_changes─┘                       │
 *     └─────────────────────────reopen─────────────────────────────────┘
 */
enum Transition: string
{
    case Submit = 'submit';
    case Approve = 'approve';
    case RequestChanges = 'request_changes';
    case Archive = 'archive';
    case Reopen = 'reopen';

    /** @return list<S> */
    public function allowedFrom(): array
    {
        return match ($this) {
            self::Submit => [S::Draft, S::ChangesRequested],
            self::Approve, self::RequestChanges => [S::InReview],
            self::Archive => [S::Published],
            self::Reopen => [S::Archived],
        };
    }

    public function to(): S
    {
        return match ($this) {
            self::Submit => S::InReview,
            self::Approve => S::Published,
            self::RequestChanges => S::ChangesRequested,
            self::Archive => S::Archived,
            self::Reopen => S::Draft,
        };
    }

    /** Name of the ArticleTranslationPolicy method that decides WHO may do it. */
    public function ability(): string
    {
        return match ($this) {
            self::Submit => 'submit',
            self::Approve => 'approve',
            self::RequestChanges => 'requestChanges',
            self::Archive => 'archive',
            self::Reopen => 'reopen',
        };
    }

    public function label(): string
    {
        return match ($this) {
            self::Submit => 'Submit for review',
            self::Approve => 'Approve & publish',
            self::RequestChanges => 'Request changes',
            self::Archive => 'Unpublish',
            self::Reopen => 'Reopen as draft',
        };
    }
}
