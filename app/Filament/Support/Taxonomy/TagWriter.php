<?php

namespace App\Filament\Support\Taxonomy;

use App\Enums\Locale;
use App\Models\Tag;
use App\Models\TagTranslation;
use App\Support\Slugger;
use Illuminate\Support\Facades\DB;

/** Creates tags from the article form, reusing an existing tag instead of duplicating it. */
final class TagWriter
{
    public function findOrCreate(?string $nameEn, ?string $nameAr): Tag
    {
        $versions = [
            Locale::En->value => ['name' => $nameEn],
            Locale::Ar->value => ['name' => $nameAr],
        ];

        return DB::transaction(function () use ($versions): Tag {
            foreach ($versions as $locale => $version) {
                $name = trim((string) $version['name']);
                if ($name === '') {
                    continue;
                }

                $existing = TagTranslation::query()
                    ->where('locale', $locale)
                    ->where('slug', Slugger::make($name))
                    ->first();

                if ($existing !== null) {
                    return $existing->tag;
                }
            }

            $tag = Tag::query()->create();
            TaxonomyTranslations::save($tag, TagTranslation::class, $versions, removeBlank: false);

            return $tag;
        });
    }
}
