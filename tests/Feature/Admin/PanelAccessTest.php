<?php

namespace Tests\Feature\Admin;

use App\Filament\Pages\Auth\Login;
use App\Models\User;
use Illuminate\Support\Facades\Route;
use Livewire\Livewire;

class PanelAccessTest extends AdminTestCase
{
    public function test_guests_are_sent_to_the_staff_login(): void
    {
        $this->get('/admin')->assertRedirect('/admin/login');
        $this->get('/admin/articles')->assertRedirect('/admin/login');
    }

    public function test_active_staff_reach_the_dashboard(): void
    {
        foreach ([User::factory()->journalist(), User::factory()->editor(), User::factory()->admin()] as $factory) {
            $this->actingAs($factory->create())->get('/admin')->assertOk();
        }
    }

    public function test_a_deactivated_account_cannot_enter_the_panel(): void
    {
        $inactive = User::factory()->editor()->inactive()->create();

        $this->actingAs($inactive)->get('/admin')->assertForbidden();
        $this->actingAs($inactive)->get('/admin/articles')->assertForbidden();
    }

    public function test_a_deactivated_account_cannot_sign_in(): void
    {
        $inactive = User::factory()->editor()->inactive()->create(['email' => 'gone@example.com', 'password' => 'CorrectHorse42']);

        Livewire::test(Login::class)
            ->fillForm(['email' => 'gone@example.com', 'password' => 'CorrectHorse42'])
            ->call('authenticate')
            ->assertHasFormErrors(['email']);

        $this->assertGuest();
    }

    public function test_an_active_account_can_sign_in(): void
    {
        User::factory()->journalist()->create(['email' => 'jad@example.com', 'password' => 'CorrectHorse42']);

        Livewire::test(Login::class)
            ->fillForm(['email' => 'jad@example.com', 'password' => 'CorrectHorse42'])
            ->call('authenticate')
            ->assertHasNoFormErrors();

        $this->assertAuthenticated();
    }

    public function test_v1_has_no_registration_password_reset_or_email_verification(): void
    {
        $this->assertFalse(Route::has('filament.admin.auth.register'));
        $this->assertFalse(Route::has('filament.admin.auth.password-reset.request'));
        $this->assertFalse(Route::has('filament.admin.auth.password-reset.reset'));
        $this->assertFalse(Route::has('filament.admin.auth.email-verification.prompt'));

        $this->get('/admin/register')->assertNotFound();
        $this->get('/admin/password-reset/request')->assertNotFound();
        $this->get('/admin/login')->assertDontSee('Forgot')->assertDontSee('Sign up');
    }

    public function test_the_login_page_is_branded_and_in_english_although_the_site_default_is_arabic(): void
    {
        app()->setLocale('ar');

        $this->get('/admin/login')
            ->assertOk()
            ->assertSee('brand/logo-380.webp', false)
            ->assertDontSee('brand/logo.png', false)
            ->assertSee('brand/icon.png', false)
            ->assertSee('Newsroom sign in')
            ->assertSee('lang="en"', false)
            ->assertSee('dir="ltr"', false);
    }

    public function test_the_panel_never_calls_a_third_party_avatar_service(): void
    {
        $response = $this->actingAs(User::factory()->journalist()->create(['name' => 'Jad Habib']))->get('/admin');

        $response->assertOk()->assertDontSee('ui-avatars.com');
    }

    public function test_the_editor_styles_for_mixed_arabic_and_english_text_are_on_every_panel_page(): void
    {
        $this->actingAs(User::factory()->journalist()->create())
            ->get('/admin')
            ->assertSee('.fi-fo-rich-editor .ProseMirror', false);
    }
}
