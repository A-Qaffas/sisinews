<?php

namespace Tests\Feature\Admin;

use App\Filament\Resources\LiveStreams\LiveStreamResource;
use App\Filament\Resources\LiveStreams\Pages\CreateLiveStream;
use App\Filament\Resources\LiveStreams\Pages\EditLiveStream;
use App\Filament\Resources\LiveStreams\Pages\ListLiveStreams;
use App\Models\LiveStream;
use App\Models\User;
use App\Policies\LiveStreamPolicy;
use Filament\Facades\Filament;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Facades\Gate;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\DataProvider;
use ReflectionClass;

class LiveStreamAccessTest extends AdminTestCase
{
    private function sidebar(): array
    {
        return collect(Filament::getNavigation())
            ->flatMap(fn ($group) => $group->getItems())
            ->map(fn ($item) => $item->getLabel())
            ->all();
    }

    /** @return array<string, list<string>> */
    public static function reviewerRoles(): array
    {
        return ['editor' => ['editor'], 'admin' => ['admin']];
    }

    public function test_a_journalist_gets_403_on_every_live_screen_and_never_sees_the_menu_item(): void
    {
        $stream = LiveStream::factory()->withTranslations()->create();
        $this->actingAs(User::factory()->journalist()->create());

        $this->get('/admin/live-broadcasts')->assertForbidden();
        $this->get('/admin/live-broadcasts/create')->assertForbidden();
        $this->get("/admin/live-broadcasts/{$stream->id}/edit")->assertForbidden();
        Livewire::test(ListLiveStreams::class)->assertForbidden();
        Livewire::test(CreateLiveStream::class)->assertForbidden();
        Livewire::test(EditLiveStream::class, ['record' => $stream->getKey()])->assertForbidden();
        $this->assertFalse(LiveStreamResource::canViewAny());
        $this->assertFalse(LiveStreamResource::canCreate());
        $this->assertNotContains('Broadcasts', $this->sidebar());
    }

    public function test_a_journalist_cannot_reach_the_screens_even_while_a_broadcast_is_live(): void
    {
        $stream = LiveStream::factory()->live()->withTranslations()->create();
        $this->actingAs(User::factory()->journalist()->create());

        $this->get("/admin/live-broadcasts/{$stream->id}/edit")->assertForbidden();
        $this->assertNull(LiveStreamResource::getNavigationBadge(), 'no hint of the broadcast in the journalist menu');
    }

    #[DataProvider('reviewerRoles')]
    public function test_editors_and_admins_reach_the_list_the_forms_and_the_menu_item(string $role): void
    {
        $stream = LiveStream::factory()->withTranslations()->create();
        $this->actingAs(User::factory()->create(['role' => $role]));

        $this->get('/admin/live-broadcasts')->assertOk();
        $this->get('/admin/live-broadcasts/create')->assertOk();
        $this->get("/admin/live-broadcasts/{$stream->id}/edit")->assertOk();
        $this->assertContains('Broadcasts', $this->sidebar());
    }

    public function test_the_menu_item_sits_in_the_newsroom_group_with_a_signal_icon(): void
    {
        $this->actingAs(User::factory()->editor()->create());

        $this->assertSame('Newsroom', LiveStreamResource::getNavigationGroup());
        $this->assertSame('Broadcasts', LiveStreamResource::getNavigationLabel());

        $group = collect(Filament::getNavigation())->first(fn ($group) => $group->getLabel() === 'Newsroom');
        // Short enough for the 15rem sidebar with the live count beside it; the page and breadcrumb say it in full, in sentence case.
        $this->assertLessThanOrEqual(10, mb_strlen(LiveStreamResource::getNavigationLabel()));
        $this->assertSame('Live broadcasts', LiveStreamResource::getBreadcrumb());
        $this->assertSame('Live broadcasts', Livewire::test(ListLiveStreams::class)->instance()->getTitle());

        $item = collect($group->getItems())->first(fn ($item) => $item->getLabel() === 'Broadcasts');
        $this->assertNotNull($item);
        $this->assertSame(Heroicon::OutlinedSignal, $item->getIcon());
    }

    public function test_the_menu_shows_how_many_broadcasts_are_on_air_to_reviewers_only(): void
    {
        $this->actingAs(User::factory()->editor()->create());
        $this->assertNull(LiveStreamResource::getNavigationBadge());

        LiveStream::factory()->live()->count(2)->create();
        LiveStream::factory()->ended()->create();
        LiveStream::factory()->scheduled()->create();
        $this->assertSame('2', LiveStreamResource::getNavigationBadge());
        $this->assertSame('danger', LiveStreamResource::getNavigationBadgeColor());

        $this->actingAs(User::factory()->journalist()->create());
        $this->assertNull(LiveStreamResource::getNavigationBadge());
    }

    public function test_a_deactivated_editor_is_shut_out(): void
    {
        $this->actingAs(User::factory()->editor()->inactive()->create());

        $this->get('/admin/live-broadcasts')->assertForbidden();
    }

    public function test_guests_are_sent_to_the_login(): void
    {
        $this->get('/admin/live-broadcasts')->assertRedirect('/admin/login');
        $this->get('/admin/live-broadcasts/create')->assertRedirect('/admin/login');
    }

    // --- the policy itself ------------------------------------------------------------------

    public function test_the_policy_allows_reviewers_every_move_and_journalists_none(): void
    {
        $stream = LiveStream::factory()->scheduled()->create();

        foreach (['viewAny', 'create'] as $ability) {
            $this->assertTrue(Gate::forUser(User::factory()->editor()->make())->allows($ability, LiveStream::class), "editor {$ability}");
            $this->assertTrue(Gate::forUser(User::factory()->admin()->make())->allows($ability, LiveStream::class), "admin {$ability}");
            $this->assertFalse(Gate::forUser(User::factory()->journalist()->make())->allows($ability, LiveStream::class), "journalist {$ability}");
        }

        foreach (['view', 'update', 'goLive', 'end', 'delete'] as $ability) {
            $this->assertTrue(Gate::forUser(User::factory()->editor()->make())->allows($ability, $stream), "editor {$ability}");
            $this->assertTrue(Gate::forUser(User::factory()->admin()->make())->allows($ability, $stream), "admin {$ability}");
            $this->assertFalse(Gate::forUser(User::factory()->journalist()->make())->allows($ability, $stream), "journalist {$ability}");
        }
    }

    public function test_a_deactivated_account_is_refused_everything(): void
    {
        $stream = LiveStream::factory()->scheduled()->create();

        foreach (['editor', 'admin'] as $role) {
            $inactive = User::factory()->create(['role' => $role, 'is_active' => false]);
            foreach (['view', 'update', 'goLive', 'end', 'delete'] as $ability) {
                $this->assertFalse(Gate::forUser($inactive)->allows($ability, $stream), "inactive {$role} {$ability}");
            }
            $this->assertFalse(Gate::forUser($inactive)->allows('viewAny', LiveStream::class));
            $this->assertFalse(Gate::forUser($inactive)->allows('create', LiveStream::class));
        }
    }

    #[DataProvider('reviewerRoles')]
    public function test_a_live_broadcast_cannot_be_deleted_by_anyone_but_the_others_can(string $role): void
    {
        $user = User::factory()->create(['role' => $role]);

        $this->assertFalse(Gate::forUser($user)->allows('delete', LiveStream::factory()->live()->create()));
        $this->assertTrue(Gate::forUser($user)->allows('delete', LiveStream::factory()->scheduled()->create()));
        $this->assertTrue(Gate::forUser($user)->allows('delete', LiveStream::factory()->ended()->create()));
    }

    #[DataProvider('reviewerRoles')]
    public function test_bulk_deletion_is_never_offered(string $role): void
    {
        $this->actingAs($user = User::factory()->create(['role' => $role]));

        $this->assertFalse(Gate::forUser($user)->allows('deleteAny', LiveStream::class));
        $this->assertSame([], Livewire::test(ListLiveStreams::class)->instance()->getTable()->getBulkActions());
    }

    public function test_every_ability_the_panel_asks_about_is_defined_explicitly_because_authorization_is_strict(): void
    {
        $policy = new ReflectionClass(LiveStreamPolicy::class);

        foreach (['viewAny', 'view', 'create', 'update', 'delete', 'deleteAny', 'goLive', 'end'] as $method) {
            $this->assertTrue($policy->hasMethod($method), "LiveStreamPolicy::{$method}()");
        }
    }
}
