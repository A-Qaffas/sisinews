<?php

namespace App\Models;

use App\Enums\Locale;
use App\Services\FooterPages;
use App\Support\HtmlSanitizer;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['page_id', 'locale', 'title', 'body', 'meta_description', 'is_published'])]
class PageTranslation extends Model
{
    use HasFactory;

    protected static function booted(): void
    {
        // The footer lists page titles, so a retitled, published or unpublished language must show at once.
        static::saved(fn () => FooterPages::forget());
        static::deleted(fn () => FooterPages::forget());
    }

    protected function casts(): array
    {
        return [
            'locale' => Locale::class,
            'is_published' => 'boolean',
        ];
    }

    /**
     * Bodies are rendered unescaped, so they are sanitized on every assignment (forms, seeders,
     * factories, tinker), exactly like ArticleTranslation. Mass `query()->update()` bypasses models.
     */
    protected function body(): Attribute
    {
        return Attribute::set(
            fn (mixed $value) => $value === null ? '' : HtmlSanitizer::clean((string) $value)
        );
    }

    public function page(): BelongsTo
    {
        return $this->belongsTo(Page::class);
    }
}
