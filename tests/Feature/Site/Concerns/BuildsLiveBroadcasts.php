<?php

namespace Tests\Feature\Site\Concerns;

use App\Enums\LiveProvider;
use App\Enums\LiveStatus;
use App\Models\Governorate;
use App\Models\LiveStream;

/**
 * Builders for the public Live tests. Rows are created with forceFill because status and the timestamps are
 * deliberately not mass-assignable. Deliberately independent of the model factories.
 */
trait BuildsLiveBroadcasts
{
    private int $broadcastCount = 0;

    protected function governorate(string $slug, string $en, string $ar, int $order = 1): Governorate
    {
        $governorate = (new Governorate)->forceFill(['slug' => $slug, 'name_en' => $en, 'name_ar' => $ar, 'sort_order' => $order]);
        $governorate->save();

        return $governorate;
    }

    /**
     * @param  array<string, string|array{title: ?string, description?: ?string}>  $translations  locale => title, or title + description
     * @param  array<string, mixed>  $attributes  override any live_streams column (video_ref, provider, started_at...)
     */
    protected function broadcast(
        LiveStatus $status = LiveStatus::Live,
        array $translations = ['en' => 'Broadcast', 'ar' => 'بث'],
        ?Governorate $governorate = null,
        array $attributes = [],
    ): LiveStream {
        $defaults = match ($status) {
            LiveStatus::Live => ['started_at' => now()->subMinutes(10)],
            LiveStatus::Scheduled => ['scheduled_at' => now()->addHours(2)],
            LiveStatus::Ended => ['started_at' => now()->subHours(3), 'ended_at' => now()->subHour()],
        };

        $stream = (new LiveStream)->forceFill([
            'governorate_id' => $governorate?->id,
            'provider' => LiveProvider::YouTube,
            'video_ref' => sprintf('video%06d', ++$this->broadcastCount),
            'status' => $status,
            ...$defaults,
            ...$attributes,
        ]);
        $stream->save();

        foreach ($translations as $locale => $text) {
            $text = is_array($text) ? $text : ['title' => $text];
            $stream->translations()->create(['locale' => $locale, 'title' => $text['title'], 'description' => $text['description'] ?? null]);
        }

        return $stream->fresh();
    }
}
