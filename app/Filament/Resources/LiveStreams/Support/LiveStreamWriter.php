<?php

namespace App\Filament\Resources\LiveStreams\Support;

use App\Enums\LiveProvider;
use App\Enums\Locale;
use App\Filament\Support\PublishAtField;
use App\Models\LiveStream;
use App\Models\LiveStreamTranslation;
use App\Models\User;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Reads and writes a broadcast and its language rows for the staff form. Only the provider and the video id are
 * ever stored, parsed from whatever was pasted (LiveProvider::fromInput), never the pasted text itself. Status and
 * the started/ended times are not written here at all: they belong to LiveStream::goLive() and end().
 */
final class LiveStreamWriter
{
    public const LINK_ERROR = 'Not a YouTube or Vimeo video. Paste a link that starts with https:// from YouTube (youtube.com or youtu.be) or Vimeo (vimeo.com), or just the video ID.';

    /**
     * The links editors most often copy that cannot work get their own explanation. Vimeo event links are not
     * supported on purpose: their player is a different address, outside the frame-src and the privacy page.
     */
    public const YOUTUBE_CHANNEL_ERROR = 'That is a YouTube channel page, not one broadcast. Open the broadcast itself on YouTube and paste its own link (youtube.com/watch?v=… or youtube.com/live/…).';

    public const VIMEO_EVENT_ERROR = 'That is a Vimeo event link (vimeo.com/event/…), which the site cannot show. Open the event’s current video on Vimeo and paste that video’s own link (vimeo.com/ followed by its number).';

    public const VIMEO_PRIVATE_ERROR = 'That is a private (unlisted) Vimeo link, which the site cannot show. Make the video public on Vimeo, then paste its link (vimeo.com/ followed by its number).';

    /** No real video link is longer; checked before parsing so a huge paste is refused, not processed. */
    public const LINK_MAX_LENGTH = 300;

    /**
     * The one reading of a pasted link, used by the form's check, its "Recognised" hint and the save.
     * Links copied out of chat apps and right-to-left text often carry invisible direction marks or a no-break
     * space that the editor cannot see, so those are dropped first instead of rejecting a link that looks fine.
     *
     * @return array{0: LiveProvider, 1: string}|null
     */
    public static function parseLink(string $pasted): ?array
    {
        if (strlen($pasted) > self::LINK_MAX_LENGTH * 2) {
            return null;
        }

        $clean = preg_replace('/[\p{Cf}\x{00A0}]+/u', '', $pasted);

        if ($clean === null || strlen(trim($clean)) > self::LINK_MAX_LENGTH) {
            return null;
        }

        return LiveProvider::fromInput($clean);
    }

    /**
     * Why a pasted link was refused, in words an editor can act on: a specific reason for a channel page, a Vimeo
     * event or a private Vimeo link, the general one for everything else. Only ever called for a refused link.
     */
    public static function refusal(string $pasted): string
    {
        $clean = trim((string) preg_replace('/[\p{Cf}\x{00A0}]+/u', '', $pasted));
        $parts = strlen($clean) <= self::LINK_MAX_LENGTH && preg_match('/\s/', $clean) !== 1 ? parse_url($clean) : false;

        if (! is_array($parts) || ($parts['scheme'] ?? '') !== 'https' || ! isset($parts['host'])) {
            return self::LINK_ERROR;
        }

        $host = strtolower((string) preg_replace('/^www\./i', '', $parts['host']));
        $path = $parts['path'] ?? '';

        return match (true) {
            $host === 'vimeo.com' && preg_match('~^/event/\d+~', $path) === 1 => self::VIMEO_EVENT_ERROR,
            $host === 'vimeo.com' && preg_match('~^/\d{6,12}/[0-9a-f]+/?$~i', $path) === 1 => self::VIMEO_PRIVATE_ERROR,
            in_array($host, ['youtube.com', 'm.youtube.com'], true)
                && preg_match('~^/(?:@[^/]+|channel/[^/]+|c/[^/]+|user/[^/]+)(?:/(?:live|streams|featured))?/?$~', $path) === 1 => self::YOUTUBE_CHANNEL_ERROR,
            default => self::LINK_ERROR,
        };
    }

    /**
     * The pasted-link box shows an ordinary watch link rebuilt from the stored id, which reads back to the same
     * provider and id.
     */
    public static function watchLink(LiveStream $stream): string
    {
        return match ($stream->provider) {
            LiveProvider::YouTube => 'https://www.youtube.com/watch?v='.$stream->video_ref,
            LiveProvider::Vimeo => 'https://vimeo.com/'.$stream->video_ref,
        };
    }

    /** @return array<string, mixed> the fields that are not columns of the broadcast: the link and one block per language */
    public static function formState(LiveStream $stream): array
    {
        $state = ['video_link' => self::watchLink($stream)];

        foreach ($stream->translations as $translation) {
            $state[$translation->locale->value] = ['title' => $translation->title, 'description' => $translation->description];
        }

        return $state;
    }

    /** @param  array<string, mixed>  $data  form state: governorate_id, video_link, scheduled_at, and one array per locale */
    public static function create(array $data, User $by): LiveStream
    {
        return DB::transaction(function () use ($data, $by): LiveStream {
            $stream = new LiveStream(self::shell($data));
            // Not mass-assignable on purpose; this is the one place that records who registered it.
            $stream->forceFill(['created_by' => $by->getKey()])->save();
            self::saveTranslations($stream, $data);

            // Reload so the column default (status "scheduled") is on the model.
            return $stream->refresh();
        });
    }

    /** @param  array<string, mixed>  $data */
    public static function update(LiveStream $stream, array $data): LiveStream
    {
        return DB::transaction(function () use ($stream, $data): LiveStream {
            $stream->fill(self::shell($data))->save();
            self::saveTranslations($stream, $data);
            $stream->unsetRelation('translations');

            return $stream;
        });
    }

    /**
     * The broadcast's own columns. Everything else in $data (a hand-made request may add "status" or "started_at")
     * is ignored, and the link is re-checked here even though the form already refused a bad one.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private static function shell(array $data): array
    {
        $parsed = self::parseLink((string) ($data['video_link'] ?? ''));

        if ($parsed === null) {
            throw ValidationException::withMessages(['data.video_link' => self::refusal((string) ($data['video_link'] ?? ''))]);
        }

        [$provider, $id] = $parsed;

        return [
            'governorate_id' => filled($data['governorate_id'] ?? null) ? (int) $data['governorate_id'] : null,
            'provider' => $provider,
            'video_ref' => $id,
            'scheduled_at' => PublishAtField::parse($data['scheduled_at'] ?? null),
        ];
    }

    /**
     * One row per language that has a title. A language whose title was cleared loses its row: a broadcast may be
     * in one language only (the form insists on a title in at least one).
     *
     * @param  array<string, mixed>  $data
     */
    private static function saveTranslations(LiveStream $stream, array $data): void
    {
        $written = 0;

        foreach (Locale::cases() as $locale) {
            $version = Arr::wrap($data[$locale->value] ?? []);
            $title = trim((string) ($version['title'] ?? ''));
            $description = trim((string) ($version['description'] ?? ''));
            $row = LiveStreamTranslation::query()->where('live_stream_id', $stream->getKey())->where('locale', $locale->value);

            if ($title === '') {
                $row->delete();

                continue;
            }

            $attributes = ['title' => $title, 'description' => $description === '' ? null : $description];
            $existing = $row->first();

            $existing === null
                ? LiveStreamTranslation::query()->create([...$attributes, 'live_stream_id' => $stream->getKey(), 'locale' => $locale])
                : $existing->update($attributes);
            $written++;
        }

        if ($written === 0) {
            // The form's own rule normally catches this first; a hand-made request must not leave a title-less broadcast.
            throw ValidationException::withMessages(['data.ar.title' => 'Give the broadcast a title in at least one language.']);
        }
    }
}
