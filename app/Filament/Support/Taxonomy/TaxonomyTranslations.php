<?php

namespace App\Filament\Support\Taxonomy;

use App\Enums\Locale;
use App\Support\Slugger;
use Illuminate\Database\Eloquent\Model;

/**
 * Reads and writes the per-language rows (name + slug) that categories and tags share.
 * `(locale, slug)` is unique in the database, so every slug written here is made unique
 * per language; the public URLs of categories and tags are built from it.
 */
final class TaxonomyTranslations
{
    /** @return array<string, array{name: string, slug: string}> locale value => form state */
    public static function formState(Model $owner): array
    {
        $state = [];
        foreach ($owner->translations as $translation) {
            $state[$translation->locale->value] = ['name' => $translation->name, 'slug' => $translation->slug];
        }

        return $state;
    }

    /**
     * Creates or updates one row per language that has a name. A blank name removes that
     * language's row when $removeBlank is true (tags may be single-language; categories may not).
     *
     * @param  class-string<Model>  $translationClass
     * @param  array<string, array{name?: ?string, slug?: ?string}>  $versions  keyed by locale value
     */
    public static function save(Model $owner, string $translationClass, array $versions, bool $removeBlank): void
    {
        $foreignKey = $owner->getForeignKey();

        foreach (Locale::cases() as $locale) {
            $name = trim((string) ($versions[$locale->value]['name'] ?? ''));
            $existing = $translationClass::query()
                ->where($foreignKey, $owner->getKey())
                ->where('locale', $locale->value)
                ->first();

            if ($name === '') {
                if ($removeBlank) {
                    $existing?->delete();
                }

                continue;
            }

            $wanted = Slugger::make((string) ($versions[$locale->value]['slug'] ?? '')) ?: Slugger::make($name);
            $attributes = [
                'name' => $name,
                'slug' => self::uniqueSlug($translationClass, $locale, $wanted, $existing?->getKey()),
            ];

            $existing === null
                ? $translationClass::query()->create([...$attributes, $foreignKey => $owner->getKey(), 'locale' => $locale])
                : $existing->update($attributes);
        }
    }

    /** @param  class-string<Model>  $translationClass */
    public static function uniqueSlug(string $translationClass, Locale $locale, string $wanted, ?int $ignoreId = null): string
    {
        $slug = $wanted;
        $suffix = 2;

        while (
            $translationClass::query()
                ->where('locale', $locale->value)
                ->where('slug', $slug)
                ->when($ignoreId !== null, fn ($query) => $query->whereKeyNot($ignoreId))
                ->exists()
        ) {
            $slug = "{$wanted}-{$suffix}";
            $suffix++;
        }

        return $slug;
    }
}
