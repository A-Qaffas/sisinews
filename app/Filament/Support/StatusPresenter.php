<?php

namespace App\Filament\Support;

use App\Editorial\Transition;
use App\Enums\Locale;
use App\Enums\TranslationStatus;
use App\Models\ArticleTranslation;
use Carbon\CarbonInterface;

/** How workflow state and language are shown in the panel. */
final class StatusPresenter
{
    public const TIMEZONE = 'Africa/Cairo';

    /** Colour of the "Scheduled" badge, registered in AdminPanelProvider: it must look neither live (green) nor off-site (grey). */
    public const SCHEDULED_COLOR = 'scheduled';

    public static function color(?TranslationStatus $status): string
    {
        return match ($status) {
            TranslationStatus::Draft => 'info',
            TranslationStatus::InReview => 'warning',
            TranslationStatus::ChangesRequested => 'danger',
            TranslationStatus::Published => 'success',
            TranslationStatus::Archived, null => 'gray',
        };
    }

    public static function label(?TranslationStatus $status): string
    {
        return $status?->label() ?? 'No version';
    }

    /**
     * Published with a date still ahead: the stored status stays `published`, but readers
     * cannot see it yet, so staff are shown something other than a plain "Published".
     */
    public static function isScheduled(?ArticleTranslation $translation): bool
    {
        return $translation?->status === TranslationStatus::Published
            && $translation->published_at?->isFuture() === true;
    }

    /** Compact date for badges and tables, in newsroom (Cairo) time. */
    public static function when(CarbonInterface $date, string $format = 'j M, H:i'): string
    {
        return $date->copy()->timezone(self::TIMEZONE)->format($format);
    }

    /** Short badge text for a tab or panel (the date, when there is one, sits next to it); null when there is no version. */
    public static function shortLabel(?ArticleTranslation $translation): ?string
    {
        return match (true) {
            $translation === null => null,
            self::isScheduled($translation) => 'Scheduled',
            default => $translation->status->label(),
        };
    }

    /** The badge text for one language version: its status, or "Scheduled for ..." when it is not live yet. */
    public static function stateLabel(?ArticleTranslation $translation): string
    {
        return self::isScheduled($translation)
            ? 'Scheduled for '.self::when($translation->published_at)
            : self::label($translation?->status);
    }

    public static function stateColor(?ArticleTranslation $translation): string
    {
        return self::isScheduled($translation) ? self::SCHEDULED_COLOR : self::color($translation?->status);
    }

    /** English name for the UI (the native name would drag RTL text into English labels). */
    public static function language(Locale $locale): string
    {
        return $locale === Locale::Ar ? 'Arabic' : 'English';
    }

    public static function transitionColor(Transition $transition): string
    {
        return match ($transition) {
            Transition::Submit => 'primary',
            Transition::Approve => 'success',
            Transition::RequestChanges => 'warning',
            Transition::Archive => 'danger',
            Transition::Reopen => 'gray',
        };
    }
}
