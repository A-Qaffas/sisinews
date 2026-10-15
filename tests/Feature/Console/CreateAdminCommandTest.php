<?php

namespace Tests\Feature\Console;

use App\Enums\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class CreateAdminCommandTest extends TestCase
{
    use RefreshDatabase;

    private const STRONG = 'river-and-moon-2026';

    public function test_it_creates_an_active_admin_from_options(): void
    {
        $this->artisan('sisi:create-admin', [
            'email' => 'chief@sisi.news',
            '--name' => 'Chief Editor',
            '--name-ar' => 'رئيس التحرير',
            '--password' => self::STRONG,
        ])
            ->expectsOutputToContain('Admin account created for chief@sisi.news')
            ->assertSuccessful();

        $user = User::where('email', 'chief@sisi.news')->sole();
        $this->assertSame(Role::Admin, $user->role);
        $this->assertTrue($user->is_active);
        $this->assertSame('Chief Editor', $user->name);
        $this->assertSame('رئيس التحرير', $user->name_ar);
        $this->assertTrue(Hash::check(self::STRONG, $user->password));
        $this->assertNotSame(self::STRONG, $user->password);
    }

    public function test_it_refuses_the_address_reserved_for_the_newsroom_byline(): void
    {
        foreach (['desk@sisi.news', ' Desk@Sisi.News '] as $address) {
            $this->artisan('sisi:create-admin', ['email' => $address, '--password' => self::STRONG])
                ->expectsOutputToContain('reserved for the newsroom byline')
                ->assertFailed();
        }

        $this->assertDatabaseMissing('users', ['email' => 'desk@sisi.news']);
    }

    public function test_it_asks_for_the_password_when_it_is_not_given_and_confirms_it(): void
    {
        $this->artisan('sisi:create-admin', ['email' => 'night.desk@sisi.news'])
            ->expectsQuestion('Password (at least 12 characters)', self::STRONG)
            ->expectsQuestion('Confirm password', self::STRONG)
            ->assertSuccessful();

        $user = User::where('email', 'night.desk@sisi.news')->sole();
        $this->assertTrue(Hash::check(self::STRONG, $user->password));
        $this->assertSame('Night Desk', $user->name, 'the name defaults to the email local part');
        $this->assertNull($user->name_ar);
    }

    public function test_a_mismatched_confirmation_creates_nothing(): void
    {
        $this->artisan('sisi:create-admin', ['email' => 'chief@sisi.news'])
            ->expectsQuestion('Password (at least 12 characters)', self::STRONG)
            ->expectsQuestion('Confirm password', 'something-else-entirely')
            ->expectsOutputToContain('do not match')
            ->assertFailed();

        $this->assertSame(0, User::count());
    }

    public function test_it_cannot_prompt_when_the_session_is_not_interactive(): void
    {
        $this->artisan('sisi:create-admin', ['email' => 'chief@sisi.news', '--no-interaction' => true])
            ->expectsOutputToContain('No password given')
            ->assertFailed();

        $this->assertSame(0, User::count());
    }

    #[DataProvider('weakPasswords')]
    public function test_it_rejects_weak_passwords_without_echoing_them(string $weak, string $reason): void
    {
        $this->artisan('sisi:create-admin', ['email' => 'chief@sisi.news', '--password' => $weak])
            ->expectsOutputToContain($reason)
            ->doesntExpectOutputToContain($weak)
            ->assertFailed();

        $this->assertSame(0, User::count());
    }

    public static function weakPasswords(): array
    {
        return [
            'too short' => ['Short1!', 'at least 12 characters'],
            'eleven characters' => ['abcdefghijk', 'at least 12 characters'],
            'the word alone, in capitals' => ['PASSWORD', 'at least 12 characters'],
            'long enough but contains the word' => ['password12345', 'must not contain the word'],
            'the word in mixed case' => ['My-Password-Is-Long-Enough', 'must not contain the word'],
            'the word in capitals' => ['PASSWORD-with-12345', 'must not contain the word'],
        ];
    }

    public function test_the_literal_word_password_is_refused(): void
    {
        $this->artisan('sisi:create-admin', ['email' => 'chief@sisi.news', '--password' => 'password'])
            ->assertFailed();

        $this->assertSame(0, User::count());
    }

    public function test_a_weak_prompted_password_is_refused_before_asking_to_confirm(): void
    {
        $this->artisan('sisi:create-admin', ['email' => 'chief@sisi.news'])
            ->expectsQuestion('Password (at least 12 characters)', 'tooshort')
            ->expectsOutputToContain('at least 12 characters')
            ->assertFailed();

        $this->assertSame(0, User::count());
    }

    public function test_it_never_prints_the_password_on_success(): void
    {
        $this->artisan('sisi:create-admin', ['email' => 'chief@sisi.news', '--password' => self::STRONG])
            ->doesntExpectOutputToContain(self::STRONG)
            ->assertSuccessful();
    }

    public function test_it_normalises_the_email_to_lower_case(): void
    {
        $this->artisan('sisi:create-admin', ['email' => '  Chief@SISI.news ', '--password' => self::STRONG])->assertSuccessful();

        $this->assertSame('chief@sisi.news', User::sole()->email);
    }

    public function test_it_rejects_an_invalid_email(): void
    {
        $this->artisan('sisi:create-admin', ['email' => 'not-an-email', '--password' => self::STRONG])
            ->assertFailed();

        $this->assertSame(0, User::count());
    }

    public function test_an_existing_email_is_refused_and_points_to_reset_password(): void
    {
        $existing = User::factory()->admin()->create(['email' => 'chief@sisi.news']);
        $oldHash = $existing->password;

        $this->artisan('sisi:create-admin', ['email' => 'chief@sisi.news', '--password' => self::STRONG])
            ->expectsOutputToContain('--reset-password')
            ->assertFailed();

        $this->assertSame($oldHash, $existing->fresh()->password);
        $this->assertSame(1, User::count());
    }

    public function test_reset_password_changes_only_the_password_and_reactivates_the_admin(): void
    {
        $admin = User::factory()->admin()->inactive()->create(['email' => 'chief@sisi.news', 'name' => 'Chief']);

        $this->artisan('sisi:create-admin', [
            'email' => 'chief@sisi.news',
            '--reset-password' => true,
            '--password' => self::STRONG,
        ])
            ->doesntExpectOutputToContain(self::STRONG)
            ->assertSuccessful();

        $admin->refresh();
        $this->assertTrue(Hash::check(self::STRONG, $admin->password));
        $this->assertTrue($admin->is_active);
        $this->assertSame('Chief', $admin->name);
        $this->assertSame(1, User::count());
    }

    public function test_reset_password_still_validates_the_new_password(): void
    {
        $admin = User::factory()->admin()->create(['email' => 'chief@sisi.news']);
        $oldHash = $admin->password;

        $this->artisan('sisi:create-admin', ['email' => 'chief@sisi.news', '--reset-password' => true, '--password' => 'short'])
            ->assertFailed();

        $this->assertSame($oldHash, $admin->fresh()->password);
    }

    public function test_reset_password_needs_an_existing_account(): void
    {
        $this->artisan('sisi:create-admin', ['email' => 'ghost@sisi.news', '--reset-password' => true, '--password' => self::STRONG])
            ->expectsOutputToContain('no account')
            ->assertFailed();

        $this->assertSame(0, User::count());
    }

    #[DataProvider('nonAdminRoles')]
    public function test_reset_password_never_touches_a_non_admin_account(string $state): void
    {
        $user = User::factory()->{$state}()->create(['email' => 'staff@sisi.news']);
        $oldHash = $user->password;

        $this->artisan('sisi:create-admin', ['email' => 'staff@sisi.news', '--reset-password' => true, '--password' => self::STRONG])
            ->expectsOutputToContain('not an admin')
            ->assertFailed();

        $user->refresh();
        $this->assertSame($oldHash, $user->password);
        $this->assertNotSame(Role::Admin, $user->role);
    }

    public static function nonAdminRoles(): array
    {
        return ['journalist' => ['journalist'], 'editor' => ['editor']];
    }
}
