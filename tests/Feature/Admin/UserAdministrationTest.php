<?php

namespace Tests\Feature\Admin;

use App\Enums\Role;
use App\Filament\Pages\Auth\EditProfile;
use App\Filament\Resources\Users\Pages\CreateUser;
use App\Filament\Resources\Users\Pages\EditUser;
use App\Filament\Resources\Users\Pages\ListUsers;
use App\Filament\Resources\Users\UserResource;
use App\Models\User;
use Illuminate\Support\Facades\Hash;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\DataProvider;

class UserAdministrationTest extends AdminTestCase
{
    public function test_an_admin_creates_a_staff_account_with_a_hashed_password(): void
    {
        $this->actingAs(User::factory()->admin()->create());

        Livewire::test(CreateUser::class)
            ->fillForm([
                'name' => 'New Editor',
                'name_ar' => 'محرر جديد',
                'email' => 'new.editor@example.com',
                'password' => 'CorrectHorse42',
                'role' => Role::Editor->value,
                'is_active' => true,
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $user = User::query()->where('email', 'new.editor@example.com')->firstOrFail();
        $this->assertSame(Role::Editor, $user->role);
        $this->assertTrue($user->is_active);
        $this->assertTrue(Hash::check('CorrectHorse42', $user->password));
        $this->assertNotSame('CorrectHorse42', $user->password);
    }

    public function test_a_password_is_required_and_must_be_strong_for_a_new_account(): void
    {
        $this->actingAs(User::factory()->admin()->create());
        $base = ['name' => 'Someone', 'email' => 'someone@example.com', 'role' => Role::Journalist->value];

        Livewire::test(CreateUser::class)
            ->fillForm($base)
            ->call('create')
            ->assertHasFormErrors(['password' => 'required']);

        Livewire::test(CreateUser::class)
            ->fillForm([...$base, 'password' => 'short1'])
            ->call('create')
            ->assertHasFormErrors(['password']);

        Livewire::test(CreateUser::class)
            ->fillForm([...$base, 'password' => 'onlyletterspassword'])
            ->call('create')
            ->assertHasFormErrors(['password']);
    }

    public function test_the_email_must_be_unique(): void
    {
        $this->actingAs(User::factory()->admin()->create());
        User::factory()->create(['email' => 'taken@example.com']);

        Livewire::test(CreateUser::class)
            ->fillForm(['name' => 'Dup', 'email' => 'taken@example.com', 'password' => 'CorrectHorse42', 'role' => Role::Journalist->value])
            ->call('create')
            ->assertHasFormErrors(['email' => 'unique']);
    }

    public function test_an_admin_changes_another_accounts_role_and_deactivates_it(): void
    {
        $this->actingAs(User::factory()->admin()->create());
        $journalist = User::factory()->journalist()->create();

        Livewire::test(EditUser::class, ['record' => $journalist->getKey()])
            ->fillForm(['role' => Role::Editor->value, 'is_active' => false])
            ->call('save')
            ->assertHasNoFormErrors();

        $journalist->refresh();
        $this->assertSame(Role::Editor, $journalist->role);
        $this->assertFalse($journalist->is_active);
    }

    public function test_editing_an_account_never_touches_its_password(): void
    {
        $this->actingAs(User::factory()->admin()->create());
        $user = User::factory()->journalist()->create(['password' => 'OriginalPass123']);

        // The edit form has no password field (that is the "Reset password" action), even for a crafted request.
        Livewire::test(EditUser::class, ['record' => $user->getKey()])
            ->assertFormFieldHidden('password')
            ->fillForm(['name' => 'Renamed'])
            ->set('data.password', 'SneakyNewPass789')
            ->call('save')
            ->assertHasNoFormErrors();

        $user->refresh();
        $this->assertSame('Renamed', $user->name);
        $this->assertTrue(Hash::check('OriginalPass123', $user->password));
    }

    public function test_an_admin_sets_a_new_password_for_someone_else(): void
    {
        $this->actingAs(User::factory()->admin()->create());
        $user = User::factory()->journalist()->create(['password' => 'OriginalPass123']);

        Livewire::test(ListUsers::class)
            ->callTableAction('resetPassword', $user, data: ['password' => 'BrandNewPass456', 'passwordConfirmation' => 'BrandNewPass456'])
            ->assertHasNoTableActionErrors();

        $this->assertTrue(Hash::check('BrandNewPass456', $user->fresh()->password));
    }

    public function test_an_admin_cannot_demote_or_deactivate_themselves(): void
    {
        $admin = User::factory()->admin()->create();
        $this->actingAs($admin);

        Livewire::test(EditUser::class, ['record' => $admin->getKey()])
            ->assertFormFieldIsDisabled('role')
            ->assertFormFieldIsDisabled('is_active')
            ->fillForm(['name' => 'Still admin', 'role' => Role::Journalist->value, 'is_active' => false])
            ->call('save');

        $admin->refresh();
        $this->assertSame(Role::Admin, $admin->role);
        $this->assertTrue($admin->is_active);
        $this->assertSame('Still admin', $admin->name, 'the rest of the form still saves');
    }

    public function test_a_crafted_request_cannot_make_an_admin_demote_themselves_either(): void
    {
        $admin = User::factory()->admin()->create();
        $this->actingAs($admin);

        Livewire::test(EditUser::class, ['record' => $admin->getKey()])
            ->set('data.role', Role::Journalist->value)
            ->set('data.is_active', false)
            ->call('save');

        $admin->refresh();
        $this->assertSame(Role::Admin, $admin->role);
        $this->assertTrue($admin->is_active);
    }

    public function test_the_user_list_shows_roles_and_filters_by_them(): void
    {
        $this->actingAs(User::factory()->admin()->create());
        $journalist = User::factory()->journalist()->create();
        $editor = User::factory()->editor()->create();

        Livewire::test(ListUsers::class)
            ->assertCanSeeTableRecords([$journalist, $editor])
            ->filterTable('role', Role::Editor->value)
            ->assertCanSeeTableRecords([$editor])
            ->assertCanNotSeeTableRecords([$journalist]);
    }

    public function test_accounts_cannot_be_deleted_only_deactivated(): void
    {
        $admin = User::factory()->admin()->create();
        $this->actingAs($admin);

        $this->assertFalse($admin->can('delete', User::factory()->create()));
        $this->assertFalse($admin->can('deleteAny', User::class));
    }

    #[DataProvider('nonAdminRoles')]
    public function test_nobody_but_an_admin_can_reach_the_user_resource(string $role): void
    {
        $user = User::factory()->create(['role' => $role]);
        $target = User::factory()->create();
        $this->actingAs($user);

        Livewire::test(ListUsers::class)->assertForbidden();
        Livewire::test(CreateUser::class)->assertForbidden();
        Livewire::test(EditUser::class, ['record' => $target->getKey()])->assertForbidden();
        $this->get(UserResource::getUrl('index'))->assertForbidden();
        $this->assertFalse(UserResource::canViewAny());
        $this->assertFalse(UserResource::canAccess());
        $this->get('/admin')->assertOk()->assertDontSee('/admin/users');
    }

    public static function nonAdminRoles(): array
    {
        return ['journalist' => ['journalist'], 'editor' => ['editor']];
    }

    public function test_a_journalist_cannot_escalate_their_role_through_the_profile_page(): void
    {
        $journalist = User::factory()->journalist()->create();
        $this->actingAs($journalist);

        Livewire::test(EditProfile::class)
            ->assertFormFieldExists('name')
            ->assertFormFieldDoesNotExist('email')
            ->assertFormFieldDoesNotExist('role')
            ->fillForm(['name' => 'Renamed Jad'])
            ->set('data.role', Role::Admin->value)
            ->set('data.is_active', true)
            ->set('data.email', 'attacker@example.com')
            ->call('save')
            ->assertHasNoFormErrors();

        $journalist->refresh();
        $this->assertSame('Renamed Jad', $journalist->name);
        $this->assertSame(Role::Journalist, $journalist->role);
        $this->assertNotSame('attacker@example.com', $journalist->email);
    }

    public function test_staff_can_change_their_own_password_with_the_current_one(): void
    {
        $user = User::factory()->journalist()->create(['password' => 'OriginalPass123']);
        $this->actingAs($user);

        Livewire::test(EditProfile::class)
            ->fillForm(['password' => 'BrandNewPass456', 'passwordConfirmation' => 'BrandNewPass456', 'currentPassword' => 'wrong-password'])
            ->call('save')
            ->assertHasFormErrors(['currentPassword']);
        $this->assertTrue(Hash::check('OriginalPass123', $user->fresh()->password));

        Livewire::test(EditProfile::class)
            ->fillForm(['password' => 'BrandNewPass456', 'passwordConfirmation' => 'BrandNewPass456', 'currentPassword' => 'OriginalPass123'])
            ->call('save')
            ->assertHasNoFormErrors();
        $this->assertTrue(Hash::check('BrandNewPass456', $user->fresh()->password));
    }
}
