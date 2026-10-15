<?php

namespace App\Models;

use App\Enums\Locale;
use App\Services\FooterPages;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A static page (About, Privacy...). The slug is part of its public address and is set once at creation:
 * the staff form never writes it afterwards, so links to a page cannot be broken by an edit.
 */
#[Fillable(['slug', 'sort_order', 'show_in_footer'])]
class Page extends Model
{
    use HasFactory;

    /** Pages the trust links and the footer rely on: they can be unpublished but never deleted. */
    public const CORE_SLUGS = ['about', 'standards', 'corrections', 'privacy', 'terms'];

    /** Lowercase kebab-case; the same shape the public route accepts. */
    public const SLUG_PATTERN = '[a-z0-9]+(?:-[a-z0-9]+)*';

    protected static function booted(): void
    {
        // The footer links are cached; a changed shell (order, footer flag) or a deleted page must show at once.
        static::saved(fn () => FooterPages::forget());
        static::deleted(fn () => FooterPages::forget());
    }

    protected function casts(): array
    {
        return ['show_in_footer' => 'boolean'];
    }

    public function translations(): HasMany
    {
        return $this->hasMany(PageTranslation::class);
    }

    /** Reads the loaded relation (eager-load `translations` to avoid N+1). */
    public function translate(Locale $locale): ?PageTranslation
    {
        return $this->translations->firstWhere('locale', $locale);
    }

    public function isCore(): bool
    {
        return in_array($this->slug, self::CORE_SLUGS, true);
    }

    public function url(Locale $locale): string
    {
        return route('page', ['locale' => $locale->value, 'slug' => $this->slug]);
    }
}
