<?php

namespace Tests\Feature\Admin;

use App\Filament\Resources\Pages\PageResource;
use App\Filament\Resources\Pages\Pages\CreatePage;
use App\Filament\Resources\Pages\Pages\EditPage;
use App\Filament\Resources\Pages\Pages\ListPages;
use App\Filament\Support\AdminNavigationGroup;
use App\Filament\Support\PhoneFriendly;
use App\Models\Page;
use App\Models\PageTranslation;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\Gate;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\DataProvider;

class PageAdminTest extends AdminTestCase
{
    private function page(string $slug = 'ownership', array $shell = []): Page
    {
        $page = Page::factory()->create(['slug' => $slug, ...$shell]);
        PageTranslation::factory()->for($page)->create(['title' => 'Ownership', 'body' => '<p>English text.</p>']);
        PageTranslation::factory()->for($page)->arabic()->create(['title' => 'الملكية', 'body' => '<p>نص عربي.</p>']);

        return $page;
    }

    private function pageVersion(Page $page, string $locale): PageTranslation
    {
        return PageTranslation::query()->where('page_id', $page->id)->where('locale', $locale)->firstOrFail();
    }

    private function sidebar(): array
    {
        return collect(Filament::getNavigation())
            ->flatMap(fn ($group) => $group->getItems())
            ->map(fn ($item) => $item->getLabel())
            ->all();
    }

    /** @return array<string, list<string>> */
    public static function coreSlugs(): array
    {
        return array_combine(Page::CORE_SLUGS, array_map(fn (string $slug) => [$slug], Page::CORE_SLUGS));
    }

    // --- access matrix ----------------------------------------------------------------------

    public function test_a_journalist_gets_403_on_every_page_screen_and_never_sees_the_menu_item(): void
    {
        $page = $this->page();
        $this->actingAs(User::factory()->journalist()->create());

        $this->get('/admin/pages')->assertForbidden();
        $this->get('/admin/pages/create')->assertForbidden();
        $this->get("/admin/pages/{$page->id}/edit")->assertForbidden();
        Livewire::test(ListPages::class)->assertForbidden();
        Livewire::test(CreatePage::class)->assertForbidden();
        Livewire::test(EditPage::class, ['record' => $page->getKey()])->assertForbidden();
        $this->assertFalse(PageResource::canViewAny());
        $this->assertNotContains('Pages', $this->sidebar());
    }

    #[DataProvider('reviewerRoles')]
    public function test_editors_and_admins_reach_the_list_the_forms_and_the_menu_item(string $role): void
    {
        $page = $this->page();
        $this->actingAs(User::factory()->create(['role' => $role]));

        $this->get('/admin/pages')->assertOk();
        $this->get('/admin/pages/create')->assertOk();
        $this->get("/admin/pages/{$page->id}/edit")->assertOk();
        $this->assertContains('Pages', $this->sidebar());
        $this->assertSame(AdminNavigationGroup::Site, PageResource::getNavigationGroup());
    }

    public static function reviewerRoles(): array
    {
        return ['editor' => ['editor'], 'admin' => ['admin']];
    }

    public function test_a_deactivated_editor_is_shut_out(): void
    {
        $this->actingAs(User::factory()->editor()->inactive()->create());

        $this->get('/admin/pages')->assertForbidden();
    }

    public function test_guests_are_sent_to_the_login(): void
    {
        $this->get('/admin/pages')->assertRedirect('/admin/login');
    }

    // --- list ------------------------------------------------------------------------------

    public function test_the_list_shows_both_titles_the_status_of_each_language_and_the_slug(): void
    {
        $page = $this->page();
        $this->pageVersion($page, 'en')->update(['is_published' => false]);
        $this->actingAs(User::factory()->editor()->create());

        Livewire::test(ListPages::class)
            ->assertSuccessful()
            ->assertCanSeeTableRecords([$page])
            ->assertTableColumnStateSet('ar_title', 'الملكية', $page)
            ->assertTableColumnStateSet('en_title', 'Ownership', $page)
            ->assertTableColumnStateSet('headline', 'الملكية', $page)
            ->assertTableColumnStateSet('versions', ['ar:published', 'en:hidden'], $page)
            ->assertTableColumnStateSet('slug', 'ownership', $page);
    }

    public function test_the_list_can_be_searched_by_either_title_or_the_slug(): void
    {
        $this->actingAs(User::factory()->editor()->create());
        $wanted = $this->page('ownership');
        $this->page('other-page');
        $wanted->translations()->where('locale', 'en')->update(['title' => 'Zanzibar funding']);

        Livewire::test(ListPages::class)->searchTable('Zanzibar')->assertCanSeeTableRecords([$wanted])->assertCountTableRecords(1);
        Livewire::test(ListPages::class)->searchTable('الملكية')->assertCountTableRecords(2);
        Livewire::test(ListPages::class)->searchTable('other-page')->assertCountTableRecords(1);
    }

    public function test_the_list_keeps_title_and_status_on_a_phone_and_defers_the_rest_to_md(): void
    {
        $this->actingAs(User::factory()->editor()->create());
        $table = Livewire::test(ListPages::class)->instance()->getTable();

        foreach (['ar_title', 'en_title', 'slug', 'show_in_footer', 'sort_order', 'updated_at'] as $secondary) {
            $this->assertSame('md', $table->getColumn($secondary)->getVisibleFrom(), "{$secondary} must wait for md");
        }
        $this->assertSame('md', $table->getColumn('headline')->getHiddenFrom());
        $this->assertNull($table->getColumn('versions')->getVisibleFrom());
        $this->assertNull($table->getColumn('versions')->getHiddenFrom());
        $this->assertStringContainsString(PhoneFriendly::HIDDEN_CLASS, (string) $table->getAction('edit')->getExtraAttributeBag()->get('class'));
    }

    public function test_the_list_runs_the_same_queries_for_one_page_or_many(): void
    {
        $this->actingAs(User::factory()->editor()->create());
        $this->page('first');
        $few = $this->queryCount(fn () => Livewire::test(ListPages::class)->assertSuccessful());

        foreach (range(1, 8) as $n) {
            $this->page("extra-{$n}");
        }

        $this->assertSame($few, $this->queryCount(fn () => Livewire::test(ListPages::class)->assertSuccessful()));
    }

    // --- create ----------------------------------------------------------------------------

    public function test_an_editor_creates_a_page_with_both_languages(): void
    {
        $this->actingAs(User::factory()->editor()->create());

        Livewire::test(CreatePage::class)
            ->fillForm([
                'slug' => 'ownership-and-funding',
                'sort_order' => 3,
                'show_in_footer' => true,
                'ar' => ['title' => 'الملكية والتمويل', 'body' => '<p>نص الملكية.</p>', 'meta_description' => 'وصف عربي', 'is_published' => true],
                'en' => ['title' => 'Ownership and funding', 'body' => '<p>Ownership text.</p>', 'meta_description' => '', 'is_published' => false],
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $page = Page::query()->with('translations')->firstOrFail();
        $this->assertSame('ownership-and-funding', $page->slug);
        $this->assertSame(3, $page->sort_order);
        $this->assertTrue($page->show_in_footer);
        $this->assertCount(2, $page->translations);

        $ar = $this->pageVersion($page, 'ar');
        $this->assertSame('الملكية والتمويل', $ar->title);
        $this->assertSame('وصف عربي', $ar->meta_description);
        $this->assertTrue($ar->is_published);
        $this->assertStringContainsString('نص الملكية.', $ar->body);

        $en = $this->pageVersion($page, 'en');
        $this->assertNull($en->meta_description, 'a blank description is stored as null');
        $this->assertFalse($en->is_published);
    }

    public function test_a_page_may_start_in_arabic_only(): void
    {
        $this->actingAs(User::factory()->editor()->create());

        Livewire::test(CreatePage::class)
            ->fillForm(['slug' => 'arabic-only', 'ar' => ['title' => 'صفحة', 'body' => '<p>نص</p>']])
            ->call('create')
            ->assertHasNoFormErrors();

        $this->assertSame(['ar'], PageTranslation::query()->pluck('locale')->map->value->all());
    }

    public function test_a_page_may_start_in_english_only(): void
    {
        $this->actingAs(User::factory()->editor()->create());

        Livewire::test(CreatePage::class)
            ->fillForm(['slug' => 'english-only', 'en' => ['title' => 'Page', 'body' => '<p>Text</p>']])
            ->call('create')
            ->assertHasNoFormErrors();

        $this->assertSame(['en'], PageTranslation::query()->pluck('locale')->map->value->all());
    }

    public function test_a_page_needs_at_least_an_arabic_title_when_nothing_else_is_filled(): void
    {
        $this->actingAs(User::factory()->editor()->create());

        Livewire::test(CreatePage::class)
            ->fillForm(['slug' => 'empty-page'])
            ->call('create')
            ->assertHasFormErrors(['ar.title' => 'required']);

        $this->assertSame(0, Page::query()->count());
    }

    public function test_a_language_that_is_started_needs_a_title_and_some_text(): void
    {
        $this->actingAs(User::factory()->editor()->create());

        Livewire::test(CreatePage::class)
            ->fillForm(['slug' => 'half-done', 'ar' => ['title' => 'عنوان', 'body' => '<p>نص</p>'], 'en' => ['title' => '', 'body' => '<p>Text without a title</p>']])
            ->call('create')
            ->assertHasFormErrors(['en.title' => 'required']);

        Livewire::test(CreatePage::class)
            ->fillForm(['slug' => 'no-text', 'ar' => ['title' => 'عنوان', 'body' => '<p></p>']])
            ->call('create')
            ->assertHasFormErrors(['ar.body']);

        Livewire::test(CreatePage::class)
            ->fillForm(['slug' => 'blank-text', 'ar' => ['title' => 'عنوان', 'body' => '<p> &nbsp; </p>']])
            ->call('create')
            ->assertHasFormErrors(['ar.body']);

        $this->assertSame(0, Page::query()->count());
    }

    #[DataProvider('badSlugs')]
    public function test_the_slug_must_be_kebab_case(string $slug): void
    {
        $this->actingAs(User::factory()->editor()->create());

        Livewire::test(CreatePage::class)
            ->fillForm(['slug' => $slug, 'ar' => ['title' => 'عنوان', 'body' => '<p>نص</p>']])
            ->call('create')
            ->assertHasFormErrors(['slug']);

        $this->assertSame(0, Page::query()->count());
    }

    public static function badSlugs(): array
    {
        return [
            'spaces' => ['two words'],
            'underscore' => ['two_words'],
            'leading hyphen' => ['-about'],
            'trailing hyphen' => ['about-'],
            'double hyphen' => ['about--us'],
            'slash' => ['about/us'],
            'arabic' => ['عن-الموقع'],
            'dot' => ['about.html'],
            'too long' => [str_repeat('a', 81)],
            'blank' => [''],
        ];
    }

    public function test_the_slug_must_be_unique(): void
    {
        $this->actingAs(User::factory()->editor()->create());
        $this->page('ownership');

        Livewire::test(CreatePage::class)
            ->fillForm(['slug' => 'ownership', 'ar' => ['title' => 'عنوان', 'body' => '<p>نص</p>']])
            ->call('create')
            ->assertHasFormErrors(['slug' => 'unique']);

        $this->assertSame(1, Page::query()->count());
    }

    public function test_a_hostile_body_is_sanitized_when_a_page_is_saved(): void
    {
        $this->actingAs(User::factory()->editor()->create());

        Livewire::test(CreatePage::class)
            ->fillForm(['slug' => 'hostile', 'en' => ['title' => 'Hostile', 'body' => '<p onclick="x()">Hi</p><script>alert(1)</script><a href="javascript:alert(2)">link</a><img src=x onerror=alert(3)>']])
            ->call('create')
            ->assertHasNoFormErrors();

        $body = PageTranslation::query()->firstOrFail()->body;
        foreach (['<script', 'alert(', 'onclick', 'javascript:', 'onerror'] as $needle) {
            $this->assertStringNotContainsString($needle, $body);
        }
        $this->assertStringContainsString('Hi', $body);
    }

    public function test_the_language_tabs_come_arabic_first_and_the_editors_carry_their_direction(): void
    {
        $this->actingAs(User::factory()->editor()->create());

        $component = Livewire::test(CreatePage::class);

        $component->assertSeeInOrder(['Arabic', 'English']);
        $html = $component->html();
        $this->assertStringContainsString('dir="rtl"', $html);
        $this->assertStringContainsString('lang="ar"', $html);
        $this->assertStringContainsString('dir="ltr"', $html);
        $this->assertStringContainsString('[[contact]]', $html, 'the editor is told about the contact token');
    }

    // --- edit ------------------------------------------------------------------------------

    public function test_editing_updates_the_text_without_touching_the_other_language(): void
    {
        $page = $this->page();
        $english = $this->pageVersion($page, 'en');
        $this->actingAs(User::factory()->editor()->create());

        Livewire::test(EditPage::class, ['record' => $page->getKey()])
            ->assertSet('data.slug', 'ownership')
            ->assertSet('data.ar.title', 'الملكية')
            ->assertSet('data.ar.is_published', true)
            ->assertSet('data.en.title', 'Ownership')
            ->assertSet('data.en.is_published', true)
            ->fillForm(['ar' => ['title' => 'من يملك الموقع', 'body' => '<p>نص جديد.</p>'], 'sort_order' => 9, 'show_in_footer' => false])
            ->call('save')
            ->assertHasNoFormErrors();

        $page->refresh();
        $this->assertSame(9, $page->sort_order);
        $this->assertFalse($page->show_in_footer);
        $this->assertSame('من يملك الموقع', $this->pageVersion($page, 'ar')->title);
        $this->assertSame('<p>نص جديد.</p>', $this->pageVersion($page, 'ar')->body);
        $this->assertEquals($english->fresh()->toArray(), $this->pageVersion($page, 'en')->toArray());
    }

    public function test_the_slug_cannot_be_changed_after_creation_even_by_a_crafted_request(): void
    {
        $page = $this->page();
        $this->actingAs(User::factory()->admin()->create());

        $component = Livewire::test(EditPage::class, ['record' => $page->getKey()])
            ->assertFormFieldDisabled('slug');

        $component->set('data.slug', 'hijacked')->call('save');

        $this->assertSame('ownership', $page->fresh()->slug);
        $this->get('/en/page/ownership')->assertOk();
        $this->get('/en/page/hijacked')->assertNotFound();
    }

    public function test_an_uppercase_slug_is_lowercased_as_it_is_typed(): void
    {
        $this->actingAs(User::factory()->editor()->create());

        Livewire::test(CreatePage::class)
            ->fillForm(['slug' => '  Ownership-Funding ', 'ar' => ['title' => 'عنوان', 'body' => '<p>نص</p>']])
            ->call('create')
            ->assertHasNoFormErrors();

        $this->assertSame('ownership-funding', Page::query()->firstOrFail()->slug);
    }

    public function test_the_slug_is_accepted_on_creation_only(): void
    {
        $this->actingAs(User::factory()->editor()->create());

        Livewire::test(CreatePage::class)->assertFormFieldEnabled('slug');
    }

    public function test_an_existing_language_cannot_be_blanked_it_must_be_unpublished_instead(): void
    {
        $page = $this->page();
        $this->actingAs(User::factory()->editor()->create());

        Livewire::test(EditPage::class, ['record' => $page->getKey()])
            ->fillForm(['en' => ['title' => '']])
            ->call('save')
            ->assertHasFormErrors(['en.title' => 'required']);

        Livewire::test(EditPage::class, ['record' => $page->getKey()])
            ->fillForm(['en' => ['body' => '<p></p>']])
            ->call('save')
            ->assertHasFormErrors(['en.body']);

        $this->assertSame('Ownership', $this->pageVersion($page, 'en')->title);
    }

    public function test_a_language_can_be_added_to_an_existing_page_later(): void
    {
        $page = Page::factory()->create(['slug' => 'ownership']);
        PageTranslation::factory()->for($page)->arabic()->create();
        $this->actingAs(User::factory()->editor()->create());

        Livewire::test(EditPage::class, ['record' => $page->getKey()])
            ->fillForm(['en' => ['title' => 'Ownership', 'body' => '<p>Now in English.</p>', 'is_published' => true]])
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertSame(2, $page->translations()->count());
        $this->get('/en/page/ownership')->assertOk()->assertSee('Now in English.');
    }

    public function test_unpublishing_a_language_takes_it_off_the_site_and_out_of_the_footer_at_once(): void
    {
        $page = $this->page();
        $this->get('/en')->assertSee('Ownership');
        $this->get('/en/page/ownership')->assertOk();
        $this->actingAs(User::factory()->editor()->create());

        Livewire::test(EditPage::class, ['record' => $page->getKey()])
            ->fillForm(['en' => ['is_published' => false]])
            ->call('save')
            ->assertHasNoFormErrors();

        $this->get('/en/page/ownership')->assertNotFound();
        $this->get('/en')->assertDontSee('Ownership');
        $this->get('/ar/page/ownership')->assertOk();
        $this->assertSame('Ownership', $this->pageVersion($page, 'en')->title, 'the text is kept');
    }

    public function test_an_edit_reaches_the_footer_and_the_page_at_once_despite_the_cache(): void
    {
        $page = $this->page();
        $this->get('/en')->assertSee('Ownership');
        $this->actingAs(User::factory()->editor()->create());

        Livewire::test(EditPage::class, ['record' => $page->getKey()])
            ->fillForm(['en' => ['title' => 'Who owns sisi.news', 'body' => '<p>Rewritten.</p>']])
            ->call('save');

        $this->get('/en')->assertSee('Who owns sisi.news');
        $this->get('/en/page/ownership')->assertSee('Rewritten.');
    }

    public function test_a_hostile_body_is_sanitized_on_edit_too(): void
    {
        $page = $this->page();
        $this->actingAs(User::factory()->editor()->create());

        Livewire::test(EditPage::class, ['record' => $page->getKey()])
            ->fillForm(['en' => ['body' => '<p>ok</p><iframe src="https://evil.example"></iframe><script>bad()</script>']])
            ->call('save');

        $this->assertSame('<p>ok</p>', $this->pageVersion($page, 'en')->body);
    }

    public function test_a_core_page_says_it_cannot_be_deleted(): void
    {
        $this->actingAs(User::factory()->admin()->create());
        $core = $this->page('about');
        $custom = $this->page('ownership');

        Livewire::test(EditPage::class, ['record' => $core->getKey()])->assertSee('cannot be deleted');
        Livewire::test(EditPage::class, ['record' => $custom->getKey()])->assertDontSee('cannot be deleted');
    }

    public function test_the_edit_screen_offers_a_link_to_each_published_language_only(): void
    {
        $page = $this->page();
        $this->pageVersion($page, 'en')->update(['is_published' => false]);
        $this->actingAs(User::factory()->editor()->create());

        Livewire::test(EditPage::class, ['record' => $page->getKey()])
            ->assertActionVisible('view_ar')
            ->assertActionHidden('view_en')
            ->assertActionHasUrl('view_ar', route('page', ['locale' => 'ar', 'slug' => 'ownership']));
    }

    // --- deleting --------------------------------------------------------------------------

    #[DataProvider('coreSlugs')]
    public function test_an_admin_cannot_delete_a_core_page_from_the_list_or_the_edit_screen(string $slug): void
    {
        $page = $this->page($slug);
        $this->actingAs($admin = User::factory()->admin()->create());

        Livewire::test(ListPages::class)->assertTableActionHidden('delete', $page);
        Livewire::test(EditPage::class, ['record' => $page->getKey()])->assertActionHidden('delete');
        $this->assertFalse(Gate::forUser($admin)->allows('delete', $page));
        $this->assertTrue(Gate::forUser($admin)->allows('update', $page), 'it can still be edited and unpublished');

        $this->assertModelExists($page);
    }

    #[DataProvider('coreSlugs')]
    public function test_a_core_page_survives_a_forced_delete_call(string $slug): void
    {
        $page = $this->page($slug);
        $this->actingAs(User::factory()->admin()->create());

        // The button is hidden, so a hand-made request that mounts and calls the action anyway must still do nothing.
        Livewire::test(ListPages::class)
            ->call('mountTableAction', 'delete', (string) $page->getKey())
            ->call('callMountedTableAction');

        $this->assertModelExists($page);
        $this->assertSame(2, $page->translations()->count());
    }

    public function test_an_admin_deletes_a_custom_page_and_its_translations_and_it_leaves_the_footer(): void
    {
        $page = $this->page('ownership');
        $this->get('/en')->assertSee('Ownership');
        $this->actingAs(User::factory()->admin()->create());

        Livewire::test(ListPages::class)->callTableAction('delete', $page);

        $this->assertModelMissing($page);
        $this->assertSame(0, PageTranslation::query()->count());
        $this->get('/en/page/ownership')->assertNotFound();
        $this->get('/en')->assertDontSee('Ownership');
    }

    public function test_an_admin_deletes_a_custom_page_from_the_edit_screen(): void
    {
        $page = $this->page('ownership');
        $this->actingAs(User::factory()->admin()->create());

        Livewire::test(EditPage::class, ['record' => $page->getKey()])->callAction('delete');

        $this->assertModelMissing($page);
    }

    public function test_an_editor_cannot_delete_any_page(): void
    {
        $custom = $this->page('ownership');
        $core = $this->page('about');
        $this->actingAs($editor = User::factory()->editor()->create());

        Livewire::test(ListPages::class)
            ->assertTableActionHidden('delete', $custom)
            ->assertTableActionHidden('delete', $core)
            ->call('mountTableAction', 'delete', (string) $custom->getKey())
            ->call('callMountedTableAction');
        Livewire::test(EditPage::class, ['record' => $custom->getKey()])->assertActionHidden('delete');

        $this->assertFalse(Gate::forUser($editor)->allows('delete', $custom));
        $this->assertModelExists($custom);
        $this->assertModelExists($core);
    }

    public function test_bulk_deletion_is_not_offered(): void
    {
        $this->actingAs(User::factory()->admin()->create());

        $this->assertFalse(Gate::forUser(auth()->user())->allows('deleteAny', Page::class));
        $this->assertSame([], Livewire::test(ListPages::class)->instance()->getTable()->getBulkActions());
    }

    public function test_a_deactivated_admin_cannot_delete(): void
    {
        $page = $this->page('ownership');

        $this->assertFalse(Gate::forUser(User::factory()->admin()->inactive()->make())->allows('delete', $page));
        $this->assertFalse(Gate::forUser(User::factory()->admin()->inactive()->make())->allows('update', $page));
    }
}
