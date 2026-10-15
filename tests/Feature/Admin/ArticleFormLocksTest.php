<?php

namespace Tests\Feature\Admin;

use App\Enums\Locale;
use App\Enums\TranslationStatus;
use App\Filament\Resources\Articles\Pages\EditArticle;
use App\Filament\Resources\Articles\Pages\ListArticles;
use App\Models\Category;
use App\Models\User;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Select;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;

/** What the story form lets a person do with a language version the form shows as locked. */
class ArticleFormLocksTest extends AdminTestCase
{
    private Category $egypt;

    protected function setUp(): void
    {
        parent::setUp();

        $this->egypt = $this->section('Egypt', 'مصر', 1);

        // A private folder for the public disk: Storage::fake() shares one folder between test processes.
        $root = sys_get_temp_dir().'/sisi-news-tests/'.bin2hex(random_bytes(6));
        Storage::set('public', Storage::build(['driver' => 'local', 'root' => $root, 'url' => '/storage']));
        $this->beforeApplicationDestroyed(fn () => File::deleteDirectory($root));
    }

    /** Sends a picture to the story editor's upload endpoint, the way the browser does, and returns what it stored. */
    private function uploadIntoBody(Testable $page, Locale $locale): array
    {
        $page
            ->set("componentFileAttachments.data.{$locale->value}.body", UploadedFile::fake()->image('inline.png', 40, 30))
            ->call('callSchemaComponentMethod', "form.{$locale->value}.body", 'saveUploadedFileAttachmentAndGetUrl');

        return Storage::disk('public')->allFiles('articles/inline');
    }

    public function test_an_editable_draft_still_takes_pictures_in_the_text(): void
    {
        $journalist = $this->journalistIn($this->egypt);
        $article = $this->story($journalist, category: $this->egypt);
        $this->actingAs($journalist);

        $stored = $this->uploadIntoBody(Livewire::test(EditArticle::class, ['record' => $article->getKey()]), Locale::Ar);

        $this->assertCount(1, $stored, 'the upload path works, so the refusals below are real');
    }

    public function test_a_version_whose_section_was_taken_away_accepts_no_upload(): void
    {
        $journalist = $this->journalistIn($this->egypt);
        $article = $this->story($journalist, category: $this->egypt);
        $journalist->allowedCategories()->detach();
        $this->actingAs($journalist);

        $page = Livewire::test(EditArticle::class, ['record' => $article->getKey()])->assertFormFieldDisabled('ar.body');

        $this->assertSame([], $this->uploadIntoBody($page, Locale::Ar));
    }

    public function test_a_version_in_review_or_published_accepts_no_upload_from_its_author(): void
    {
        $journalist = $this->journalistIn($this->egypt);
        $article = $this->story($journalist, TranslationStatus::InReview, TranslationStatus::Published, $this->egypt);
        $this->actingAs($journalist);
        $page = Livewire::test(EditArticle::class, ['record' => $article->getKey()]);

        $this->assertSame([], $this->uploadIntoBody($page, Locale::Ar));
        $this->assertSame([], $this->uploadIntoBody($page, Locale::En));
    }

    // --- a journalist's own story in a section that is no longer theirs ----------------------

    public function test_the_locked_draft_says_why_and_the_page_is_titled_view_not_edit(): void
    {
        $journalist = $this->journalistIn($this->egypt);
        $article = $this->story($journalist, TranslationStatus::Draft, null, $this->egypt);
        $journalist->allowedCategories()->detach();
        $this->actingAs($journalist);

        Livewire::test(EditArticle::class, ['record' => $article->getKey()])
            ->assertSee('This story is in Egypt, which is not one of your sections')
            ->assertSee('Ask an admin to add the section, or ask the Editor-in-chief to finish it.')
            // The English tab has no version, and it cannot be added either: no "fill in a title below".
            ->assertDontSee('Fill in a title below to create one')
            ->assertSee('View article')
            ->assertDontSee('Edit Article');
        $this->assertSame('View article', Livewire::test(EditArticle::class, ['record' => $article->getKey()])->instance()->getTitle());
    }

    public function test_while_the_section_is_theirs_the_draft_reads_as_usual(): void
    {
        $journalist = $this->journalistIn($this->egypt);
        $article = $this->story($journalist, TranslationStatus::Draft, null, $this->egypt);
        $this->actingAs($journalist);

        $page = Livewire::test(EditArticle::class, ['record' => $article->getKey()])
            ->assertDontSee('not one of your sections')
            ->assertSee('Fill in a title below to create one')
            ->assertSee('use Submit for review');
        $this->assertNotSame('View article', $page->instance()->getTitle());
    }

    public function test_a_version_in_review_keeps_its_own_hint_even_when_the_section_is_gone(): void
    {
        $journalist = $this->journalistIn($this->egypt);
        $article = $this->story($journalist, TranslationStatus::InReview, TranslationStatus::Published, $this->egypt);
        $journalist->allowedCategories()->detach();
        $this->actingAs($journalist);

        Livewire::test(EditArticle::class, ['record' => $article->getKey()])
            ->assertSee('Waiting for the Editor-in-chief.')
            ->assertSee('This version is live.')
            ->assertDontSee('which is not one of your sections, so you can read it');
    }

    public function test_reviewers_never_see_the_section_hint_and_the_locked_picture_has_no_remove_or_edit_button(): void
    {
        $journalist = $this->journalistIn($this->egypt);
        $article = $this->story($journalist, TranslationStatus::Draft, null, $this->egypt);
        $journalist->allowedCategories()->detach();

        $this->actingAs(User::factory()->editor()->create());
        Livewire::test(EditArticle::class, ['record' => $article->getKey()])
            ->assertDontSee('not one of your sections')
            ->assertFormFieldExists('hero_image', fn (FileUpload $field): bool => $field->isDeletable() && $field->hasImageEditor());

        $this->actingAs($journalist);
        Livewire::test(EditArticle::class, ['record' => $article->getKey()])
            ->assertFormFieldExists('hero_image', fn (FileUpload $field): bool => ! $field->isDeletable() && ! $field->hasImageEditor());
    }

    public function test_the_journalists_list_names_the_section_and_marks_the_ones_no_longer_theirs(): void
    {
        $sport = $this->section('Sport', 'رياضة', 2);
        $journalist = $this->journalistIn($this->egypt, $sport);
        $kept = $this->story($journalist, category: $this->egypt);
        $lost = $this->story($journalist, category: $sport);
        $journalist->allowedCategories()->detach($sport->getKey());
        $this->actingAs($journalist);

        $list = Livewire::test(ListArticles::class)
            ->assertTableColumnStateSet('category', 'Sport', $lost)
            ->assertTableColumnHasDescription('category', 'Not your section', $lost)
            ->assertTableColumnDoesNotHaveDescription('category', 'Not your section', $kept)
            ->assertTableColumnHasDescription('headline', 'Not your section', $lost)
            ->assertTableActionHasLabel('edit', 'Open', $lost)
            ->assertTableActionHasLabel('edit', 'Edit', $kept);
        $this->assertFalse($list->instance()->getTable()->getColumn('category')->isToggledHiddenByDefault(), 'journalists see the section by default');

        $this->actingAs(User::factory()->editor()->create());
        $reviewerList = Livewire::test(ListArticles::class)
            ->assertTableColumnDoesNotHaveDescription('category', 'Not your section', $lost);
        $this->assertTrue($reviewerList->instance()->getTable()->getColumn('category')->isToggledHiddenByDefault());
    }

    // --- a story credited to an inactive account (every launch story: the desk byline) -------

    public function test_an_admin_edits_a_story_whose_author_is_inactive_and_the_byline_stays(): void
    {
        $desk = User::factory()->journalist()->create(['name' => 'sisi.news Desk', 'is_active' => false]);
        $article = $this->story($desk, TranslationStatus::Published, TranslationStatus::Published, $this->egypt);
        $this->actingAs(User::factory()->admin()->create());

        Livewire::test(EditArticle::class, ['record' => $article->getKey()])
            ->assertFormFieldExists('author_id', fn (Select $field): bool => ($field->getOptions()[$desk->getKey()] ?? null) === 'sisi.news Desk (inactive)')
            ->fillForm(['en' => ['title' => 'Edited by admin']])
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertSame('Edited by admin', $this->version($article, Locale::En)->title);
        $this->assertSame($desk->getKey(), $article->fresh()->author_id);
    }

    public function test_the_editor_in_chief_approves_such_a_story_which_saves_the_form_first(): void
    {
        $desk = User::factory()->journalist()->create(['is_active' => false]);
        $article = $this->story($desk, TranslationStatus::InReview, null, $this->egypt);
        $this->actingAs(User::factory()->editor()->create());

        Livewire::test(EditArticle::class, ['record' => $article->getKey()])
            ->callAction('ar_approve')
            ->assertHasNoFormErrors()
            ->assertNotified('Arabic version published.');

        $this->assertSame(TranslationStatus::Published, $this->version($article, Locale::Ar)->status);
    }

    public function test_other_inactive_accounts_are_not_offered_and_a_story_cannot_be_moved_to_one(): void
    {
        $author = User::factory()->journalist()->create();
        $gone = User::factory()->journalist()->create(['is_active' => false]);
        $article = $this->story($author, TranslationStatus::Draft, null, $this->egypt);
        $this->actingAs(User::factory()->editor()->create());

        Livewire::test(EditArticle::class, ['record' => $article->getKey()])
            ->assertFormFieldExists('author_id', fn (Select $field): bool => ! array_key_exists($gone->getKey(), $field->getOptions())
                && $field->getOptions()[$author->getKey()] === $author->name)
            ->fillForm(['author_id' => $gone->getKey()])
            ->call('save')
            ->assertHasFormErrors(['author_id']);

        $this->assertSame($author->getKey(), $article->fresh()->author_id);
    }

    public function test_the_editor_in_chief_can_still_add_pictures_to_a_version_in_review(): void
    {
        $article = $this->story($this->journalistIn($this->egypt), TranslationStatus::InReview, null, $this->egypt);
        $this->actingAs(User::factory()->editor()->create());

        $this->assertCount(1, $this->uploadIntoBody(Livewire::test(EditArticle::class, ['record' => $article->getKey()]), Locale::Ar));
    }
}
