<?php

namespace Tests\Feature\Admin;

use App\Enums\Role;
use App\Filament\Pages\Auth\Login;
use App\Filament\Resources\Users\Pages\CreateUser;
use App\Filament\Resources\Users\Pages\EditUser;
use App\Filament\Resources\Users\Pages\ListUsers;
use App\Filament\Resources\Users\UserResource;
use App\Models\User;
use Filament\Facades\Filament;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Facades\Hash;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\DataProvider;

/** The admin-only "Team" area: one place to create staff, change roles, reset passwords and switch accounts on or off. */
class TeamAdministrationTest extends AdminTestCase
{
    private function admin(): User
    {
        return tap(User::factory()->admin()->create(), fn (User $admin) => $this->actingAs($admin));
    }

    private function sidebar(): array
    {
        return collect(Filament::getNavigation())
            ->flatMap(fn ($group) => $group->getItems())
            ->map(fn ($item) => $item->getLabel())
            ->all();
    }

    // --- navigation -------------------------------------------------------------------------

    public function test_the_team_is_a_top_level_sidebar_item_with_a_people_icon_for_admins(): void
    {
        $this->admin();

        $this->assertSame('Team', UserResource::getNavigationLabel());
        $this->assertSame(Heroicon::OutlinedUserGroup, UserResource::getNavigationIcon());
        $this->assertNull(UserResource::getNavigationGroup(), 'its own top-level item, not buried in a group');
        $this->assertContains('Team', $this->sidebar());
        $this->get('/admin')->assertOk()->assertSee('Team');
    }

    #[DataProvider('nonAdminRoles')]
    public function test_the_team_is_not_in_the_sidebar_of_editors_and_journalists(string $role): void
    {
        $this->actingAs(User::factory()->create(['role' => $role]));

        $this->assertNotContains('Team', $this->sidebar());
        $this->assertFalse(UserResource::canAccess());
        $this->get('/admin')->assertOk()->assertDontSee('/admin/users');
    }

    public function test_the_pages_are_titled_for_a_team_not_for_staff_accounts(): void
    {
        $this->admin();

        Livewire::test(ListUsers::class)->assertSee('Team')->assertSee('New team member');
        Livewire::test(CreateUser::class)->assertSee('New team member');
    }

    // --- create and edit --------------------------------------------------------------------

    public function test_an_admin_creates_each_kind_of_account_from_the_team_page(): void
    {
        $this->admin();

        foreach (Role::cases() as $role) {
            Livewire::test(CreateUser::class)
                ->fillForm([
                    'name' => "New {$role->value}",
                    'name_ar' => 'اسم',
                    'email' => "new.{$role->value}@example.com",
                    'password' => 'CorrectHorse42',
                    'role' => $role->value,
                ])
                ->call('create')
                ->assertHasNoFormErrors();

            $user = User::query()->where('email', "new.{$role->value}@example.com")->firstOrFail();
            $this->assertSame($role, $user->role);
            $this->assertTrue($user->is_active, 'new accounts start active');
            $this->assertTrue(Hash::check('CorrectHorse42', $user->password));
        }
    }

    public function test_the_new_account_can_sign_in_straight_away(): void
    {
        $this->admin();
        Livewire::test(CreateUser::class)
            ->fillForm(['name' => 'Rana', 'email' => 'rana@example.com', 'password' => 'CorrectHorse42', 'role' => Role::Journalist->value])
            ->call('create');

        auth()->logout();

        Livewire::test(Login::class)
            ->fillForm(['email' => 'rana@example.com', 'password' => 'CorrectHorse42'])
            ->call('authenticate')
            ->assertHasNoFormErrors();

        $this->assertAuthenticated();
    }

    public function test_an_admin_changes_another_accounts_role_from_its_edit_page(): void
    {
        $this->admin();
        $journalist = User::factory()->journalist()->create();

        Livewire::test(EditUser::class, ['record' => $journalist->getKey()])
            ->fillForm(['role' => Role::Admin->value])
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertSame(Role::Admin, $journalist->fresh()->role);
    }

    public function test_a_role_must_be_one_of_the_three(): void
    {
        $this->admin();
        $user = User::factory()->journalist()->create();

        Livewire::test(EditUser::class, ['record' => $user->getKey()])
            ->fillForm(['role' => 'superuser'])
            ->call('save')
            ->assertHasFormErrors(['role']);

        $this->assertSame(Role::Journalist, $user->fresh()->role);
    }

    // --- the list ---------------------------------------------------------------------------

    public function test_the_list_shows_name_with_email_a_role_badge_and_an_active_icon(): void
    {
        $this->admin();
        $editor = User::factory()->editor()->create(['name' => 'Mona Editor', 'email' => 'mona@example.com']);
        $gone = User::factory()->journalist()->inactive()->create();

        Livewire::test(ListUsers::class)
            ->assertCanSeeTableRecords([$editor, $gone])
            ->assertSee('Mona Editor')
            ->assertSee('mona@example.com')
            ->assertTableColumnStateSet('role', Role::Editor, $editor)
            ->assertTableColumnStateSet('is_active', true, $editor)
            ->assertTableColumnStateSet('is_active', false, $gone)
            ->assertTableColumnFormattedStateSet('role', 'Editor-in-chief', $editor);

        $table = Livewire::test(ListUsers::class)->instance()->getTable();
        $this->assertTrue($table->getColumn('role')->isBadge());
        $this->assertSame('warning', $table->getColumn('role')->getColor(Role::Editor));
        $this->assertSame('danger', $table->getColumn('role')->getColor(Role::Admin));
        $this->assertSame('info', $table->getColumn('role')->getColor(Role::Journalist));
    }

    public function test_the_list_is_searched_by_name_or_email(): void
    {
        $this->admin();
        $nour = User::factory()->journalist()->create(['name' => 'Nour Hassan', 'email' => 'nh@example.com']);
        $omar = User::factory()->journalist()->create(['name' => 'Omar Said', 'email' => 'omar.said@example.com']);

        Livewire::test(ListUsers::class)
            ->searchTable('Nour')
            ->assertCanSeeTableRecords([$nour])
            ->assertCanNotSeeTableRecords([$omar])
            ->searchTable('omar.said@')
            ->assertCanSeeTableRecords([$omar])
            ->assertCanNotSeeTableRecords([$nour]);
    }

    public function test_the_list_is_filtered_by_role_and_by_active(): void
    {
        $this->admin();
        $editor = User::factory()->editor()->create();
        $journalist = User::factory()->journalist()->create();
        $gone = User::factory()->journalist()->inactive()->create();

        Livewire::test(ListUsers::class)
            ->filterTable('role', Role::Journalist->value)
            ->assertCanSeeTableRecords([$journalist, $gone])
            ->assertCanNotSeeTableRecords([$editor])
            ->resetTableFilters()
            ->filterTable('is_active', false)
            ->assertCanSeeTableRecords([$gone])
            ->assertCanNotSeeTableRecords([$editor, $journalist])
            ->filterTable('is_active', true)
            ->assertCanSeeTableRecords([$editor, $journalist])
            ->assertCanNotSeeTableRecords([$gone]);
    }

    public function test_the_list_does_not_query_per_row(): void
    {
        $this->admin();
        User::factory()->count(3)->create();
        $few = $this->queryCount(fn () => Livewire::test(ListUsers::class)->assertSuccessful());

        User::factory()->count(12)->create();
        $many = $this->queryCount(fn () => Livewire::test(ListUsers::class)->assertSuccessful());

        $this->assertSame($few, $many);
    }

    // --- reset password ---------------------------------------------------------------------

    public function test_an_admin_resets_a_password_from_the_list_and_the_old_one_stops_working(): void
    {
        $this->admin();
        $user = User::factory()->editor()->create(['password' => 'OriginalPass123']);

        Livewire::test(ListUsers::class)
            ->assertTableActionVisible('resetPassword', $user)
            ->callTableAction('resetPassword', $user, data: ['password' => 'BrandNewPass456', 'passwordConfirmation' => 'BrandNewPass456'])
            ->assertHasNoTableActionErrors()
            ->assertNotified('Password changed');

        $user->refresh();
        $this->assertTrue(Hash::check('BrandNewPass456', $user->password));
        $this->assertFalse(Hash::check('OriginalPass123', $user->password));
        $this->assertSame(Role::Editor, $user->role, 'only the password changed');
        $this->assertTrue($user->is_active);
    }

    public function test_an_admin_resets_a_password_from_the_edit_page_too(): void
    {
        $this->admin();
        $user = User::factory()->journalist()->create(['password' => 'OriginalPass123']);

        Livewire::test(EditUser::class, ['record' => $user->getKey()])
            ->assertActionVisible('resetPassword')
            ->callAction('resetPassword', data: ['password' => 'BrandNewPass456', 'passwordConfirmation' => 'BrandNewPass456'])
            ->assertHasNoActionErrors();

        $this->assertTrue(Hash::check('BrandNewPass456', $user->fresh()->password));
    }

    public function test_the_new_password_must_be_confirmed_and_strong(): void
    {
        $this->admin();
        $user = User::factory()->journalist()->create(['password' => 'OriginalPass123']);

        $attempts = [
            'mismatch' => [['password' => 'BrandNewPass456', 'passwordConfirmation' => 'OtherPass456xx'], 'password'],
            'no confirmation' => [['password' => 'BrandNewPass456', 'passwordConfirmation' => ''], 'passwordConfirmation'],
            'too short' => [['password' => 'short1', 'passwordConfirmation' => 'short1'], 'password'],
            'no digits' => [['password' => 'onlyletterspassword', 'passwordConfirmation' => 'onlyletterspassword'], 'password'],
            'no letters' => [['password' => '1234567890123', 'passwordConfirmation' => '1234567890123'], 'password'],
            'empty' => [['password' => '', 'passwordConfirmation' => ''], 'password'],
        ];

        foreach ($attempts as $label => [$data, $field]) {
            Livewire::test(ListUsers::class)
                ->callTableAction('resetPassword', $user, data: $data)
                ->assertHasTableActionErrors([$field]);

            $this->assertTrue(Hash::check('OriginalPass123', $user->fresh()->password), "{$label}: the password must be unchanged");
        }
    }

    public function test_a_reset_ends_the_sessions_signed_in_with_the_old_password(): void
    {
        $user = User::factory()->journalist()->create(['password' => 'OriginalPass123']);
        $oldHash = $user->getAuthPassword();

        // The browser of the person whose password is about to be reset: signed in, hash remembered in the session.
        $this->actingAs($user)->withSession(['password_hash_web' => $oldHash])->get('/admin')->assertOk();

        $this->admin();
        Livewire::test(ListUsers::class)
            ->callTableAction('resetPassword', $user, data: ['password' => 'BrandNewPass456', 'passwordConfirmation' => 'BrandNewPass456']);

        $this->flushSession();
        $this->actingAs($user->fresh())->withSession(['password_hash_web' => $oldHash])->get('/admin')->assertRedirect('/admin/login');
    }

    public function test_an_admin_cannot_reset_their_own_password_here(): void
    {
        $admin = $this->admin();
        $admin->update(['password' => 'OriginalPass123']);

        Livewire::test(ListUsers::class)->assertTableActionHidden('resetPassword', $admin);
        Livewire::test(EditUser::class, ['record' => $admin->getKey()])->assertActionHidden('resetPassword');

        // A crafted call that names the hidden action anyway changes nothing.
        Livewire::test(ListUsers::class)
            ->call('mountTableAction', 'resetPassword', $admin->getKey())
            ->call('callMountedTableAction', ['password' => 'BrandNewPass456', 'passwordConfirmation' => 'BrandNewPass456']);

        $this->assertTrue(Hash::check('OriginalPass123', $admin->fresh()->password));
    }

    // --- activate / deactivate --------------------------------------------------------------

    public function test_an_admin_deactivates_and_reactivates_an_account_from_the_list(): void
    {
        $this->admin();
        $user = User::factory()->editor()->create();

        Livewire::test(ListUsers::class)
            ->assertTableActionVisible('deactivate', $user)
            ->assertTableActionHidden('activate', $user)
            ->callTableAction('deactivate', $user)
            ->assertNotified();
        $this->assertFalse($user->fresh()->is_active);

        Livewire::test(ListUsers::class)
            ->assertTableActionHidden('deactivate', $user)
            ->assertTableActionVisible('activate', $user)
            ->callTableAction('activate', $user)
            ->assertNotified();
        $this->assertTrue($user->fresh()->is_active);
        $this->assertSame(Role::Editor, $user->fresh()->role, 'switching on and off never changes the role');
    }

    public function test_a_deactivated_account_is_locked_out_at_once(): void
    {
        $this->admin();
        $editor = User::factory()->editor()->create();

        Livewire::test(ListUsers::class)->callTableAction('deactivate', $editor);

        $this->actingAs($editor->fresh())->get('/admin')->assertForbidden();
    }

    public function test_the_edit_page_can_switch_an_account_off_and_on_too(): void
    {
        $this->admin();
        $user = User::factory()->journalist()->create();

        Livewire::test(EditUser::class, ['record' => $user->getKey()])
            ->assertActionVisible('deactivate')
            ->assertActionHidden('activate')
            ->callAction('deactivate');
        $this->assertFalse($user->fresh()->is_active);

        Livewire::test(EditUser::class, ['record' => $user->getKey()])
            ->assertActionVisible('activate')
            ->callAction('activate');
        $this->assertTrue($user->fresh()->is_active);
    }

    public function test_an_admin_cannot_deactivate_themselves_from_anywhere(): void
    {
        $admin = $this->admin();

        Livewire::test(ListUsers::class)->assertTableActionHidden('deactivate', $admin);
        Livewire::test(EditUser::class, ['record' => $admin->getKey()])->assertActionHidden('deactivate');

        Livewire::test(ListUsers::class)
            ->call('mountTableAction', 'deactivate', $admin->getKey())
            ->call('callMountedTableAction');
        Livewire::test(EditUser::class, ['record' => $admin->getKey()])
            ->call('mountAction', 'deactivate')
            ->call('callMountedAction');

        $this->assertTrue($admin->fresh()->is_active);
    }

    public function test_bulk_deactivate_and_activate_never_touch_the_acting_admin(): void
    {
        $admin = $this->admin();
        $one = User::factory()->journalist()->create();
        $two = User::factory()->editor()->create();
        $other = User::factory()->journalist()->create();

        Livewire::test(ListUsers::class)
            ->callTableBulkAction('deactivateSelected', [$admin, $one, $two])
            ->assertNotified('2 accounts deactivated.');

        $this->assertTrue($admin->fresh()->is_active, 'the admin was in the selection and is left alone');
        $this->assertFalse($one->fresh()->is_active);
        $this->assertFalse($two->fresh()->is_active);
        $this->assertTrue($other->fresh()->is_active, 'unselected accounts are untouched');

        Livewire::test(ListUsers::class)
            ->callTableBulkAction('activateSelected', [$one, $two])
            ->assertNotified('2 accounts activated.');

        $this->assertTrue($one->fresh()->is_active);
        $this->assertTrue($two->fresh()->is_active);
        $this->assertSame(Role::Editor, $two->fresh()->role);
    }

    public function test_bulk_deactivating_only_yourself_changes_nothing(): void
    {
        $admin = $this->admin();

        Livewire::test(ListUsers::class)
            ->callTableBulkAction('deactivateSelected', [$admin])
            ->assertNotified('Nothing was changed');

        $this->assertTrue($admin->fresh()->is_active);
    }

    public function test_bulk_actions_run_a_fixed_number_of_queries_however_many_rows_are_selected(): void
    {
        $this->admin();
        $few = User::factory()->count(2)->create();
        $manyUsers = User::factory()->count(10)->create();

        $one = $this->queryCount(fn () => Livewire::test(ListUsers::class)->callTableBulkAction('deactivateSelected', $few));
        $many = $this->queryCount(fn () => Livewire::test(ListUsers::class)->callTableBulkAction('deactivateSelected', $manyUsers));

        $this->assertSame($one, $many);
    }

    public function test_accounts_still_cannot_be_deleted_anywhere_in_the_team_area(): void
    {
        $admin = $this->admin();
        $user = User::factory()->create();

        $page = Livewire::test(ListUsers::class);
        $table = $page->instance()->getTable();
        $this->assertNull($table->getAction('delete'));
        $this->assertNull($table->getBulkAction('delete'));
        Livewire::test(EditUser::class, ['record' => $user->getKey()])->assertActionDoesNotExist('delete');
        $this->assertFalse($admin->can('delete', $user));
    }

    // --- nobody but an admin ----------------------------------------------------------------

    public static function nonAdminRoles(): array
    {
        return ['journalist' => ['journalist'], 'editor' => ['editor']];
    }

    #[DataProvider('nonAdminRoles')]
    public function test_editors_and_journalists_get_403_on_every_team_page_and_cannot_use_the_actions(string $role): void
    {
        $intruder = User::factory()->create(['role' => $role]);
        $target = User::factory()->journalist()->create(['password' => 'OriginalPass123']);
        $this->actingAs($intruder);

        foreach ([
            UserResource::getUrl('index'),
            UserResource::getUrl('create'),
            UserResource::getUrl('edit', ['record' => $target]),
        ] as $url) {
            $this->get($url)->assertForbidden();
        }

        // The actions live on these components, and none of them even mounts for a non-admin.
        Livewire::test(ListUsers::class)->assertForbidden();
        Livewire::test(CreateUser::class)->assertForbidden();
        Livewire::test(EditUser::class, ['record' => $target->getKey()])->assertForbidden();

        // ... and the policy behind them says no to every move, so a hand-built request fails too.
        foreach (['viewAny', 'create'] as $ability) {
            $this->assertFalse($intruder->can($ability, User::class), $ability);
        }
        foreach (['view', 'update', 'delete'] as $ability) {
            $this->assertFalse($intruder->can($ability, $target), $ability);
        }

        $this->assertTrue(Hash::check('OriginalPass123', $target->fresh()->password));
        $this->assertTrue($target->fresh()->is_active);
        $this->assertSame(Role::Journalist, $target->fresh()->role);
    }

    public function test_a_deactivated_admin_has_no_team_access_either(): void
    {
        $this->actingAs(User::factory()->admin()->inactive()->create());

        $this->get(UserResource::getUrl('index'))->assertForbidden();
    }
}
