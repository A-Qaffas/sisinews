<?php

namespace App\Editorial;

use App\Models\ArticleTranslation;
use App\Models\User;
use Carbon\CarbonInterface;
use Illuminate\Contracts\Auth\Access\Gate;
use Illuminate\Support\Facades\DB;

/**
 * The ONLY code that may change ArticleTranslation::status.
 *
 * Two independent questions are answered in two places on purpose:
 *   WHO may act      -> ArticleTranslationPolicy (role + ownership), via the Gate
 *   WHICH state move -> Transition::allowedFrom()/to() (state validity), checked here
 *
 * Throws AuthorizationException (who) or InvalidTransition (state).
 */
final class EditorialWorkflow
{
    public function __construct(private readonly Gate $gate) {}

    /** @return list<Transition> every move $by may legally make right now (drives UI buttons). */
    public function availableFor(ArticleTranslation $t, User $by): array
    {
        return array_values(array_filter(
            Transition::cases(),
            fn (Transition $tr) => in_array($t->status, $tr->allowedFrom(), true)
                && $this->gate->forUser($by)->allows($tr->ability(), $t),
        ));
    }

    public function submit(ArticleTranslation $t, User $by): ArticleTranslation
    {
        return $this->apply(Transition::Submit, $t, $by);
    }

    /**
     * @param  CarbonInterface|null  $publishAt  a FUTURE moment schedules the story: it is published now but only
     *                                           readable from then on (ArticleTranslation::visible() needs published_at <= now).
     *                                           A past value is never used to back-date: it means "now", like null.
     */
    public function approve(ArticleTranslation $t, User $by, ?CarbonInterface $publishAt = null): ArticleTranslation
    {
        return $this->apply(Transition::Approve, $t, $by, publishAt: $publishAt);
    }

    public function requestChanges(ArticleTranslation $t, User $by, string $note): ArticleTranslation
    {
        return $this->apply(Transition::RequestChanges, $t, $by, $note);
    }

    public function archive(ArticleTranslation $t, User $by): ArticleTranslation
    {
        return $this->apply(Transition::Archive, $t, $by);
    }

    public function reopen(ArticleTranslation $t, User $by): ArticleTranslation
    {
        return $this->apply(Transition::Reopen, $t, $by);
    }

    /**
     * Convenience for editors/admins publishing their own text: submit (if still a
     * draft) and approve in ONE transaction, so the audit trail still shows both.
     * $publishAt behaves exactly as in approve().
     */
    public function publishNow(ArticleTranslation $t, User $by, ?CarbonInterface $publishAt = null): ArticleTranslation
    {
        return DB::transaction(function () use ($t, $by, $publishAt) {
            if (in_array($t->status, Transition::Submit->allowedFrom(), true)) {
                $this->submit($t, $by);
            }

            return $this->approve($t, $by, $publishAt);
        });
    }

    private function apply(Transition $tr, ArticleTranslation $t, User $by, ?string $note = null, ?CarbonInterface $publishAt = null): ArticleTranslation
    {
        $this->gate->forUser($by)->authorize($tr->ability(), $t);

        return DB::transaction(function () use ($tr, $t, $by, $note, $publishAt) {
            // Re-read under a row lock: two editors double-clicking Approve must not
            // both pass the state check against a stale in-memory status.
            $fresh = ArticleTranslation::query()->lockForUpdate()->findOrFail($t->getKey());

            if (! in_array($fresh->status, $tr->allowedFrom(), true)) {
                throw InvalidTransition::notAllowedFrom($tr, $fresh->status);
            }
            if ($tr === Transition::Submit) {
                $this->assertSubmittable($fresh);
            }
            if ($tr === Transition::RequestChanges && blank($note)) {
                throw InvalidTransition::noteRequired();
            }

            $from = $fresh->status;
            $fresh->status = $tr->to();
            if ($tr === Transition::Approve) {
                $now = now();
                if ($publishAt !== null && $publishAt->greaterThan($now)) {
                    // The column is a TIMESTAMP (ends in 2038): a date past it would be a database error, not a schedule.
                    // The picker stops at exactly one year; the extra day only absorbs its Cairo wall-clock reading.
                    if ($publishAt->greaterThan($now->copy()->addYear()->addDay())) {
                        throw InvalidTransition::scheduledTooFar();
                    }
                    // Eloquent writes the wall clock of whatever zone the instance is in; the column is app-time (UTC).
                    $fresh->published_at = $publishAt->copy()->setTimezone(config('app.timezone'));
                    $note = 'Scheduled for '.$publishAt->copy()->setTimezone(config('site.timezone', 'Africa/Cairo'))->format('j M Y, H:i').' (Cairo time)';
                } elseif ($publishAt !== null) {
                    $fresh->published_at = $now;
                } else {
                    // Only a stamp that is already on the row survives (pre-dated imports and the demo seeder);
                    // Reopen clears it, so no editor flow can bring an old date back.
                    $fresh->published_at ??= $now;
                }
            } elseif ($tr === Transition::Reopen) {
                // A new draft cycle has no publication date; the next approval stamps a fresh one.
                $fresh->published_at = null;
            } elseif ($tr === Transition::Archive && $fresh->published_at?->isFuture()) {
                // Unpublishing a still-scheduled story cancels the schedule: it never went live.
                $fresh->published_at = null;
                $note = 'Scheduled publication cancelled';
            }
            $fresh->save();

            $fresh->statusChanges()->create([
                'user_id' => $by->getKey(),
                'transition' => $tr,
                'from_status' => $from,
                'to_status' => $tr->to(),
                'note' => $note,
            ]);

            $t->setRawAttributes($fresh->getAttributes(), sync: true);

            return $t;
        });
    }

    private function assertSubmittable(ArticleTranslation $t): void
    {
        if (blank($t->title)) {
            throw InvalidTransition::incomplete('title');
        }
        if (blank(trim(strip_tags((string) $t->body)))) {
            throw InvalidTransition::incomplete('body');
        }
    }
}
