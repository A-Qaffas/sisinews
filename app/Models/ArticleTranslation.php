<?php

namespace App\Models;

use App\Enums\Locale;
use App\Enums\TranslationStatus;
use App\Editorial\Transition;
use App\Support\HtmlSanitizer;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

// `status` and `published_at` are intentionally NOT fillable: status changes only
// through App\Editorial\EditorialWorkflow, so a crafted form post cannot publish.
#[Fillable(['article_id', 'locale', 'title', 'slug', 'excerpt', 'body', 'hero_caption', 'meta_description'])]
class ArticleTranslation extends Model
{
    use HasFactory;

    protected function casts(): array
    {
        return [
            'locale' => Locale::class,
            'status' => TranslationStatus::class,
            'published_at' => 'datetime',
        ];
    }

    /**
     * Bodies are rendered unescaped, so they are sanitized on every assignment
     * (forms, Filament, factories, tinker). Mass `query()->update()` bypasses models.
     */
    protected function body(): Attribute
    {
        return Attribute::set(
            fn (mixed $value) => $value === null ? null : HtmlSanitizer::clean((string) $value)
        );
    }

    public function article(): BelongsTo
    {
        return $this->belongsTo(Article::class);
    }

    public function statusChanges(): HasMany
    {
        return $this->hasMany(ArticleStatusChange::class)->latest('id');
    }

    /** The reviewer's most recent "request changes" note, shown to the journalist. */
    public function latestChangeRequest(): HasOne
    {
        return $this->hasOne(ArticleStatusChange::class)
            ->where('transition', Transition::RequestChanges->value)
            ->latestOfMany();
    }

    /** Visible to readers: published AND its (possibly scheduled) date has arrived. */
    public function scopeVisible(Builder $query): Builder
    {
        return $query->where('status', TranslationStatus::Published->value)
            ->where('published_at', '<=', now());
    }

    public function scopeInLocale(Builder $query, Locale $locale): Builder
    {
        return $query->where('locale', $locale->value);
    }

    public function isVisible(): bool
    {
        return $this->status === TranslationStatus::Published
            && $this->published_at !== null
            && $this->published_at->lte(now());
    }

    /** Canonical public URL: /{locale}/news/{article_id}-{slug}. The id decides; the slug is cosmetic. */
    public function url(): string
    {
        return route('article', [
            'locale' => $this->locale->value,
            'idSlug' => $this->slug !== '' ? "{$this->article_id}-{$this->slug}" : (string) $this->article_id,
        ]);
    }
}
