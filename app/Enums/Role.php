<?php

namespace App\Enums;

enum Role: string
{
    case Journalist = 'journalist';
    // The stored value stays "editor"; only the name staff read changed (Arabic: رئيس التحرير).
    case Editor = 'editor';
    case Admin = 'admin';

    public function isReviewer(): bool
    {
        return $this === self::Editor || $this === self::Admin;
    }

    /** The name staff read in the panel. */
    public function label(): string
    {
        return match ($this) {
            self::Journalist => __('Journalist'),
            self::Editor => __('Editor-in-chief'),
            self::Admin => __('Admin'),
        };
    }
}
