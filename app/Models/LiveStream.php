<?php

namespace App\Models;

use App\Enums\LiveProvider;
use App\Enums\LiveStatus;
use App\Enums\Locale;
use Carbon\CarbonInterface;
use Closure;
use DomainException;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\DB;

// `status`, `started_at` and `ended_at` are NOT mass-assignable: they only change through goLive()/end(), so a
// crafted form post cannot switch a broadcast on.
#[Fillable(['governorate_id', 'provider', 'video_ref', 'scheduled_at'])]
class LiveStream extends Model
{
    use HasFactory;

    protected function casts(): array
    {
        return [
            'provider' => LiveProvider::class,
            'status' => LiveStatus::class,
            'scheduled_at' => 'datetime',
            'started_at' => 'datetime',
            'ended_at' => 'datetime',
        ];
    }

    public function governorate(): BelongsTo
    {
        return $this->belongsTo(Governorate::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function translations(): HasMany
    {
        return $this->hasMany(LiveStreamTranslation::class);
    }

    /** Reads the loaded relation (eager-load `translations` to avoid N+1). */
    public function translation(Locale $locale): ?LiveStreamTranslation
    {
        return $this->translations->firstWhere('locale', $locale);
    }

    public function scopeLiveNow(Builder $query): Builder
    {
        return $query->where('status', LiveStatus::Live->value);
    }

    public function scopeUpcoming(Builder $query): Builder
    {
        return $query->where('status', LiveStatus::Scheduled->value);
    }

    public function scopeEnded(Builder $query): Builder
    {
        return $query->where('status', LiveStatus::Ended->value);
    }

    /** The no-cookie player address built from the validated id; never from free text. */
    public function embedUrl(): string
    {
        return $this->provider->embedUrl($this->video_ref);
    }

    /** A scheduled broadcast starts; an ended one may be resumed (the stream dropped and came back). */
    public function canGoLive(): bool
    {
        return in_array($this->status, [LiveStatus::Scheduled, LiveStatus::Ended], true);
    }

    public function canEnd(): bool
    {
        return $this->status === LiveStatus::Live;
    }

    /**
     * How long after ending a resumed broadcast still counts as the same session (a stream that dropped and came
     * back). Resumed later, it is a new session: it starts now, so the Live page does not show an old start date and
     * it sorts with the newest broadcasts instead of below them (and out of the three-item home strip).
     */
    public const SAME_SESSION_HOURS = 2;

    /** True for an ended broadcast that ended less than SAME_SESSION_HOURS ago: resuming it continues the same session. */
    public function resumesSameSession(): bool
    {
        return $this->status === LiveStatus::Ended
            && $this->started_at !== null
            && $this->ended_at?->greaterThan(now()->subHours(self::SAME_SESSION_HOURS)) === true;
    }

    /** The start time going live now records: the first start for a resume within the same session, otherwise now. */
    public function startedAtIfLiveNow(): CarbonInterface
    {
        return $this->resumesSameSession() ? $this->started_at : now();
    }

    /**
     * Starts the broadcast, or resumes an ended one. A broadcast that resumes within SAME_SESSION_HOURS keeps its
     * FIRST start, so "live since" stays honest for a stream that only dropped for a moment; one resumed later
     * starts afresh. `ended_at` is cleared because it is live again.
     *
     * @throws DomainException when the broadcast is already live
     */
    public function goLive(): static
    {
        return $this->transition(
            fn (self $current): bool => $current->canGoLive(),
            fn (self $current): array => [
                'status' => LiveStatus::Live,
                'started_at' => $current->startedAtIfLiveNow(),
                'ended_at' => null,
            ],
            fn (): string => 'This broadcast is already live.',
        );
    }

    /** @throws DomainException unless the broadcast is live right now */
    public function end(): static
    {
        return $this->transition(
            fn (self $current): bool => $current->canEnd(),
            fn (): array => ['status' => LiveStatus::Ended, 'ended_at' => now()],
            fn (self $current): string => $current->status === LiveStatus::Ended
                ? 'This broadcast has already ended.'
                : 'This broadcast has not started yet, so it cannot be ended.',
        );
    }

    /**
     * The one place a broadcast's state changes. The row is re-read under a lock first, so two editors pressing
     * buttons at once (or a page showing stale state) cannot push it through a move that is no longer legal.
     *
     * @param  Closure(self): bool  $allowed
     * @param  Closure(self): array<string, mixed>  $changes
     * @param  Closure(self): string  $refusal  the message when the move is not allowed
     */
    private function transition(Closure $allowed, Closure $changes, Closure $refusal): static
    {
        if (! $this->exists) {
            throw new DomainException('Save the broadcast before changing its status.');
        }

        DB::transaction(function () use ($allowed, $changes, $refusal): void {
            $current = static::query()->lockForUpdate()->find($this->getKey());

            if ($current === null) {
                throw new DomainException('This broadcast no longer exists.');
            }
            if (! $allowed($current)) {
                throw new DomainException($refusal($current));
            }

            $current->forceFill($changes($current))->save();
            $this->setRawAttributes($current->getAttributes(), sync: true);
        });

        return $this;
    }
}
