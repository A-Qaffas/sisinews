<?php

namespace App\Filament\Support;

use App\Enums\Locale;
use App\Models\Category;
use App\Models\Tag;
use Illuminate\Support\Collection;

/** Select/table labels for categories and tags: "English / عربي", whichever exist. */
final class TaxonomyLabels
{
    public static function category(Category $category): string
    {
        return self::join(
            $category->translate(Locale::En)?->name,
            $category->translate(Locale::Ar)?->name,
        );
    }

    public static function tag(Tag $tag): string
    {
        return self::join(
            $tag->translations->firstWhere('locale', Locale::En)?->name,
            $tag->translations->firstWhere('locale', Locale::Ar)?->name,
        );
    }

    /** @return array<int, string> category id => label, in navigation order */
    public static function categoryOptions(): array
    {
        return Category::query()
            ->with('translations')
            ->orderBy('sort_order')
            ->orderBy('id')
            ->get()
            ->mapWithKeys(fn (Category $c): array => [$c->getKey() => self::category($c)])
            ->all();
    }

    /** @param  Collection<int, Tag>  $tags
     * @return array<int, string> */
    public static function tagOptions(Collection $tags): array
    {
        return $tags->mapWithKeys(fn (Tag $t): array => [$t->getKey() => self::tag($t)])->all();
    }

    private static function join(?string $en, ?string $ar): string
    {
        return collect([$en, $ar])->filter()->unique()->implode(' / ');
    }
}
