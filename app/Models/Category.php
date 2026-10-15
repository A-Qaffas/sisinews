<?php

namespace App\Models;

use App\Enums\Locale;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['sort_order', 'show_in_nav'])]
class Category extends Model
{
    use HasFactory;

    protected function casts(): array
    {
        return ['show_in_nav' => 'boolean'];
    }

    public function translations(): HasMany
    {
        return $this->hasMany(CategoryTranslation::class);
    }

    public function articles(): HasMany
    {
        return $this->hasMany(Article::class);
    }

    /** Reads the loaded relation (eager-load `translations` to avoid N+1). */
    public function translate(Locale $locale): ?CategoryTranslation
    {
        return $this->translations->firstWhere('locale', $locale);
    }

    /** Name in $locale, falling back to the other language so navigation never renders blank. */
    public function nameIn(Locale $locale): string
    {
        return ($this->translate($locale) ?? $this->translate($locale->other()))?->name ?? '';
    }
}
