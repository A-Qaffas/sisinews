<?php

/*
 * One-off for the 2026-10-18 update (masthead credits beside the logo and in the footer).
 *
 * Fills in the two names the owner gave, in Arabic only:
 *   رئيس مجلس الإدارة: تغريد رفاعي      (masthead_chairman_ar)
 *   رئيس التحرير: ناجي أبو مغنم          (masthead_editor_ar)
 * The English spellings are not decided yet, so masthead_chairman_en and masthead_editor_en stay empty and English pages
 * show no credits until an admin types them in the panel (Site > Site settings).
 *
 * Run it AFTER deploying the update: it needs the site_settings table (the deploy's migrate creates it) and
 * App\Services\SiteSettings. It writes to the production database, so run it only with the owner's go-ahead.
 * From the project folder in Git Bash:
 *
 *   ssh wxr46gn9f7ld@92.205.88.46 'cd ~/sisi.news && DRY_RUN=1 php' < deploy/one-off/2026-10-18-masthead.php   # report only
 *   ssh wxr46gn9f7ld@92.205.88.46 'cd ~/sisi.news && php' < deploy/one-off/2026-10-18-masthead.php             # write
 *
 * Safe to run again: a name is written only while that setting is empty, so a name an admin has since typed in the
 * panel is never overwritten, and a second run reports "already set". It saves through SiteSettings, so the cached
 * settings are dropped and the names show on the next page.
 * Exit code 1 when something has to be checked by hand (the table is missing); nothing is written then.
 *
 * tests/Feature/Deploy/MastheadOneOffTest.php loads this file with SISI_ONE_OFF_LIBRARY_ONLY defined, so only the
 * functions below are declared and nothing runs.
 */

use App\Models\SiteSetting;
use App\Services\SiteSettings;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

if (! function_exists('sisi_masthead_names')) {
    /**
     * The names to fill in, by setting key. English keys are listed with null: reported, never written.
     *
     * @return array<string, string|null>
     */
    function sisi_masthead_names(): array
    {
        return [
            'masthead_chairman_ar' => 'تغريد رفاعي',
            'masthead_editor_ar' => 'ناجي أبو مغنم',
            'masthead_chairman_en' => null,
            'masthead_editor_en' => null,
        ];
    }

    /**
     * Fills every empty Arabic name. With $write false nothing is saved.
     *
     * @return array{0: list<string>, 1: bool} the report lines, and whether everything could be done
     */
    function sisi_masthead_run(bool $write): array
    {
        if (! Schema::hasTable('site_settings')) {
            return [['CHECK BY HAND: the site_settings table does not exist. Deploy the 2026-10-18 update first (its migrate creates the table). Nothing was changed.'], false];
        }

        $settings = app(SiteSettings::class);
        $lines = [];

        DB::transaction(function () use ($settings, $write, &$lines): void {
            foreach (sisi_masthead_names() as $key => $name) {
                // The table decides, not the cache: an admin's edit must never be mistaken for an empty setting.
                $current = trim((string) SiteSetting::query()->where('key', $key)->value('value'));

                if ($current !== '') {
                    $lines[] = "{$key}: already set to \"{$current}\", left as it is.";
                } elseif ($name === null) {
                    $lines[] = "{$key}: empty, left empty (the English spelling is not decided; an admin fills it in the panel).";
                } elseif ($write) {
                    $settings->set($key, $name);
                    $lines[] = "{$key}: set to \"{$name}\". Saved.";
                } else {
                    $lines[] = "{$key}: would be set to \"{$name}\" (dry run, nothing written).";
                }
            }
        });

        return [$lines, true];
    }
}

if (defined('SISI_ONE_OFF_LIBRARY_ONLY')) {
    return;
}

if (! is_file('vendor/autoload.php') || ! is_file('bootstrap/app.php')) {
    fwrite(STDERR, "Run this inside the application folder: ssh <host> 'cd ~/sisi.news && php' < deploy/one-off/2026-10-18-masthead.php\n");
    exit(1);
}

require 'vendor/autoload.php';
$app = require 'bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

$write = in_array(getenv('DRY_RUN'), [false, '', '0'], true);
echo $write ? "Filling in the masthead names (write mode).\n" : "DRY RUN: reporting only, nothing is written.\n";

[$lines, $complete] = sisi_masthead_run($write);
foreach ($lines as $line) {
    echo $line, "\n";
}

exit($complete ? 0 : 1);
