<?php

namespace Tests\Feature\Deploy;

use App\Models\SiteSetting;
use App\Services\SiteSettings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * deploy/one-off/2026-10-18-masthead.php fills the owner's two names, in Arabic and English, into the live site's
 * settings, only while they are empty: a name an admin types in the panel later must survive any rerun.
 */
class MastheadOneOffTest extends TestCase
{
    use RefreshDatabase;

    public static function setUpBeforeClass(): void
    {
        parent::setUpBeforeClass();

        if (! defined('SISI_ONE_OFF_LIBRARY_ONLY')) {
            define('SISI_ONE_OFF_LIBRARY_ONLY', true);
        }
        require_once dirname(__DIR__, 3).'/deploy/one-off/2026-10-18-masthead.php';
    }

    private function settings(): SiteSettings
    {
        return app(SiteSettings::class);
    }

    /** @return array<string, string|null> */
    private function stored(): array
    {
        return SiteSetting::query()->orderBy('key')->pluck('value', 'key')->all();
    }

    public function test_it_fills_both_names_in_both_languages(): void
    {
        [$lines, $complete] = sisi_masthead_run(write: true);

        $this->assertTrue($complete);
        $this->assertSame([
            'masthead_chairman_ar' => 'تغريد رفاعي',
            'masthead_chairman_en' => 'Taghreed Refaay',
            'masthead_editor_ar' => 'ناجي أبو مغنم',
            'masthead_editor_en' => 'Nagy Abu Moghanem',
        ], $this->stored());
        $this->assertSame([
            'masthead_chairman_ar: set to "تغريد رفاعي". Saved.',
            'masthead_editor_ar: set to "ناجي أبو مغنم". Saved.',
            'masthead_chairman_en: set to "Taghreed Refaay". Saved.',
            'masthead_editor_en: set to "Nagy Abu Moghanem". Saved.',
        ], $lines);
    }

    /** The live site got the Arabic names from the first version of this script; the rerun adds only the English ones. */
    public function test_a_rerun_after_the_arabic_only_version_fills_just_the_english_names(): void
    {
        $this->settings()->set('masthead_chairman_ar', 'تغريد رفاعي');
        $this->settings()->set('masthead_editor_ar', 'ناجي أبو مغنم');
        $arabic = fn () => SiteSetting::query()->whereIn('key', ['masthead_chairman_ar', 'masthead_editor_ar'])->orderBy('key')->get(['key', 'value', 'updated_at'])->toArray();
        $arabicBefore = $arabic();
        $this->assertCount(2, $arabicBefore);

        [$lines, $complete] = sisi_masthead_run(write: true);

        $this->assertTrue($complete);
        $this->assertSame($arabicBefore, $arabic(), 'the Arabic rows are not touched, not even their timestamps');
        $this->assertSame([
            'masthead_chairman_ar: already set to "تغريد رفاعي", left as it is.',
            'masthead_editor_ar: already set to "ناجي أبو مغنم", left as it is.',
            'masthead_chairman_en: set to "Taghreed Refaay". Saved.',
            'masthead_editor_en: set to "Nagy Abu Moghanem". Saved.',
        ], $lines);
    }

    public function test_the_names_show_on_the_next_page_because_the_cache_is_dropped(): void
    {
        $this->assertNull($this->settings()->get('masthead_editor_ar'), 'warms the cache with "nothing set"');

        sisi_masthead_run(write: true);

        $this->assertSame('ناجي أبو مغنم', $this->settings()->get('masthead_editor_ar'));
        $this->get('/ar')->assertOk()->assertSee('رئيس التحرير:')->assertSee('<bdi lang="ar">ناجي أبو مغنم</bdi>', false);
        $this->get('/en')->assertOk()->assertSee('Editor-in-Chief:')->assertSee('<bdi lang="en">Nagy Abu Moghanem</bdi>', false);
    }

    public function test_a_dry_run_writes_nothing(): void
    {
        [$lines, $complete] = sisi_masthead_run(write: false);

        $this->assertTrue($complete);
        $this->assertSame([], $this->stored());
        $this->assertSame('masthead_chairman_ar: would be set to "تغريد رفاعي" (dry run, nothing written).', $lines[0]);
        $this->assertSame('masthead_editor_ar: would be set to "ناجي أبو مغنم" (dry run, nothing written).', $lines[1]);
    }

    public function test_it_never_overwrites_a_name_an_admin_has_set(): void
    {
        $this->settings()->set('masthead_editor_ar', 'اسم عدّله المسؤول');
        $this->settings()->set('masthead_chairman_en', 'Spelling chosen by an admin');

        [$lines] = sisi_masthead_run(write: true);

        $this->assertSame([
            'masthead_chairman_ar' => 'تغريد رفاعي',
            'masthead_chairman_en' => 'Spelling chosen by an admin',
            'masthead_editor_ar' => 'اسم عدّله المسؤول',
            'masthead_editor_en' => 'Nagy Abu Moghanem',
        ], $this->stored());
        $this->assertContains('masthead_editor_ar: already set to "اسم عدّله المسؤول", left as it is.', $lines);
        $this->assertContains('masthead_chairman_en: already set to "Spelling chosen by an admin", left as it is.', $lines);
    }

    public function test_a_setting_an_admin_emptied_counts_as_empty(): void
    {
        $this->settings()->set('masthead_chairman_ar', '');

        sisi_masthead_run(write: true);

        $this->assertSame('تغريد رفاعي', $this->settings()->get('masthead_chairman_ar'));
    }

    public function test_a_second_run_changes_nothing(): void
    {
        sisi_masthead_run(write: true);
        $before = SiteSetting::query()->orderBy('key')->get(['key', 'value', 'updated_at'])->toArray();

        [$lines, $complete] = sisi_masthead_run(write: true);

        $this->assertTrue($complete);
        $this->assertSame($before, SiteSetting::query()->orderBy('key')->get(['key', 'value', 'updated_at'])->toArray());
        $this->assertSame('masthead_chairman_ar: already set to "تغريد رفاعي", left as it is.', $lines[0]);
        $this->assertSame('masthead_editor_ar: already set to "ناجي أبو مغنم", left as it is.', $lines[1]);
    }

    public function test_it_reports_and_stops_when_the_update_is_not_deployed_yet(): void
    {
        Schema::drop('site_settings');

        [$lines, $complete] = sisi_masthead_run(write: true);

        $this->assertFalse($complete);
        $this->assertCount(1, $lines);
        $this->assertStringStartsWith('CHECK BY HAND: the site_settings table does not exist.', $lines[0]);
    }

    public function test_the_script_boots_the_app_the_way_the_other_one_offs_do_and_honours_dry_run(): void
    {
        $source = (string) file_get_contents(base_path('deploy/one-off/2026-10-18-masthead.php'));

        $this->assertStringContainsString("require 'vendor/autoload.php';\n\$app = require 'bootstrap/app.php';\n\$app->make(Kernel::class)->bootstrap();", $source);
        $this->assertStringContainsString("\$write = in_array(getenv('DRY_RUN'), [false, '', '0'], true);", $source);
        $this->assertStringContainsString('$settings->set($key, $name);', $source, 'writes through SiteSettings');
    }
}
