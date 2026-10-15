<?php

namespace Tests\Feature\Admin;

use App\Enums\Locale;
use App\Enums\TranslationStatus;
use App\Filament\Resources\Articles\ArticleResource;
use App\Filament\Resources\Articles\Pages\EditArticle;
use App\Filament\Resources\ReviewQueue\Pages\ListReviewQueue;
use App\Filament\Resources\ReviewQueue\ReviewQueueResource;
use App\Filament\Widgets\NewsroomStats;
use App\Models\User;
use Livewire\Livewire;

class ReviewQueueTest extends AdminTestCase
{
    public function test_an_editor_sees_only_versions_awaiting_review(): void
    {
        $author = User::factory()->journalist()->create();
        $waiting = $this->story($author, TranslationStatus::InReview, TranslationStatus::Draft);
        $this->story($author, TranslationStatus::Published, TranslationStatus::Draft);
        $this->actingAs(User::factory()->editor()->create());

        Livewire::test(ListReviewQueue::class)
            ->assertCanSeeTableRecords([$this->version($waiting, Locale::Ar)])
            ->assertCanNotSeeTableRecords([$this->version($waiting, Locale::En)])
            ->assertCountTableRecords(1);
    }

    public function test_the_navigation_badge_counts_versions_in_review(): void
    {
        $author = User::factory()->journalist()->create();
        $this->story($author, TranslationStatus::InReview, TranslationStatus::InReview);
        $this->story($author, TranslationStatus::Draft, TranslationStatus::Published);
        $this->actingAs(User::factory()->editor()->create());

        $this->assertSame('2', ReviewQueueResource::getNavigationBadge());
    }

    public function test_the_badge_is_hidden_when_the_queue_is_empty(): void
    {
        $this->actingAs(User::factory()->editor()->create());

        $this->assertNull(ReviewQueueResource::getNavigationBadge());
    }

    public function test_an_editor_approves_inline(): void
    {
        $article = $this->story(User::factory()->journalist()->create(), TranslationStatus::InReview, TranslationStatus::Draft);
        $ar = $this->version($article, Locale::Ar);
        $this->actingAs(User::factory()->editor()->create());

        Livewire::test(ListReviewQueue::class)
            ->callTableAction('approve', $ar)
            ->assertNotified('Version published.');

        $this->assertSame(TranslationStatus::Published, $ar->fresh()->status);
    }

    public function test_an_editor_requests_changes_inline_with_a_note(): void
    {
        $article = $this->story(User::factory()->journalist()->create(), TranslationStatus::InReview, TranslationStatus::Draft);
        $ar = $this->version($article, Locale::Ar);
        $this->actingAs(User::factory()->editor()->create());

        Livewire::test(ListReviewQueue::class)
            ->callTableAction('requestChanges', $ar, data: ['note' => 'The headline overstates the facts.']);

        $ar->refresh();
        $this->assertSame(TranslationStatus::ChangesRequested, $ar->status);
        $this->assertSame('The headline overstates the facts.', $ar->latestChangeRequest->note);
    }

    public function test_requesting_changes_inline_without_a_note_is_rejected(): void
    {
        $article = $this->story(User::factory()->journalist()->create(), TranslationStatus::InReview, TranslationStatus::Draft);
        $ar = $this->version($article, Locale::Ar);
        $this->actingAs(User::factory()->editor()->create());

        Livewire::test(ListReviewQueue::class)
            ->callTableAction('requestChanges', $ar, data: ['note' => ''])
            ->assertHasTableActionErrors(['note' => 'required']);

        $this->assertSame(TranslationStatus::InReview, $ar->fresh()->status);
    }

    public function test_the_queue_does_not_run_a_query_per_row(): void
    {
        $author = User::factory()->journalist()->create();
        $this->actingAs(User::factory()->editor()->create());

        foreach (range(1, 3) as $i) {
            $this->story($author, TranslationStatus::InReview, TranslationStatus::InReview);
        }
        $few = $this->queryCount(fn () => Livewire::test(ListReviewQueue::class)->assertSuccessful());

        foreach (range(1, 8) as $i) {
            $this->story($author, TranslationStatus::InReview, TranslationStatus::InReview);
        }
        $many = $this->queryCount(fn () => Livewire::test(ListReviewQueue::class)->assertSuccessful());

        $this->assertSame($few, $many);
    }

    public function test_a_journalist_cannot_open_the_queue(): void
    {
        $this->actingAs(User::factory()->journalist()->create());

        Livewire::test(ListReviewQueue::class)->assertForbidden();
        $this->assertFalse(ReviewQueueResource::canViewAny());
    }

    public function test_a_story_deleted_while_in_review_leaves_the_queue_the_badge_and_the_dashboard_count(): void
    {
        $author = User::factory()->journalist()->create();
        $doomed = $this->story($author, TranslationStatus::InReview, TranslationStatus::Draft);
        $kept = $this->story($author, TranslationStatus::InReview, TranslationStatus::Draft);
        $editor = User::factory()->editor()->create();
        $this->actingAs($editor);

        Livewire::test(EditArticle::class, ['record' => $doomed->getKey()])->callAction('delete');
        $this->assertSoftDeleted($doomed);

        // Used to be a 500 for every reviewer: the row's story (and its section) was gone.
        $this->get(ReviewQueueResource::getUrl('index'))->assertOk();
        Livewire::test(ListReviewQueue::class)
            ->assertCanSeeTableRecords([$this->version($kept, Locale::Ar)])
            ->assertCountTableRecords(1);
        $this->assertSame('1', ReviewQueueResource::getNavigationBadge());

        $awaiting = collect(invade(Livewire::test(NewsroomStats::class)->instance())->getStats())
            ->first(fn ($stat) => (string) $stat->getLabel() === 'Awaiting review');
        $this->assertSame('1', (string) $awaiting->getValue());
    }

    public function test_deleting_a_story_sends_its_version_in_review_back_with_a_note(): void
    {
        $article = $this->story(User::factory()->journalist()->create(), TranslationStatus::InReview, TranslationStatus::Published);
        $this->actingAs(User::factory()->editor()->create());

        Livewire::test(EditArticle::class, ['record' => $article->getKey()])->callAction('delete');

        $ar = $this->version($article, Locale::Ar);
        $this->assertSame(TranslationStatus::ChangesRequested, $ar->status, 'nothing is left waiting on a deleted story');
        $this->assertSame(ArticleResource::DELETED_WHILE_IN_REVIEW_NOTE, $ar->latestChangeRequest->note);
        $this->assertSame(TranslationStatus::Archived, $this->version($article, Locale::En)->status);
    }

    public function test_an_odd_row_whose_story_is_gone_still_renders(): void
    {
        $article = $this->story(User::factory()->journalist()->create(), TranslationStatus::InReview, null);
        // Deleted without the panel's delete button (tinker, an old release): the version is still in review.
        $article->delete();
        $this->actingAs(User::factory()->admin()->create());

        $this->get(ReviewQueueResource::getUrl('index'))->assertOk();
        $this->assertNull(ReviewQueueResource::getNavigationBadge());

        $column = Livewire::test(ListReviewQueue::class)->instance()->getTable()->getColumn('category');
        $orphan = $this->version($article, Locale::Ar)->setRelation('article', null);
        $this->assertSame('-', $column->record($orphan)->getState());
    }

    public function test_the_queue_link_opens_the_article(): void
    {
        $article = $this->story(User::factory()->journalist()->create(), TranslationStatus::InReview, TranslationStatus::Draft);
        $this->actingAs(User::factory()->editor()->create());

        $this->get(ArticleResource::getUrl('edit', ['record' => $article]))->assertOk();
    }
}
