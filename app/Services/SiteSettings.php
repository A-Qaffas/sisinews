<?php

namespace App\Services;

use App\Models\SiteSetting;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Key/value settings an admin changes in the panel (Site > Site settings), such as the masthead names.
 *
 * Every setting is read from ONE cached array, so pages cost no query on the settings table. The array is dropped
 * whenever a SiteSetting row is saved or deleted, so a change shows on the next page; its lifetime
 * (config site.cache.settings) is only a safety net for a row changed outside the model.
 */
final class SiteSettings
{
    private const CACHE_KEY = 'site:settings';

    private const MAX_KEY_LENGTH = 64;

    public function get(string $key, ?string $default = null): ?string
    {
        return $this->all()[$key] ?? $default;
    }

    /** Stores $value trimmed; null, '' and whitespace are stored as null ("not set"). */
    public function set(string $key, ?string $value): void
    {
        if ($key === '' || mb_strlen($key) > self::MAX_KEY_LENGTH) {
            throw new InvalidArgumentException('A setting key is 1 to '.self::MAX_KEY_LENGTH.' characters long.');
        }

        $value = $value === null ? null : trim($value);

        SiteSetting::query()->updateOrCreate(['key' => $key], ['value' => $value === '' ? null : $value]);
    }

    /** @return array<string, string|null> */
    public function all(): array
    {
        try {
            return Cache::remember(self::CACHE_KEY, (int) config('site.cache.settings'), fn (): array => SiteSetting::query()->pluck('value', 'key')->all());
        } catch (QueryException $exception) {
            // In the seconds between a deploy's code sync and its migration the table does not exist yet. A setting is
            // never worth a broken page: report it and render without (nothing is cached, so the next page tries again).
            report($exception);

            return [];
        }
    }

    public static function forget(): void
    {
        $drop = fn () => Cache::forget(self::CACHE_KEY);

        $drop();
        // Inside a transaction a read before the commit could cache the old values again (another request) or values
        // that are then rolled back (this one), so drop them once more when it ends. Outside one, afterCommit runs at once.
        DB::afterCommit($drop);
        DB::afterRollBack($drop);
    }
}
