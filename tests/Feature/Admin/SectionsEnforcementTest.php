<?php

namespace Tests\Feature\Admin;

use App\Enums\Locale;
use App\Enums\TranslationStatus;
use App\Filament\Resources\Articles\ArticleResource;
use App\Filament\Resources\Articles\Pages\CreateArticle;
use App\Filament\Resources\Articles\Pages\EditArticle;
use App\Filament\Resources\Articles\Pages\ListArticles;
use App\Filament\Resources\Articles\Support\ArticleWriter;
use App\Filament\Resources\Articles\Support\NoSectionsNotice as Notice;
use App\Filament\Widgets\NoSectionsNotice;
use App\Models\Article;
use App\Models\Category;
use App\Models\User;
use Filament\Forms\Components\Select;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\DataProvider;

/** A journalist writes only in the sections an admin assigned; editors-in-chief and admins write in all of them. */
class SectionsEnforcementTest extends AdminTestCase
{
    private Category $egypt;

    private Category $sport;

    private Category $economy;

    protected function setUp(): void
    {
        parent::setUp();

        $this->egypt = $this->section('Egypt', 'مصر', 1);
        $this->sport = $this->section('Sport', 'رياضة', 2);
        $this->economy = $this->section('Economy', 'اقتصاد', 3);
    }

    /** @return array<string, mixed> */
    private function payload(Category|int|string|array|null $category, string $title = 'A headline'): array
    {
        return [
            'category_id' => $category instanceof Category ? $category->getKey() : $category,
            'en' => ['title' => $title, 'body' => '<p>Text</p>'],
        ];
    }

    private function assertSectionRefused(callable $attempt): void
    {
        try {
            $attempt();
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('data.category_id', $exception->errors());

            return;
        }

        $this->fail('The section should have been refused.');
    }

    /** @return list<int> */
    private function optionIds(Select $field): array
    {
        return array_map('intval', array_keys($field->getOptions()));
    }

    // --- (a) what the form offers -----------------------------------------------------------

    public function test_a_journalist_is_offered_only_the_sections_assigned_to_them(): void
    {
        $this->actingAs($this->journalistIn($this->egypt, $this->economy));

        Livewire::test(CreateArticle::class)
            ->assertFormFieldExists('category_id', fn (Select $field): bool => $this->optionIds($field) === [$this->egypt->getKey(), $this->economy->getKey()]
                && $field->getOptions()[$this->egypt->getKey()] === 'Egypt / مصر');
    }

    public function test_a_journalist_with_one_section_starts_in_it_and_one_with_several_chooses(): void
    {
        $this->actingAs($this->journalistIn($this->sport));
        Livewire::test(CreateArticle::class)->assertFormSet(['category_id' => $this->sport->getKey()]);

        $this->actingAs($this->journalistIn($this->egypt, $this->economy));
        Livewire::test(CreateArticle::class)->assertFormSet(['category_id' => null]);

        $this->actingAs(User::factory()->editor()->create());
        Livewire::test(CreateArticle::class)->assertFormSet(['category_id' => null]);
    }

    #[DataProvider('reviewers')]
    public function test_a_reviewer_is_offered_every_section(string $role): void
    {
        $this->actingAs(User::factory()->create(['role' => $role]));

        Livewire::test(CreateArticle::class)
            ->assertFormFieldExists('category_id', fn (Select $field): bool => $this->optionIds($field) === [$this->egypt->getKey(), $this->sport->getKey(), $this->economy->getKey()]);
    }

    public static function reviewers(): array
    {
        return ['editor-in-chief' => ['editor'], 'admin' => ['admin']];
    }

    public function test_editing_a_story_still_names_its_section_and_offers_only_assigned_ones(): void
    {
        $journalist = $this->journalistIn($this->egypt, $this->sport);
        $article = $this->story($journalist, category: $this->egypt);
        $this->actingAs($journalist);

        Livewire::test(EditArticle::class, ['record' => $article->getKey()])
            ->assertFormFieldExists('category_id', fn (Select $field): bool => $this->optionIds($field) === [$this->egypt->getKey(), $this->sport->getKey()]);
    }

    // --- (b) the writer re-checks -----------------------------------------------------------

    public function test_a_journalist_creates_a_story_in_an_assigned_section(): void
    {
        $journalist = $this->journalistIn($this->egypt);
        $this->actingAs($journalist);

        Livewire::test(CreateArticle::class)
            ->fillForm($this->payload($this->egypt))
            ->call('create')
            ->assertHasNoFormErrors();

        $article = Article::query()->firstOrFail();
        $this->assertSame($this->egypt->getKey(), $article->category_id);
        $this->assertSame($journalist->getKey(), $article->author_id);
    }

    public function test_a_crafted_create_into_a_forbidden_section_is_rejected(): void
    {
        $this->actingAs($this->journalistIn($this->egypt));

        Livewire::test(CreateArticle::class)
            ->fillForm($this->payload($this->egypt))
            ->set('data.category_id', $this->sport->getKey())
            ->call('create')
            ->assertHasFormErrors(['category_id']);

        $this->assertSame(0, Article::query()->count());
    }

    public function test_the_writer_itself_refuses_a_create_into_a_forbidden_section(): void
    {
        $journalist = $this->journalistIn($this->egypt);

        $this->assertSectionRefused(fn () => app(ArticleWriter::class)->create($this->payload($this->sport), $journalist));

        $this->assertSame(0, Article::query()->count());
    }

    public function test_a_crafted_update_cannot_move_a_story_out_of_an_allowed_section(): void
    {
        $journalist = $this->journalistIn($this->egypt);
        $article = $this->story($journalist, category: $this->egypt);
        $this->actingAs($journalist);

        Livewire::test(EditArticle::class, ['record' => $article->getKey()])
            ->set('data.category_id', $this->sport->getKey())
            ->call('save')
            ->assertHasFormErrors(['category_id']);

        $this->assertSame($this->egypt->getKey(), $article->fresh()->category_id);
    }

    public function test_the_writer_itself_refuses_to_move_a_story_into_a_forbidden_section(): void
    {
        $journalist = $this->journalistIn($this->egypt);
        $article = $this->story($journalist, category: $this->egypt);

        $this->assertSectionRefused(fn () => app(ArticleWriter::class)->update($article->fresh(), [
            'category_id' => $this->sport->getKey(),
            'en' => ['title' => 'Edited at the same time'],
        ], $journalist));

        $article->refresh()->load('translations');
        $this->assertSame($this->egypt->getKey(), $article->category_id);
        $this->assertNotSame('Edited at the same time', $article->translation(Locale::En)->title, 'the refusal rolled the whole save back');
    }

    public function test_a_journalist_may_move_a_story_between_two_assigned_sections(): void
    {
        $journalist = $this->journalistIn($this->egypt, $this->sport);
        $article = $this->story($journalist, category: $this->egypt);
        $this->actingAs($journalist);

        Livewire::test(EditArticle::class, ['record' => $article->getKey()])
            ->fillForm(['category_id' => $this->sport->getKey()])
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertSame($this->sport->getKey(), $article->fresh()->category_id);
    }

    public function test_a_text_edit_that_leaves_the_section_out_of_the_payload_keeps_it(): void
    {
        $journalist = $this->journalistIn($this->egypt);
        $article = $this->story($journalist, category: $this->egypt);

        app(ArticleWriter::class)->update($article->fresh(), ['en' => ['title' => 'New headline']], $journalist);

        $article->refresh()->load('translations');
        $this->assertSame($this->egypt->getKey(), $article->category_id);
        $this->assertSame('New headline', $article->translation(Locale::En)->title);
    }

    /** @return array<string, array{0: mixed}> */
    public static function nonsenseSections(): array
    {
        return [
            'a section that does not exist' => [987654],
            'text' => ['abc'],
            'a number with text behind it' => ['1abc'],
            'a decimal' => ['1.5'],
            'zero' => [0],
            'nothing' => [null],
            'empty text' => [''],
            'a list of ids' => [[1, 2]],
        ];
    }

    #[DataProvider('nonsenseSections')]
    public function test_the_writer_refuses_a_section_that_is_not_one_on_create_and_on_update(mixed $nonsense): void
    {
        $journalist = $this->journalistIn($this->egypt);
        $editor = User::factory()->editor()->create();
        $article = $this->story($journalist, category: $this->egypt);

        foreach ([$journalist, $editor] as $user) {
            $this->assertSectionRefused(fn () => app(ArticleWriter::class)->create($this->payload($nonsense), $user));
        }
        $this->assertSectionRefused(fn () => app(ArticleWriter::class)->update($article->fresh(), ['category_id' => $nonsense], $journalist));
        $this->assertSectionRefused(fn () => app(ArticleWriter::class)->update($article->fresh(), ['category_id' => $nonsense], $editor));

        $this->assertSame(1, Article::query()->count(), 'only the fixture story exists');
        $this->assertSame($this->egypt->getKey(), $article->fresh()->category_id);
    }

    public function test_the_section_is_not_checked_when_the_shell_is_locked_it_is_simply_not_written(): void
    {
        $journalist = $this->journalistIn($this->egypt);
        $article = $this->story($journalist, TranslationStatus::InReview, TranslationStatus::Draft, $this->egypt);

        app(ArticleWriter::class)->update($article->fresh(), [
            'category_id' => $this->sport->getKey(),
            'en' => ['title' => 'Still my draft'],
        ], $journalist);

        $article->refresh()->load('translations');
        $this->assertSame($this->egypt->getKey(), $article->category_id);
        $this->assertSame('Still my draft', $article->translation(Locale::En)->title);
    }

    // --- (c) the policies -------------------------------------------------------------------

    public function test_only_a_journalist_with_a_section_may_create(): void
    {
        $this->assertFalse($this->journalistIn()->can('create', Article::class));
        $this->assertTrue($this->journalistIn($this->egypt)->can('create', Article::class));
        $this->assertTrue(User::factory()->editor()->create()->can('create', Article::class), 'no pivot rows needed');
        $this->assertTrue(User::factory()->admin()->create()->can('create', Article::class));

        $inactive = $this->journalistIn($this->egypt);
        $inactive->update(['is_active' => false]);
        $this->assertFalse($inactive->can('create', Article::class));
    }

    public function test_a_journalist_whose_section_was_removed_can_read_their_story_but_not_edit_or_submit_it(): void
    {
        $journalist = $this->journalistIn($this->egypt);
        $article = $this->story($journalist, category: $this->egypt);
        $article->load('translations');
        $arabic = $article->translation(Locale::Ar)->setRelation('article', $article);

        $this->assertTrue($journalist->can('update', $article), 'while the section is theirs');
        $this->assertTrue($journalist->can('update', $arabic));
        $this->assertTrue($journalist->can('submit', $arabic));

        $journalist->allowedCategories()->detach($this->egypt->getKey());

        $this->assertTrue($journalist->can('view', $article), 'still readable');
        $this->assertTrue($journalist->can('view', $arabic));
        $this->assertFalse($journalist->can('update', $article));
        $this->assertFalse($journalist->can('update', $arabic));
        $this->assertFalse($journalist->can('submit', $arabic));

        $journalist->allowedCategories()->attach($this->egypt->getKey());
        $this->assertTrue($journalist->can('update', $article), 'giving the section back gives the story back');
        $this->assertTrue($journalist->can('submit', $arabic));
    }

    public function test_the_article_page_of_such_a_story_opens_but_nothing_can_be_changed_or_submitted(): void
    {
        $journalist = $this->journalistIn($this->egypt);
        $article = $this->story($journalist, category: $this->egypt);
        $titleBefore = $this->version($article, Locale::Ar)->title;
        $journalist->allowedCategories()->detach();
        $this->actingAs($journalist);

        $this->get(ArticleResource::getUrl('edit', ['record' => $article]))->assertOk();

        $page = Livewire::test(EditArticle::class, ['record' => $article->getKey()])
            ->assertActionHidden('ar_submit')
            ->assertActionHidden('en_submit')
            ->assertFormFieldDisabled('category_id')
            ->assertFormFieldDisabled('ar.title')
            // A crafted request that names the hidden action and fills the disabled fields anyway:
            ->call('mountAction', 'ar_submit')
            ->call('callMountedAction')
            ->set('data.ar.title', 'Edited without a section')
            ->set('data.category_id', $this->sport->getKey())
            ->call('save');
        $page->assertForbidden();

        $this->assertSame(TranslationStatus::Draft, $this->version($article, Locale::Ar)->status);
        $this->assertSame($titleBefore, $this->version($article, Locale::Ar)->title);
        $this->assertSame($this->egypt->getKey(), $article->fresh()->category_id);
    }

    public function test_the_list_offers_such_a_story_as_open_not_edit(): void
    {
        $journalist = $this->journalistIn($this->egypt);
        $article = $this->story($journalist);
        $this->actingAs($journalist);

        Livewire::test(ListArticles::class)
            ->assertCanSeeTableRecords([$article])
            ->assertTableActionHasLabel('edit', 'Edit', $article);

        $journalist->allowedCategories()->detach();

        Livewire::test(ListArticles::class)
            ->assertCanSeeTableRecords([$article])
            ->assertTableActionHasLabel('edit', 'Open', $article);
    }

    public function test_the_writer_does_not_add_a_language_to_a_story_whose_section_was_removed(): void
    {
        $journalist = $this->journalistIn($this->egypt);
        $article = $this->story($journalist, TranslationStatus::Draft, null);
        $journalist->allowedCategories()->detach();

        app(ArticleWriter::class)->update($article->fresh(), ['en' => ['title' => 'Injected', 'body' => '<p>x</p>']], $journalist);

        $this->assertSame(1, $article->translations()->count());
    }

    public function test_an_editor_in_chief_can_still_edit_and_submit_it_and_reassign_the_section(): void
    {
        $journalist = $this->journalistIn($this->egypt);
        $article = $this->story($journalist, category: $this->egypt);
        $journalist->allowedCategories()->detach();
        $this->actingAs(User::factory()->editor()->create());

        Livewire::test(EditArticle::class, ['record' => $article->getKey()])
            ->assertActionVisible('ar_submit')
            ->fillForm(['category_id' => $this->economy->getKey(), 'ar' => ['title' => 'عنوان صححه رئيس التحرير']])
            ->call('save')
            ->assertHasNoFormErrors()
            ->callAction('ar_submit');

        $this->assertSame($this->economy->getKey(), $article->fresh()->category_id);
        $this->assertSame(TranslationStatus::InReview, $this->version($article, Locale::Ar)->status);
    }

    // --- (d) a journalist with no section ---------------------------------------------------

    public function test_a_journalist_with_no_section_sees_a_notice_and_no_new_article_button(): void
    {
        $journalist = $this->journalistIn();
        $this->actingAs($journalist);

        Livewire::test(ListArticles::class)
            ->assertActionHidden('create')
            ->assertSee(Notice::HEADING)
            ->assertSee('An admin has not chosen the sections you may write in');

        $this->assertTrue(Notice::applies($journalist));
    }

    public function test_such_a_journalist_cannot_open_the_create_page_either(): void
    {
        $this->actingAs($this->journalistIn());

        $this->get(ArticleResource::getUrl('create'))->assertForbidden();
        Livewire::test(CreateArticle::class)->assertForbidden();
        $this->assertFalse(ArticleResource::canCreate());
    }

    public function test_the_notice_leaves_the_stories_they_already_have_in_the_list(): void
    {
        $journalist = $this->journalistIn($this->egypt);
        $article = $this->story($journalist);
        $journalist->allowedCategories()->detach();
        $this->actingAs($journalist);

        Livewire::test(ListArticles::class)
            ->assertCanSeeTableRecords([$article])
            ->assertSee(Notice::HEADING);
    }

    public function test_everyone_else_sees_the_new_article_button_and_no_notice(): void
    {
        foreach ([$this->journalistIn($this->egypt), User::factory()->editor()->create(), User::factory()->admin()->create()] as $user) {
            $this->actingAs($user);

            Livewire::test(ListArticles::class)
                ->assertActionVisible('create')
                ->assertDontSee(Notice::HEADING);
            $this->assertFalse(Notice::applies($user));
            $this->get(ArticleResource::getUrl('create'))->assertOk();
        }
    }

    public function test_the_dashboard_shows_the_notice_widget_to_a_journalist_with_no_section_only(): void
    {
        $this->actingAs($this->journalistIn())
            ->get('/admin')
            ->assertOk()
            ->assertSeeLivewire(NoSectionsNotice::class)
            ->assertSee(Notice::HEADING);
        $this->assertTrue(NoSectionsNotice::canView());

        foreach ([$this->journalistIn($this->egypt), User::factory()->editor()->create(), User::factory()->admin()->create()] as $user) {
            $this->actingAs($user)
                ->get('/admin')
                ->assertOk()
                ->assertDontSeeLivewire(NoSectionsNotice::class);
            $this->assertFalse(NoSectionsNotice::canView());
        }
    }

    public function test_the_widget_renders_the_plain_message(): void
    {
        $this->actingAs($this->journalistIn());

        Livewire::test(NoSectionsNotice::class)
            ->assertSee(Notice::HEADING)
            ->assertSee('Ask an admin to assign at least one section');
    }

    // --- (e) nothing changes for editors and admins -----------------------------------------

    #[DataProvider('reviewers')]
    public function test_a_reviewer_writes_in_any_section_with_no_assignment_at_all(string $role): void
    {
        $reviewer = User::factory()->create(['role' => $role]);
        $this->actingAs($reviewer);

        Livewire::test(CreateArticle::class)
            ->fillForm($this->payload($this->sport))
            ->call('create')
            ->assertHasNoFormErrors();

        $article = Article::query()->firstOrFail();
        $this->assertSame($this->sport->getKey(), $article->category_id);
        $this->assertSame(0, $reviewer->allowedCategories()->count());

        Livewire::test(EditArticle::class, ['record' => $article->getKey()])
            ->fillForm(['category_id' => $this->economy->getKey()])
            ->call('save')
            ->assertHasNoFormErrors()
            ->assertActionVisible('en_publish_now')
            ->callAction('en_publish_now');

        $this->assertSame($this->economy->getKey(), $article->fresh()->category_id);
        $this->assertSame(TranslationStatus::Published, $this->version($article, Locale::En)->status);
    }

    #[DataProvider('reviewers')]
    public function test_a_reviewer_edits_a_journalists_story_whatever_that_journalist_may_write_in(string $role): void
    {
        $journalist = $this->journalistIn();
        $article = $this->story($journalist, TranslationStatus::InReview, TranslationStatus::Published);
        $journalist->allowedCategories()->detach();
        $reviewer = User::factory()->create(['role' => $role]);

        $arabic = $this->version($article, Locale::Ar)->setRelation('article', $article);
        $this->assertTrue($reviewer->can('update', $article));
        $this->assertTrue($reviewer->can('update', $arabic));
        $this->assertTrue($reviewer->can('approve', $arabic));
        $this->assertTrue($reviewer->can('view', $article));
    }

    public function test_a_reviewer_may_hand_a_story_to_any_active_journalist(): void
    {
        $editor = User::factory()->editor()->create();
        $writer = $this->journalistIn();
        $article = $this->story($editor);

        app(ArticleWriter::class)->update($article->fresh(), ['author_id' => $writer->getKey()], $editor);

        $this->assertSame($writer->getKey(), $article->fresh()->author_id);
    }
}
