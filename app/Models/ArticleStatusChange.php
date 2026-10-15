<?php

namespace App\Models;

use App\Editorial\Transition;
use App\Enums\TranslationStatus;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

// Append-only audit row written by EditorialWorkflow; never edited by hand.
#[Fillable(['article_translation_id', 'user_id', 'transition', 'from_status', 'to_status', 'note'])]
class ArticleStatusChange extends Model
{
    public const UPDATED_AT = null;

    protected function casts(): array
    {
        return [
            'transition' => Transition::class,
            'from_status' => TranslationStatus::class,
            'to_status' => TranslationStatus::class,
        ];
    }

    public function translation(): BelongsTo
    {
        return $this->belongsTo(ArticleTranslation::class, 'article_translation_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
