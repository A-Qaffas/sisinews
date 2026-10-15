<?php

namespace App\Models;

use App\Enums\Locale;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['category_id', 'locale', 'name', 'slug'])]
class CategoryTranslation extends Model
{
    public $timestamps = false;

    protected function casts(): array
    {
        return ['locale' => Locale::class];
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(Category::class);
    }
}
