<?php

namespace Tests\Feature\Admin;

use App\Editorial\EditorialWorkflow;
use App\Editorial\Transition;
use App\Enums\Locale;
use App\Enums\TranslationStatus;
use App\Filament\Resources\Articles\Pages\EditArticle;
use App\Filament\Resources\Articles\Pages\ListArticles;
use App\Filament\Resources\ReviewQueue\Pages\ListReviewQueue;
use App\Filament\Support\PublishAtField;
use App\Filament\Support\StatusPresenter;
use App\Models\ArticleTranslation;
use App\Models\User;
use Carbon\CarbonImmutable;
use Filament\Forms\Components\DateTimePicker;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;

/** Publish-at on Approve and Publish now, the "Scheduled" badge, and the "Unpublished" wording. */
class SchedulingTest extends AdminTestCase
{
    /** A Cairo wall-clock moment $days ahead: what the picker hands over for the browser's local time. */
    private function cairo(int $days = 2, int $hour = 9, int $minute = 30): CarbonImmutable
    {
        return CarbonImmutable::now(StatusPresenter::TIMEZONE)->addDays($days)->setTime($hour, $minute);
    }

    private function picked(CarbonImmutable $at): array
    {
        return [PublishAtField::NAME => $at->format('Y-m-d H:i:s')];
    }

    // --- the article page --------------------------------------------------------------------

    public function test_approve_offers_an_optional_publish_at_picker(): void
    {
        $article = $this->story(User::factory()->journalist()->create(), TranslationStatus::InReview);
        $this->actingAs(User::factory()->editor()->create());

        Livewire::test(EditArticle::class, ['record' => $article->getKey()])
            ->mountAction('ar_approve')
            ->assertSchemaComponentExists(PublishAtField::NAME, 'mountedActionSchema0', fn (DateTimePicker $picker): bool => $picker->getLabel() === 'Publish at (leave empty to publish now)'
                && $picker->getTimezone() === 'Africa/Cairo'
                && ! $picker->isRequired());
    }

    public function test_an_editor_schedules_an_approval_from_the_article_page(): void
    {
        $article = $this->story(User::factory()->journalist()->create(), TranslationStatus::InReview);
        $editor = User::factory()->editor()->create();
        $this->actingAs($editor);
        $at = $this->cairo();

        Livewire::test(EditArticle::class, ['record' => $article->getKey()])
            ->callAction('ar_approve', data: $this->picked($at))
            ->assertHasNoActionErrors()
            ->assertNotified('Arabic version scheduled for '.StatusPresenter::when($at).'.');

        $ar = $this->version($article, Locale::Ar);
        $this->assertSame(TranslationStatus::Published, $ar->status);
        $this->assertTrue($ar->published_at->equalTo($at), 'the Cairo time the editor picked is the stored instant');
        $this->assertFalse($ar->isVisible());
        $this->assertSame(0, ArticleTranslation::visible()->count());
        $this->assertStringContainsString('Scheduled for', $ar->statusChanges()->first()->note);
        $this->assertSame($editor->getKey(), $ar->statusChanges()->first()->user_id);
    }

    public function test_approving_without_a_date_still_publishes_now(): void
    {
        $article = $this->story(User::factory()->journalist()->create(), TranslationStatus::InReview);
        $this->actingAs(User::factory()->editor()->create());

        Livewire::test(EditArticle::class, ['record' => $article->getKey()])
            ->callAction('ar_approve', data: [PublishAtField::NAME => null])
            ->assertHasNoActionErrors()
            ->assertNotified('Arabic version published.');

        $this->assertTrue($this->version($article, Locale::Ar)->isVisible());
    }

    public function test_a_moment_in_the_past_is_refused_by_the_picker(): void
    {
        $article = $this->story(User::factory()->journalist()->create(), TranslationStatus::InReview);
        $this->actingAs(User::factory()->editor()->create());
        // 30 minutes ago on the Cairo clock, which is still "ahead" if the zones were mixed up (Cairo is UTC+2/+3).
        $past = CarbonImmutable::now(StatusPresenter::TIMEZONE)->subMinutes(30);

        Livewire::test(EditArticle::class, ['record' => $article->getKey()])
            ->callAction('ar_approve', data: $this->picked($past))
            ->assertHasActionErrors([PublishAtField::NAME]);

        $this->assertSame(TranslationStatus::InReview, $this->version($article, Locale::Ar)->status);
    }

    public function test_a_moment_beyond_a_year_ahead_is_refused_by_the_picker(): void
    {
        $article = $this->story(User::factory()->journalist()->create(), TranslationStatus::InReview);
        $this->actingAs(User::factory()->editor()->create());

        // 2040 is past the end of the TIMESTAMP column: unguarded it was a database error, not a validation message.
        Livewire::test(EditArticle::class, ['record' => $article->getKey()])
            ->callAction('ar_approve', data: $this->picked(CarbonImmutable::parse('2040-01-01 09:00:00')))
            ->assertHasActionErrors([PublishAtField::NAME]);

        Livewire::test(EditArticle::class, ['record' => $article->getKey()])
            ->callAction('ar_approve', data: $this->picked(CarbonImmutable::now(StatusPresenter::TIMEZONE)->addYear()->addWeek()))
            ->assertHasActionErrors([PublishAtField::NAME]);

        $this->assertSame(TranslationStatus::InReview, $this->version($article, Locale::Ar)->status);

        Livewire::test(EditArticle::class, ['record' => $article->getKey()])
            ->callAction('ar_approve', data: $this->picked(CarbonImmutable::now(StatusPresenter::TIMEZONE)->addMonths(11)))
            ->assertHasNoActionErrors();
        $this->assertSame(TranslationStatus::Published, $this->version($article, Locale::Ar)->status);
    }

    public function test_a_moment_a_little_ahead_on_the_cairo_clock_is_accepted(): void
    {
        $article = $this->story(User::factory()->journalist()->create(), TranslationStatus::InReview);
        $this->actingAs(User::factory()->editor()->create());
        $soon = CarbonImmutable::now(StatusPresenter::TIMEZONE)->addHour();

        Livewire::test(EditArticle::class, ['record' => $article->getKey()])
            ->callAction('ar_approve', data: $this->picked($soon))
            ->assertHasNoActionErrors();

        $this->assertSame(TranslationStatus::Published, $this->version($article, Locale::Ar)->status);
    }

    public function test_publish_now_can_be_scheduled_too(): void
    {
        $editor = User::factory()->editor()->create();
        $article = $this->story($editor, TranslationStatus::Draft, null);
        $this->actingAs($editor);
        $at = $this->cairo(1, 7, 0);

        Livewire::test(EditArticle::class, ['record' => $article->getKey()])
            ->mountAction('ar_publish_now')
            ->assertSchemaComponentExists(PublishAtField::NAME, 'mountedActionSchema0');

        Livewire::test(EditArticle::class, ['record' => $article->getKey()])
            ->callAction('ar_publish_now', data: $this->picked($at))
            ->assertHasNoActionErrors()
            ->assertNotified('Arabic version scheduled for '.StatusPresenter::when($at).'.');

        $ar = $this->version($article, Locale::Ar);
        $this->assertSame(TranslationStatus::Published, $ar->status);
        $this->assertTrue($ar->published_at->equalTo($at));
        $this->assertFalse($ar->isVisible());
    }

    public function test_publish_now_without_a_date_goes_live_at_once(): void
    {
        $editor = User::factory()->editor()->create();
        $article = $this->story($editor, TranslationStatus::Draft, null);
        $this->actingAs($editor);

        Livewire::test(EditArticle::class, ['record' => $article->getKey()])
            ->callAction('ar_publish_now', data: [PublishAtField::NAME => null])
            ->assertNotified('Arabic version published.');

        $this->assertTrue($this->version($article, Locale::Ar)->isVisible());
    }

    public function test_a_journalist_cannot_schedule_anything_even_with_a_crafted_request(): void
    {
        $author = User::factory()->journalist()->create();
        $inReview = $this->story($author, TranslationStatus::InReview, null);
        $draft = $this->story($author, TranslationStatus::Draft, null);
        $this->actingAs($author);

        Livewire::test(EditArticle::class, ['record' => $inReview->getKey()])
            ->assertActionHidden('ar_approve')
            ->call('mountAction', 'ar_approve')
            ->set('mountedActions.0.data.'.PublishAtField::NAME, $this->cairo()->format('Y-m-d H:i:s'))
            ->call('callMountedAction');
        Livewire::test(EditArticle::class, ['record' => $draft->getKey()])
            ->assertActionHidden('ar_publish_now')
            ->call('mountAction', 'ar_publish_now')
            ->call('callMountedAction');

        $this->assertSame(TranslationStatus::InReview, $this->version($inReview, Locale::Ar)->status);
        $this->assertNull($this->version($inReview, Locale::Ar)->published_at);
        $this->assertSame(TranslationStatus::Draft, $this->version($draft, Locale::Ar)->status);
    }

    public function test_cancelling_a_schedule_is_unpublish_then_reopen(): void
    {
        $editor = User::factory()->editor()->create();
        $article = $this->story(User::factory()->journalist()->create(), TranslationStatus::InReview, null);
        $this->actingAs($editor);

        $page = Livewire::test(EditArticle::class, ['record' => $article->getKey()])
            ->callAction('ar_approve', data: $this->picked($this->cairo()))
            ->assertActionVisible('ar_archive')
            ->callAction('ar_archive');

        $ar = $this->version($article, Locale::Ar);
        $this->assertSame(TranslationStatus::Archived, $ar->status);
        $this->assertNull($ar->published_at);

        $page->assertActionVisible('ar_reopen')->callAction('ar_reopen');
        $this->assertSame(TranslationStatus::Draft, $this->version($article, Locale::Ar)->status);
    }

    // --- what staff see ----------------------------------------------------------------------

    public function test_the_story_panel_says_scheduled_for_a_published_version_that_is_not_live_yet(): void
    {
        $editor = User::factory()->editor()->create();
        $article = $this->story(User::factory()->journalist()->create(), TranslationStatus::InReview, TranslationStatus::Published);
        $this->actingAs($editor);
        $at = $this->cairo(3, 18, 45);
        app(EditorialWorkflow::class)->approve($this->version($article, Locale::Ar), $editor, $at);

        Livewire::test(EditArticle::class, ['record' => $article->getKey()])
            ->assertSee('Scheduled for '.$at->format('j M Y, H:i'))
            ->assertSee('goes live by itself')
            ->assertSee('Live since')   // the English version, published a minute ago
            ->assertDontSee('Was live from');
    }

    public function test_the_language_tab_badge_reads_scheduled_not_published(): void
    {
        $this->assertSame('Scheduled', StatusPresenter::shortLabel($this->scheduledTranslation()));
        $this->assertSame('Published', StatusPresenter::shortLabel(ArticleTranslation::factory()->published()->make()));
        $this->assertSame('Unpublished', StatusPresenter::shortLabel(ArticleTranslation::factory()->status(TranslationStatus::Archived)->make()));
        $this->assertNull(StatusPresenter::shortLabel(null));
        $this->assertSame(StatusPresenter::SCHEDULED_COLOR, StatusPresenter::stateColor($this->scheduledTranslation()));
        $this->assertSame('success', StatusPresenter::stateColor(ArticleTranslation::factory()->published()->make()));
        $this->assertNotSame('success', StatusPresenter::SCHEDULED_COLOR, 'a scheduled story must not look live');
    }

    public function test_the_article_list_shows_a_scheduled_badge_with_its_date(): void
    {
        $editor = User::factory()->editor()->create();
        $article = $this->story(User::factory()->journalist()->create(), TranslationStatus::InReview, TranslationStatus::Published);
        $this->actingAs($editor);
        $at = $this->cairo(2, 6, 15);
        app(EditorialWorkflow::class)->approve($this->version($article, Locale::Ar), $editor, $at);

        Livewire::test(ListArticles::class)
            ->assertSee('AR · Scheduled for '.StatusPresenter::when($at))
            ->assertDontSee('AR · Published')
            ->assertSee('EN · Published');
    }

    public function test_the_article_list_does_not_run_a_query_per_scheduled_row(): void
    {
        $editor = User::factory()->editor()->create();
        $this->actingAs($editor);
        $workflow = app(EditorialWorkflow::class);
        $schedule = fn () => $workflow->approve($this->version($this->story(User::factory()->journalist()->create(), TranslationStatus::InReview), Locale::Ar), $editor, $this->cairo());

        foreach (range(1, 2) as $i) {
            $schedule();
        }
        $few = $this->queryCount(fn () => Livewire::test(ListArticles::class)->assertSuccessful());

        foreach (range(1, 6) as $i) {
            $schedule();
        }
        $many = $this->queryCount(fn () => Livewire::test(ListArticles::class)->assertSuccessful());

        $this->assertSame($few, $many);
    }

    public function test_archived_is_shown_to_staff_as_unpublished_while_the_stored_value_is_unchanged(): void
    {
        $this->assertSame('Unpublished', TranslationStatus::Archived->label());
        $this->assertSame('archived', TranslationStatus::Archived->value);
        $this->assertSame('Unpublished', StatusPresenter::label(TranslationStatus::Archived));
        $this->assertSame('Unpublish', Transition::Archive->label());

        $editor = User::factory()->editor()->create();
        $article = $this->story(User::factory()->journalist()->create(), TranslationStatus::Published, null);
        $this->actingAs($editor);

        Livewire::test(EditArticle::class, ['record' => $article->getKey()])
            ->callAction('ar_archive')
            ->assertNotified('Arabic version unpublished.')
            ->assertSee('Unpublished')
            ->assertDontSee('Archived');

        $this->assertSame('archived', DB::table('article_translations')->where('id', $this->version($article, Locale::Ar)->getKey())->value('status'));

        Livewire::test(ListArticles::class)
            ->assertSee('AR · Unpublished')
            ->assertDontSee('Archived');
    }

    public function test_the_status_filter_offers_unpublished_not_archived(): void
    {
        $this->actingAs(User::factory()->editor()->create());
        $filter = Livewire::test(ListArticles::class)->instance()->getTable()->getFilter('status');

        $this->assertSame('Unpublished', $filter->getOptions()['archived']);
        $this->assertNotContains('Archived', $filter->getOptions());
    }

    // --- the review queue --------------------------------------------------------------------

    public function test_an_editor_schedules_an_approval_from_the_review_queue(): void
    {
        $article = $this->story(User::factory()->journalist()->create(), TranslationStatus::InReview, TranslationStatus::Draft);
        $ar = $this->version($article, Locale::Ar);
        $this->actingAs(User::factory()->editor()->create());
        $at = $this->cairo(1, 12, 0);

        Livewire::test(ListReviewQueue::class)
            ->mountTableAction('approve', $ar)
            ->assertSchemaComponentExists(PublishAtField::NAME, 'mountedActionSchema0', fn (DateTimePicker $picker): bool => $picker->getLabel() === 'Publish at (leave empty to publish now)');

        Livewire::test(ListReviewQueue::class)
            ->callTableAction('approve', $ar, data: $this->picked($at))
            ->assertHasNoTableActionErrors()
            ->assertNotified('Version scheduled for '.StatusPresenter::when($at).'.');

        $ar->refresh();
        $this->assertSame(TranslationStatus::Published, $ar->status);
        $this->assertTrue($ar->published_at->equalTo($at));
        $this->assertFalse($ar->isVisible());
    }

    public function test_the_review_queue_still_approves_without_a_date(): void
    {
        $article = $this->story(User::factory()->journalist()->create(), TranslationStatus::InReview, TranslationStatus::Draft);
        $ar = $this->version($article, Locale::Ar);
        $this->actingAs(User::factory()->editor()->create());

        Livewire::test(ListReviewQueue::class)
            ->callTableAction('approve', $ar, data: [PublishAtField::NAME => null])
            ->assertNotified('Version published.');

        $this->assertTrue($ar->fresh()->isVisible());
    }

    public function test_the_review_queue_refuses_a_moment_in_the_past(): void
    {
        $article = $this->story(User::factory()->journalist()->create(), TranslationStatus::InReview, TranslationStatus::Draft);
        $ar = $this->version($article, Locale::Ar);
        $this->actingAs(User::factory()->editor()->create());

        Livewire::test(ListReviewQueue::class)
            ->callTableAction('approve', $ar, data: $this->picked(CarbonImmutable::now(StatusPresenter::TIMEZONE)->subMinutes(30)))
            ->assertHasTableActionErrors([PublishAtField::NAME]);

        $this->assertSame(TranslationStatus::InReview, $ar->fresh()->status);
    }

    public function test_a_journalist_cannot_schedule_from_the_review_queue(): void
    {
        $author = User::factory()->journalist()->create();
        $article = $this->story($author, TranslationStatus::InReview, TranslationStatus::Draft);
        $this->actingAs($author);

        Livewire::test(ListReviewQueue::class)->assertForbidden();

        $this->assertSame(TranslationStatus::InReview, $this->version($article, Locale::Ar)->status);
    }

    // --- the picker's own rules --------------------------------------------------------------

    public function test_the_picker_text_is_read_as_app_time(): void
    {
        $this->assertNull(PublishAtField::parse(null));
        $this->assertNull(PublishAtField::parse(''));
        $this->assertNull(PublishAtField::parse('   '));
        $this->assertNull(PublishAtField::parse(['2026-10-20 10:00:00']), 'anything but text means "no date"');

        $parsed = PublishAtField::parse('2026-10-20 10:00:00');
        $this->assertSame(config('app.timezone'), $parsed->getTimezone()->getName());
        $this->assertSame('2026-10-20 10:00:00', $parsed->format('Y-m-d H:i:s'));
    }

    private function scheduledTranslation(): ArticleTranslation
    {
        return tap(ArticleTranslation::factory()->published()->make(), fn (ArticleTranslation $t) => $t->forceFill(['published_at' => now()->addDay()]));
    }
}
