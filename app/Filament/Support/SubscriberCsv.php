<?php

namespace App\Filament\Support;

use App\Models\NewsletterSubscriber;
use Illuminate\Database\Eloquent\Builder;
use Symfony\Component\HttpFoundation\StreamedResponse;

/** Streams the (filtered) subscriber list as CSV without loading it into memory. */
final class SubscriberCsv
{
    /** @param  Builder<NewsletterSubscriber>  $query */
    public static function download(Builder $query): StreamedResponse
    {
        $filename = 'subscribers-'.now()->format('Y-m-d').'.csv';

        return response()->streamDownload(function () use ($query): void {
            $out = fopen('php://output', 'w');

            // The byte-order mark makes Excel read the file as UTF-8.
            fwrite($out, "\xEF\xBB\xBF");
            fputcsv($out, ['Email', 'Language', 'Status', 'Signed up (UTC)', 'Unsubscribed (UTC)']);

            $query->reorder('id')->chunkById(500, function ($subscribers) use ($out): void {
                foreach ($subscribers as $subscriber) {
                    fputcsv($out, array_map(self::safe(...), [
                        $subscriber->email,
                        $subscriber->locale->value,
                        $subscriber->unsubscribed_at === null ? 'subscribed' : 'unsubscribed',
                        $subscriber->created_at?->utc()->toIso8601String(),
                        $subscriber->unsubscribed_at?->utc()->toIso8601String(),
                    ]));
                }
            });

            fclose($out);
        }, $filename, ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    /** A cell that starts with = + - @ would run as a formula when the file is opened in a spreadsheet. */
    private static function safe(?string $value): string
    {
        $value ??= '';

        return $value !== '' && str_contains("=+-@\t\r", $value[0]) ? "'".$value : $value;
    }
}
