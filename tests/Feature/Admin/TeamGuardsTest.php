<?php

namespace Tests\Feature\Admin;

use App\Enums\Role;
use App\Filament\Resources\Users\Pages\CreateUser;
use App\Filament\Resources\Users\Pages\EditUser;
use App\Filament\Resources\Users\Pages\ListUsers;
use App\Filament\Resources\Users\Support\TeamGuard;
use App\Filament\Resources\Users\UserResource;
use App\Models\User;
use Database\Seeders\Support\LaunchContentImporter;
use Filament\Notifications\Notification;
use Illuminate\Database\Query\Grammars\MySqlGrammar;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Livewire\Livewire;

/**
 * Two rules the Team area enforces on the server: the launch byline account (desk@sisi.news) is out of
 * reach, and there is always at least one active administrator.
 */
class TeamGuardsTest extends AdminTestCase
{
    private function admin(): User
    {
        return tap(User::factory()->admin()->create(), fn (User $admin) => $this->actingAs($admin));
    }

    /** The account the launch importer creates: inactive, credited on the launch stories, nobody signs in as it. */
    private function desk(array $attributes = []): User
    {
        return User::factory()->journalist()->inactive()->create([
            'name' => 'sisi.news Desk',
            'email' => TeamGuard::BYLINE_EMAIL,
            'password' => 'UnguessableDeskPass42',
            ...$attributes,
        ]);
    }

    private function assertDeskUntouched(User $desk, string $role = 'journalist'): void
    {
        $desk->refresh();
        $this->assertFalse($desk->is_active, 'the byline account must stay switched off');
        $this->assertSame($role, $desk->role->value);
        $this->assertTrue(Hash::check('UnguessableDeskPass42', $desk->password), 'its password must not change');
    }

    // --- the byline account -----------------------------------------------------------------

    public function test_the_guard_uses_the_address_the_launch_importer_creates(): void
    {
        $this->assertSame(LaunchContentImporter::BYLINE_EMAIL, TeamGuard::BYLINE_EMAIL);
    }

    public function test_the_reserved_address_is_recognised_whatever_its_case_or_padding(): void
    {
        foreach (['desk@sisi.news', 'Desk@Sisi.News', '  desk@sisi.news ', 'DESK@SISI.NEWS'] as $email) {
            $this->assertTrue(TeamGuard::isReservedEmail($email), $email);
        }
        foreach (['night.desk@sisi.news', 'desk@sisi.news.example', 'desk2@sisi.news', 'desk@example.com', '', null] as $email) {
            $this->assertFalse(TeamGuard::isReservedEmail($email), (string) $email);
        }
    }

    public function test_the_byline_account_is_not_in_the_team_list_or_its_search(): void
    {
        $this->admin();
        $desk = $this->desk();
        $editor = User::factory()->editor()->create(['name' => 'Mona Editor']);

        Livewire::test(ListUsers::class)
            ->assertCanSeeTableRecords([$editor])
            ->assertCanNotSeeTableRecords([$desk])
            ->searchTable('desk@sisi.news')
            ->assertCanNotSeeTableRecords([$desk])
            ->searchTable('sisi.news Desk')
            ->assertCanNotSeeTableRecords([$desk]);
    }

    public function test_the_byline_account_is_hidden_even_when_its_address_is_written_in_capitals(): void
    {
        $this->admin();
        $desk = $this->desk(['email' => 'Desk@Sisi.News']);

        Livewire::test(ListUsers::class)->assertCanNotSeeTableRecords([$desk]);
    }

    public function test_the_byline_account_has_no_edit_page(): void
    {
        $this->admin();
        $desk = $this->desk();

        $this->get(UserResource::getUrl('edit', ['record' => $desk]))->assertNotFound();

        Livewire::test(EditUser::class, ['record' => $desk->getKey()])->assertNotFound();
    }

    public function test_a_crafted_row_action_on_the_byline_account_changes_nothing(): void
    {
        $this->admin();
        $desk = $this->desk();

        foreach ([
            ['activate', []],
            ['resetPassword', ['password' => 'BrandNewPass456', 'passwordConfirmation' => 'BrandNewPass456']],
            ['deactivate', []],
        ] as [$action, $data]) {
            try {
                Livewire::test(ListUsers::class)->callTableAction($action, $desk, data: $data);
            } catch (\Throwable) {
                // Refusing loudly (a 404) is as good as refusing quietly; what matters is the account below.
            }

            $this->assertDeskUntouched($desk);
        }
    }

    public function test_the_byline_account_is_skipped_by_bulk_actions_even_when_it_is_in_the_selection(): void
    {
        $this->admin();
        $desk = $this->desk();
        $other = User::factory()->journalist()->inactive()->create();

        Livewire::test(ListUsers::class)
            ->callTableBulkAction('activateSelected', [$desk, $other]);

        $this->assertDeskUntouched($desk);
        $this->assertTrue($other->fresh()->is_active, 'the real account in the same selection was activated');
    }

    public function test_nobody_can_create_an_account_on_the_byline_address(): void
    {
        $this->admin();

        foreach (['desk@sisi.news', 'Desk@Sisi.News', 'DESK@SISI.NEWS'] as $email) {
            Livewire::test(CreateUser::class)
                ->fillForm(['name' => 'Impostor', 'email' => $email, 'password' => 'CorrectHorse42', 'role' => Role::Admin->value])
                ->call('create')
                ->assertHasFormErrors(['email']);
        }

        $this->assertSame(0, User::query()->where('name', 'Impostor')->count());
    }

    public function test_nobody_can_move_an_account_onto_the_byline_address(): void
    {
        $this->admin();
        $person = User::factory()->journalist()->create(['email' => 'rana@example.com']);

        Livewire::test(EditUser::class, ['record' => $person->getKey()])
            ->fillForm(['email' => 'desk@sisi.news'])
            ->call('save')
            ->assertHasFormErrors(['email']);

        $this->assertSame('rana@example.com', $person->fresh()->email);
    }

    public function test_similar_addresses_stay_available_to_real_people(): void
    {
        $this->admin();

        Livewire::test(CreateUser::class)
            ->fillForm(['name' => 'Night Desk', 'email' => 'night.desk@sisi.news', 'password' => 'CorrectHorse42', 'role' => Role::Journalist->value])
            ->call('create')
            ->assertHasNoFormErrors();

        $this->assertTrue(User::query()->where('email', 'night.desk@sisi.news')->exists());
    }

    public function test_the_byline_account_never_counts_as_an_administrator(): void
    {
        $desk = $this->desk(['role' => Role::Admin, 'is_active' => true]);
        $real = User::factory()->admin()->create();

        $ran = false;
        $done = TeamGuard::keepingAnAdministrator([$real->getKey()], function () use (&$ran) {
            $ran = true;
        });

        $this->assertFalse($done, 'the only real administrator may not leave just because the desk account could stand in');
        $this->assertFalse($ran);
        $this->assertSame([$real->getKey()], TeamGuard::lockedAdministrators()->pluck('id')->all());
        $this->assertNotContains($desk->getKey(), TeamGuard::lockedAdministrators()->pluck('id')->all());
    }

    // --- the guard itself -------------------------------------------------------------------

    public function test_the_change_runs_inside_a_transaction_that_locks_the_administrator_rows(): void
    {
        User::factory()->admin()->count(2)->create();
        $outside = DB::transactionLevel();

        $inside = null;
        TeamGuard::keepingAnAdministrator([], function () use (&$inside) {
            $inside = DB::transactionLevel();
        });
        $this->assertGreaterThan($outside, $inside);

        // SQLite (the test database) has no row locks, so check the statement MySQL and MariaDB would run.
        $locking = TeamGuard::lockedAdministrators()->toBase();
        $this->assertSame('for update', strtolower(substr((new MySqlGrammar(DB::connection()))->compileSelect($locking), -10)));
        $this->assertStringContainsString('"role" = ?', TeamGuard::lockedAdministrators()->toSql());
    }

    public function test_the_last_active_administrator_cannot_leave(): void
    {
        $only = User::factory()->admin()->create();
        User::factory()->editor()->create();
        User::factory()->admin()->inactive()->create();

        $ran = false;
        $this->assertFalse(TeamGuard::keepingAnAdministrator([$only->getKey()], function () use (&$ran) {
            $ran = true;
        }));
        $this->assertFalse($ran, 'a refused change is never run');
    }

    public function test_an_administrator_may_leave_while_another_one_stays(): void
    {
        $one = User::factory()->admin()->create();
        $two = User::factory()->admin()->create();

        $this->assertTrue(TeamGuard::keepingAnAdministrator([$one->getKey()], fn () => $one->update(['is_active' => false])));
        $this->assertFalse($one->fresh()->is_active);

        // Now $two is the last one.
        $this->assertFalse(TeamGuard::keepingAnAdministrator([$two->getKey()], fn () => $two->update(['is_active' => false])));
        $this->assertTrue($two->fresh()->is_active);
    }

    public function test_everyone_may_leave_together_only_if_somebody_else_stays(): void
    {
        $one = User::factory()->admin()->create();
        $two = User::factory()->admin()->create();
        $stays = User::factory()->admin()->create();

        $this->assertFalse(TeamGuard::keepingAnAdministrator([$one->getKey(), $two->getKey(), $stays->getKey()], fn () => null));
        $this->assertTrue(TeamGuard::keepingAnAdministrator([$one->getKey(), (string) $two->getKey()], fn () => null), 'ids may arrive as strings');
    }

    public function test_accounts_that_are_not_active_administrators_are_never_blocked(): void
    {
        $admin = User::factory()->admin()->create();
        $editor = User::factory()->editor()->create();
        $retired = User::factory()->admin()->inactive()->create();

        $this->assertTrue(TeamGuard::keepingAnAdministrator([$editor->getKey(), $retired->getKey()], fn () => null));
        $this->assertTrue(TeamGuard::keepingAnAdministrator([$admin->getKey() + 1000], fn () => null), 'an unknown id changes nothing');
    }

    public function test_a_change_that_throws_is_rolled_back(): void
    {
        $one = User::factory()->admin()->create();
        $two = User::factory()->admin()->create();

        try {
            TeamGuard::keepingAnAdministrator([$one->getKey()], function () use ($one) {
                $one->update(['is_active' => false]);
                throw new \RuntimeException('boom');
            });
        } catch (\RuntimeException) {
        }

        $this->assertTrue($one->fresh()->is_active);
        $this->assertTrue($two->fresh()->is_active);
    }

    // --- the moves in the Team area ---------------------------------------------------------

    public function test_an_administrator_can_still_be_deactivated_or_demoted_while_another_remains(): void
    {
        $this->admin();
        $other = User::factory()->admin()->create();
        $third = User::factory()->admin()->create();

        Livewire::test(ListUsers::class)->callTableAction('deactivate', $other)->assertNotified("{$other->name} was deactivated.");
        $this->assertFalse($other->fresh()->is_active);

        Livewire::test(EditUser::class, ['record' => $third->getKey()])
            ->fillForm(['role' => Role::Editor->value])
            ->call('save')
            ->assertHasNoFormErrors();
        $this->assertSame(Role::Editor, $third->fresh()->role);
    }

    /**
     * Two admins who switch each other off at the same moment. SQLite cannot hold two requests at once, so the
     * second one is replayed with the state its request would have started from (still signed in as an active
     * admin, because the first one had not committed yet) and the database as the first one left it.
     * On MySQL/MariaDB the row lock makes the second request wait for the first and then see the same thing.
     */
    public function test_two_admins_deactivating_each_other_leave_one_of_them_active(): void
    {
        $a = User::factory()->admin()->create(['name' => 'Admin A']);
        $b = User::factory()->admin()->create(['name' => 'Admin B']);

        $this->actingAs($a);
        $asA = Livewire::test(ListUsers::class);
        $this->actingAs($b);
        $asB = Livewire::test(ListUsers::class);

        $this->actingAs($a);
        $asA->callTableAction('deactivate', $b)->assertNotified('Admin B was deactivated.');
        $this->assertFalse($b->fresh()->is_active);

        // B's request was already past authorization when A's committed.
        $this->actingAs($b);
        $this->assertTrue($b->is_active, 'B is still "active" in the request that is in flight');
        $asB->callTableAction('deactivate', $a)->assertNotified('Nothing was changed');

        $this->assertTrue($a->fresh()->is_active);
        $this->assertSame(1, User::query()->where('role', Role::Admin->value)->where('is_active', true)->count());
    }

    public function test_the_same_race_through_the_bulk_action_changes_nothing_at_all(): void
    {
        $a = User::factory()->admin()->create(['name' => 'Admin A']);
        $b = User::factory()->admin()->create(['name' => 'Admin B']);
        $journalist = User::factory()->journalist()->create();

        $this->actingAs($a);
        Livewire::test(ListUsers::class)->callTableBulkAction('deactivateSelected', [$b]);
        $this->assertFalse($b->fresh()->is_active);

        $this->actingAs($b);
        Livewire::test(ListUsers::class)
            ->callTableBulkAction('deactivateSelected', [$a, $journalist])
            ->assertNotified('Nothing was changed');

        $this->assertTrue($a->fresh()->is_active);
        $this->assertTrue($journalist->fresh()->is_active, 'all or nothing: the rest of the selection is not switched off either');
    }

    public function test_the_same_race_through_the_edit_page_deactivate_button(): void
    {
        $a = User::factory()->admin()->create(['name' => 'Admin A']);
        $b = User::factory()->admin()->create(['name' => 'Admin B']);

        $this->actingAs($a);
        $pageOfB = Livewire::test(EditUser::class, ['record' => $b->getKey()]);
        $this->actingAs($b);
        $pageOfA = Livewire::test(EditUser::class, ['record' => $a->getKey()]);

        $this->actingAs($a);
        $pageOfB->callAction('deactivate');
        $this->assertFalse($b->fresh()->is_active);

        $this->actingAs($b);
        $pageOfA->callAction('deactivate')->assertNotified('Nothing was changed');
        $this->assertTrue($a->fresh()->is_active);
    }

    public function test_the_same_race_through_the_edit_form_refuses_a_demotion_and_shows_what_is_stored(): void
    {
        $a = User::factory()->admin()->create(['name' => 'Admin A']);
        $b = User::factory()->admin()->create(['name' => 'Admin B']);

        $this->actingAs($a);
        $formOfB = Livewire::test(EditUser::class, ['record' => $b->getKey()]);
        $this->actingAs($b);
        $formOfA = Livewire::test(EditUser::class, ['record' => $a->getKey()]);

        $this->actingAs($a);
        $formOfB->fillForm(['role' => Role::Editor->value])->call('save')->assertHasNoFormErrors();
        $this->assertSame(Role::Editor, $b->fresh()->role);

        $this->actingAs($b);
        $formOfA
            ->fillForm(['role' => Role::Journalist->value])
            ->call('save')
            ->assertNotified('Nothing was changed')
            ->assertFormSet(['role' => Role::Admin->value, 'is_active' => true]);

        $this->assertSame(Role::Admin, $a->fresh()->role);
    }

    public function test_the_edit_form_cannot_switch_off_the_last_administrator_with_the_active_toggle(): void
    {
        $a = User::factory()->admin()->create();
        $b = User::factory()->admin()->create();

        $this->actingAs($a);
        $formOfB = Livewire::test(EditUser::class, ['record' => $b->getKey()]);
        $this->actingAs($b);
        $formOfA = Livewire::test(EditUser::class, ['record' => $a->getKey()]);

        $this->actingAs($a);
        $formOfB->fillForm(['is_active' => false])->call('save');
        $this->assertFalse($b->fresh()->is_active);

        $this->actingAs($b);
        $formOfA->fillForm(['is_active' => false])->call('save')->assertNotified('Nothing was changed');
        $this->assertTrue($a->fresh()->is_active);
    }

    public function test_an_ordinary_edit_of_an_administrator_is_not_affected_by_the_guard(): void
    {
        $this->admin();
        $other = User::factory()->admin()->create(['name' => 'Old Name']);

        Livewire::test(EditUser::class, ['record' => $other->getKey()])
            ->fillForm(['name' => 'New Name'])
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertSame('New Name', $other->fresh()->name);
        $this->assertSame(Role::Admin, $other->fresh()->role);
    }

    public function test_the_refusal_names_what_to_do(): void
    {
        $a = User::factory()->admin()->create();
        $b = User::factory()->admin()->create();

        $this->actingAs($a);
        $asA = Livewire::test(ListUsers::class);
        $this->actingAs($b);
        $asB = Livewire::test(ListUsers::class);

        $this->actingAs($a);
        $asA->callTableAction('deactivate', $b);

        $this->actingAs($b);
        $asB->callTableAction('deactivate', $a)
            ->assertNotified(
                Notification::make()->danger()
                    ->title('Nothing was changed')
                    ->body('At least one active administrator must remain. Make another account an active administrator first.'),
            );
    }
}
