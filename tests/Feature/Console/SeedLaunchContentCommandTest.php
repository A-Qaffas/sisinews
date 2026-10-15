<?php

namespace Tests\Feature\Console;

use App\Console\Commands\SeedLaunchContent;
use App\Models\Article;
use App\Models\ArticleStatusChange;
use App\Models\ArticleTranslation;
use App\Models\Tag;
use App\Models\User;
use Database\Seeders\Support\LaunchContentImporter;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\Feature\Seeders\LaunchContentTest;

class SeedLaunchContentCommandTest extends LaunchContentTest
{
    /** @return array<string, int> */
    private function written(): array
    {
        $counts = [];
        foreach (['users', 'tags', 'articles', 'article_translations', 'article_tag', 'article_status_changes'] as $table) {
            $counts[$table] = DB::table($table)->count();
        }
        $counts['files'] = count(Storage::disk('public')->allFiles());

        return $counts;
    }

    public function test_it_imports_and_publishes_the_fixture_as_the_given_admin(): void
    {
        $admin = $this->admin();

        $this->artisan('sisi:seed-launch-content', ['--admin' => 'chief@sisi.news', '--path' => $this->fixtureDirectory()])
            ->expectsOutputToContain('Created the inactive staff account desk@sisi.news')
            ->expectsOutputToContain('Imported nile-corniche-evening')
            ->expectsOutputToContain('Imported gulf-trade-talks')
            ->expectsOutputToContain('Imported museum-after-dark')
            ->expectsOutputToContain('Done. The stories are live')
            ->assertSuccessful();

        $this->assertSame(3, Article::count());
        $this->assertSame(6, ArticleTranslation::visible()->count());
        $this->assertSame(4, Tag::count());
        $this->assertSame([$admin->id], ArticleStatusChange::query()->distinct()->pluck('user_id')->all());
        $this->assertCount(3 + 9, Storage::disk('public')->allFiles());
    }

    public function test_the_admin_email_is_matched_ignoring_case_and_spaces(): void
    {
        $this->admin();

        $this->artisan('sisi:seed-launch-content', ['--admin' => '  Chief@SISI.news ', '--path' => $this->fixtureDirectory()])->assertSuccessful();

        $this->assertSame(3, Article::count());
    }

    public function test_it_never_prints_a_secret(): void
    {
        $this->admin();

        $this->artisan('sisi:seed-launch-content', ['--admin' => 'chief@sisi.news', '--path' => $this->fixtureDirectory()])
            ->doesntExpectOutputToContain('$2y$')
            ->doesntExpectOutputToContain('password')
            ->assertSuccessful();

        $this->artisan('sisi:seed-launch-content', ['--admin' => 'chief@sisi.news', '--path' => $this->fixtureDirectory(), '--dry-run' => true])
            ->doesntExpectOutputToContain('$2y$')
            ->doesntExpectOutputToContain('password')
            ->assertSuccessful();
    }

    public function test_a_dry_run_prints_the_plan_and_writes_nothing(): void
    {
        $this->admin();
        $before = $this->written();

        $this->artisan('sisi:seed-launch-content', ['--admin' => 'chief@sisi.news', '--path' => $this->fixtureDirectory(), '--dry-run' => true])
            ->expectsOutputToContain('would create the inactive staff account desk@sisi.news')
            ->expectsOutputToContain('Would import nile-corniche-evening: egypt, featured, 2 tags')
            ->expectsOutputToContain('Would import gulf-trade-talks: business, 2 tags')
            ->expectsOutputToContain('Would import museum-after-dark: culture, 1 tag,')
            ->expectsOutputToContain('Dry run: nothing was written.')
            ->assertSuccessful();

        $this->assertSame($before, $this->written());
        $this->assertFalse(User::where('email', 'desk@sisi.news')->exists());
        $this->assertSame([], Storage::disk('public')->allFiles());
    }

    public function test_a_dry_run_still_needs_a_valid_admin_and_valid_content(): void
    {
        $this->artisan('sisi:seed-launch-content', ['--admin' => 'ghost@sisi.news', '--path' => $this->fixtureDirectory(), '--dry-run' => true])
            ->expectsOutputToContain('There is no account for ghost@sisi.news')
            ->assertFailed();

        $this->admin();
        $this->artisan('sisi:seed-launch-content', ['--admin' => 'chief@sisi.news', '--path' => sys_get_temp_dir().'/sisi-no-such-content', '--dry-run' => true])
            ->expectsOutputToContain('does not exist')
            ->assertFailed();
    }

    public function test_running_it_again_skips_everything_and_succeeds(): void
    {
        $this->admin();
        $this->artisan('sisi:seed-launch-content', ['--admin' => 'chief@sisi.news', '--path' => $this->fixtureDirectory()])->assertSuccessful();
        $after = $this->written();

        $this->artisan('sisi:seed-launch-content', ['--admin' => 'chief@sisi.news', '--path' => $this->fixtureDirectory()])
            ->expectsOutputToContain('Skipping nile-corniche-evening: already imported.')
            ->expectsOutputToContain('Skipping gulf-trade-talks: already imported.')
            ->expectsOutputToContain('Skipping museum-after-dark: already imported.')
            ->expectsOutputToContain('Nothing to do: every story is already imported.')
            ->assertSuccessful();

        $this->assertSame($after, $this->written());
    }

    public function test_it_needs_to_be_told_which_admin_publishes(): void
    {
        $this->admin();

        $this->artisan('sisi:seed-launch-content', ['--path' => $this->fixtureDirectory()])
            ->expectsOutputToContain('Say who publishes the stories with --admin=')
            ->assertFailed();

        $this->assertSame(0, Article::count());
    }

    /** @return array<string, array{string, string}> */
    public static function unfitAdmins(): array
    {
        return [
            'an email nobody has' => ['missing', 'There is no account for'],
            'an editor' => ['editor', 'is a editor, not an admin'],
            'a journalist' => ['journalist', 'is a journalist, not an admin'],
            'a deactivated admin' => ['inactive', 'is deactivated'],
        ];
    }

    #[DataProvider('unfitAdmins')]
    public function test_it_refuses_a_publisher_who_is_not_an_active_admin_and_writes_nothing(string $kind, string $message): void
    {
        match ($kind) {
            'missing' => null,
            'inactive' => User::factory()->admin()->inactive()->create(['email' => 'someone@sisi.news']),
            default => User::factory()->{$kind}()->create(['email' => 'someone@sisi.news']),
        };
        $before = $this->written();

        $this->artisan('sisi:seed-launch-content', ['--admin' => 'someone@sisi.news', '--path' => $this->fixtureDirectory()])
            ->expectsOutputToContain($message)
            ->expectsOutputToContain('Nothing was written')
            ->assertFailed();

        $this->assertSame($before, $this->written());
        $this->assertFalse(User::where('email', 'desk@sisi.news')->exists());
    }

    public function test_every_problem_in_the_content_and_the_admin_is_reported_together(): void
    {
        $directory = $this->contentDirectory(function (array $data): array {
            $data['stories'][0]['category'] = 'sports';
            $data['stories'][1]['en']['title'] = '';
            $data['stories'][2]['image'] = 'images/gone.jpg';

            return $data;
        });

        $this->artisan('sisi:seed-launch-content', ['--admin' => 'ghost@sisi.news', '--path' => $directory])
            ->expectsOutputToContain('4 problems found. Nothing was written.')
            ->expectsOutputToContain('There is no account for ghost@sisi.news')
            ->expectsOutputToContain('story #1 (nile-corniche-evening): category "sports"')
            ->expectsOutputToContain('story #2 (gulf-trade-talks), en: title is required')
            ->expectsOutputToContain('story #3 (museum-after-dark): image file "images/gone.jpg" does not exist')
            ->assertFailed();
    }

    public function test_a_story_with_a_missing_image_fails_validation_before_any_write(): void
    {
        $this->admin();
        $directory = $this->contentDirectory();
        unlink("{$directory}/images/museum.jpg");
        $before = $this->written();

        $this->artisan('sisi:seed-launch-content', ['--admin' => 'chief@sisi.news', '--path' => $directory])
            ->expectsOutputToContain('1 problem found. Nothing was written.')
            ->expectsOutputToContain('image file "images/museum.jpg" does not exist')
            ->doesntExpectOutputToContain('Imported')
            ->assertFailed();

        $this->assertSame($before, $this->written(), 'the two stories with pictures were not imported either');
        $this->assertSame(0, Article::count());
        $this->assertSame([], Storage::disk('public')->allFiles());
        $this->assertFalse(User::where('email', 'desk@sisi.news')->exists());
    }

    public function test_warnings_are_shown_but_do_not_block(): void
    {
        $this->admin();
        $directory = $this->contentDirectory();
        unlink("{$directory}/images/credits.json");

        $this->artisan('sisi:seed-launch-content', ['--admin' => 'chief@sisi.news', '--path' => $directory])
            ->expectsOutputToContain('images/credits.json is missing')
            ->assertSuccessful();

        $this->assertSame(3, Article::count());
    }

    public function test_a_relative_path_is_taken_from_the_application_root(): void
    {
        $this->admin();

        $this->artisan('sisi:seed-launch-content', ['--admin' => 'chief@sisi.news', '--path' => 'tests/fixtures/launch-content', '--dry-run' => true])
            ->expectsOutputToContain('Would import nile-corniche-evening')
            ->assertSuccessful();
    }

    public function test_the_default_content_directory_is_database_launch_content(): void
    {
        $this->assertSame('database/launch-content', SeedLaunchContent::DEFAULT_PATH);
        $this->admin();
        $before = $this->written();

        // Whatever is in that directory right now, a dry run must not write a thing.
        $this->artisan('sisi:seed-launch-content', ['--admin' => 'chief@sisi.news', '--dry-run' => true])
            ->expectsOutputToContain('Content directory: '.base_path('database/launch-content'))
            ->run();

        $this->assertSame($before, $this->written());
    }

    public function test_stories_that_fail_are_rolled_back_the_exit_code_says_so_and_a_rerun_finishes_the_job(): void
    {
        $this->admin();
        Log::spy();
        $event = 'eloquent.creating: '.ArticleTranslation::class;
        Event::listen($event, function (ArticleTranslation $translation): void {
            if ($translation->title === 'Gulf and Egyptian Firms Open Trade Talks') {
                throw new RuntimeException('database went away (simulated)');
            }
        });

        $this->artisan('sisi:seed-launch-content', ['--admin' => 'chief@sisi.news', '--path' => $this->fixtureDirectory()])
            ->expectsOutputToContain('Failed gulf-trade-talks, rolled back: database went away (simulated)')
            ->expectsOutputToContain('Some stories failed and were rolled back')
            ->assertFailed();

        $this->assertSame(2, Article::count());
        Storage::disk('public')->assertMissing('articles/launch/gulf-trade-talks.webp');

        Event::forget($event);
        $this->artisan('sisi:seed-launch-content', ['--admin' => 'chief@sisi.news', '--path' => $this->fixtureDirectory()])
            ->expectsOutputToContain('Imported gulf-trade-talks')
            ->expectsOutputToContain('Skipping nile-corniche-evening: already imported.')
            ->assertSuccessful();

        $this->assertSame(3, Article::count());
        $this->assertSame(6, ArticleTranslation::visible()->count());
    }

    public function test_it_will_not_start_while_another_import_is_running(): void
    {
        $this->admin();
        $held = Cache::lock(LaunchContentImporter::LOCK, 60);
        $this->assertTrue($held->get());

        try {
            $this->artisan('sisi:seed-launch-content', ['--admin' => 'chief@sisi.news', '--path' => $this->fixtureDirectory()])
                ->expectsOutputToContain('Another launch-content import is running')
                ->assertFailed();
        } finally {
            $held->release();
        }

        $this->assertSame(0, Article::count());
    }
}
