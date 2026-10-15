<?php

namespace Tests\Feature\Admin;

use App\Editorial\EditorialWorkflow;
use App\Enums\Locale;
use App\Enums\TranslationStatus;
use App\Filament\Resources\Articles\ArticleResource;
use App\Filament\Resources\Articles\Pages\EditArticle;
use App\Filament\Resources\Articles\Pages\ListArticles;
use App\Filament\Resources\ReviewQueue\ReviewQueueResource;
use App\Filament\Widgets\NewsroomStats;
use App\Models\User;
use Filament\Schemas\Components\Tabs;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;

/** What a newsroom user sees and is told, found while walking the panel in a browser. */
class StaffUsabilityTest extends AdminTestCase
{
    public function test_an_author_is_told_why_a_version_in_review_is_locked(): void
    {
        $author = User::factory()->journalist()->create();
        $article = $this->story($author, TranslationStatus::InReview, TranslationStatus::Draft);
        $this->actingAs($author);

        Livewire::test(EditArticle::class, ['record' => $article->getKey()])
            ->assertSee('Waiting for the Editor-in-chief')
            ->assertSee('Submit for review at the top right');
    }

    public function test_an_author_is_told_a_live_version_is_the_editor_in_chiefs_to_change(): void
    {
        $author = User::factory()->journalist()->create();
        $article = $this->story($author, TranslationStatus::Published, TranslationStatus::Draft);
        $this->actingAs($author);

        Livewire::test(EditArticle::class, ['record' => $article->getKey()])
            ->assertSee('Only the Editor-in-chief can change it or take it down');

        $this->actingAs(User::factory()->editor()->create());

        Livewire::test(EditArticle::class, ['record' => $article->getKey()])
            ->assertDontSee('Only the Editor-in-chief can change it or take it down');
    }

    public function test_a_reviewers_note_is_laid_out_in_its_own_direction(): void
    {
        $author = User::factory()->journalist()->create();
        $editor = User::factory()->editor()->create();
        $article = $this->story($author, TranslationStatus::InReview, TranslationStatus::Draft);
        app(EditorialWorkflow::class)->requestChanges($this->version($article, Locale::Ar), $editor, 'يرجى التأكد من الرقم مع بيان Samsung الرسمي.');
        $this->actingAs($author);

        Livewire::test(EditArticle::class, ['record' => $article->getKey()])
            ->assertSeeHtml('<div dir="auto">يرجى التأكد من الرقم مع بيان Samsung الرسمي.</div>');
    }

    public function test_the_story_opens_on_the_language_waiting_for_the_author(): void
    {
        $author = User::factory()->journalist()->create();
        $article = $this->story($author, TranslationStatus::Published, TranslationStatus::ChangesRequested);
        $this->actingAs($author);

        $this->assertSame(2, $this->activeTab(Livewire::test(EditArticle::class, ['record' => $article->getKey()])));
    }

    public function test_the_story_opens_on_the_language_waiting_for_the_reviewer(): void
    {
        $author = User::factory()->journalist()->create();
        $article = $this->story($author, TranslationStatus::Draft, TranslationStatus::InReview);
        $this->actingAs(User::factory()->editor()->create());

        $this->assertSame(2, $this->activeTab(Livewire::test(EditArticle::class, ['record' => $article->getKey()])));
    }

    public function test_the_story_opens_on_arabic_when_nothing_is_waiting_for_this_person(): void
    {
        $author = User::factory()->journalist()->create();
        $article = $this->story($author, TranslationStatus::Published, TranslationStatus::Published);
        $this->actingAs($author);

        $this->assertSame(1, $this->activeTab(Livewire::test(EditArticle::class, ['record' => $article->getKey()])));
    }

    public function test_the_list_shows_both_languages_status_in_one_column(): void
    {
        $author = User::factory()->journalist()->create();
        $this->story($author, TranslationStatus::Published, TranslationStatus::Draft);
        $this->actingAs($author);

        Livewire::test(ListArticles::class)
            ->assertSee('AR · Published')
            ->assertSee('EN · Draft');
    }

    public function test_a_missing_language_is_labelled_in_the_list(): void
    {
        $author = User::factory()->journalist()->create();
        $this->story($author, TranslationStatus::Draft, null);
        $this->actingAs($author);

        Livewire::test(ListArticles::class)->assertSee('EN · No version');
    }

    public function test_a_forbidden_cms_page_sends_staff_back_to_the_cms(): void
    {
        $this->actingAs(User::factory()->journalist()->create())
            ->get('/admin/users')
            ->assertForbidden()
            ->assertSee('Back to the newsroom')
            ->assertSee(url('/admin'), false);
    }

    public function test_the_editor_styles_let_blocks_inherit_the_language_direction(): void
    {
        $css = $this->actingAs(User::factory()->journalist()->create())->get('/admin')->assertOk()->getContent();

        $this->assertMatchesRegularExpression('/\.fi-fo-rich-editor \.ProseMirror[^{]*\{[^}]*unicode-bidi: normal/', $css);
        $this->assertDoesNotMatchRegularExpression('/\.ProseMirror[^{]*\{[^}]*unicode-bidi: plaintext/', $css, 'plaintext flips an Arabic sentence that opens with a Latin brand');
    }

    public function test_dashboard_cards_lead_to_the_list_they_count(): void
    {
        $articles = ArticleResource::getUrl('index');

        $this->actingAs(User::factory()->editor()->create());
        $reviewer = collect(invade(Livewire::test(NewsroomStats::class)->instance())->getStats())->map(fn ($stat) => $stat->getUrl())->all();
        $this->assertSame([$articles, ReviewQueueResource::getUrl('index'), $articles], $reviewer);

        $this->actingAs(User::factory()->journalist()->create());
        $author = collect(invade(Livewire::test(NewsroomStats::class)->instance())->getStats())->map(fn ($stat) => $stat->getUrl())->all();
        $this->assertSame([$articles, $articles, $articles], $author);
    }

    private function activeTab(Testable $page): int
    {
        $tabs = $page->instance()->getSchema('form')->getComponent(fn ($component): bool => $component instanceof Tabs);

        return $tabs->getActiveTab();
    }
}
