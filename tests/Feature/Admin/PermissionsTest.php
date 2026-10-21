<?php

namespace Tests\Feature\Admin;

use App\Enums\Permission;
use App\Enums\Role;
use App\Enums\TranslationStatus;
use App\Filament\Resources\Users\Pages\CreateUser;
use App\Filament\Resources\Users\Pages\EditUser;
use App\Filament\Resources\Users\Pages\ListUsers;
use App\Filament\Resources\Users\Support\TeamGuard;
use App\Models\Article;
use App\Models\ArticleTranslation;
use App\Models\Category;
use App\Models\User;
use Livewire\Livewire;

/**
 * A role is a ready-made bundle of permissions; an admin can tick or untick single ones per person on the Team form
 * ("a journalist who may create sections but not approve stories"). Policies ask for permissions, never roles.
 */
class PermissionsTest extends AdminTestCase
{
    /** @param  list<Permission>  $permissions */
    private function staff(Role $role, array $grant = [], array $revoke = []): User
    {
        $user = User::factory()->create(['role' => $role]);
        $chosen = array_filter(
            [...$role->permissions(), ...$grant],
            fn (Permission $p): bool => ! in_array($p, $revoke, true),
        );
        $user->choosePermissions($chosen);
        $user->save();

        return $user->fresh();
    }

    private function storyInReview(): ArticleTranslation
    {
        $article = Article::factory()->create();

        return ArticleTranslation::factory()->for($article)->create(['status' => TranslationStatus::InReview]);
    }

    // --- the bundles -----------------------------------------------------------------------------

    public function test_the_bundles_keep_exactly_what_each_role_could_do_before(): void
    {
        $journalist = User::factory()->journalist()->create();
        $editor = User::factory()->editor()->create();
        $admin = User::factory()->admin()->create();

        $this->assertSame([Permission::WriteStories], $journalist->permissions());
        $this->assertTrue($editor->hasPermission(Permission::PublishStories));
        $this->assertTrue($editor->hasPermission(Permission::ManageSections));
        foreach ([Permission::PurgeStories, Permission::DeletePages, Permission::DeleteSubscribers, Permission::ManageTeam, Permission::ManageSettings] as $adminOnly) {
            $this->assertFalse($editor->hasPermission($adminOnly), $adminOnly->value.' stays with admins');
        }
        $this->assertSame(Permission::cases(), $admin->permissions());
        $this->assertFalse($journalist->hasCustomPermissions());
    }

    public function test_a_deactivated_account_has_no_permissions_but_keeps_its_settings(): void
    {
        $user = $this->staff(Role::Journalist, grant: [Permission::ManageSections]);
        $user->update(['is_active' => false]);

        $this->assertSame([], $user->permissions());
        $this->assertContains(Permission::ManageSections, $user->configuredPermissions());
    }

    public function test_what_is_stored_is_only_the_difference_from_the_bundle(): void
    {
        $user = $this->staff(Role::Editor, grant: [Permission::ManageSettings], revoke: [Permission::PublishStories]);

        $this->assertSame(['settings.manage'], $user->permissions_granted);
        $this->assertSame(['stories.publish'], $user->permissions_revoked);
        $this->assertTrue($user->hasCustomPermissions());

        $user->choosePermissions(Role::Editor->permissions());
        $this->assertNull($user->permissions_granted);
        $this->assertNull($user->permissions_revoked);
    }

    public function test_a_permission_that_no_longer_exists_is_ignored(): void
    {
        $user = User::factory()->journalist()->create();
        $user->forceFill(['permissions_granted' => ['stories.write', 'something.removed']])->save();

        $this->assertSame([Permission::WriteStories], $user->fresh()->permissions());
    }

    // --- the owner's example: sections yes, approving no -------------------------------------------

    public function test_a_journalist_given_sections_can_manage_sections_but_still_cannot_approve(): void
    {
        $user = $this->staff(Role::Journalist, grant: [Permission::ManageSections]);
        $this->actingAs($user);

        $this->get('/admin/categories/create')->assertOk();
        $this->assertTrue($user->can('create', Category::class));
        $this->assertTrue($user->can('delete', Category::factory()->create()));

        $story = $this->storyInReview();
        $this->assertFalse($user->can('approve', $story));
        $this->get('/admin/review-queue')->assertForbidden();
        $this->get('/admin/tags/create')->assertForbidden();
    }

    public function test_an_editor_without_publishing_edits_everything_but_approves_nothing(): void
    {
        $user = $this->staff(Role::Editor, revoke: [Permission::PublishStories]);
        $story = $this->storyInReview();

        $this->assertFalse($user->can('approve', $story));
        $this->assertFalse($user->can('requestChanges', $story));
        $this->assertTrue($user->can('update', $story));
        $this->actingAs($user)->get('/admin/review-queue')->assertForbidden();
    }

    public function test_a_journalist_may_be_allowed_every_section_without_assignments(): void
    {
        $user = $this->staff(Role::Journalist, grant: [Permission::WriteInAllSections]);

        $this->assertTrue($user->canWriteIn(Category::factory()->create()));
        $this->assertTrue($user->can('create', Article::class));
    }

    public function test_without_write_stories_nobody_starts_a_story(): void
    {
        $user = $this->staff(Role::Editor, revoke: [Permission::WriteStories]);

        $this->assertFalse($user->can('create', Article::class));
        $this->assertFalse($user->canWriteIn(Category::factory()->create()));
    }

    public function test_an_admin_without_team_management_cannot_open_the_team(): void
    {
        User::factory()->admin()->create(); // someone still manages the team
        $user = $this->staff(Role::Admin, revoke: [Permission::ManageTeam]);

        $this->actingAs($user)->get('/admin/users')->assertForbidden();
        $this->actingAs($user)->get('/admin/site-settings')->assertOk();
    }

    // --- the Team form ------------------------------------------------------------------------------

    public function test_choosing_a_role_ticks_its_bundle_and_single_boxes_can_then_change(): void
    {
        $this->actingAs(User::factory()->admin()->create());

        Livewire::test(CreateUser::class)
            ->assertFormSet(['permissions_stories' => ['stories.write'], 'permissions_taxonomy' => []])
            ->fillForm(['role' => 'editor'])
            ->assertFormSet(['permissions_taxonomy' => ['sections.manage', 'tags.manage'], 'permissions_administration' => []])
            ->fillForm(['role' => 'journalist'])
            ->assertFormSet(['permissions_taxonomy' => []])
            ->fillForm([
                'name' => 'Hany',
                'email' => 'hany@example.com',
                'password' => 'CorrectHorse42',
                'permissions_taxonomy' => ['sections.manage'],
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $hany = User::query()->where('email', 'hany@example.com')->sole();
        $this->assertSame([Permission::WriteStories, Permission::ManageSections], $hany->permissions());
        $this->assertSame(['sections.manage'], $hany->permissions_granted);
    }

    public function test_editing_shows_the_stored_boxes_and_saves_the_change(): void
    {
        $this->actingAs(User::factory()->admin()->create());
        $editor = User::factory()->editor()->create();

        Livewire::test(EditUser::class, ['record' => $editor->getRouteKey()])
            ->assertFormSet(['permissions_stories' => ['stories.write', 'stories.all_sections', 'stories.edit_all', 'stories.publish', 'stories.delete']])
            ->fillForm(['permissions_stories' => ['stories.write', 'stories.all_sections', 'stories.edit_all', 'stories.delete']])
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertFalse($editor->fresh()->hasPermission(Permission::PublishStories));
        $this->assertSame(['stories.publish'], $editor->fresh()->permissions_revoked);
    }

    public function test_the_team_list_marks_people_with_custom_permissions(): void
    {
        $this->actingAs(User::factory()->admin()->create());
        $this->staff(Role::Journalist, grant: [Permission::ManageSections]);

        Livewire::test(ListUsers::class)->assertSee(__('Custom permissions'));
    }

    public function test_your_own_permissions_are_locked_even_for_a_crafted_request(): void
    {
        $me = User::factory()->admin()->create();
        User::factory()->admin()->create();
        $this->actingAs($me);

        Livewire::test(EditUser::class, ['record' => $me->getRouteKey()])
            ->assertFormFieldIsDisabled('permissions_administration')
            ->set('data.permissions_administration', [])
            ->call('save');

        $this->assertTrue($me->fresh()->hasPermission(Permission::ManageTeam));
        $this->assertFalse($me->fresh()->hasCustomPermissions());
    }

    public function test_the_section_picker_follows_write_in_every_section_not_the_role(): void
    {
        $this->actingAs(User::factory()->admin()->create());

        Livewire::test(CreateUser::class)
            ->assertFormFieldIsVisible('section_ids')
            ->fillForm(['permissions_stories' => ['stories.write', 'stories.all_sections']])
            ->assertFormFieldIsHidden('section_ids')
            ->fillForm(['permissions_stories' => []])
            ->assertFormFieldIsHidden('section_ids');
    }

    // --- someone must always be able to manage the team ----------------------------------------

    public function test_a_person_given_team_management_by_hand_counts_as_an_administrator(): void
    {
        $admin = User::factory()->admin()->create();
        $manager = $this->staff(Role::Editor, grant: [Permission::ManageTeam]);

        $this->assertTrue(TeamGuard::keepingAnAdministrator([$admin->getKey()], fn () => $admin->update(['is_active' => false])));
        $this->assertFalse(TeamGuard::keepingAnAdministrator([$manager->getKey()], fn () => $manager->update(['is_active' => false])));
    }

    public function test_taking_team_management_from_the_last_person_who_has_it_is_undone(): void
    {
        $admin = User::factory()->admin()->create();

        $done = TeamGuard::keepingAnAdministrator([], function () use ($admin): void {
            $admin->choosePermissions(array_filter(Permission::cases(), fn (Permission $p): bool => $p !== Permission::ManageTeam));
            $admin->save();
        });

        $this->assertFalse($done);
        $this->assertTrue($admin->fresh()->hasPermission(Permission::ManageTeam), 'the change was rolled back');
    }

    public function test_an_admin_whose_team_management_was_unticked_no_longer_counts(): void
    {
        $this->staff(Role::Admin, revoke: [Permission::ManageTeam]);
        $real = User::factory()->admin()->create();

        $this->assertFalse(TeamGuard::keepingAnAdministrator([$real->getKey()], fn () => $real->update(['is_active' => false])));
    }
}
