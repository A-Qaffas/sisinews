<?php

/*
 * One-off for 2026-11-05: the owner asked for story 86 (Taghreed Refaay's column «حدود القوة وبداية المحاسبة بعد
 * انتخابات الكونجرس») to show 07:00 Cairo time as its publication time, the time it was written. It went live later
 * that morning.
 *
 * The editorial workflow refuses to back-date on purpose (EditorialWorkflow::approve treats a past time as "now"),
 * so no panel flow can do this. It is a direct, recorded data fix: this file is the record. It changes
 * `published_at` only. `status` is untouched, and so is `updated_at`, so the story's modified time (article:modified_time,
 * JSON-LD dateModified) still says when it really went out.
 *
 *   ssh wxr46gn9f7ld@92.205.88.46 'cd ~/sisi.news && DRY_RUN=1 php' < deploy/one-off/2026-11-05-story-86-published-at.php   # report only
 *   ssh wxr46gn9f7ld@92.205.88.46 'cd ~/sisi.news && php' < deploy/one-off/2026-11-05-story-86-published-at.php             # write
 *
 * Guards: only a PUBLISHED language version of article 86, only to an EARLIER time on the same Cairo day, never into
 * the future. It prints the old value of every row it changes, so it can be put back by hand.
 */

use Carbon\CarbonImmutable;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;

const SISI_ARTICLE_ID = 86;
const SISI_NEW_TIME_CAIRO = '2026-11-05 07:00:00';

if (! is_file('vendor/autoload.php') || ! is_file('bootstrap/app.php')) {
    fwrite(STDERR, "Run this inside the application folder: ssh <host> 'cd ~/sisi.news && php' < deploy/one-off/2026-11-05-story-86-published-at.php\n");
    exit(1);
}

require 'vendor/autoload.php';
$app = require 'bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

$write = in_array(getenv('DRY_RUN'), [false, '', '0'], true);
echo $write ? "Setting the publication time (write mode).\n" : "DRY RUN: reporting only, nothing is written.\n";

$cairo = 'Africa/Cairo';
$target = CarbonImmutable::parse(SISI_NEW_TIME_CAIRO, $cairo);
$targetUtc = $target->setTimezone(config('app.timezone'));
$now = CarbonImmutable::now();

$rows = DB::table('article_translations')->where('article_id', SISI_ARTICLE_ID)->orderBy('locale')->get();
if ($rows->isEmpty()) {
    echo "No language versions found for article ".SISI_ARTICLE_ID.". Nothing done.\n";
    exit(1);
}

$problem = false;
foreach ($rows as $row) {
    $current = $row->published_at ? CarbonImmutable::parse($row->published_at, config('app.timezone')) : null;
    $label = "article ".SISI_ARTICLE_ID." [{$row->locale}] «".mb_substr((string) $row->title, 0, 70)."»";
    echo "{$label}\n  status {$row->status}, published_at ".($current ? $current->setTimezone($cairo)->format('Y-m-d H:i:s').' Cairo ('.$current->toDateTimeString().' UTC)' : 'NULL')."\n";

    if ($row->status !== 'published' || $current === null) {
        echo "  skipped: not published\n";
        continue;
    }
    if ($target->greaterThan($now)) {
        echo "  REFUSED: the new time is in the future\n";
        $problem = true;
        continue;
    }
    if ($current->setTimezone($cairo)->toDateString() !== $target->toDateString() || ! $target->lessThan($current)) {
        echo "  REFUSED: only an earlier time on the same Cairo day is allowed\n";
        $problem = true;
        continue;
    }

    echo "  -> new published_at ".$target->format('Y-m-d H:i:s')." Cairo (".$targetUtc->toDateTimeString()." UTC)\n";
    if ($write) {
        // Query builder, not the model: the model would also bump updated_at, which must keep the real go-live time.
        DB::table('article_translations')->where('id', $row->id)->update(['published_at' => $targetUtc->toDateTimeString()]);
        echo "  written. To undo: published_at = '".$current->toDateTimeString()."' (UTC) on article_translations.id {$row->id}\n";
    }
}

exit($problem ? 1 : 0);
