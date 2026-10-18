<?php

namespace App\Models;

use App\Services\SiteSettings;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

/**
 * One key/value pair of the site settings. Read and write them through App\Services\SiteSettings, which caches them
 * all as one array; saving or deleting a row here drops that cache, so a change shows on the next page.
 * Values are plain text and are always escaped on output.
 */
#[Fillable(['key', 'value'])]
class SiteSetting extends Model
{
    protected static function booted(): void
    {
        static::saved(fn () => SiteSettings::forget());
        static::deleted(fn () => SiteSettings::forget());
    }
}
