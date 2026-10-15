<?php

namespace Database\Seeders;

use App\Enums\Role;
use App\Models\User;
use Database\Seeders\Support\LaunchContentImporter;
use Database\Seeders\Support\LaunchContentPackage;
use Illuminate\Database\Seeder;
use RuntimeException;

/**
 * Loads the launch stories into a LOCAL database, publishing them as the first active admin:
 *
 *   php artisan db:seed --class=LaunchContentSeeder
 *
 * It is the same importer the production command uses (php artisan sisi:seed-launch-content),
 * so it is idempotent too. It is deliberately NOT part of DatabaseSeeder: `db:seed` on a
 * server must stay reference data only.
 */
class LaunchContentSeeder extends Seeder
{
    /** @param  string|null  $path  content directory; defaults to database/launch-content */
    public function __construct(private readonly ?string $path = null) {}

    public function run(LaunchContentImporter $importer): void
    {
        // The stories are filed under the sections, which are reference data and idempotent to seed.
        $this->call(CategorySeeder::class);

        $admin = User::query()->where('role', Role::Admin->value)->where('is_active', true)->orderBy('id')->first();
        if ($admin === null) {
            throw new RuntimeException('No active admin to publish as. Create one with `php artisan sisi:create-admin` (or run DemoContentSeeder) first.');
        }

        $package = LaunchContentPackage::load($this->path ?? database_path('launch-content'));
        foreach ($package->warnings() as $warning) {
            $this->command?->warn($warning);
        }

        $result = $importer->import($package, $admin, function (string $level, string $message): void {
            match ($level) {
                'warn' => $this->command?->warn($message),
                'error' => $this->command?->error($message),
                default => $this->command?->line($message),
            };
        });

        if (! $result->successful()) {
            throw new RuntimeException(count($result->failed).' launch stories failed and were rolled back; see above. Run the seeder again after fixing the cause.');
        }
    }
}
