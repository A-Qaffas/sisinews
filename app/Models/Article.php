<?php

namespace App\Models;

use App\Enums\Locale;
use App\Observers\ArticleObserver;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\ObservedBy;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Language-neutral shell of a story: who wrote it, its section, hero image and
 * breaking/featured flags. The words live in ArticleTranslation.
 */
#[Fillable(['category_id', 'author_id', 'hero_image', 'is_featured', 'is_breaking', 'breaking_until'])]
#[ObservedBy(ArticleObserver::class)]
class Article extends Model
{
    use HasFactory, SoftDeletes;

    protected function casts(): array
    {
        return [
            'is_featured' => 'boolean',
            'is_breaking' => 'boolean',
            'breaking_until' => 'datetime',
        ];
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(Category::class);
    }

    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'author_id');
    }

    public function translations(): HasMany
    {
        return $this->hasMany(ArticleTranslation::class);
    }

    public function tags(): BelongsToMany
    {
        return $this->belongsToMany(Tag::class);
    }

    /** Reads the loaded relation (eager-load `translations` to avoid N+1). */
    public function translation(Locale $locale): ?ArticleTranslation
    {
        return $this->translations->firstWhere('locale', $locale);
    }

    /** Breaking now = flagged and (no expiry or expiry still in the future). */
    public function isBreakingNow(): bool
    {
        return $this->is_breaking
            && ($this->breaking_until === null || $this->breaking_until->isFuture());
    }
}
