<?php

namespace App\Models;

use App\Enums\Locale;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['tag_id', 'locale', 'name', 'slug'])]
class TagTranslation extends Model
{
    public $timestamps = false;

    protected function casts(): array
    {
        return ['locale' => Locale::class];
    }

    public function tag(): BelongsTo
    {
        return $this->belongsTo(Tag::class);
    }
}
