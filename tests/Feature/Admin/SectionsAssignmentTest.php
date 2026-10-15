<?php

namespace Tests\Feature\Admin;

use App\Enums\Role;
use App\Filament\Resources\Users\Pages\CreateUser;
use App\Filament\Resources\Users\Pages\EditUser;
use App\Filament\Resources\Users\Pages\ListUsers;
use App\Filament\Resources\Users\Support\SectionAssignments;
use App\Models\Category;
use App\Models\User;
use Filament\Forms\Components\Select;
use Illuminate\Auth\Access\AuthorizationException;
use Livewire\Livewire;

/** The Team area decides which sections a journalist may write in (the category_user pivot). Admins only. */
class SectionsAssignmentTest extends AdminTestCase
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

    private function admin(): User
    {
        return tap(User::factory()->admin()->create(), fn (User $admin) => $this->actingAs($admin));
    }

    /** @return list<int> */
    private function sectionsOf(User $user): array
    {
        $ids = $user->allowedCategories()->pluck('categories.id')->map(fn ($id): int => (int) $id)->all();
        sort($ids);

        return $ids;
    }

    /** @param  list<Category>  $categories @return list<int> */
    private function ids(array $categories): array
    {
        $ids = array_map(fn (Category $category): int => $category->getKey(), $categories);
        sort($ids);

        return $ids;
    }

    // --- the picker -------------------------------------------------------------------------

    public function test_the_picker_lists_every_section_by_its_arabic_and_english_name(): void
    {
        $this->admin();
        $journalist = User::factory()->journalist()->create();

        $expected = [
            $this->egypt->getKey() => 'Egypt / مصر',
            $this->sport->getKey() => 'Sport / رياضة',
            $this->economy->getKey() => 'Economy / اقتصاد',
        ];

        Livewire::test(CreateUser::class)
            ->assertFormFieldExists(SectionAssignments::STATE, fn (Select $field): bool => $field->getOptions() === $expected && $field->isMultiple());
        Livewire::test(EditUser::class, ['record' => $journalist->getKey()])
            ->assertFormFieldExists(SectionAssignments::STATE, fn (Select $field): bool => $field->getOptions() === $expected);
    }

    public function test_the_picker_is_only_there_for_a_journalist(): void
    {
        $this->admin();

        Livewire::test(CreateUser::class)
            ->assertFormFieldVisible(SectionAssignments::STATE)
            ->fillForm(['role' => Role::Editor->value])
            ->assertFormFieldHidden(SectionAssignments::STATE)
            ->fillForm(['role' => Role::Admin->value])
            ->assertFormFieldHidden(SectionAssignments::STATE)
            ->fillForm(['role' => Role::Journalist->value])
            ->assertFormFieldVisible(SectionAssignments::STATE);

        foreach ([Role::Editor, Role::Admin] as $role) {
            $account = User::factory()->create(['role' => $role]);
            Livewire::test(EditUser::class, ['record' => $account->getKey()])->assertFormFieldHidden(SectionAssignments::STATE);
        }
        $journalist = User::factory()->journalist()->create();
        Livewire::test(EditUser::class, ['record' => $journalist->getKey()])->assertFormFieldVisible(SectionAssignments::STATE);
    }

    public function test_the_picker_is_not_required_and_says_what_an_empty_choice_means(): void
    {
        $this->admin();
        $journalist = User::factory()->journalist()->create();

        Livewire::test(EditUser::class, ['record' => $journalist->getKey()])
            ->assertSee('Sections this journalist may write in')
            ->assertSee('Until at least one section is chosen, this journalist cannot start a story.')
            ->fillForm([SectionAssignments::STATE => [$this->egypt->getKey()]])
            ->assertDontSee('Until at least one section is chosen')
            ->assertSee('only in these sections')
            ->fillForm([SectionAssignments::STATE => []])
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertSame([], $this->sectionsOf($journalist));
    }

    // --- saving -----------------------------------------------------------------------------

    public function test_an_admin_creates_a_journalist_with_sections_and_they_are_stored(): void
    {
        $this->admin();

        Livewire::test(CreateUser::class)
            ->fillForm([
                'name' => 'Rana',
                'email' => 'rana@example.com',
                'password' => 'CorrectHorse42',
                'role' => Role::Journalist->value,
                SectionAssignments::STATE => [$this->egypt->getKey(), $this->economy->getKey()],
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $rana = User::query()->where('email', 'rana@example.com')->firstOrFail();
        $this->assertSame($this->ids([$this->egypt, $this->economy]), $this->sectionsOf($rana));
        $this->assertTrue($rana->canWriteIn($this->egypt));
        $this->assertFalse($rana->canWriteIn($this->sport));
    }

    public function test_an_admin_creates_a_journalist_without_sections(): void
    {
        $this->admin();

        Livewire::test(CreateUser::class)
            ->fillForm(['name' => 'Rana', 'email' => 'rana@example.com', 'password' => 'CorrectHorse42', 'role' => Role::Journalist->value])
            ->call('create')
            ->assertHasNoFormErrors();

        $this->assertSame([], $this->sectionsOf(User::query()->where('email', 'rana@example.com')->firstOrFail()));
    }

    public function test_an_admin_changes_the_sections_of_a_journalist(): void
    {
        $this->admin();
        $journalist = $this->journalistIn($this->egypt, $this->sport);

        Livewire::test(EditUser::class, ['record' => $journalist->getKey()])
            ->assertFormSet(function (array $state) use ($journalist): bool {
                $shown = array_map('intval', $state[SectionAssignments::STATE]);
                sort($shown);

                return $shown === $this->sectionsOf($journalist);
            })
            ->fillForm([SectionAssignments::STATE => [$this->sport->getKey(), $this->economy->getKey()]])
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertSame($this->ids([$this->sport, $this->economy]), $this->sectionsOf($journalist));
    }

    public function test_the_other_account_details_are_saved_as_before(): void
    {
        $this->admin();
        $journalist = $this->journalistIn($this->egypt);

        Livewire::test(EditUser::class, ['record' => $journalist->getKey()])
            ->fillForm(['name' => 'Renamed', 'name_ar' => 'اسم جديد'])
            ->call('save')
            ->assertHasNoFormErrors();

        $journalist->refresh();
        $this->assertSame('Renamed', $journalist->name);
        $this->assertSame($this->ids([$this->egypt]), $this->sectionsOf($journalist), 'saving the name leaves the sections as they were');
    }

    public function test_something_that_is_not_a_section_is_refused(): void
    {
        $this->admin();
        $journalist = $this->journalistIn($this->egypt);

        Livewire::test(EditUser::class, ['record' => $journalist->getKey()])
            ->fillForm([SectionAssignments::STATE => [$this->sport->getKey(), 987654]])
            ->call('save')
            ->assertHasFormErrors([SectionAssignments::STATE.'.1']);

        $this->assertSame($this->ids([$this->egypt]), $this->sectionsOf($journalist), 'nothing was saved');
    }

    public function test_the_helper_drops_ids_that_are_not_sections_even_when_called_directly(): void
    {
        $admin = $this->admin();
        $journalist = $this->journalistIn($this->egypt);

        SectionAssignments::sync($journalist, [(string) $this->sport->getKey(), 987654, 0], $admin);

        $this->assertSame($this->ids([$this->sport]), $this->sectionsOf($journalist));
    }

    public function test_a_weak_password_still_refuses_the_account_and_no_section_is_stored(): void
    {
        $this->admin();

        Livewire::test(CreateUser::class)
            ->fillForm([
                'name' => 'Rana',
                'email' => 'rana@example.com',
                'password' => 'short',
                'role' => Role::Journalist->value,
                SectionAssignments::STATE => [$this->egypt->getKey()],
            ])
            ->call('create')
            ->assertHasFormErrors(['password']);

        $this->assertNull(User::query()->where('email', 'rana@example.com')->first());
        $this->assertDatabaseCount('category_user', 0);
    }

    // --- roles and the hidden picker --------------------------------------------------------

    public function test_the_sections_of_a_journalist_survive_becoming_an_editor_in_chief_and_back(): void
    {
        $this->admin();
        $person = $this->journalistIn($this->egypt, $this->sport);
        $kept = $this->ids([$this->egypt, $this->sport]);

        Livewire::test(EditUser::class, ['record' => $person->getKey()])
            ->fillForm(['role' => Role::Editor->value])
            ->assertFormFieldHidden(SectionAssignments::STATE)
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertSame(Role::Editor, $person->fresh()->role);
        $this->assertSame($kept, $this->sectionsOf($person), 'hidden is not deleted');
        $this->assertTrue($person->fresh()->canWriteIn($this->economy), 'as an editor the person writes in every section');

        Livewire::test(EditUser::class, ['record' => $person->getKey()])
            ->assertFormFieldHidden(SectionAssignments::STATE)
            ->fillForm(['role' => Role::Journalist->value])
            ->assertFormFieldVisible(SectionAssignments::STATE)
            ->assertFormSet(function (array $state) use ($kept): bool {
                $shown = array_map('intval', $state[SectionAssignments::STATE]);
                sort($shown);

                return $shown === $kept;
            })
            ->call('save')
            ->assertHasNoFormErrors();

        $person->refresh();
        $this->assertSame(Role::Journalist, $person->role);
        $this->assertSame($kept, $this->sectionsOf($person));
        $this->assertTrue($person->canWriteIn($this->sport));
        $this->assertFalse($person->canWriteIn($this->economy), 'back to the sections an admin assigned');
    }

    public function test_a_picker_that_is_hidden_changes_nothing_even_if_a_payload_fills_it(): void
    {
        $this->admin();
        $editor = User::factory()->editor()->create();
        $editor->allowedCategories()->attach($this->egypt->getKey());

        Livewire::test(EditUser::class, ['record' => $editor->getKey()])
            ->set('data.'.SectionAssignments::STATE, [$this->sport->getKey(), $this->economy->getKey()])
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertSame($this->ids([$this->egypt]), $this->sectionsOf($editor), 'a hidden field is not submitted, so it cannot rewrite the assignment');
    }

    public function test_a_new_editor_in_chief_gets_no_section_rows(): void
    {
        $this->admin();

        Livewire::test(CreateUser::class)
            ->fillForm([
                'name' => 'Chief',
                'email' => 'chief@example.com',
                'password' => 'CorrectHorse42',
                'role' => Role::Editor->value,
                SectionAssignments::STATE => [$this->egypt->getKey()],
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $this->assertDatabaseCount('category_user', 0);
    }

    // --- nobody but an admin ----------------------------------------------------------------

    public function test_an_editor_in_chief_cannot_change_sections_by_any_route(): void
    {
        $editor = User::factory()->editor()->create();
        $journalist = $this->journalistIn($this->egypt);
        $this->actingAs($editor);

        Livewire::test(EditUser::class, ['record' => $journalist->getKey()])->assertForbidden();
        Livewire::test(CreateUser::class)->assertForbidden();
        $this->get(route('filament.admin.resources.users.edit', ['record' => $journalist]))->assertForbidden();

        $this->assertThrows(
            fn () => SectionAssignments::sync($journalist, [$this->sport->getKey()], $editor),
            AuthorizationException::class,
        );
        $this->assertFalse($editor->can('update', $journalist));
        $this->assertSame($this->ids([$this->egypt]), $this->sectionsOf($journalist));
    }

    public function test_a_journalist_cannot_change_their_own_or_anyone_elses_sections(): void
    {
        $journalist = $this->journalistIn($this->egypt);
        $colleague = $this->journalistIn($this->sport);
        $this->actingAs($journalist);

        foreach ([$journalist, $colleague] as $target) {
            Livewire::test(EditUser::class, ['record' => $target->getKey()])->assertForbidden();
            $this->assertThrows(
                fn () => SectionAssignments::sync($target, [$this->economy->getKey()], $journalist),
                AuthorizationException::class,
            );
        }
        Livewire::test(CreateUser::class)->assertForbidden();

        $this->assertSame($this->ids([$this->egypt]), $this->sectionsOf($journalist));
        $this->assertSame($this->ids([$this->sport]), $this->sectionsOf($colleague));
    }

    public function test_a_deactivated_admin_cannot_change_sections_either(): void
    {
        $gone = User::factory()->admin()->inactive()->create();
        $journalist = $this->journalistIn($this->egypt);

        $this->assertThrows(
            fn () => SectionAssignments::sync($journalist, [$this->sport->getKey()], $gone),
            AuthorizationException::class,
        );
        $this->assertSame($this->ids([$this->egypt]), $this->sectionsOf($journalist));
    }

    // --- the list ---------------------------------------------------------------------------

    public function test_the_list_shows_each_journalists_sections_a_plus_n_more_or_none_yet(): void
    {
        $this->admin();
        $none = $this->journalistIn();
        $one = $this->journalistIn($this->sport);
        $two = $this->journalistIn($this->egypt, $this->sport);
        $four = $this->journalistIn($this->egypt, $this->sport, $this->economy, $this->section('Culture', 'ثقافة', 4));
        $editor = User::factory()->editor()->create();
        $editor->allowedCategories()->attach($this->egypt->getKey());

        $page = Livewire::test(ListUsers::class)
            ->assertTableColumnStateSet('sections', 'None yet', $none)
            ->assertTableColumnStateSet('sections', 'Sport', $one)
            ->assertTableColumnStateSet('sections', 'Egypt, Sport', $two)
            ->assertTableColumnStateSet('sections', 'Egypt, Sport +2 more', $four)
            ->assertTableColumnStateSet('sections', 'All sections', $editor);

        $column = $page->instance()->getTable()->getColumn('sections');
        $this->assertSame('warning', $column->record($none)->getColor(null));
        $this->assertSame('gray', $column->record($editor)->getColor(null));
        $this->assertSame('primary', $column->record($two)->getColor(null));
    }

    public function test_the_list_filters_by_section_and_by_journalists_with_none(): void
    {
        $this->admin();
        $none = $this->journalistIn();
        $inEgypt = $this->journalistIn($this->egypt);
        $inSport = $this->journalistIn($this->sport);
        $editor = User::factory()->editor()->create();
        $editor->allowedCategories()->attach($this->egypt->getKey());

        Livewire::test(ListUsers::class)
            ->filterTable('section', $this->egypt->getKey())
            ->assertCanSeeTableRecords([$inEgypt])
            ->assertCanNotSeeTableRecords([$none, $inSport, $editor])
            ->resetTableFilters()
            ->filterTable('no_sections', true)
            ->assertCanSeeTableRecords([$none])
            ->assertCanNotSeeTableRecords([$inEgypt, $inSport, $editor]);
    }

    public function test_the_list_does_not_query_per_row_for_sections(): void
    {
        $this->admin();
        foreach (range(1, 3) as $i) {
            $this->journalistIn($this->egypt, $this->sport);
        }
        $few = $this->queryCount(fn () => Livewire::test(ListUsers::class)->assertSuccessful());

        foreach (range(1, 12) as $i) {
            $this->journalistIn($this->egypt, $this->sport, $this->economy);
        }
        $many = $this->queryCount(fn () => Livewire::test(ListUsers::class)->assertSuccessful());

        $this->assertSame($few, $many);
    }
}
