<?php

namespace Tests\Feature\Site\Services;

use App\Models\SiteSetting;
use App\Services\SiteSettings;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Facades\Schema;
use InvalidArgumentException;
use RuntimeException;
use Tests\TestCase;

class SiteSettingsTest extends TestCase
{
    use RefreshDatabase;

    private function settings(): SiteSettings
    {
        return app(SiteSettings::class);
    }

    /** Queries that read or write the settings table while $work runs. */
    private function settingsQueries(callable $work): int
    {
        $count = 0;
        DB::listen(function ($query) use (&$count): void {
            if (str_contains($query->sql, 'site_settings')) {
                $count++;
            }
        });

        $work();

        return $count;
    }

    public function test_an_unknown_key_gives_the_default(): void
    {
        $this->assertNull($this->settings()->get('masthead_editor_ar'));
        $this->assertSame('fallback', $this->settings()->get('masthead_editor_ar', 'fallback'));
    }

    public function test_a_value_is_stored_trimmed_and_read_back(): void
    {
        $this->settings()->set('masthead_editor_ar', "  ناجي أبو مغنم \n");

        $this->assertSame('ناجي أبو مغنم', $this->settings()->get('masthead_editor_ar'));
        $this->assertSame('ناجي أبو مغنم', SiteSetting::query()->where('key', 'masthead_editor_ar')->value('value'));
    }

    public function test_saving_again_replaces_the_value_in_the_same_row(): void
    {
        $this->settings()->set('masthead_editor_en', 'First');
        $this->settings()->set('masthead_editor_en', 'Second');

        $this->assertSame('Second', $this->settings()->get('masthead_editor_en'));
        $this->assertSame(1, SiteSetting::query()->where('key', 'masthead_editor_en')->count());
    }

    public function test_empty_and_whitespace_values_are_stored_as_not_set(): void
    {
        foreach (['', '   ', null] as $empty) {
            $this->settings()->set('masthead_editor_ar', 'Someone');
            $this->settings()->set('masthead_editor_ar', $empty);

            $this->assertNull($this->settings()->get('masthead_editor_ar'));
            $this->assertSame('default', $this->settings()->get('masthead_editor_ar', 'default'));
            $this->assertNull(SiteSetting::query()->where('key', 'masthead_editor_ar')->value('value'));
        }
    }

    public function test_html_is_kept_as_plain_text(): void
    {
        $this->settings()->set('masthead_editor_en', '<b>Bold</b> & co');

        $this->assertSame('<b>Bold</b> & co', $this->settings()->get('masthead_editor_en'));
    }

    public function test_keys_must_fit_the_column(): void
    {
        $this->settings()->set(str_repeat('k', 64), 'fits');
        $this->assertSame('fits', $this->settings()->get(str_repeat('k', 64)));

        foreach (['', str_repeat('k', 65)] as $bad) {
            try {
                $this->settings()->set($bad, 'x');
                $this->fail('accepted the key "'.$bad.'"');
            } catch (InvalidArgumentException) {
                $this->assertSame(1, SiteSetting::query()->count());
            }
        }
    }

    public function test_all_settings_are_read_with_one_query_and_then_from_the_cache(): void
    {
        $this->settings()->set('masthead_chairman_ar', 'تغريد رفاعي');
        $this->settings()->set('masthead_editor_ar', 'ناجي أبو مغنم');

        $this->assertSame(1, $this->settingsQueries(function (): void {
            $this->settings()->get('masthead_chairman_ar');
            $this->settings()->get('masthead_editor_ar');
            $this->settings()->get('masthead_editor_en');
        }), 'one query for every key');

        $this->assertSame(0, $this->settingsQueries(fn () => $this->settings()->get('masthead_editor_ar')), 'then the cache');
    }

    public function test_every_save_drops_the_cache_so_the_new_value_shows_at_once(): void
    {
        $this->settings()->set('masthead_editor_ar', 'الأول');
        $this->assertSame('الأول', $this->settings()->get('masthead_editor_ar'));

        $this->settings()->set('masthead_editor_ar', 'الثاني');

        $this->assertSame(1, $this->settingsQueries(fn () => $this->assertSame('الثاني', $this->settings()->get('masthead_editor_ar'))), 'read again once');
    }

    public function test_a_zero_lifetime_disables_the_cache_like_the_other_site_caches(): void
    {
        config(['site.cache.settings' => 0]);
        $this->settings()->set('masthead_editor_ar', 'ناجي أبو مغنم');

        $this->assertSame(2, $this->settingsQueries(function (): void {
            $this->settings()->get('masthead_editor_ar');
            $this->settings()->get('masthead_editor_ar');
        }));
    }

    public function test_a_change_made_on_the_model_directly_also_drops_the_cache(): void
    {
        $this->settings()->set('masthead_editor_en', 'Before');
        $this->assertSame('Before', $this->settings()->get('masthead_editor_en'));

        SiteSetting::query()->where('key', 'masthead_editor_en')->firstOrFail()->update(['value' => 'After']);
        $this->assertSame('After', $this->settings()->get('masthead_editor_en'));

        SiteSetting::query()->where('key', 'masthead_editor_en')->firstOrFail()->delete();
        $this->assertNull($this->settings()->get('masthead_editor_en'));
    }

    public function test_a_rolled_back_save_never_stays_in_the_cache(): void
    {
        $this->settings()->set('masthead_editor_en', 'Kept');

        try {
            DB::transaction(function (): void {
                $this->settings()->set('masthead_editor_en', 'Rolled back');
                // Read inside the transaction: this caches the uncommitted value.
                $this->assertSame('Rolled back', $this->settings()->get('masthead_editor_en'));

                throw new RuntimeException('abort');
            });
        } catch (RuntimeException) {
        }

        $this->assertSame('Kept', $this->settings()->get('masthead_editor_en'));
    }

    public function test_a_missing_table_means_no_settings_not_a_broken_page(): void
    {
        Exceptions::fake();
        Schema::drop('site_settings');

        $this->assertNull($this->settings()->get('masthead_editor_ar'));
        $this->assertSame('default', $this->settings()->get('masthead_editor_ar', 'default'));
        $this->assertSame([], $this->settings()->all());
        Exceptions::assertReported(QueryException::class);
    }
}
