<?php

namespace Tests\Feature;

use App\Editorial\EditorialWorkflow;
use App\Editorial\InvalidTransition;
use App\Editorial\Transition;
use App\Enums\Locale;
use App\Enums\TranslationStatus;
use App\Models\Article;
use App\Models\ArticleTranslation;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class EditorialWorkflowTest extends TestCase
{
    use RefreshDatabase;

    private EditorialWorkflow $workflow;

    protected function setUp(): void
    {
        parent::setUp();
        // Refusals are panel messages, and the suite runs the panel in English.
        app()->setLocale('en');
        $this->workflow = app(EditorialWorkflow::class);
    }

    private function translationBy(User $author, TranslationStatus $status = TranslationStatus::Draft, Locale $locale = Locale::En): ArticleTranslation
    {
        $article = Article::factory()->create(['author_id' => $author->id]);
        // A journalist writes only in the sections an admin assigned; the author of this story was given its section.
        $author->allowedCategories()->syncWithoutDetaching([$article->category_id]);

        return ArticleTranslation::factory()->status($status)->create([
            'article_id' => $article->id,
            'locale' => $locale,
        ])->fresh();
    }

    public function test_author_submits_own_draft_and_it_is_audited(): void
    {
        $author = User::factory()->journalist()->create();
        $t = $this->translationBy($author);

        $this->workflow->submit($t, $author);

        $this->assertSame(TranslationStatus::InReview, $t->fresh()->status);
        $change = $t->statusChanges()->first();
        $this->assertSame(Transition::Submit, $change->transition);
        $this->assertSame(TranslationStatus::Draft, $change->from_status);
        $this->assertSame($author->id, $change->user_id);
    }

    public function test_journalist_cannot_submit_someone_elses_draft(): void
    {
        $t = $this->translationBy(User::factory()->journalist()->create());

        $this->expectException(AuthorizationException::class);
        $this->workflow->submit($t, User::factory()->journalist()->create());
    }

    public function test_journalist_cannot_approve_even_their_own_story(): void
    {
        $author = User::factory()->journalist()->create();
        $t = $this->translationBy($author, TranslationStatus::InReview);

        $this->expectException(AuthorizationException::class);
        $this->workflow->approve($t, $author);
    }

    public function test_editor_approves_and_publish_date_is_stamped(): void
    {
        $editor = User::factory()->editor()->create();
        $t = $this->translationBy(User::factory()->create(), TranslationStatus::InReview);

        $this->workflow->approve($t, $editor);

        $t->refresh();
        $this->assertSame(TranslationStatus::Published, $t->status);
        $this->assertNotNull($t->published_at);
        $this->assertTrue($t->isVisible());
    }

    public function test_approve_keeps_an_editor_chosen_future_date_so_scheduling_works(): void
    {
        $editor = User::factory()->editor()->create();
        $t = $this->translationBy(User::factory()->create(), TranslationStatus::InReview);
        $future = now()->addDay()->startOfSecond();
        $t->forceFill(['published_at' => $future])->save();

        $this->workflow->approve($t, $editor);

        $t->refresh();
        $this->assertSame(TranslationStatus::Published, $t->status);
        $this->assertTrue($t->published_at->equalTo($future));
        $this->assertFalse($t->isVisible());
        $this->assertSame(0, ArticleTranslation::visible()->count());
    }

    public function test_cannot_approve_a_draft(): void
    {
        $editor = User::factory()->editor()->create();
        $t = $this->translationBy(User::factory()->create());

        $this->expectException(InvalidTransition::class);
        $this->workflow->approve($t, $editor);
    }

    public function test_stale_double_approve_is_rejected_by_the_row_lock_recheck(): void
    {
        $editorA = User::factory()->editor()->create();
        $editorB = User::factory()->editor()->create();
        $t = $this->translationBy(User::factory()->create(), TranslationStatus::InReview);
        $staleCopy = ArticleTranslation::find($t->id);

        $this->workflow->approve($t, $editorA);

        $this->expectException(InvalidTransition::class);
        $this->workflow->approve($staleCopy, $editorB);
    }

    public function test_request_changes_needs_a_note_then_author_can_fix_and_resubmit(): void
    {
        $author = User::factory()->journalist()->create();
        $editor = User::factory()->editor()->create();
        $t = $this->translationBy($author, TranslationStatus::InReview);

        try {
            $this->workflow->requestChanges($t, $editor, '   ');
            $this->fail('A blank note must be rejected.');
        } catch (InvalidTransition) {
            $this->assertSame(TranslationStatus::InReview, $t->fresh()->status);
        }

        $this->workflow->requestChanges($t, $editor, 'Please add a second source.');
        $t->refresh();
        $this->assertSame(TranslationStatus::ChangesRequested, $t->status);
        $this->assertSame('Please add a second source.', $t->latestChangeRequest->note);
        $this->assertTrue($author->can('update', $t));

        $this->workflow->submit($t, $author);
        $this->assertSame(TranslationStatus::InReview, $t->fresh()->status);
    }

    public function test_author_loses_edit_rights_once_in_review_or_published(): void
    {
        $author = User::factory()->journalist()->create();

        foreach ([TranslationStatus::InReview, TranslationStatus::Published, TranslationStatus::Archived] as $status) {
            $t = $this->translationBy($author, $status);
            $this->assertFalse($author->can('update', $t), "author must not edit a {$status->value} translation");
        }
        foreach ([TranslationStatus::Draft, TranslationStatus::ChangesRequested] as $status) {
            $t = $this->translationBy($author, $status);
            $this->assertTrue($author->can('update', $t), "author may edit a {$status->value} translation");
        }
    }

    public function test_archive_then_reopen_returns_to_draft(): void
    {
        $editor = User::factory()->editor()->create();
        $t = $this->translationBy(User::factory()->create(), TranslationStatus::Published);

        $this->workflow->archive($t, $editor);
        $this->assertSame(TranslationStatus::Archived, $t->fresh()->status);
        $this->assertSame(0, ArticleTranslation::visible()->count());

        $this->workflow->reopen($t, $editor);
        $this->assertSame(TranslationStatus::Draft, $t->fresh()->status);
    }

    public function test_submit_rejects_an_empty_body_or_title(): void
    {
        $author = User::factory()->journalist()->create();
        $t = $this->translationBy($author);
        $t->forceFill(['body' => '<p> </p>'])->save();

        $this->expectException(InvalidTransition::class);
        $this->workflow->submit($t, $author);
    }

    public function test_publish_now_by_editor_walks_both_steps_in_one_transaction(): void
    {
        $editor = User::factory()->editor()->create();
        $t = $this->translationBy($editor);

        $this->workflow->publishNow($t, $editor);

        $this->assertSame(TranslationStatus::Published, $t->fresh()->status);
        $this->assertSame(
            ['submit', 'approve'],
            $t->statusChanges()->reorder('id')->get()->map(fn ($c) => $c->transition->value)->all(),
        );
    }

    public function test_publish_now_is_denied_to_a_journalist(): void
    {
        $author = User::factory()->journalist()->create();
        $t = $this->translationBy($author);

        try {
            $this->workflow->publishNow($t, $author);
            $this->fail('A journalist must not be able to publish.');
        } catch (AuthorizationException) {
            $this->assertSame(TranslationStatus::Draft, $t->fresh()->status, 'the submit step must roll back too');
            $this->assertSame(0, $t->statusChanges()->count());
        }
    }

    public function test_available_transitions_follow_role_and_status(): void
    {
        $author = User::factory()->journalist()->create();
        $editor = User::factory()->editor()->create();
        $t = $this->translationBy($author, TranslationStatus::InReview);

        $this->assertSame([], $this->workflow->availableFor($t, $author));
        $this->assertEqualsCanonicalizing(
            [Transition::Approve, Transition::RequestChanges],
            $this->workflow->availableFor($t, $editor),
        );

        $draft = $this->translationBy($author);
        $this->assertSame([Transition::Submit], $this->workflow->availableFor($draft, $author));
    }

    public function test_deactivated_staff_can_do_nothing(): void
    {
        $inactiveEditor = User::factory()->editor()->inactive()->create();
        $t = $this->translationBy(User::factory()->create(), TranslationStatus::InReview);

        $this->assertSame([], $this->workflow->availableFor($t, $inactiveEditor));
        $this->expectException(AuthorizationException::class);
        $this->workflow->approve($t, $inactiveEditor);
    }

    public function test_status_cannot_be_set_through_mass_assignment(): void
    {
        $article = Article::factory()->create();

        $t = ArticleTranslation::create([
            'article_id' => $article->id,
            'locale' => Locale::En,
            'title' => 'Sneaky',
            'slug' => 'sneaky',
            'status' => 'published',
            'published_at' => now()->subDay(),
        ]);

        $this->assertSame(TranslationStatus::Draft, $t->fresh()->status);
        $this->assertNull($t->fresh()->published_at);
    }

    public function test_approve_with_a_future_publish_at_schedules_the_story_and_audits_it(): void
    {
        $this->freezeSecond();
        $editor = User::factory()->editor()->create();
        $t = $this->translationBy(User::factory()->create(), TranslationStatus::InReview);
        $at = now()->addDays(2)->setTime(9, 30);

        $this->workflow->approve($t, $editor, $at);

        $t->refresh();
        $this->assertSame(TranslationStatus::Published, $t->status, 'the stored status stays published');
        $this->assertTrue($t->published_at->equalTo($at));
        $this->assertFalse($t->isVisible());
        $this->assertSame(0, ArticleTranslation::visible()->count());
        $note = $t->statusChanges()->first()->note;
        $this->assertStringContainsString('Scheduled for', $note);
        $this->assertStringContainsString($at->copy()->timezone('Africa/Cairo')->format('j M Y, H:i'), $note);
        $this->assertStringContainsString('Cairo time', $note);
    }

    public function test_a_scheduled_story_goes_live_by_itself_when_its_time_comes(): void
    {
        $this->freezeSecond();
        $editor = User::factory()->editor()->create();
        $t = $this->translationBy(User::factory()->create(), TranslationStatus::InReview);
        $at = now()->addHours(5);

        $this->workflow->approve($t, $editor, $at);
        $this->assertSame(0, ArticleTranslation::visible()->count());

        $this->travelTo($at->copy()->subSecond());
        $this->assertSame(0, ArticleTranslation::visible()->count());

        $this->travelTo($at);
        $this->assertSame(1, ArticleTranslation::visible()->count());
        $this->assertTrue($t->fresh()->isVisible());
    }

    public function test_a_publish_at_in_another_timezone_is_stored_as_the_same_moment_in_app_time(): void
    {
        $this->freezeSecond();
        $editor = User::factory()->editor()->create();
        $t = $this->translationBy(User::factory()->create(), TranslationStatus::InReview);
        $cairo = now('Africa/Cairo')->addDay()->setTime(8, 0);

        $this->workflow->approve($t, $editor, $cairo);

        $stored = $t->fresh()->published_at;
        $this->assertTrue($stored->equalTo($cairo), 'same instant');
        $this->assertSame(config('app.timezone'), $stored->getTimezone()->getName());
        // The column holds UTC wall-clock time, not Cairo's.
        $this->assertSame($cairo->copy()->utc()->format('Y-m-d H:i:s'), DB::table('article_translations')->where('id', $t->id)->value('published_at'));
    }

    public function test_publish_now_accepts_a_future_publish_at_and_audits_both_steps(): void
    {
        $this->freezeSecond();
        $editor = User::factory()->editor()->create();
        $t = $this->translationBy($editor);
        $at = now()->addDay();

        $this->workflow->publishNow($t, $editor, $at);

        $t->refresh();
        $this->assertSame(TranslationStatus::Published, $t->status);
        $this->assertTrue($t->published_at->equalTo($at));
        $this->assertFalse($t->isVisible());
        $changes = $t->statusChanges()->reorder('id')->get();
        $this->assertSame(['submit', 'approve'], $changes->map(fn ($c) => $c->transition->value)->all());
        $this->assertNull($changes[0]->note);
        $this->assertStringContainsString('Scheduled for', $changes[1]->note);
    }

    public function test_a_past_publish_at_never_back_dates_it_silently_becomes_now(): void
    {
        $this->freezeSecond();
        $editor = User::factory()->editor()->create();
        $t = $this->translationBy(User::factory()->create(), TranslationStatus::InReview);

        $this->workflow->approve($t, $editor, now()->subDays(3));

        $t->refresh();
        $this->assertTrue($t->published_at->equalTo(now()), 'published now, not three days ago');
        $this->assertTrue($t->isVisible());
        $this->assertNull($t->statusChanges()->first()->note, 'nothing was scheduled, so nothing is noted');

        $draft = $this->translationBy($editor);
        $this->workflow->publishNow($draft, $editor, now()->subMinute());
        $this->assertTrue($draft->fresh()->published_at->equalTo(now()));
    }

    public function test_an_exact_now_is_not_a_schedule(): void
    {
        $this->freezeSecond();
        $editor = User::factory()->editor()->create();
        $t = $this->translationBy(User::factory()->create(), TranslationStatus::InReview);

        $this->workflow->approve($t, $editor, now());

        $this->assertTrue($t->fresh()->isVisible());
        $this->assertNull($t->statusChanges()->first()->note);
    }

    public function test_a_schedule_more_than_a_year_away_is_refused_and_nothing_changes(): void
    {
        $this->freezeSecond();
        $editor = User::factory()->editor()->create();
        $t = $this->translationBy(User::factory()->create(), TranslationStatus::InReview);

        try {
            $this->workflow->approve($t, $editor, now()->addYear()->addDays(2));
            $this->fail('A date beyond the one-year limit must be refused.');
        } catch (InvalidTransition $e) {
            $this->assertSame('A story can be scheduled at most one year ahead.', $e->getMessage());
        }

        $this->assertSame(TranslationStatus::InReview, $t->fresh()->status);
        $this->assertNull($t->fresh()->published_at);
        $this->assertSame(0, $t->statusChanges()->count());

        $this->workflow->approve($t, $editor, now()->addYear());
        $this->assertSame(TranslationStatus::Published, $t->fresh()->status, 'exactly one year ahead is allowed');
    }

    public function test_publish_now_with_a_schedule_beyond_the_limit_rolls_the_submission_back_too(): void
    {
        $this->freezeSecond();
        $editor = User::factory()->editor()->create();
        $draft = $this->translationBy($editor);

        $this->expectException(InvalidTransition::class);
        try {
            $this->workflow->publishNow($draft, $editor, now()->addYears(15));
        } finally {
            $this->assertSame(TranslationStatus::Draft, $draft->fresh()->status);
            $this->assertSame(0, $draft->statusChanges()->count());
        }
    }

    public function test_no_publish_at_means_now(): void
    {
        $this->freezeSecond();
        $editor = User::factory()->editor()->create();
        $t = $this->translationBy(User::factory()->create(), TranslationStatus::InReview);

        $this->workflow->approve($t, $editor);
        $this->assertTrue($t->fresh()->published_at->equalTo(now()));
        $this->assertTrue($t->fresh()->isVisible());

        $draft = $this->translationBy($editor);
        $this->workflow->publishNow($draft, $editor);
        $this->assertTrue($draft->fresh()->published_at->equalTo(now()));
    }

    public function test_a_publish_at_does_not_change_who_may_approve(): void
    {
        $author = User::factory()->journalist()->create();
        $inactiveEditor = User::factory()->editor()->inactive()->create();
        $t = $this->translationBy($author, TranslationStatus::InReview);
        $d = $this->translationBy($author);
        $future = now()->addDay();

        foreach ([[$author, 'approve'], [$inactiveEditor, 'approve'], [$author, 'publishNow']] as [$user, $method]) {
            try {
                $method === 'approve' ? $this->workflow->approve($t, $user, $future) : $this->workflow->publishNow($d, $user, $future);
                $this->fail("{$user->role->value} must not be able to {$method}");
            } catch (AuthorizationException) {
                // expected
            }
        }

        $this->assertSame(TranslationStatus::InReview, $t->fresh()->status);
        $this->assertNull($t->fresh()->published_at);
        $this->assertSame(TranslationStatus::Draft, $d->fresh()->status);
        $this->assertSame(0, $d->statusChanges()->count(), 'the submit step of publishNow rolled back too');
    }

    public function test_a_publish_at_does_not_change_which_moves_are_legal(): void
    {
        $editor = User::factory()->editor()->create();
        $draft = $this->translationBy(User::factory()->create());

        $this->expectException(InvalidTransition::class);
        $this->workflow->approve($draft, $editor, now()->addDay());
    }

    public function test_unpublishing_a_story_that_is_still_scheduled_cancels_the_schedule(): void
    {
        $this->freezeSecond();
        $editor = User::factory()->editor()->create();
        $t = $this->translationBy(User::factory()->create(), TranslationStatus::InReview);
        $this->workflow->approve($t, $editor, now()->addDay());

        $this->workflow->archive($t, $editor);

        $t->refresh();
        $this->assertSame(TranslationStatus::Archived, $t->status);
        $this->assertNull($t->published_at, 'it never went live, so it was never "live from" a date');
        $this->assertSame('Scheduled publication cancelled', $t->statusChanges()->first()->note);
    }

    public function test_unpublishing_a_live_story_keeps_the_date_it_went_live(): void
    {
        $editor = User::factory()->editor()->create();
        $t = $this->translationBy(User::factory()->create(), TranslationStatus::Published);
        $wentLive = $t->published_at;

        $this->workflow->archive($t, $editor);

        $this->assertTrue($t->fresh()->published_at->equalTo($wentLive));
        $this->assertNull($t->statusChanges()->first()->note);
    }

    public function test_reopening_clears_the_old_date_so_the_next_approval_without_a_date_goes_live_now(): void
    {
        $this->freezeSecond();
        $editor = User::factory()->editor()->create();
        $t = $this->translationBy(User::factory()->create(), TranslationStatus::Published);
        $this->workflow->archive($t, $editor);
        $this->workflow->reopen($t, $editor);
        $this->assertNull($t->fresh()->published_at);

        $this->travelTo(now()->addDays(4));
        $this->workflow->publishNow($t, $editor);

        $this->assertTrue($t->fresh()->published_at->equalTo(now()), 'a fresh stamp, not the date of the first publication');
    }

    public function test_a_cancelled_schedule_cannot_come_back_on_the_next_approval(): void
    {
        $this->freezeSecond();
        $editor = User::factory()->editor()->create();
        $t = $this->translationBy(User::factory()->create(), TranslationStatus::InReview);
        $this->workflow->approve($t, $editor, now()->addDays(10));
        $this->workflow->archive($t, $editor);
        $this->workflow->reopen($t, $editor);

        $this->workflow->publishNow($t, $editor);

        $this->assertTrue($t->fresh()->isVisible(), 'approved without a date means live now, not on the old scheduled date');
    }

    public function test_each_language_version_has_its_own_lifecycle(): void
    {
        $author = User::factory()->journalist()->create();
        $editor = User::factory()->editor()->create();
        $article = Article::factory()->create(['author_id' => $author->id]);
        $ar = ArticleTranslation::factory()->arabic()->status(TranslationStatus::InReview)->create(['article_id' => $article->id]);
        $en = ArticleTranslation::factory()->status(TranslationStatus::Draft)->create(['article_id' => $article->id]);

        $this->workflow->approve($ar, $editor);

        $this->assertSame(TranslationStatus::Published, $ar->fresh()->status);
        $this->assertSame(TranslationStatus::Draft, $en->fresh()->status);
        $this->assertSame([Locale::Ar], ArticleTranslation::visible()->get()->pluck('locale')->all());
    }
}
