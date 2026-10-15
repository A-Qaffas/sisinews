<?php

namespace Tests\Feature\Admin;

use App\Editorial\EditorialWorkflow;
use App\Editorial\Transition;
use App\Enums\Locale;
use App\Enums\TranslationStatus;
use App\Filament\Resources\Articles\Pages\CreateArticle;
use App\Filament\Resources\Articles\Pages\EditArticle;
use App\Filament\Resources\Articles\Support\ArticleWriter;
use App\Filament\Resources\ReviewQueue\Pages\ListReviewQueue;
use App\Filament\Support\PublishAtField;
use App\Models\Article;
use App\Models\ArticleTranslation;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * Regression guard for the newsroom rule: a journalist posts a story, only the Editor-in-chief (or an admin)
 * publishes it. Assigning sections to journalists must never widen that, so every way a journalist could
 * try to approve, publish, schedule or "publish now" is attempted here, with and without a crafted request.
 */
class RoleJournalistCannotPublishTest extends AdminTestCase
{
    private function futureCairoTime(): string
    {
        return now()->addDays(2)->format('Y-m-d H:i:s');
    }

    private function assertNothingMoved(Article $article, TranslationStatus $arabic, ?TranslationStatus $english = null): void
    {
        $this->assertSame($arabic, $this->version($article, Locale::Ar)->status);
        $this->assertNull($this->version($article, Locale::Ar)->published_at);
        if ($english !== null) {
            $this->assertSame($english, $this->version($article, Locale::En)->status);
        }
        $this->assertSame(0, ArticleTranslation::visible()->count(), 'nothing is readable on the public site');
        $this->assertSame(0, ArticleTranslation::query()->where('status', TranslationStatus::Published->value)->count());
    }

    /** @return array<string, array{0: TranslationStatus}> */
    public static function statesWorthTrying(): array
    {
        return [
            'draft' => [TranslationStatus::Draft],
            'in review' => [TranslationStatus::InReview],
            'changes requested' => [TranslationStatus::ChangesRequested],
            'unpublished' => [TranslationStatus::Archived],
        ];
    }

    public function test_the_policy_gives_a_journalist_no_move_that_publishes_or_changes_a_live_version(): void
    {
        $author = User::factory()->journalist()->create();
        $article = $this->story($author, TranslationStatus::InReview, TranslationStatus::Published);

        foreach (Locale::cases() as $locale) {
            $translation = $this->version($article, $locale)->setRelation('article', $article);

            foreach (['approve', 'requestChanges', 'archive', 'reopen', 'delete'] as $ability) {
                $this->assertFalse($author->can($ability, $translation), "{$ability} ({$locale->value})");
            }
        }

        $draft = $this->story($author, TranslationStatus::Draft, null);
        $own = $this->version($draft, Locale::Ar)->setRelation('article', $draft);
        $this->assertTrue($author->can('submit', $own), 'the one thing a journalist can do with a draft is send it in');
        $this->assertFalse($author->can('approve', $own));
    }

    #[DataProvider('statesWorthTrying')]
    public function test_the_workflow_refuses_a_journalist_every_publishing_move_including_a_schedule(TranslationStatus $status): void
    {
        $author = User::factory()->journalist()->create();
        $article = $this->story($author, $status, null);
        $translation = $this->version($article, Locale::Ar)->setRelation('article', $article);
        $workflow = app(EditorialWorkflow::class);

        $this->assertSame(
            [],
            array_values(array_filter($workflow->availableFor($translation, $author), fn (Transition $move): bool => $move !== Transition::Submit)),
            'at most "submit" is ever on offer to a journalist',
        );

        foreach ([
            'approve' => fn () => $workflow->approve($translation, $author),
            'approve with a schedule' => fn () => $workflow->approve($translation, $author, now()->addDays(2)),
            'publish now' => fn () => $workflow->publishNow($translation, $author),
            'publish now with a schedule' => fn () => $workflow->publishNow($translation, $author, now()->addDays(2)),
            'archive' => fn () => $workflow->archive($translation, $author),
            'reopen' => fn () => $workflow->reopen($translation, $author),
        ] as $name => $attempt) {
            try {
                $attempt();
                $this->fail("{$name} was allowed for a journalist");
            } catch (AuthorizationException) {
                $this->addToAssertionCount(1);
            }
        }

        $this->assertSame($status, $translation->fresh()->status);
        $this->assertNull($translation->fresh()->published_at);
        $this->assertSame(0, $translation->statusChanges()->count(), 'a refused move leaves no audit row');
    }

    public function test_a_journalist_can_submit_but_then_cannot_approve_their_own_story(): void
    {
        $author = User::factory()->journalist()->create();
        $article = $this->story($author, TranslationStatus::Draft, null);
        $this->actingAs($author);

        Livewire::test(EditArticle::class, ['record' => $article->getKey()])
            ->assertActionVisible('ar_submit')
            ->callAction('ar_submit')
            ->assertActionHidden('ar_approve')
            ->assertActionHidden('ar_publish_now')
            ->assertActionHidden('ar_request_changes');

        $this->assertSame(TranslationStatus::InReview, $this->version($article, Locale::Ar)->status);
        $this->assertSame(1, $this->version($article, Locale::Ar)->statusChanges()->count(), 'only the submission was recorded');
        $this->assertSame(0, ArticleTranslation::visible()->count());
    }

    public function test_a_forced_approve_action_does_not_publish_even_with_a_schedule(): void
    {
        $author = User::factory()->journalist()->create();
        $article = $this->story($author, TranslationStatus::InReview, TranslationStatus::Draft);
        $this->actingAs($author);

        Livewire::test(EditArticle::class, ['record' => $article->getKey()])
            ->assertActionHidden('ar_approve')
            // The plain forced request ...
            ->call('mountAction', 'ar_approve')
            ->call('callMountedAction');
        $this->assertNothingMoved($article, TranslationStatus::InReview, TranslationStatus::Draft);

        // ... and one that writes the mounted action, its schedule included, straight into the component state.
        Livewire::test(EditArticle::class, ['record' => $article->getKey()])
            ->set('mountedActions', [[
                'name' => 'ar_approve',
                'arguments' => [],
                'context' => [],
                'data' => [PublishAtField::NAME => $this->futureCairoTime()],
            ]])
            ->call('callMountedAction');
        $this->assertNothingMoved($article, TranslationStatus::InReview, TranslationStatus::Draft);
        $this->assertSame(0, $this->version($article, Locale::Ar)->statusChanges()->count());
    }

    #[DataProvider('statesWorthTrying')]
    public function test_a_forced_publish_now_action_does_not_publish_or_schedule(TranslationStatus $status): void
    {
        $author = User::factory()->journalist()->create();
        $article = $this->story($author, $status, null);
        $this->actingAs($author);

        Livewire::test(EditArticle::class, ['record' => $article->getKey()])
            ->assertActionHidden('ar_publish_now')
            ->call('mountAction', 'ar_publish_now')
            ->call('callMountedAction');
        $this->assertNothingMoved($article, $status);

        Livewire::test(EditArticle::class, ['record' => $article->getKey()])
            ->set('mountedActions', [[
                'name' => 'ar_publish_now',
                'arguments' => [],
                'context' => [],
                'data' => [PublishAtField::NAME => $this->futureCairoTime()],
            ]])
            ->call('callMountedAction');
        $this->assertNothingMoved($article, $status);
        $this->assertSame(0, $this->version($article, Locale::Ar)->statusChanges()->count());
    }

    public function test_the_other_workflow_actions_are_closed_to_a_journalist_too(): void
    {
        $author = User::factory()->journalist()->create();
        $article = $this->story($author, TranslationStatus::InReview, TranslationStatus::Draft);
        $this->actingAs($author);

        foreach (['ar_request_changes', 'ar_archive', 'ar_reopen', 'en_approve', 'en_publish_now', 'en_archive'] as $name) {
            Livewire::test(EditArticle::class, ['record' => $article->getKey()])
                ->assertActionHidden($name)
                ->call('mountAction', $name)
                ->call('callMountedAction');
        }

        $this->assertNothingMoved($article, TranslationStatus::InReview, TranslationStatus::Draft);
    }

    public function test_status_and_publication_date_cannot_be_smuggled_in_through_the_form_data(): void
    {
        $author = $this->journalistIn();
        $category = $this->category();
        $author->allowedCategories()->attach($category->getKey());
        $this->actingAs($author);

        Livewire::test(CreateArticle::class)
            ->fillForm(['category_id' => $category->getKey(), 'ar' => ['title' => 'عنوان', 'body' => '<p>نص</p>']])
            ->set('data.status', 'published')
            ->set('data.ar.status', 'published')
            ->set('data.ar.published_at', now()->subDay()->toDateTimeString())
            ->set('data.en.published_at', $this->futureCairoTime())
            ->call('create');

        $article = Article::query()->firstOrFail();
        $this->assertNothingMoved($article, TranslationStatus::Draft);

        Livewire::test(EditArticle::class, ['record' => $article->getKey()])
            ->set('data.ar.status', 'published')
            ->set('data.ar.published_at', $this->futureCairoTime())
            ->call('save');

        $this->assertNothingMoved($article, TranslationStatus::Draft);

        app(ArticleWriter::class)->update($article->fresh(), [
            'ar' => ['title' => 'عنوان آخر', 'status' => 'published', 'published_at' => $this->futureCairoTime()],
        ], $author);
        $this->assertNothingMoved($article, TranslationStatus::Draft);
    }

    public function test_a_journalist_cannot_reach_the_review_queue_or_approve_from_it(): void
    {
        $author = User::factory()->journalist()->create();
        $article = $this->story($author, TranslationStatus::InReview, null);
        $translation = $this->version($article, Locale::Ar);
        $this->actingAs($author);

        Livewire::test(ListReviewQueue::class)->assertForbidden();
        $this->get('/admin/review-queue')->assertForbidden();

        $this->assertNothingMoved($article, TranslationStatus::InReview);
        $this->assertSame(TranslationStatus::InReview, $translation->fresh()->status);
    }

    public function test_the_same_requests_do_work_for_the_editor_in_chief(): void
    {
        $editor = User::factory()->editor()->create();
        $article = $this->story(User::factory()->journalist()->create(), TranslationStatus::InReview, TranslationStatus::Draft);
        $this->actingAs($editor);

        Livewire::test(EditArticle::class, ['record' => $article->getKey()])
            ->assertActionVisible('ar_approve')
            ->callAction('ar_approve', data: [PublishAtField::NAME => $this->futureCairoTime()])
            ->assertActionVisible('en_publish_now')
            ->callAction('en_publish_now');

        $arabic = $this->version($article, Locale::Ar);
        $this->assertSame(TranslationStatus::Published, $arabic->status);
        $this->assertTrue($arabic->published_at->isFuture(), 'the Editor-in-chief can schedule');
        $this->assertSame(TranslationStatus::Published, $this->version($article, Locale::En)->status);
        $this->assertSame(1, ArticleTranslation::visible()->count(), 'the English version is live, the scheduled Arabic one is not yet');
    }
}
