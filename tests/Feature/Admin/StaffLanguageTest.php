<?php

namespace Tests\Feature\Admin;

use App\Enums\Locale;
use App\Filament\Http\Middleware\UseStaffLanguage;
use App\Filament\Pages\Auth\EditProfile;
use App\Filament\Resources\Users\Pages\CreateUser;
use App\Models\User;
use Livewire\Livewire;

/**
 * The staff panel speaks Arabic unless the person picked English (user menu, login page or profile). The choice is
 * saved on the account; before sign-in it lives in the session. The suite runs the panel in English
 * (phpunit.xml ADMIN_LOCALE=en), so these tests set the production default explicitly.
 */
class StaffLanguageTest extends AdminTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config(['site.admin_locale' => 'ar']);
    }

    public function test_an_account_that_never_chose_opens_the_panel_in_arabic_right_to_left(): void
    {
        $this->actingAs(User::factory()->journalist()->create())
            ->get('/admin')
            ->assertOk()
            ->assertSee('lang="ar"', false)->assertSee('dir="rtl"', false);
    }

    public function test_the_arabic_panel_shows_our_own_menus_in_arabic_too(): void
    {
        $this->actingAs(User::factory()->admin()->create())
            ->get('/admin')
            ->assertOk()
            ->assertSee('غرفة الأخبار')
            ->assertSee('فريق العمل')
            ->assertDontSeeText('Newsroom')
            ->assertDontSeeText('Review queue');
    }

    public function test_the_sidebar_groups_follow_the_person_not_the_language_the_app_booted_in(): void
    {
        // The panel is configured at boot, before the language is picked: the group labels must be worked out later.
        app()->setLocale('ar');

        $this->actingAs(User::factory()->admin()->create(['locale' => Locale::En]))
            ->get('/admin')
            ->assertSeeText('Newsroom')
            ->assertDontSeeText('غرفة الأخبار');
    }

    public function test_an_account_that_chose_english_gets_english_left_to_right(): void
    {
        $this->actingAs(User::factory()->editor()->create(['locale' => Locale::En]))
            ->get('/admin')
            ->assertOk()
            ->assertSee('lang="en"', false)->assertSee('dir="ltr"', false);
    }

    public function test_the_user_menu_offers_the_other_language_in_its_own_script(): void
    {
        $this->actingAs(User::factory()->journalist()->create())
            ->get('/admin')
            ->assertSee(route('admin.language', ['locale' => 'en']), false)
            ->assertSee('English');

        $this->actingAs(User::factory()->journalist()->create(['locale' => Locale::En]))
            ->get('/admin')
            ->assertSee(route('admin.language', ['locale' => 'ar']), false)
            ->assertSee('العربية');
    }

    public function test_switching_saves_the_choice_on_the_account_and_returns_to_the_same_page(): void
    {
        $user = User::factory()->journalist()->create();
        $from = url('/admin/articles');

        $this->actingAs($user)
            ->get('/admin/language/en', ['referer' => $from])
            ->assertRedirect($from);

        $this->assertSame(Locale::En, $user->fresh()->locale);
        $this->actingAs($user->fresh())->get('/admin')->assertSee('lang="en"', false)->assertSee('dir="ltr"', false);
    }

    public function test_it_never_redirects_off_the_panel_whatever_the_referer_says(): void
    {
        $user = User::factory()->journalist()->create();

        foreach (['https://evil.example/admin', url('/ar'), url('/admin/language/ar'), ''] as $referer) {
            $this->actingAs($user)
                ->get('/admin/language/en', $referer === '' ? [] : ['referer' => $referer])
                ->assertRedirect(url('/admin'));
        }
    }

    public function test_before_sign_in_the_choice_lives_in_the_session_and_the_login_page_offers_the_other_language(): void
    {
        $this->get('/admin/login')
            ->assertOk()
            ->assertSee('lang="ar"', false)->assertSee('dir="rtl"', false)
            ->assertSee(route('admin.language', ['locale' => 'en']), false);

        $this->get('/admin/language/en')->assertSessionHas(UseStaffLanguage::SESSION_KEY, 'en');

        $this->get('/admin/login')
            ->assertSee('lang="en"', false)->assertSee('dir="ltr"', false)
            ->assertSee(route('admin.language', ['locale' => 'ar']), false);
    }

    public function test_a_deactivated_account_is_not_written_to(): void
    {
        $inactive = User::factory()->editor()->inactive()->create();

        $this->actingAs($inactive)->get('/admin/language/en')->assertRedirect();

        $this->assertNull($inactive->fresh()->locale);
    }

    public function test_only_the_two_site_languages_exist(): void
    {
        $this->get('/admin/language/fr')->assertNotFound();
        $this->get('/admin/language/AR')->assertNotFound();
    }

    public function test_the_profile_page_switches_the_language_and_reloads_in_it(): void
    {
        $user = User::factory()->journalist()->create();
        $this->actingAs($user);

        Livewire::test(EditProfile::class)
            ->assertFormSet(['locale' => 'ar'])
            ->fillForm(['locale' => 'en'])
            ->call('save')
            ->assertHasNoFormErrors()
            ->assertRedirect(EditProfile::getUrl());

        $this->assertSame(Locale::En, $user->fresh()->locale);
    }

    public function test_saving_the_profile_without_changing_the_language_stays_on_the_page(): void
    {
        $this->actingAs(User::factory()->journalist()->create(['locale' => Locale::En]));

        Livewire::test(EditProfile::class)
            ->assertFormSet(['locale' => 'en'])
            ->fillForm(['name' => 'Renamed'])
            ->call('save')
            ->assertHasNoFormErrors()
            ->assertNoRedirect();
    }

    public function test_new_staff_start_in_arabic_and_an_admin_can_set_english(): void
    {
        $this->actingAs(User::factory()->admin()->create());

        Livewire::test(CreateUser::class)
            ->assertFormSet(['locale' => 'ar'])
            ->fillForm(['name' => 'Mona', 'email' => 'mona@example.com', 'password' => 'CorrectHorse42', 'role' => 'editor', 'locale' => 'en'])
            ->call('create')
            ->assertHasNoFormErrors();

        $this->assertSame(Locale::En, User::query()->where('email', 'mona@example.com')->sole()->locale);
    }

    public function test_livewire_requests_inside_the_panel_keep_the_language(): void
    {
        $this->assertContains(UseStaffLanguage::class, Livewire::getPersistentMiddleware());
    }
}
