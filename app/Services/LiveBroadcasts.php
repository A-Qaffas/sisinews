<?php

namespace App\Services;

use App\Enums\Locale;
use App\Models\Governorate;
use App\Models\LiveStream;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\QueryException;
use Illuminate\Support\Arr;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;

/**
 * What readers see of the live broadcasts: the Live page (live now, coming up, recently ended) and the small
 * "is anything live" summary the header dot and the home strip share.
 *
 * Egypt-wide broadcasts (no governorate) belong to every governorate view as well as the unfiltered one.
 * Every query eager-loads the translations and takes governorate names from one preloaded list, so the number of
 * queries does not grow with the number of broadcasts.
 */
final class LiveBroadcasts
{
    /** A scheduled broadcast stays listed this long after its start time, so a late start does not vanish from the page. */
    private const UPCOMING_GRACE_HOURS = 6;

    private const UPCOMING_LIMIT = 30;

    private const ENDED_LIMIT = 6;

    private const ENDED_DAYS = 7;

    /** @return Collection<int, Governorate> in display order */
    public function governorates(): Collection
    {
        return Governorate::query()->orderBy('sort_order')->orderBy('id')->get();
    }

    /**
     * Broadcasts live right now, newest first, as plain arrays (title, governorate...) so the result can sit in
     * the cache. Rendered on every page, so a failing query must never take the news pages down: it is
     * reported and treated as "nothing live" for one cache window.
     *
     * @return list<array{id: int, title: string, title_lang: ?string, title_dir: ?string, governorate: ?string, governorate_slug: ?string}>
     */
    public function liveNow(Locale $locale): array
    {
        return Cache::remember("site:live:now:{$locale->value}", (int) config('site.cache.live'), function () use ($locale) {
            try {
                $governorates = $this->governorates()->keyBy('id');

                return $this->streams()->liveNow()
                    ->orderByDesc('started_at')->orderByDesc('id')
                    ->get()
                    ->map(fn (LiveStream $stream) => Arr::only(
                        $this->present($stream, $locale, $governorates),
                        ['id', 'title', 'title_lang', 'title_dir', 'governorate', 'governorate_slug'],
                    ))
                    ->all();
            } catch (QueryException $e) {
                report($e);

                return [];
            }
        });
    }

    /**
     * Everything the Live page lists. $focus narrows it to one governorate (plus Egypt-wide broadcasts).
     * `liveGovernorateIds` and `anyLive` ignore the focus: the filter chips show where something is live
     * whichever chip is selected.
     *
     * @param  Collection<int, Governorate>  $governorates  from governorates()
     * @return array{live: list<array<string, mixed>>, upcoming: list<array<string, mixed>>, ended: list<array<string, mixed>>, liveGovernorateIds: list<int>, anyLive: bool}
     */
    public function page(Locale $locale, Collection $governorates, ?Governorate $focus = null): array
    {
        $byId = $governorates->keyBy('id');
        $within = fn (Builder $query) => $focus === null
            ? $query
            : $query->where(fn (Builder $q) => $q->whereNull('governorate_id')->orWhere('governorate_id', $focus->id));

        $liveAnywhere = $this->streams()->liveNow()->orderByDesc('started_at')->orderByDesc('id')->get();

        $isOwn = fn (LiveStream $s) => $focus !== null && $s->governorate_id !== null && (int) $s->governorate_id === (int) $focus->id;

        $live = $liveAnywhere
            ->filter(fn (LiveStream $s) => $focus === null || $s->governorate_id === null || $isOwn($s))
            // In a governorate's own view its broadcasts come before the Egypt-wide ones (the sort is stable).
            ->sortBy(fn (LiveStream $s) => $isOwn($s) ? 0 : 1)
            ->values();

        $upcoming = $within($this->streams()->upcoming())
            ->where(fn (Builder $q) => $q->whereNull('scheduled_at')->orWhere('scheduled_at', '>=', now()->subHours(self::UPCOMING_GRACE_HOURS)))
            // Broadcasts without a start time come last.
            ->orderByRaw('scheduled_at is null')
            ->orderBy('scheduled_at')->orderBy('id')
            ->limit(self::UPCOMING_LIMIT)
            ->get();

        $ended = $within($this->streams()->ended())
            ->where('ended_at', '>=', now()->subDays(self::ENDED_DAYS))
            ->orderByDesc('ended_at')->orderByDesc('id')
            ->limit(self::ENDED_LIMIT)
            ->get();

        $present = fn (Collection $streams, string $moment, bool $weekday = false) => $streams
            ->map(fn (LiveStream $s) => $this->present($s, $locale, $byId, $s->{$moment}, $weekday))
            ->all();

        return [
            'live' => $present($live, 'started_at'),
            'upcoming' => $present($upcoming, 'scheduled_at', weekday: true),
            'ended' => $present($ended, 'ended_at'),
            'liveGovernorateIds' => $liveAnywhere->pluck('governorate_id')->filter()->unique()->map(fn ($id) => (int) $id)->values()->all(),
            'anyLive' => $liveAnywhere->isNotEmpty(),
        ];
    }

    /** @return Builder<LiveStream> */
    private function streams(): Builder
    {
        return LiveStream::query()->with('translations');
    }

    /**
     * One broadcast ready to print. Title and description each fall back to the other language when the current
     * one is blank; `title_lang`/`title_dir` (and the description pair) are set only for such a fallback, so the
     * view can mark the text with its real language.
     *
     * @param  Collection<int, Governorate>  $governorates  keyed by id
     * @return array{id: int, stream: LiveStream, title: string, title_lang: ?string, title_dir: ?string, description: ?string, description_lang: ?string, description_dir: ?string, governorate: ?string, governorate_slug: ?string, moment: ?array{iso: string, date: string, time: string}}
     */
    private function present(LiveStream $stream, Locale $locale, Collection $governorates, ?Carbon $at = null, bool $weekday = false): array
    {
        [$title, $titleLocale] = $this->text($stream, 'title', $locale);
        [$description, $descriptionLocale] = $this->text($stream, 'description', $locale);
        $governorate = $governorates->get($stream->governorate_id);

        return [
            'id' => $stream->id,
            'stream' => $stream,
            'title' => $title ?? __('live.untitled', [], $locale->value),
            'title_lang' => $titleLocale?->value,
            'title_dir' => $titleLocale?->dir(),
            'description' => $description,
            'description_lang' => $descriptionLocale?->value,
            'description_dir' => $descriptionLocale?->dir(),
            'governorate' => $governorate?->nameIn($locale),
            'governorate_slug' => $governorate?->slug,
            'moment' => $this->moment($at, $locale, $weekday),
        ];
    }

    /**
     * @return array{0: ?string, 1: ?Locale} the text, and the language it is in when that is not $locale
     */
    private function text(LiveStream $stream, string $field, Locale $locale): array
    {
        $own = $stream->translation($locale)?->{$field};
        if (filled($own)) {
            return [$own, null];
        }

        $fallback = $stream->translation($locale->other())?->{$field};

        return filled($fallback) ? [$fallback, $locale->other()] : [null, null];
    }

    /** @return array{iso: string, date: string, time: string}|null Cairo time, with the language's own month and day names */
    private function moment(?Carbon $at, Locale $locale, bool $weekday): ?array
    {
        if ($at === null) {
            return null;
        }

        $local = $at->copy()->timezone(config('site.timezone'))->locale($locale->value);

        return [
            'iso' => $at->copy()->utc()->toIso8601String(),
            'date' => $local->translatedFormat($weekday ? 'l j F' : 'j F'),
            'time' => $local->translatedFormat('g:i A'),
        ];
    }
}
