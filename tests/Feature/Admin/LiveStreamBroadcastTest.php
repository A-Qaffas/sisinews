<?php

namespace Tests\Feature\Admin;

use App\Enums\LiveProvider;
use App\Enums\LiveStatus;
use App\Filament\Resources\LiveStreams\Pages\EditLiveStream;
use App\Filament\Resources\LiveStreams\Pages\ListLiveStreams;
use App\Filament\Resources\LiveStreams\Support\LiveStreamActions;
use App\Filament\Support\PhoneFriendly;
use App\Filament\Support\StatusPresenter;
use App\Models\Governorate;
use App\Models\LiveStream;
use App\Models\LiveStreamTranslation;
use App\Models\User;
use Carbon\CarbonImmutable;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Notifications\Notification;
use Filament\Support\Icons\Heroicon;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\DataProvider;

/** The list, the Go live / End / Resume buttons and deleting: what an editor does with a broadcast day to day. */
class LiveStreamBroadcastTest extends AdminTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo(CarbonImmutable::parse('2026-11-20 10:00:00', 'UTC'));
    }

    /** @return array<string, list<string>> */
    public static function reviewerRoles(): array
    {
        return ['editor' => ['editor'], 'admin' => ['admin']];
    }

    private function signIn(string $role = 'editor'): User
    {
        return tap(User::factory()->create(['role' => $role]), fn (User $user) => $this->actingAs($user));
    }

    // --- the list -------------------------------------------------------------------------------

    public function test_the_list_shows_titles_governorate_provider_status_and_time(): void
    {
        $this->signIn();
        $giza = Governorate::factory()->named('giza', 'Giza', 'الجيزة', 2)->create();
        $live = LiveStream::factory()->live(now()->subMinutes(20))->inGovernorate($giza)->withTranslations('بث من الجيزة', 'Live from Giza')->create();
        $wide = LiveStream::factory()->vimeo()->scheduled(now()->addDay())->withTranslations()->create();
        $ended = LiveStream::factory()->ended()->arabicOnly('بث انتهى')->create();

        Livewire::test(ListLiveStreams::class)
            ->assertSuccessful()
            ->assertCanSeeTableRecords([$live, $wide, $ended])
            ->assertTableColumnStateSet('ar_title', 'بث من الجيزة', $live)
            ->assertTableColumnStateSet('en_title', 'Live from Giza', $live)
            ->assertTableColumnStateSet('headline', 'بث من الجيزة', $live)
            ->assertTableColumnStateSet('governorate', 'Giza', $live)
            ->assertTableColumnHasDescription('governorate', 'الجيزة', $live)
            ->assertTableColumnStateSet('governorate', 'Egypt-wide', $wide)
            ->assertTableColumnDoesNotHaveDescription('governorate', 'الجيزة', $wide)
            ->assertTableColumnStateSet('provider', LiveProvider::YouTube, $live)
            ->assertTableColumnStateSet('provider', LiveProvider::Vimeo, $wide)
            ->assertTableColumnStateSet('status', LiveStatus::Live, $live)
            ->assertTableColumnStateSet('status', LiveStatus::Scheduled, $wide)
            ->assertTableColumnStateSet('status', LiveStatus::Ended, $ended)
            ->assertTableColumnStateSet('en_title', null, $ended)
            ->assertTableColumnStateSet('when', 'Since '.StatusPresenter::when(now()->subMinutes(20)), $live)
            ->assertTableColumnStateSet('when', 'Due '.StatusPresenter::when(now()->addDay()), $wide)
            ->assertTableColumnStateSet('when', 'Ended '.StatusPresenter::when($ended->fresh()->ended_at), $ended);
    }

    public function test_the_phone_title_falls_back_to_english_and_names_the_governorate_beneath_it(): void
    {
        $this->signIn();
        $cairo = Governorate::factory()->named('cairo', 'Cairo', 'القاهرة', 1)->create();
        $english = LiveStream::factory()->inGovernorate($cairo)->englishOnly('Only in English')->create();
        $wide = LiveStream::factory()->arabicOnly('عنوان')->create();

        Livewire::test(ListLiveStreams::class)
            ->assertTableColumnStateSet('headline', 'Only in English', $english)
            ->assertTableColumnStateSet('headline', 'عنوان', $wide)
            ->assertTableColumnHasDescription('headline', 'القاهرة', $english)
            ->assertTableColumnHasDescription('headline', 'Egypt-wide', $wide);
    }

    public function test_live_broadcasts_come_first_then_the_scheduled_then_the_ended(): void
    {
        $this->signIn();
        $endedOld = LiveStream::factory()->ended()->withTranslations()->create();
        $scheduled = LiveStream::factory()->scheduled()->withTranslations()->create();
        $liveOld = LiveStream::factory()->live()->withTranslations()->create();
        $endedNew = LiveStream::factory()->ended()->withTranslations()->create();
        $liveNew = LiveStream::factory()->live()->withTranslations()->create();

        Livewire::test(ListLiveStreams::class)
            ->assertCanSeeTableRecords([$liveNew, $liveOld, $scheduled, $endedNew, $endedOld], inOrder: true);
    }

    public function test_within_each_group_the_most_relevant_comes_first(): void
    {
        $this->signIn();
        // Created in the "wrong" order, so sorting by id alone (as before) would fail every group.
        $liveLate = LiveStream::factory()->live(now()->subMinutes(5))->withTranslations()->create();
        $liveEarly = LiveStream::factory()->live(now()->subHours(3))->withTranslations()->create();
        $tomorrow = LiveStream::factory()->scheduled(now()->addDay())->withTranslations()->create();
        $nextWeek = LiveStream::factory()->scheduled(now()->addWeek())->withTranslations()->create();
        $unscheduled = LiveStream::factory()->withTranslations()->create();
        $endedThisMinute = LiveStream::factory()->ended()->withTranslations()->create();
        $endedThisMorning = LiveStream::factory()->ended()->withTranslations()->create();
        $endedThisMinute->forceFill(['ended_at' => now()->subMinute()])->save();
        $endedThisMorning->forceFill(['ended_at' => now()->subHours(6)])->save();

        Livewire::test(ListLiveStreams::class)->assertCanSeeTableRecords(
            [$liveLate, $liveEarly, $tomorrow, $nextWeek, $unscheduled, $endedThisMinute, $endedThisMorning],
            inOrder: true,
        );
    }

    public function test_the_live_badge_stands_out(): void
    {
        $this->signIn();
        $live = LiveStream::factory()->live()->withTranslations()->create();
        $scheduled = LiveStream::factory()->scheduled()->withTranslations()->create();
        $ended = LiveStream::factory()->ended()->withTranslations()->create();

        $column = Livewire::test(ListLiveStreams::class)->instance()->getTable()->getColumn('status');

        $this->assertTrue($column->isBadge());
        $this->assertStringContainsString('sisi-live-status', (string) $column->getExtraAttributeBag()->get('class'), 'the class admin-styles uses to make "Live now" solid crimson');
        $this->assertSame('danger', $column->record($live)->getColor(LiveStatus::Live));
        $this->assertSame(Heroicon::Signal, $column->getIcon(LiveStatus::Live));
        $this->assertSame(StatusPresenter::SCHEDULED_COLOR, $column->getColor(LiveStatus::Scheduled));
        $this->assertSame('gray', $column->getColor(LiveStatus::Ended));
        $this->assertNull($column->getIcon(LiveStatus::Scheduled));
        $this->assertNull($column->getIcon(LiveStatus::Ended));
        $this->assertSame('Live now', $column->formatState(LiveStatus::Live));
        $this->assertSame('Scheduled', $column->formatState(LiveStatus::Scheduled));
        $this->assertSame('Ended', $column->formatState(LiveStatus::Ended));
    }

    public function test_the_list_filters_by_status(): void
    {
        $this->signIn();
        $live = LiveStream::factory()->live()->withTranslations()->create();
        $scheduled = LiveStream::factory()->scheduled()->withTranslations()->create();
        $ended = LiveStream::factory()->ended()->withTranslations()->create();

        Livewire::test(ListLiveStreams::class)
            ->filterTable('status', 'live')
            ->assertCanSeeTableRecords([$live])
            ->assertCanNotSeeTableRecords([$scheduled, $ended])
            ->filterTable('status', 'scheduled')
            ->assertCanSeeTableRecords([$scheduled])
            ->assertCanNotSeeTableRecords([$live, $ended])
            ->filterTable('status', 'ended')
            ->assertCanSeeTableRecords([$ended])
            ->assertCanNotSeeTableRecords([$live, $scheduled]);
    }

    public function test_the_list_filters_by_governorate_and_by_egypt_wide(): void
    {
        $this->signIn();
        $cairo = Governorate::factory()->named('cairo', 'Cairo', 'القاهرة', 1)->create();
        $giza = Governorate::factory()->named('giza', 'Giza', 'الجيزة', 2)->create();
        $inCairo = LiveStream::factory()->inGovernorate($cairo)->withTranslations()->create();
        $inGiza = LiveStream::factory()->inGovernorate($giza)->withTranslations()->create();
        $wide = LiveStream::factory()->egyptWide()->withTranslations()->create();

        Livewire::test(ListLiveStreams::class)
            ->filterTable('governorate_id', (string) $cairo->getKey())
            ->assertCanSeeTableRecords([$inCairo])
            ->assertCanNotSeeTableRecords([$inGiza, $wide])
            ->filterTable('governorate_id', 'egypt')
            ->assertCanSeeTableRecords([$wide])
            ->assertCanNotSeeTableRecords([$inCairo, $inGiza])
            ->removeTableFilter('governorate_id')
            ->assertCanSeeTableRecords([$inCairo, $inGiza, $wide]);
    }

    public function test_the_list_can_be_searched_by_either_title(): void
    {
        $this->signIn();
        $wanted = LiveStream::factory()->withTranslations('بث من الأقصر', 'Zanzibar harbour')->create();
        $other = LiveStream::factory()->withTranslations('بث آخر', 'Something else')->create();

        Livewire::test(ListLiveStreams::class)
            ->searchTable('Zanzibar')->assertCanSeeTableRecords([$wanted])->assertCanNotSeeTableRecords([$other])
            ->searchTable('الأقصر')->assertCanSeeTableRecords([$wanted])->assertCanNotSeeTableRecords([$other])
            ->searchTable('100%_')->assertCountTableRecords(0);
    }

    public function test_an_empty_list_explains_what_to_do(): void
    {
        $this->signIn();

        Livewire::test(ListLiveStreams::class)
            ->assertSee('No broadcasts yet')
            ->assertSee('press Go live when it starts');
    }

    public function test_the_list_keeps_title_and_status_on_a_phone_and_defers_the_rest_to_md(): void
    {
        $this->signIn();
        $table = Livewire::test(ListLiveStreams::class)->instance()->getTable();

        foreach (['provider', 'when'] as $secondary) {
            $this->assertSame('md', $table->getColumn($secondary)->getVisibleFrom(), "{$secondary} must wait for md");
        }
        // Up to xl (a 1024 px tablet, a laptop with the sidebar open) one title cell carries the governorate too, so the
        // Go live / End buttons stay on screen; from xl each language and the governorate get their own column.
        foreach (['ar_title', 'en_title', 'governorate'] as $wide) {
            $this->assertSame('xl', $table->getColumn($wide)->getVisibleFrom(), "{$wide} must wait for xl");
        }
        $this->assertSame('xl', $table->getColumn('headline')->getHiddenFrom());
        $this->assertNull($table->getColumn('status')->getVisibleFrom());
        $this->assertNull($table->getColumn('status')->getHiddenFrom());

        $classes = fn (string $name): string => (string) $table->getAction($name)->getExtraAttributeBag()->get('class');
        $this->assertStringContainsString(PhoneFriendly::ICON_ONLY_CLASS, $classes('goLive'));
        $this->assertStringContainsString(PhoneFriendly::ICON_ONLY_CLASS, $classes('end'));
        $this->assertStringContainsString(PhoneFriendly::HIDDEN_CLASS, $classes('edit'), 'the whole row opens the broadcast on a phone');
        $this->assertNotNull($table->getRecordUrl(LiveStream::factory()->create()), 'the row is a link');
        $this->assertInstanceOf(ActionGroup::class, collect($table->getRecordActions())->last(), 'delete sits behind one menu icon');
    }

    public function test_the_list_runs_the_same_queries_for_one_broadcast_or_many(): void
    {
        $this->signIn();
        $cairo = Governorate::factory()->create();
        LiveStream::factory()->inGovernorate($cairo)->withTranslations()->create();
        $few = $this->queryCount(fn () => Livewire::test(ListLiveStreams::class)->assertSuccessful());

        foreach (range(1, 8) as $ignored) {
            LiveStream::factory()->inGovernorate($cairo)->live()->withTranslations()->create();
        }

        $this->assertSame($few, $this->queryCount(fn () => Livewire::test(ListLiveStreams::class)->assertSuccessful()));
    }

    // --- Go live --------------------------------------------------------------------------------

    #[DataProvider('reviewerRoles')]
    public function test_go_live_from_the_list_puts_a_scheduled_broadcast_on_air(string $role): void
    {
        $this->signIn($role);
        $stream = LiveStream::factory()->scheduled(now()->addHour())->withTranslations()->create();

        Livewire::test(ListLiveStreams::class)
            ->callTableAction('goLive', $stream)
            ->assertNotified('The broadcast is live.');

        $fresh = $stream->fresh();
        $this->assertSame(LiveStatus::Live, $fresh->status);
        $this->assertTrue($fresh->started_at->equalTo(now()));
        $this->assertNull($fresh->ended_at);
        $this->assertSame([$stream->getKey()], LiveStream::query()->liveNow()->pluck('id')->all());
    }

    public function test_go_live_asks_for_confirmation_first(): void
    {
        $this->signIn();
        $stream = LiveStream::factory()->scheduled()->withTranslations()->create();

        $component = Livewire::test(ListLiveStreams::class);
        $this->assertTrue($component->instance()->getTable()->getAction('goLive')->isConfirmationRequired());
        $this->assertTrue($component->instance()->getTable()->getAction('end')->isConfirmationRequired());

        // Opening the action does not run it: only confirming does.
        $component->mountTableAction('goLive', $stream);
        $this->assertSame(LiveStatus::Scheduled, $stream->fresh()->status);
    }

    public function test_the_buttons_offered_follow_the_status(): void
    {
        $this->signIn();
        $scheduled = LiveStream::factory()->scheduled()->withTranslations()->create();
        $live = LiveStream::factory()->live()->withTranslations()->create();
        $ended = LiveStream::factory()->ended()->withTranslations()->create();

        Livewire::test(ListLiveStreams::class)
            ->assertTableActionVisible('goLive', $scheduled)
            ->assertTableActionHidden('end', $scheduled)
            ->assertTableActionHidden('goLive', $live)
            ->assertTableActionVisible('end', $live)
            ->assertTableActionVisible('goLive', $ended)
            ->assertTableActionHidden('end', $ended);
    }

    public function test_an_ended_broadcast_is_offered_as_a_resume_and_keeps_its_first_start(): void
    {
        $this->signIn();
        $stream = LiveStream::factory()->ended()->withTranslations()->create();
        $firstStart = $stream->fresh()->started_at;

        $component = Livewire::test(ListLiveStreams::class);
        $table = $component->instance()->getTable();
        $this->assertSame('Resume', $table->getAction('goLive')->record($stream->fresh())->getLabel());
        $this->assertSame('Go live', $table->getAction('goLive')->record(LiveStream::factory()->scheduled()->create()->fresh())->getLabel());

        $component->callTableAction('goLive', $stream)->assertNotified('The broadcast is live.');

        $fresh = $stream->fresh();
        $this->assertSame(LiveStatus::Live, $fresh->status);
        $this->assertTrue($fresh->started_at->equalTo($firstStart));
        $this->assertNull($fresh->ended_at);
    }

    public function test_a_stale_click_on_go_live_after_someone_else_did_it_changes_nothing(): void
    {
        $this->signIn();
        $stream = LiveStream::factory()->scheduled()->withTranslations()->create();
        $component = Livewire::test(ListLiveStreams::class)->assertTableActionVisible('goLive', $stream);

        $this->travel(10)->minutes();
        LiveStream::query()->findOrFail($stream->getKey())->goLive();
        $startedAt = $stream->fresh()->started_at;
        $this->travel(10)->minutes();

        $component
            ->call('mountTableAction', 'goLive', (string) $stream->getKey())
            ->call('callMountedTableAction');

        $fresh = $stream->fresh();
        $this->assertSame(LiveStatus::Live, $fresh->status);
        $this->assertTrue($fresh->started_at->equalTo($startedAt), 'the start time is not reset by the second click');
    }

    public function test_a_refused_move_becomes_a_message_not_an_error_page(): void
    {
        $this->signIn();
        $staleCopy = LiveStream::factory()->scheduled()->create();
        LiveStream::query()->findOrFail($staleCopy->getKey())->goLive();

        // The copy still says "scheduled", so the button is on offer; the model refuses because the stored state moved on.
        LiveStreamActions::goLive()->record($staleCopy)->call();

        Notification::assertNotified(
            Notification::make()->danger()->title('Nothing was changed')->body('This broadcast is already live.')
        );
        $this->assertSame(LiveStatus::Live, $staleCopy->fresh()->status);
    }

    // --- End ------------------------------------------------------------------------------------

    #[DataProvider('reviewerRoles')]
    public function test_end_from_the_list_takes_a_live_broadcast_off_air(string $role): void
    {
        $this->signIn($role);
        $stream = LiveStream::factory()->live(now()->subMinutes(30))->withTranslations()->create();

        Livewire::test(ListLiveStreams::class)
            ->callTableAction('end', $stream)
            ->assertNotified('The broadcast has ended.');

        $fresh = $stream->fresh();
        $this->assertSame(LiveStatus::Ended, $fresh->status);
        $this->assertTrue($fresh->ended_at->equalTo(now()));
        $this->assertTrue($fresh->started_at->equalTo(now()->subMinutes(30)));
        $this->assertSame(0, LiveStream::query()->liveNow()->count());
    }

    public function test_ending_a_broadcast_that_is_not_live_is_impossible_from_the_list(): void
    {
        $this->signIn();
        $scheduled = LiveStream::factory()->scheduled()->withTranslations()->create();
        $ended = LiveStream::factory()->ended()->withTranslations()->create();
        $endedAt = $ended->fresh()->ended_at;

        Livewire::test(ListLiveStreams::class)
            ->call('mountTableAction', 'end', (string) $scheduled->getKey())
            ->call('callMountedTableAction')
            ->call('mountTableAction', 'end', (string) $ended->getKey())
            ->call('callMountedTableAction');

        $this->assertSame(LiveStatus::Scheduled, $scheduled->fresh()->status);
        $this->assertNull($scheduled->fresh()->ended_at);
        $this->assertTrue($ended->fresh()->ended_at->equalTo($endedAt));
    }

    // --- the edit page --------------------------------------------------------------------------

    public function test_the_edit_page_offers_the_same_buttons_and_they_work(): void
    {
        $this->signIn();
        $stream = LiveStream::factory()->scheduled()->withTranslations()->create();

        $page = Livewire::test(EditLiveStream::class, ['record' => $stream->getKey()])
            ->assertActionVisible('goLive')
            ->assertActionHidden('end')
            ->callAction('goLive')
            ->assertNotified('The broadcast is live.');

        $this->assertSame(LiveStatus::Live, $stream->fresh()->status);
        $page->assertActionHidden('goLive')->assertActionVisible('end');

        $page->callAction('end')->assertNotified('The broadcast has ended.');

        $this->assertSame(LiveStatus::Ended, $stream->fresh()->status);
        $page->assertActionVisible('goLive')->assertActionHidden('end');
    }

    public function test_go_live_on_the_edit_page_puts_the_link_on_screen_on_air_not_the_last_saved_one(): void
    {
        $this->signIn();
        $alexandria = Governorate::factory()->named('alexandria', 'Alexandria', 'الإسكندرية', 2)->create();
        $stream = LiveStream::factory()->youtube('M7lc1UVf-VE')->scheduled()->inGovernorate($alexandria)->withTranslations()->create();

        $page = Livewire::test(EditLiveStream::class, ['record' => $stream->getKey()])
            ->fillForm(['video_link' => 'https://youtu.be/dQw4w9WgXcQ'])
            ->mountAction('goLive');
        $this->assertStringContainsString('YouTube video dQw4w9WgXcQ', (string) $page->instance()->getMountedAction()->getModalDescription(), 'the confirmation names the video that will go live');
        $this->assertStringContainsString('saved first', (string) $page->instance()->getMountedAction()->getModalDescription());

        $page->callMountedAction()
            ->assertHasNoFormErrors()
            ->assertNotified('The broadcast is live.');

        $fresh = $stream->fresh();
        $this->assertSame(LiveStatus::Live, $fresh->status);
        $this->assertSame('dQw4w9WgXcQ', $fresh->video_ref, 'readers get the video the editor sees');
    }

    public function test_a_link_that_does_not_validate_stops_go_live_on_the_edit_page(): void
    {
        $this->signIn();
        $stream = LiveStream::factory()->youtube('M7lc1UVf-VE')->scheduled()->withTranslations()->create();

        Livewire::test(EditLiveStream::class, ['record' => $stream->getKey()])
            ->fillForm(['video_link' => 'https://example.com/not-a-video'])
            ->callAction('goLive')
            ->assertHasFormErrors(['video_link'])
            ->assertNotified(Notification::make()->danger()->title('Nothing was changed')->body('Fix the highlighted fields first. The broadcast did not go live.'));

        $fresh = $stream->fresh();
        $this->assertSame(LiveStatus::Scheduled, $fresh->status);
        $this->assertSame('M7lc1UVf-VE', $fresh->video_ref);
    }

    public function test_the_confirmations_name_the_broadcast_its_place_and_its_video(): void
    {
        $this->signIn();
        $luxor = Governorate::factory()->named('luxor', 'Luxor', 'الأقصر', 3)->create();
        $scheduled = LiveStream::factory()->youtube('dQw4w9WgXcQ')->scheduled()->inGovernorate($luxor)->withTranslations('افتتاح طريق الكباش', 'Avenue of Sphinxes')->create();
        $live = LiveStream::factory()->vimeo('123456789')->live()->englishOnly('Cabinet briefing')->create();
        $table = Livewire::test(ListLiveStreams::class)->instance()->getTable();

        $goLive = $table->getAction('goLive')->record($scheduled->fresh(['translations', 'governorate']));
        $this->assertSame('Go live with “<bdi>افتتاح طريق الكباش</bdi>”?', $goLive->getModalHeading()->toHtml());
        $this->assertStringStartsWith('Luxor (الأقصر). YouTube video dQw4w9WgXcQ.', $goLive->getModalDescription());

        $end = $table->getAction('end')->record($live->fresh(['translations', 'governorate']));
        $this->assertSame('End “<bdi>Cabinet briefing</bdi>”?', $end->getModalHeading()->toHtml());
        $this->assertStringStartsWith('Egypt-wide.', $end->getModalDescription());
        $this->assertSame('danger', $end->getColor());
        $this->assertSame(Heroicon::OutlinedStopCircle, $end->getModalIcon());

        $untitled = LiveStream::factory()->scheduled()->create();
        $this->assertSame('Go live with this broadcast?', $table->getAction('goLive')->record($untitled->fresh(['translations', 'governorate']))->getModalHeading()->toHtml());

        $hostile = LiveStream::factory()->live()->englishOnly('<script>x</script>')->create();
        $this->assertStringNotContainsString('<script>', $table->getAction('end')->record($hostile->fresh(['translations', 'governorate']))->getModalHeading()->toHtml());
    }

    public function test_after_go_live_or_end_the_menu_count_is_redrawn(): void
    {
        $this->signIn();
        $stream = LiveStream::factory()->scheduled()->withTranslations()->create();

        Livewire::test(ListLiveStreams::class)
            ->callTableAction('goLive', $stream)
            ->assertDispatched('refresh-sidebar')
            ->callTableAction('end', $stream->fresh())
            ->assertDispatched('refresh-sidebar');
    }

    public function test_resuming_long_after_the_end_is_announced_as_a_new_start(): void
    {
        $this->signIn();
        $recent = LiveStream::factory()->ended()->withTranslations()->create();
        $old = LiveStream::factory()->ended()->withTranslations()->create();
        $old->forceFill(['started_at' => now()->subDays(40), 'ended_at' => now()->subDays(40)->addHour()])->save();
        $table = Livewire::test(ListLiveStreams::class)->instance()->getTable();

        $this->assertStringContainsString('keeps its first start time', $table->getAction('goLive')->record($recent->fresh())->getModalDescription());
        $this->assertStringContainsString('counts as a new start', $table->getAction('goLive')->record($old->fresh())->getModalDescription());
    }

    // --- deleting -------------------------------------------------------------------------------

    #[DataProvider('reviewerRoles')]
    public function test_a_scheduled_or_ended_broadcast_can_be_deleted_from_the_list_with_its_titles(string $role): void
    {
        $this->signIn($role);
        $scheduled = LiveStream::factory()->scheduled()->withTranslations()->create();
        $ended = LiveStream::factory()->ended()->withTranslations()->create();

        Livewire::test(ListLiveStreams::class)
            ->assertTableActionVisible('delete', $scheduled)
            ->assertTableActionVisible('delete', $ended)
            ->callTableAction('delete', $scheduled)
            ->callTableAction('delete', $ended);

        $this->assertModelMissing($scheduled);
        $this->assertModelMissing($ended);
        $this->assertSame(0, LiveStreamTranslation::query()->count());
    }

    #[DataProvider('reviewerRoles')]
    public function test_a_live_broadcast_cannot_be_deleted_from_the_list_even_by_a_forced_call(string $role): void
    {
        $this->signIn($role);
        $live = LiveStream::factory()->live()->withTranslations()->create();

        Livewire::test(ListLiveStreams::class)
            ->assertTableActionHidden('delete', $live)
            ->call('mountTableAction', 'delete', (string) $live->getKey())
            ->call('callMountedTableAction');

        $this->assertModelExists($live);
        $this->assertSame(2, $live->translations()->count());
        $this->assertSame(LiveStatus::Live, $live->fresh()->status);
    }

    public function test_the_edit_page_offers_delete_only_when_the_broadcast_is_not_live(): void
    {
        $this->signIn();
        $live = LiveStream::factory()->live()->withTranslations()->create();
        $scheduled = LiveStream::factory()->scheduled()->withTranslations()->create();

        Livewire::test(EditLiveStream::class, ['record' => $live->getKey()])->assertActionHidden('delete');
        Livewire::test(EditLiveStream::class, ['record' => $scheduled->getKey()])
            ->assertActionVisible('delete')
            ->callAction('delete');

        $this->assertModelExists($live);
        $this->assertModelMissing($scheduled);
    }

    public function test_a_broadcast_must_be_ended_before_it_can_be_deleted(): void
    {
        $this->signIn();
        $stream = LiveStream::factory()->live()->withTranslations()->create();

        Livewire::test(ListLiveStreams::class)->assertTableActionHidden('delete', $stream)->callTableAction('end', $stream);
        $this->assertModelExists($stream);

        Livewire::test(ListLiveStreams::class)->assertTableActionVisible('delete', $stream->fresh())->callTableAction('delete', $stream->fresh());
        $this->assertModelMissing($stream);
    }

    public function test_deleting_a_broadcast_leaves_the_governorate_and_the_other_broadcasts_alone(): void
    {
        $this->signIn();
        $cairo = Governorate::factory()->create();
        $doomed = LiveStream::factory()->ended()->inGovernorate($cairo)->withTranslations()->create();
        $kept = LiveStream::factory()->live()->inGovernorate($cairo)->withTranslations()->create();

        Livewire::test(ListLiveStreams::class)->callTableAction('delete', $doomed);

        $this->assertModelExists($cairo);
        $this->assertModelExists($kept);
        $this->assertSame(2, $kept->translations()->count());
    }

    public function test_the_row_actions_are_plain_filament_actions_with_confirmation_wording(): void
    {
        $this->signIn();
        $table = Livewire::test(ListLiveStreams::class)->instance()->getTable();

        foreach (['goLive', 'end', 'delete'] as $name) {
            $this->assertInstanceOf(Action::class, $table->getAction($name), $name);
        }
    }
}
