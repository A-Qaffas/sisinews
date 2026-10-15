<?php

namespace Tests\Feature\Admin;

use App\Editorial\Transition;
use App\Enums\Locale;
use App\Enums\TranslationStatus;
use App\Filament\Resources\Articles\Pages\EditArticle;
use App\Filament\Resources\Articles\Pages\ListArticles;
use App\Models\Article;
use App\Models\ArticleTranslation;
use App\Models\User;
use Livewire\Livewire;

class ArticleWorkflowTest extends AdminTestCase
{
    public function test_a_reviewer_approves_a_version_from_the_article_page(): void
    {
        $editor = User::factory()->editor()->create();
        $article = $this->story(User::factory()->journalist()->create(), TranslationStatus::InReview);
        $this->actingAs($editor);

        Livewire::test(EditArticle::class, ['record' => $article->getKey()])
            ->assertActionVisible('ar_approve')
            ->assertActionVisible('ar_request_changes')
            ->assertActionHidden('en_approve')
            ->callAction('ar_approve')
            ->assertNotified('Arabic version published.');

        $ar = $this->version($article, Locale::Ar);
        $this->assertSame(TranslationStatus::Published, $ar->status);
        $this->assertTrue($ar->isVisible());
        $this->assertSame(TranslationStatus::Draft, $this->version($article, Locale::En)->status);
        $this->assertSame($editor->getKey(), $ar->statusChanges()->first()->user_id);
    }

    public function test_requesting_changes_needs_a_note(): void
    {
        $article = $this->story(User::factory()->journalist()->create(), TranslationStatus::InReview);
        $this->actingAs(User::factory()->editor()->create());

        Livewire::test(EditArticle::class, ['record' => $article->getKey()])
            ->callAction('ar_request_changes', data: ['note' => ''])
            ->assertHasActionErrors(['note' => 'required']);

        $this->assertSame(TranslationStatus::InReview, $this->version($article, Locale::Ar)->status);
    }

    public function test_requesting_changes_with_a_note_sends_the_version_back_and_the_author_sees_the_note(): void
    {
        $author = User::factory()->journalist()->create();
        $article = $this->story($author, TranslationStatus::InReview);
        $this->actingAs(User::factory()->editor()->create());

        Livewire::test(EditArticle::class, ['record' => $article->getKey()])
            ->callAction('ar_request_changes', data: ['note' => 'Add a second source.']);

        $this->assertSame(TranslationStatus::ChangesRequested, $this->version($article, Locale::Ar)->status);

        $this->actingAs($author);
        Livewire::test(EditArticle::class, ['record' => $article->getKey()])
            ->assertSee('Changes requested on the Arabic version')
            ->assertSee('Add a second source.')
            ->assertActionVisible('ar_submit')
            ->callAction('ar_submit');

        $this->assertSame(TranslationStatus::InReview, $this->version($article, Locale::Ar)->status, 'the author fixed it and resubmitted');
    }

    public function test_a_journalist_cannot_approve_even_their_own_story(): void
    {
        $author = User::factory()->journalist()->create();
        $article = $this->story($author, TranslationStatus::InReview);
        $this->actingAs($author);

        Livewire::test(EditArticle::class, ['record' => $article->getKey()])
            ->assertActionHidden('ar_approve')
            ->assertActionHidden('ar_request_changes')
            ->assertActionHidden('ar_publish_now')
            // A crafted request that names the hidden action anyway:
            ->call('mountAction', 'ar_approve')
            ->call('callMountedAction');

        $this->assertSame(TranslationStatus::InReview, $this->version($article, Locale::Ar)->status);
    }

    public function test_a_reviewer_can_publish_a_draft_in_one_step(): void
    {
        $editor = User::factory()->editor()->create();
        $article = $this->story($editor, TranslationStatus::Draft, null);
        $this->actingAs($editor);

        Livewire::test(EditArticle::class, ['record' => $article->getKey()])
            ->assertActionVisible('ar_publish_now')
            ->callAction('ar_publish_now')
            ->assertNotified('Arabic version published.');

        $ar = $this->version($article, Locale::Ar);
        $this->assertSame(TranslationStatus::Published, $ar->status);
        $this->assertSame(
            [Transition::Submit, Transition::Approve],
            $ar->statusChanges()->reorder('id')->get()->map(fn ($c) => $c->transition)->all(),
        );
    }

    public function test_a_journalist_cannot_publish_now(): void
    {
        $author = User::factory()->journalist()->create();
        $article = $this->story($author, TranslationStatus::Draft, null);
        $this->actingAs($author);

        Livewire::test(EditArticle::class, ['record' => $article->getKey()])
            ->assertActionHidden('ar_publish_now')
            ->call('mountAction', 'ar_publish_now')
            ->call('callMountedAction');

        $this->assertSame(TranslationStatus::Draft, $this->version($article, Locale::Ar)->status);
    }

    public function test_a_reviewer_unpublishes_and_reopens_a_version(): void
    {
        $editor = User::factory()->editor()->create();
        $article = $this->story(User::factory()->journalist()->create(), TranslationStatus::Published, null);
        $this->actingAs($editor);

        $page = Livewire::test(EditArticle::class, ['record' => $article->getKey()])
            ->assertActionVisible('ar_archive')
            ->assertActionHidden('ar_reopen')
            ->callAction('ar_archive');
        $this->assertSame(TranslationStatus::Archived, $this->version($article, Locale::Ar)->status);

        $page->assertActionVisible('ar_reopen')->callAction('ar_reopen');
        $this->assertSame(TranslationStatus::Draft, $this->version($article, Locale::Ar)->status);
    }

    public function test_submitting_saves_what_is_on_screen_first(): void
    {
        $author = User::factory()->journalist()->create();
        $article = $this->story($author, TranslationStatus::Draft, null);
        $this->actingAs($author);

        Livewire::test(EditArticle::class, ['record' => $article->getKey()])
            ->fillForm(['ar' => ['title' => 'عنوان جديد لم يحفظ بعد']])
            ->callAction('ar_submit');

        $ar = $this->version($article, Locale::Ar);
        $this->assertSame('عنوان جديد لم يحفظ بعد', $ar->title);
        $this->assertSame(TranslationStatus::InReview, $ar->status);
    }

    public function test_an_incomplete_version_is_refused_with_a_notification_not_an_error(): void
    {
        $author = User::factory()->journalist()->create();
        $article = $this->story($author, TranslationStatus::Draft, null);
        $this->actingAs($author);

        Livewire::test(EditArticle::class, ['record' => $article->getKey()])
            ->fillForm(['ar' => ['body' => '']])
            ->callAction('ar_submit')
            ->assertNotified('Nothing was changed');

        $this->assertSame(TranslationStatus::Draft, $this->version($article, Locale::Ar)->status);
    }

    public function test_a_validation_problem_stops_the_move_and_nothing_is_changed(): void
    {
        $author = User::factory()->journalist()->create();
        $article = $this->story($author, TranslationStatus::Draft, null);
        $this->actingAs($author);

        Livewire::test(EditArticle::class, ['record' => $article->getKey()])
            ->fillForm(['ar' => ['title' => '']])
            ->callAction('ar_submit')
            ->assertHasErrors(['data.ar.title'])
            ->assertNotified('Nothing was changed');

        $this->assertSame(TranslationStatus::Draft, $this->version($article, Locale::Ar)->status);
    }

    public function test_the_workflow_actions_follow_each_languages_own_status(): void
    {
        $editor = User::factory()->editor()->create();
        $article = $this->story(User::factory()->journalist()->create(), TranslationStatus::Published, TranslationStatus::InReview);
        $this->actingAs($editor);

        Livewire::test(EditArticle::class, ['record' => $article->getKey()])
            ->assertActionVisible('ar_archive')
            ->assertActionHidden('ar_approve')
            ->assertActionVisible('en_approve')
            ->assertActionHidden('en_archive');
    }

    public function test_deleting_an_article_takes_its_live_versions_off_the_site_first(): void
    {
        $editor = User::factory()->editor()->create();
        $article = $this->story(User::factory()->journalist()->create(), TranslationStatus::Published, TranslationStatus::Draft);
        $this->actingAs($editor);

        Livewire::test(EditArticle::class, ['record' => $article->getKey()])
            ->callAction('delete');

        $this->assertSoftDeleted($article);
        $this->assertSame(TranslationStatus::Archived, $this->version($article, Locale::Ar)->status);
        $this->assertSame(TranslationStatus::Draft, $this->version($article, Locale::En)->status);
        $this->assertSame(0, ArticleTranslation::visible()->count(), 'nothing of a deleted story stays readable');
    }

    public function test_a_journalist_cannot_delete_an_article(): void
    {
        $author = User::factory()->journalist()->create();
        $article = $this->story($author);
        $this->actingAs($author);

        Livewire::test(EditArticle::class, ['record' => $article->getKey()])
            ->assertActionHidden('delete');

        $this->assertNotSoftDeleted(Article::class, ['id' => $article->getKey()]);
    }

    public function test_only_an_admin_sees_trashed_articles_and_can_restore_them(): void
    {
        $article = $this->story(User::factory()->journalist()->create());
        $article->delete();

        $this->actingAs(User::factory()->editor()->create());
        Livewire::test(ListArticles::class)
            ->assertTableFilterHidden('trashed');

        $this->actingAs(User::factory()->admin()->create());
        Livewire::test(ListArticles::class)
            ->filterTable('trashed', false)
            ->assertCanSeeTableRecords([$article])
            ->callTableAction('restore', $article);

        $this->assertNotSoftDeleted(Article::class, ['id' => $article->getKey()]);
    }
}
