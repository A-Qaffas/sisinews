<?php

namespace Tests\Feature\Seeders;

use App\Models\Article;
use App\Models\ArticleStatusChange;
use App\Models\ArticleTranslation;
use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Database\Seeders\LaunchContentSeeder;
use Database\Seeders\Support\LaunchImportException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Log;
use RuntimeException;

class LaunchContentSeederTest extends LaunchContentTest
{
    private function runSeeder(?string $directory = null): void
    {
        (new LaunchContentSeeder($directory ?? $this->fixtureDirectory()))->setContainer($this->app)->__invoke();
    }

    public function test_it_loads_the_stories_as_the_first_active_admin(): void
    {
        User::factory()->admin()->inactive()->create(['email' => 'retired@sisi.news']);
        User::factory()->editor()->create(['email' => 'editor@sisi.news']);
        $admin = User::factory()->admin()->create(['email' => 'chief@sisi.news']);
        User::factory()->admin()->create(['email' => 'second@sisi.news']);

        $this->runSeeder();

        $this->assertSame(3, Article::count());
        $this->assertSame(6, ArticleTranslation::visible()->count());
        $this->assertSame([$admin->id], ArticleStatusChange::query()->distinct()->pluck('user_id')->all(), 'the first ACTIVE admin published');
        $this->assertFalse(User::where('email', 'desk@sisi.news')->sole()->is_active);
    }

    public function test_it_seeds_the_sections_itself_so_a_bare_local_database_works(): void
    {
        DB::table('category_translations')->delete();
        DB::table('categories')->delete();
        $this->admin();

        $this->runSeeder();

        $this->assertSame(3, Article::count());
    }

    public function test_it_can_run_twice(): void
    {
        $this->admin();

        $this->runSeeder();
        $this->runSeeder();

        $this->assertSame(3, Article::count());
        $this->assertSame(6, ArticleTranslation::count());
    }

    public function test_without_an_active_admin_it_stops_and_says_how_to_get_one(): void
    {
        User::factory()->admin()->inactive()->create();
        User::factory()->editor()->create();

        try {
            $this->runSeeder();
            $this->fail('The seeder ran without an admin.');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('No active admin', $e->getMessage());
            $this->assertStringContainsString('sisi:create-admin', $e->getMessage());
        }

        $this->assertSame(0, Article::count());
        $this->assertFalse(User::where('email', 'desk@sisi.news')->exists());
    }

    public function test_invalid_content_stops_it_and_lists_the_problems(): void
    {
        $this->admin();
        $directory = $this->contentDirectory(function (array $data): array {
            $data['stories'][0]['category'] = 'sports';
            $data['stories'][2]['image'] = 'images/gone.jpg';

            return $data;
        });

        try {
            $this->runSeeder($directory);
            $this->fail('The seeder imported invalid content.');
        } catch (LaunchImportException $e) {
            $this->assertStringContainsString('category "sports"', $e->getMessage());
            $this->assertStringContainsString('"images/gone.jpg" does not exist', $e->getMessage());
        }

        $this->assertSame(0, Article::count());
    }

    public function test_a_story_that_fails_makes_the_seeder_fail_loudly(): void
    {
        $this->admin();
        Log::spy();
        Event::listen('eloquent.creating: '.ArticleTranslation::class, function (ArticleTranslation $translation): void {
            if ($translation->title === 'A Museum After Dark') {
                throw new RuntimeException('disk full (simulated)');
            }
        });

        try {
            $this->runSeeder();
            $this->fail('The seeder reported success although a story failed.');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('1 launch stories failed and were rolled back', $e->getMessage());
        }

        $this->assertSame(2, Article::count(), 'the other stories are in');
        $this->assertFalse(Article::where('hero_image', 'articles/launch/museum-after-dark.jpg')->exists());
    }

    public function test_the_database_seeder_stays_reference_data_only(): void
    {
        $this->seed(DatabaseSeeder::class);

        $this->assertSame(0, Article::count());
        $this->assertSame(0, User::count());
        $this->assertSame(6, DB::table('categories')->count());
    }
}
