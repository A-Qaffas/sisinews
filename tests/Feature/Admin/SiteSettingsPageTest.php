<?php

namespace Tests\Feature\Admin;

use App\Filament\Pages\ManageSiteSettings;
use App\Models\SiteSetting;
use App\Models\User;
use App\Services\SiteSettings;
use Filament\Facades\Filament;
use Filament\Support\Icons\Heroicon;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\HttpKernel\Exception\HttpException;

class SiteSettingsPageTest extends AdminTestCase
{
    private const URL = '/admin/site-settings';

    private const NAMES = [
        'masthead_chairman_ar' => 'تغريد رفاعي',
        'masthead_chairman_en' => 'Taghreed Refaie',
        'masthead_editor_ar' => 'ناجي أبو مغنم',
        'masthead_editor_en' => 'Nagy Abou Meghnem',
    ];

    private function sidebar(): array
    {
        return collect(Filament::getNavigation())
            ->flatMap(fn ($group) => $group->getItems())
            ->map(fn ($item) => $item->getLabel())
            ->all();
    }

    /** @return array<string, string|null> */
    private function stored(): array
    {
        return SiteSetting::query()->orderBy('key')->pluck('value', 'key')->all();
    }

    // --- access matrix ----------------------------------------------------------------------

    public function test_an_admin_reaches_the_page_from_the_site_group(): void
    {
        $this->actingAs(User::factory()->admin()->create());

        $this->get(self::URL)
            ->assertOk()
            ->assertSee('Site settings')
            ->assertSee('Masthead')
            ->assertSee('Chairman of the Board (Arabic)')
            ->assertSee('Editor-in-Chief (English)');
        $this->assertContains('Site settings', $this->sidebar());
        $this->assertSame('Site', ManageSiteSettings::getNavigationGroup());
        $this->assertSame(Heroicon::OutlinedCog6Tooth, ManageSiteSettings::getNavigationIcon());

        $group = collect(Filament::getNavigation())->first(fn ($group) => $group->getLabel() === 'Site');
        $this->assertSame(['Pages', 'Site settings'], collect($group->getItems())->map(fn ($item) => $item->getLabel())->values()->all());
    }

    /** @return array<string, array{0: string}> */
    public static function notAdmins(): array
    {
        return ['editor-in-chief' => ['editor'], 'journalist' => ['journalist']];
    }

    #[DataProvider('notAdmins')]
    public function test_editors_in_chief_and_journalists_get_403_and_no_menu_item(string $role): void
    {
        $this->actingAs(User::factory()->create(['role' => $role]));

        $this->get(self::URL)->assertForbidden();
        Livewire::test(ManageSiteSettings::class)->assertForbidden();
        $this->assertFalse(ManageSiteSettings::canAccess());
        $this->assertNotContains('Site settings', $this->sidebar());
    }

    public function test_a_deactivated_admin_is_shut_out(): void
    {
        $this->actingAs(User::factory()->admin()->inactive()->create());

        $this->get(self::URL)->assertForbidden();
        Livewire::test(ManageSiteSettings::class)->assertForbidden();
        $this->assertFalse(ManageSiteSettings::canAccess());
    }

    public function test_guests_are_sent_to_the_login(): void
    {
        $this->get(self::URL)->assertRedirect('/admin/login');
    }

    #[DataProvider('notAdmins')]
    public function test_a_crafted_save_from_someone_who_is_not_an_admin_is_refused(string $role): void
    {
        // The page was opened and filled in by an admin; the save call comes from another account.
        $component = Livewire::actingAs(User::factory()->admin()->create())
            ->test(ManageSiteSettings::class)
            ->set('data.masthead_editor_ar', 'منتحل');
        $this->actingAs(User::factory()->create(['role' => $role]));

        $component->call('save')->assertForbidden();

        $this->assertSame([], $this->stored());
    }

    public function test_an_admin_deactivated_while_the_page_is_open_can_no_longer_save(): void
    {
        $admin = User::factory()->admin()->create();
        $component = Livewire::actingAs($admin)->test(ManageSiteSettings::class)->set('data.masthead_editor_ar', 'منتحل');
        $admin->update(['is_active' => false]);

        $component->call('save')->assertForbidden();

        $this->assertSame([], $this->stored());
    }

    public function test_save_itself_refuses_a_caller_who_is_not_an_admin(): void
    {
        // Belt and braces: even if save() were reached without the per-request access check, it refuses on its own.
        $this->actingAs(User::factory()->editor()->create());
        $page = new ManageSiteSettings;

        try {
            $page->save();
            $this->fail('save() ran for an editor-in-chief');
        } catch (HttpException $exception) {
            $this->assertSame(403, $exception->getStatusCode());
        }
        $this->assertSame([], $this->stored());
    }

    public function test_a_crafted_payload_cannot_create_other_settings(): void
    {
        $this->actingAs(User::factory()->admin()->create());

        Livewire::test(ManageSiteSettings::class)
            ->set('data.masthead_editor_ar', 'ناجي أبو مغنم')
            ->set('data.site_owner', 'Someone else')
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertArrayNotHasKey('site_owner', $this->stored());
        $this->assertSame(['masthead_chairman_ar', 'masthead_chairman_en', 'masthead_editor_ar', 'masthead_editor_en'], array_keys($this->stored()));
    }

    // --- the form ---------------------------------------------------------------------------

    public function test_an_admin_saves_the_four_names_and_is_told_so(): void
    {
        $this->actingAs(User::factory()->admin()->create());

        Livewire::test(ManageSiteSettings::class)
            ->fillForm(self::NAMES)
            ->call('save')
            ->assertHasNoFormErrors()
            ->assertNotified('Site settings saved');

        $this->assertSame(self::NAMES, $this->stored());
        $this->assertSame('ناجي أبو مغنم', app(SiteSettings::class)->get('masthead_editor_ar'));
    }

    public function test_the_form_opens_with_the_stored_names(): void
    {
        foreach (self::NAMES as $key => $name) {
            app(SiteSettings::class)->set($key, $name);
        }
        $this->actingAs(User::factory()->admin()->create());

        Livewire::test(ManageSiteSettings::class)->assertFormSet(self::NAMES);
    }

    public function test_names_are_trimmed_and_an_emptied_field_hides_its_line(): void
    {
        app(SiteSettings::class)->set('masthead_editor_en', 'Old name');
        $this->actingAs(User::factory()->admin()->create());

        Livewire::test(ManageSiteSettings::class)
            ->fillForm(['masthead_chairman_ar' => "   تغريد رفاعي \t", 'masthead_editor_en' => '   '])
            ->call('save')
            ->assertHasNoFormErrors()
            ->assertFormSet(['masthead_chairman_ar' => 'تغريد رفاعي', 'masthead_editor_en' => null]);

        $this->assertSame('تغريد رفاعي', app(SiteSettings::class)->get('masthead_chairman_ar'));
        $this->assertNull(app(SiteSettings::class)->get('masthead_editor_en'));
    }

    public function test_a_name_is_at_most_80_characters_after_trimming(): void
    {
        $this->actingAs(User::factory()->admin()->create());

        Livewire::test(ManageSiteSettings::class)
            ->fillForm(['masthead_chairman_ar' => str_repeat('ب', 81)])
            ->call('save')
            ->assertHasFormErrors(['masthead_chairman_ar' => 'max']);
        $this->assertSame([], $this->stored());

        Livewire::test(ManageSiteSettings::class)
            ->fillForm(['masthead_chairman_ar' => '  '.str_repeat('ب', 80).'  '])
            ->call('save')
            ->assertHasNoFormErrors();
        $this->assertSame(str_repeat('ب', 80), app(SiteSettings::class)->get('masthead_chairman_ar'));
    }

    public function test_a_crafted_non_text_value_is_refused(): void
    {
        $this->actingAs(User::factory()->admin()->create());

        Livewire::test(ManageSiteSettings::class)
            ->set('data.masthead_editor_ar', ['<b>x</b>'])
            ->call('save')
            ->assertHasFormErrors(['masthead_editor_ar' => 'string']);

        $this->assertSame([], $this->stored());
    }

    public function test_html_is_stored_as_typed_and_shown_escaped_on_the_site(): void
    {
        $this->actingAs(User::factory()->admin()->create());

        Livewire::test(ManageSiteSettings::class)
            ->fillForm(['masthead_editor_en' => '<script>alert(1)</script><b>Nagy</b>'])
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertSame('<script>alert(1)</script><b>Nagy</b>', app(SiteSettings::class)->get('masthead_editor_en'));

        $this->get('/en')
            ->assertOk()
            ->assertSee('&lt;script&gt;alert(1)&lt;/script&gt;&lt;b&gt;Nagy&lt;/b&gt;', false)
            ->assertDontSee('<script>alert(1)</script>', false)
            ->assertDontSee('<b>Nagy</b>', false);
    }

    public function test_arabic_fields_are_right_to_left_and_english_fields_left_to_right(): void
    {
        $this->actingAs(User::factory()->admin()->create());

        $html = $this->get(self::URL)->assertOk()->getContent();

        foreach (['masthead_chairman_ar', 'masthead_editor_ar'] as $key) {
            $this->assertMatchesRegularExpression('/<input[^>]*dir="rtl"[^>]*lang="ar"[^>]*id="form\.'.$key.'"|<input[^>]*id="form\.'.$key.'"[^>]*dir="rtl"[^>]*lang="ar"/s', $html, $key);
        }
        foreach (['masthead_chairman_en', 'masthead_editor_en'] as $key) {
            $this->assertMatchesRegularExpression('/<input[^>]*dir="ltr"[^>]*lang="en"[^>]*id="form\.'.$key.'"|<input[^>]*id="form\.'.$key.'"[^>]*dir="ltr"[^>]*lang="en"/s', $html, $key);
        }
    }
}
