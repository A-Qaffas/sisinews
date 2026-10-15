<?php

namespace Tests\Feature\Seeders;

use App\Models\User;
use Carbon\Carbon;
use Closure;
use Database\Seeders\CategorySeeder;
use Database\Seeders\Support\LaunchContentImporter;
use Database\Seeders\Support\LaunchContentPackage;
use Database\Seeders\Support\LaunchImportResult;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Shared set-up for the launch-content tests (abstract, so PHPUnit only collects its subclasses).
 *
 * The public disk gets a folder of its own because other suites run at the same time in this
 * working tree, and the clock is frozen so the "published N hours ago" dates can be asserted exactly.
 */
abstract class LaunchContentTest extends TestCase
{
    use RefreshDatabase;

    protected const NOW = '2026-10-12 12:00:00';

    private string $pictureRoot;

    protected function setUp(): void
    {
        parent::setUp();

        $this->pictureRoot = storage_path('framework/testing/disks/launch-content-'.Str::lower(Str::random(10)));
        config(['filesystems.disks.public.root' => $this->pictureRoot]);
        Storage::forgetDisk('public');

        $this->travelTo(Carbon::parse(self::NOW));
        $this->seed(CategorySeeder::class);
    }

    protected function tearDown(): void
    {
        (new Filesystem)->deleteDirectory($this->pictureRoot);
        Storage::forgetDisk('public');

        parent::tearDown();
    }

    protected function fixtureDirectory(): string
    {
        return base_path('tests/fixtures/launch-content');
    }

    /**
     * A throw-away copy of the fixture, optionally with stories.json edited first.
     *
     * @param  (Closure(array<string, mixed>): array<string, mixed>)|null  $edit
     */
    protected function contentDirectory(?Closure $edit = null): string
    {
        $directory = sys_get_temp_dir().'/sisi-launch-content-'.bin2hex(random_bytes(6));
        $files = new Filesystem;
        $files->copyDirectory($this->fixtureDirectory(), $directory);
        $this->beforeApplicationDestroyed(fn () => $files->deleteDirectory($directory));

        if ($edit !== null) {
            $data = json_decode((string) file_get_contents("{$directory}/stories.json"), true, 512, JSON_THROW_ON_ERROR);
            file_put_contents(
                "{$directory}/stories.json",
                json_encode($edit($data), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT),
            );
        }

        return $directory;
    }

    protected function admin(): User
    {
        return User::factory()->admin()->create(['email' => 'chief@sisi.news']);
    }

    protected function importer(): LaunchContentImporter
    {
        return app(LaunchContentImporter::class);
    }

    protected function importFixture(?User $admin = null, ?string $directory = null): LaunchImportResult
    {
        return $this->importer()->import(
            LaunchContentPackage::load($directory ?? $this->fixtureDirectory()),
            $admin ?? $this->admin(),
        );
    }
}
