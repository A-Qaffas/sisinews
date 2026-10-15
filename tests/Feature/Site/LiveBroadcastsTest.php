<?php

namespace Tests\Feature\Site;

use App\Enums\LiveStatus;
use App\Enums\Locale;
use App\Services\LiveBroadcasts;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Tests\Feature\Site\Concerns\BuildsLiveBroadcasts;
use Tests\TestCase;

class LiveBroadcastsTest extends TestCase
{
    use BuildsLiveBroadcasts, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo(Carbon::parse('2026-01-15 12:00:00', 'UTC'));
        config(['site.cache.live' => 0]);
    }

    /** @return list<string> every SQL statement $work runs */
    private function queriesDuring(callable $work): array
    {
        $queries = [];
        DB::listen(function ($query) use (&$queries) {
            $queries[] = $query->sql;
        });

        $work();

        return $queries;
    }

    private ?array $regions = null;

    private function populate(int $count): void
    {
        $governorates = $this->regions ??= array_map(fn ($i) => $this->governorate("gov-{$i}", "Gov {$i}", "محافظة {$i}", $i), range(1, 5));

        foreach (range(1, $count) as $i) {
            $governorate = $governorates[$i % 5];
            $this->broadcast(LiveStatus::Live, ['en' => "Live {$i}", 'ar' => "مباشر {$i}"], $governorate);
            $this->broadcast(LiveStatus::Scheduled, ['en' => "Soon {$i}", 'ar' => "قريبا {$i}"], $governorate);
            $this->broadcast(LiveStatus::Ended, ['en' => "Done {$i}", 'ar' => "انتهى {$i}"], $governorate);
        }
    }

    public function test_the_live_page_runs_the_same_number_of_queries_however_many_broadcasts_there_are(): void
    {
        $this->populate(1);
        $this->get('/en/live')->assertOk(); // warms the navigation and ticker caches, which are not what is measured
        $few = $this->queriesDuring(fn () => $this->get('/en/live')->assertOk());

        $this->populate(6);
        $many = $this->queriesDuring(fn () => $this->get('/en/live')->assertOk());

        $this->assertCount(count($few), $many, 'queries with 1 set: '.implode(' | ', $few)."\n\nwith 7 sets: ".implode(' | ', $many));
        $this->assertSame([], array_values(array_filter($many, fn ($sql) => str_contains($sql, 'where "id" = ?') || str_contains($sql, 'where `id` = ?'))), 'no lazy loads by id');
    }

    public function test_the_live_page_and_a_filtered_view_each_use_few_queries(): void
    {
        config(['site.cache.live' => 30]);
        $this->populate(6);
        app(LiveBroadcasts::class)->liveNow(Locale::En); // the header summary is cached in production

        $queries = $this->queriesDuring(fn () => $this->get('/en/live?g=gov-1')->assertOk());

        $liveQueries = array_filter($queries, fn ($sql) => str_contains($sql, 'live_stream') || str_contains($sql, 'governorates'));
        // governorates + (streams + translations) for live, upcoming and ended.
        $this->assertLessThanOrEqual(7, count($liveQueries), implode("\n", $liveQueries));
    }

    public function test_the_header_summary_is_one_cached_lookup_with_constant_queries(): void
    {
        config(['site.cache.live' => 30]);
        $this->populate(6);

        $first = $this->queriesDuring(fn () => app(LiveBroadcasts::class)->liveNow(Locale::En));
        $second = $this->queriesDuring(fn () => app(LiveBroadcasts::class)->liveNow(Locale::En));

        $this->assertLessThanOrEqual(3, count($first), 'governorates, streams, translations');
        $this->assertSame([], $second, 'served from the cache');
    }

    public function test_the_summary_is_plain_arrays_in_the_page_language(): void
    {
        $cairo = $this->governorate('cairo', 'Cairo', 'القاهرة');
        $stream = $this->broadcast(LiveStatus::Live, ['en' => 'English title', 'ar' => 'عنوان عربي'], $cairo);
        $this->broadcast(LiveStatus::Scheduled, ['en' => 'Not live']);

        $summary = app(LiveBroadcasts::class)->liveNow(Locale::Ar);

        $this->assertSame([[
            'id' => $stream->id,
            'title' => 'عنوان عربي',
            'title_lang' => null,
            'title_dir' => null,
            'governorate' => 'القاهرة',
            'governorate_slug' => 'cairo',
        ]], $summary);
        $this->assertSame('Cairo', app(LiveBroadcasts::class)->liveNow(Locale::En)[0]['governorate']);
    }

    public function test_a_broadcast_whose_governorate_was_deleted_becomes_egypt_wide(): void
    {
        $cairo = $this->governorate('cairo', 'Cairo', 'القاهرة');
        $this->broadcast(LiveStatus::Live, ['en' => 'Was local'], $cairo);

        $cairo->delete();

        $summary = app(LiveBroadcasts::class)->liveNow(Locale::En);
        $this->assertNull($summary[0]['governorate']);
        $this->get('/en/live')->assertOk()->assertSee('Egypt-wide');
    }

    public function test_the_time_a_broadcast_ended_decides_whether_it_is_still_recent(): void
    {
        $this->broadcast(LiveStatus::Ended, ['en' => 'Just inside'], attributes: ['ended_at' => now()->subDays(7)->addMinute()]);
        $this->broadcast(LiveStatus::Ended, ['en' => 'Just outside'], attributes: ['ended_at' => now()->subDays(7)->subMinute()]);

        $ended = app(LiveBroadcasts::class)->page(Locale::En, app(LiveBroadcasts::class)->governorates())['ended'];

        $this->assertSame(['Just inside'], array_column($ended, 'title'));
    }

    public function test_the_governorate_list_is_in_sort_order(): void
    {
        $this->governorate('c', 'C', 'ج', 3);
        $this->governorate('a', 'A', 'أ', 1);
        $this->governorate('b', 'B', 'ب', 2);

        $this->assertSame(['a', 'b', 'c'], app(LiveBroadcasts::class)->governorates()->pluck('slug')->all());
    }
}
