<?php

namespace App\Enums;

enum TranslationStatus: string
{
    case Draft = 'draft';
    case InReview = 'in_review';
    case ChangesRequested = 'changes_requested';
    case Published = 'published';
    case Archived = 'archived';

    public function label(): string
    {
        return match ($this) {
            self::Draft => 'Draft',
            self::InReview => 'In review',
            self::ChangesRequested => 'Changes requested',
            self::Published => 'Published',
            // The stored value stays `archived`; "Unpublished" is simply what staff call it.
            self::Archived => 'Unpublished',
        };
    }

    /** Statuses in which the original author may still edit their own text. */
    public function isAuthorEditable(): bool
    {
        return $this === self::Draft || $this === self::ChangesRequested;
    }
}
