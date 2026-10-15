<?php

namespace App\Models;

use App\Enums\Locale;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Tag extends Model
{
    public function translations(): HasMany
    {
        return $this->hasMany(TagTranslation::class);
    }

    public function articles(): BelongsToMany
    {
        return $this->belongsToMany(Article::class);
    }

    public function nameIn(Locale $locale): string
    {
        $t = $this->translations->firstWhere('locale', $locale)
            ?? $this->translations->firstWhere('locale', $locale->other());

        return $t?->name ?? '';
    }
}
