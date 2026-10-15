<?php

namespace App\Console\Commands;

use App\Models\User;
use Database\Seeders\Support\LaunchContentImporter;
use Database\Seeders\Support\LaunchContentPackage;
use Database\Seeders\Support\LaunchImportException;
use Database\Seeders\Support\LaunchImportResult;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Str;

/**
 * Loads the launch stories (real pictures with their credits, tags, Arabic and English text)
 * and publishes them as an admin. Safe in production and safe to run twice; see
 * LaunchContentImporter. Validates everything first and reports all problems at once, so a
 * bad content file never leaves half a launch behind.
 */
#[Signature('sisi:seed-launch-content
    {--admin= : Email of the active admin who publishes the stories}
    {--path= : Content directory, default database/launch-content}
    {--dry-run : Validate and show what would happen, writing nothing}')]
#[Description('Import and publish the launch stories (pictures, tags, Arabic and English text) as an admin')]
class SeedLaunchContent extends Command
{
    public const DEFAULT_PATH = 'database/launch-content';

    public function handle(LaunchContentImporter $importer): int
    {
        $email = Str::lower(trim((string) $this->option('admin')));
        $directory = $this->contentDirectory();
        $dryRun = (bool) $this->option('dry-run');

        $this->line('Content directory: '.$directory);

        $admin = $email === '' ? null : User::query()->where('email', $email)->first();
        $adminProblem = $email === ''
            ? 'Say who publishes the stories with --admin=<email of an active admin>.'
            : LaunchContentImporter::publisherProblem($admin, $email);

        $package = LaunchContentPackage::load($directory);
        $problems = [...($adminProblem === null ? [] : [$adminProblem]), ...$package->problems()];

        foreach ($package->warnings() as $warning) {
            $this->components->warn($warning);
        }
        if ($problems !== []) {
            $this->components->error(count($problems).' '.Str::plural('problem', count($problems)).' found. Nothing was written.');
            foreach ($problems as $problem) {
                $this->line('  - '.$problem);
            }

            return self::FAILURE;
        }

        try {
            $result = $dryRun
                ? $importer->dryRun($package, $this->report(...))
                : $importer->import($package, $admin ?? throw new LaunchImportException('No admin to publish as.'), $this->report(...));
        } catch (LaunchImportException $e) {
            $this->components->error($e->getMessage());

            return self::FAILURE;
        }

        $this->summarise($result, $dryRun);

        return $result->successful() ? self::SUCCESS : self::FAILURE;
    }

    private function contentDirectory(): string
    {
        $path = trim((string) $this->option('path'));
        if ($path === '') {
            $path = self::DEFAULT_PATH;
        }

        // Relative paths are taken from the application root, wherever the command is run from.
        return preg_match('#^([A-Za-z]:)?[\\\\/]#', $path) === 1 ? $path : base_path($path);
    }

    private function report(string $level, string $message): void
    {
        match ($level) {
            'warn' => $this->components->warn($message),
            'error' => $this->components->error($message),
            'info' => $this->components->info($message),
            default => $this->line('  '.$message),
        };
    }

    private function summarise(LaunchImportResult $result, bool $dryRun): void
    {
        $this->newLine();
        if ($dryRun) {
            $this->components->info('Dry run: nothing was written.');
        }

        $this->components->twoColumnDetail($dryRun ? 'Would import' : 'Imported', (string) count($result->imported));
        $this->components->twoColumnDetail($dryRun ? 'Would skip (already imported)' : 'Skipped (already imported)', (string) count($result->skipped));
        if (! $dryRun) {
            $this->components->twoColumnDetail('New tags', (string) $result->createdTags);
            $this->components->twoColumnDetail('Failed', (string) count($result->failed));
        }

        if ($result->failed !== []) {
            $this->components->error('Some stories failed and were rolled back. Fix the cause and run the command again: finished stories are skipped.');
        } elseif (! $dryRun && $result->imported !== []) {
            $this->components->info('Done. The stories are live on '.url('/').'.');
        } elseif (! $dryRun) {
            $this->components->info('Nothing to do: every story is already imported.');
        }
    }
}
