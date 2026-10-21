<?php

namespace Tests\Unit;

use App\Enums\LiveStatus;
use App\Models\LiveStream;
use DomainException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * goLive() and end() are the only way a broadcast changes state: scheduled or ended -> live, live -> ended.
 */
class LiveStreamStateTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // The refusals are panel messages, and the suite runs the panel in English.
        app()->setLocale('en');
        $this->travelTo(now()->startOfSecond());
    }

    // --- going live -------------------------------------------------------------------------

    public function test_a_scheduled_broadcast_goes_live_and_records_when_it_started(): void
    {
        $stream = LiveStream::factory()->scheduled()->create();

        $returned = $stream->goLive();

        $this->assertSame($stream, $returned, 'it returns the same model so calls can be chained');
        $this->assertSame(LiveStatus::Live, $stream->status);
        $this->assertTrue($stream->started_at->equalTo(now()));
        $this->assertNull($stream->ended_at);

        $stored = LiveStream::query()->findOrFail($stream->getKey());
        $this->assertSame(LiveStatus::Live, $stored->status);
        $this->assertTrue($stored->started_at->equalTo(now()));
        $this->assertNull($stored->ended_at);
    }

    public function test_going_live_leaves_the_announced_time_alone(): void
    {
        $announced = now()->addHours(3);
        $stream = LiveStream::factory()->scheduled($announced)->create();

        $stream->goLive();

        $this->assertTrue($stream->fresh()->scheduled_at->equalTo($announced));
    }

    public function test_a_broadcast_without_an_announced_time_can_go_live(): void
    {
        $stream = LiveStream::factory()->create();
        $this->assertNull($stream->scheduled_at);

        $stream->goLive();

        $this->assertSame(LiveStatus::Live, $stream->fresh()->status);
    }

    public function test_a_broadcast_created_with_nothing_but_the_defaults_starts_as_scheduled_and_can_go_live(): void
    {
        $stream = new LiveStream(['provider' => 'youtube', 'video_ref' => 'dQw4w9WgXcQ']);
        $stream->save();

        $this->assertSame(LiveStatus::Scheduled, $stream->fresh()->status);

        $stream->goLive();

        $this->assertSame(LiveStatus::Live, $stream->fresh()->status);
    }

    public function test_an_ended_broadcast_resumes_and_keeps_its_first_start(): void
    {
        $firstStart = now()->subHours(2);
        $stream = LiveStream::factory()->ended()->create();
        $stream->forceFill(['started_at' => $firstStart])->save();

        $stream->goLive();

        $fresh = $stream->fresh();
        $this->assertSame(LiveStatus::Live, $fresh->status);
        $this->assertTrue($fresh->started_at->equalTo($firstStart), 'the first start is kept when a stream resumes');
        $this->assertNull($fresh->ended_at, 'it is on air again, so it has no end time');
    }

    public function test_a_broadcast_resumed_long_after_it_ended_starts_afresh(): void
    {
        $stream = LiveStream::factory()->ended()->create();
        $stream->forceFill(['started_at' => now()->subDays(40), 'ended_at' => now()->subDays(40)->addHours(2)])->save();
        $this->assertFalse($stream->fresh()->resumesSameSession());

        $stream->goLive();

        $fresh = $stream->fresh();
        $this->assertSame(LiveStatus::Live, $fresh->status);
        $this->assertTrue($fresh->started_at->equalTo(now()), 'not "started 40 days ago": it sorts with the new broadcasts');
        $this->assertNull($fresh->ended_at);
    }

    public function test_the_same_session_lasts_until_two_hours_after_the_end(): void
    {
        $firstStart = now()->subHours(5);
        $within = LiveStream::factory()->ended()->create();
        $within->forceFill(['started_at' => $firstStart, 'ended_at' => now()->subHours(LiveStream::SAME_SESSION_HOURS)->addMinute()])->save();
        $past = LiveStream::factory()->ended()->create();
        $past->forceFill(['started_at' => $firstStart, 'ended_at' => now()->subHours(LiveStream::SAME_SESSION_HOURS)->subMinute()])->save();

        $within->fresh()->goLive();
        $past->fresh()->goLive();

        $this->assertTrue($within->fresh()->started_at->equalTo($firstStart));
        $this->assertTrue($past->fresh()->started_at->equalTo(now()));
    }

    public function test_an_ended_broadcast_that_never_recorded_a_start_gets_one_when_it_resumes(): void
    {
        $stream = LiveStream::factory()->ended()->create();
        $stream->forceFill(['started_at' => null])->save();

        $stream->goLive();

        $this->assertTrue($stream->fresh()->started_at->equalTo(now()));
    }

    public function test_a_live_broadcast_cannot_go_live_again_and_nothing_changes(): void
    {
        $since = now()->subMinutes(30);
        $stream = LiveStream::factory()->live($since)->create();

        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('already live');

        try {
            $stream->goLive();
        } finally {
            $fresh = $stream->fresh();
            $this->assertSame(LiveStatus::Live, $fresh->status);
            $this->assertTrue($fresh->started_at->equalTo($since), 'the start time is not reset by a repeated click');
        }
    }

    // --- ending ----------------------------------------------------------------------------

    public function test_a_live_broadcast_ends_and_records_when(): void
    {
        $since = now()->subMinutes(45);
        $stream = LiveStream::factory()->live($since)->create();

        $returned = $stream->end();

        $this->assertSame($stream, $returned);
        $this->assertSame(LiveStatus::Ended, $stream->status);
        $this->assertTrue($stream->ended_at->equalTo(now()));

        $fresh = $stream->fresh();
        $this->assertSame(LiveStatus::Ended, $fresh->status);
        $this->assertTrue($fresh->ended_at->equalTo(now()));
        $this->assertTrue($fresh->started_at->equalTo($since), 'ending never rewrites the start');
    }

    public function test_a_scheduled_broadcast_cannot_be_ended(): void
    {
        $stream = LiveStream::factory()->scheduled()->create();

        try {
            $stream->end();
            $this->fail('A broadcast that has not started cannot be ended.');
        } catch (DomainException $exception) {
            $this->assertStringContainsString('not started', $exception->getMessage());
        }

        $fresh = $stream->fresh();
        $this->assertSame(LiveStatus::Scheduled, $fresh->status);
        $this->assertNull($fresh->ended_at);
    }

    public function test_an_ended_broadcast_cannot_be_ended_again_and_keeps_its_end_time(): void
    {
        $stream = LiveStream::factory()->ended()->create();
        $endedAt = $stream->fresh()->ended_at;

        try {
            $stream->end();
            $this->fail('An ended broadcast cannot be ended again.');
        } catch (DomainException $exception) {
            $this->assertStringContainsString('already ended', $exception->getMessage());
        }

        $this->assertTrue($stream->fresh()->ended_at->equalTo($endedAt));
    }

    // --- the full cycle and the helpers -----------------------------------------------------

    public function test_a_broadcast_can_be_taken_through_scheduled_live_ended_live_ended(): void
    {
        $stream = LiveStream::factory()->scheduled()->create();

        $stream->goLive();
        $this->travel(30)->minutes();
        $stream->end();
        $this->travel(5)->minutes();
        $stream->goLive();
        $this->assertSame(LiveStatus::Live, $stream->fresh()->status);
        $this->travel(10)->minutes();
        $stream->end();

        $fresh = $stream->fresh();
        $this->assertSame(LiveStatus::Ended, $fresh->status);
        $this->assertTrue($fresh->started_at->equalTo(now()->subMinutes(45)));
        $this->assertTrue($fresh->ended_at->equalTo(now()));
    }

    /** @return array<string, array{0: string, 1: bool, 2: bool}> */
    public static function legalMoves(): array
    {
        return [
            'scheduled' => ['scheduled', true, false],
            'live' => ['live', false, true],
            'ended' => ['ended', true, false],
        ];
    }

    #[DataProvider('legalMoves')]
    public function test_the_helpers_say_which_moves_are_open(string $state, bool $canGoLive, bool $canEnd): void
    {
        $stream = LiveStream::factory()->{$state}()->create();

        $this->assertSame($canGoLive, $stream->canGoLive());
        $this->assertSame($canEnd, $stream->canEnd());
    }

    public function test_the_move_is_judged_by_the_stored_state_not_by_a_stale_copy(): void
    {
        $stale = LiveStream::factory()->scheduled()->create();
        LiveStream::query()->findOrFail($stale->getKey())->goLive();

        $this->assertSame(LiveStatus::Scheduled, $stale->status, 'the stale copy has not noticed yet');

        try {
            $stale->goLive();
            $this->fail('The broadcast is already live in the database.');
        } catch (DomainException $exception) {
            $this->assertStringContainsString('already live', $exception->getMessage());
        }

        // Ending through the stale copy works, because the stored state allows it, and the copy catches up.
        $stale->end();
        $this->assertSame(LiveStatus::Ended, $stale->status);
        $this->assertSame(LiveStatus::Ended, $stale->fresh()->status);
    }

    public function test_the_move_does_not_overwrite_unrelated_columns_of_the_stored_row(): void
    {
        $stream = LiveStream::factory()->scheduled()->create(['video_ref' => 'AAAAAAAAAAA']);
        LiveStream::query()->whereKey($stream->getKey())->update(['video_ref' => 'BBBBBBBBBBB']);

        $stream->goLive();

        $this->assertSame('BBBBBBBBBBB', $stream->fresh()->video_ref, 'a link changed elsewhere in the meantime survives');
        $this->assertSame('BBBBBBBBBBB', $stream->video_ref, 'and the model reflects it');
    }

    public function test_a_broadcast_that_was_never_saved_cannot_change_state(): void
    {
        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('Save the broadcast');

        (new LiveStream)->goLive();
    }

    public function test_a_deleted_broadcast_cannot_change_state(): void
    {
        $stream = LiveStream::factory()->scheduled()->create();
        LiveStream::query()->whereKey($stream->getKey())->delete();

        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('no longer exists');

        $stream->goLive();
    }

    public function test_status_and_the_times_cannot_be_mass_assigned(): void
    {
        $stream = LiveStream::factory()->scheduled()->create();

        $stream->fill(['status' => 'live', 'started_at' => now(), 'ended_at' => now(), 'created_by' => 1])->save();

        $fresh = $stream->fresh();
        $this->assertSame(LiveStatus::Scheduled, $fresh->status);
        $this->assertNull($fresh->started_at);
        $this->assertNull($fresh->ended_at);
        $this->assertNull($fresh->created_by);
    }

    public function test_the_scopes_follow_the_state(): void
    {
        $scheduled = LiveStream::factory()->scheduled()->create();
        $live = LiveStream::factory()->live()->create();
        $ended = LiveStream::factory()->ended()->create();

        $this->assertSame([$live->getKey()], LiveStream::query()->liveNow()->pluck('id')->all());
        $this->assertSame([$scheduled->getKey()], LiveStream::query()->upcoming()->pluck('id')->all());
        $this->assertSame([$ended->getKey()], LiveStream::query()->ended()->pluck('id')->all());

        $scheduled->goLive();
        $live->end();

        $this->assertEqualsCanonicalizing([$scheduled->getKey()], LiveStream::query()->liveNow()->pluck('id')->all());
        $this->assertEqualsCanonicalizing([$live->getKey(), $ended->getKey()], LiveStream::query()->ended()->pluck('id')->all());
    }
}
