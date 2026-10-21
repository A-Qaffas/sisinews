<?php

namespace App\Filament\Support;

use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Filament\Forms\Components\DateTimePicker;

/**
 * The optional "publish at" picker of every Approve and Publish-now action, so the article page
 * and the review queue offer the same thing and read the answer the same way.
 * The workflow itself decides what a date means (EditorialWorkflow::approve()).
 */
final class PublishAtField
{
    public const NAME = 'publish_at';

    public static function make(): DateTimePicker
    {
        return DateTimePicker::make(self::NAME)
            ->label(__('Publish at (leave empty to publish now)'))
            ->helperText(__('Cairo time. Pick a moment in the future and the story goes live by itself then.'))
            ->timezone(StatusPresenter::TIMEZONE)
            ->seconds(false)
            // The picker and its validation both read the typed time as Cairo wall-clock time, so the
            // limit is Cairo's clock as plain text. A UTC "now" would let the last 2-3 hours slip through.
            ->minDate(fn (): string => now(StatusPresenter::TIMEZONE)->format('Y-m-d H:i:s'))
            // EditorialWorkflow refuses anything later than a year (plus a day of slack for this very clock reading).
            ->maxDate(fn (): string => now(StatusPresenter::TIMEZONE)->addYear()->format('Y-m-d H:i:s'));
    }

    /** The picker hands over app-time (UTC) text; empty means "now". */
    public static function parse(mixed $state): ?CarbonImmutable
    {
        return is_string($state) && trim($state) !== ''
            ? CarbonImmutable::parse($state, config('app.timezone'))
            : null;
    }

    /**
     * What to tell the editor: "<subject> published." or "<subject> scheduled for <date>.".
     *
     * @param  string  $subject  already translated ("Arabic version", "Version")
     */
    public static function successTitle(string $subject, ?CarbonInterface $publishAt): string
    {
        return $publishAt !== null && $publishAt->isFuture()
            ? __(':subject scheduled for :date.', ['subject' => $subject, 'date' => StatusPresenter::when($publishAt)])
            : __(':subject published.', ['subject' => $subject]);
    }
}
