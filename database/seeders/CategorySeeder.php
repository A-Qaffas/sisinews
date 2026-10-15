<?php

namespace Database\Seeders;

use App\Enums\Locale;
use App\Models\Category;
use App\Models\CategoryTranslation;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/**
 * The site's six launch sections, mirrored from the old sisi.news placeholder navigation. Create-only, like PageSeeder:
 * it seeds an EMPTY table and does nothing once any section exists, so a deploy (which runs db:seed) never reverts a
 * section an editor renamed in the panel, and never half-creates a duplicate of one whose slug changed.
 */
class CategorySeeder extends Seeder
{
    /** [sort, en name, en slug, ar name, ar slug] */
    private const SECTIONS = [
        [1, 'Egypt', 'egypt', 'مصر', 'مصر'],
        [2, 'Politics', 'politics', 'سياسة', 'سياسة'],
        [3, 'Business', 'business', 'أعمال', 'أعمال'],
        [4, 'Regional affairs', 'regional-affairs', 'شؤون إقليمية', 'شؤون-إقليمية'],
        [5, 'World', 'world', 'العالم', 'العالم'],
        [6, 'Culture', 'culture', 'ثقافة', 'ثقافة'],
    ];

    public function run(): void
    {
        // After the first seed the panel owns names, slugs and order.
        if (Category::query()->exists()) {
            $this->command?->info('Sections: already present, left untouched.');

            return;
        }

        // All six or none: a failure half-way must not leave a partial menu behind.
        DB::transaction(function (): void {
            foreach (self::SECTIONS as [$sort, $enName, $enSlug, $arName, $arSlug]) {
                $category = Category::create(['sort_order' => $sort, 'show_in_nav' => true]);

                CategoryTranslation::updateOrCreate(
                    ['category_id' => $category->id, 'locale' => Locale::En->value],
                    ['name' => $enName, 'slug' => $enSlug],
                );
                CategoryTranslation::updateOrCreate(
                    ['category_id' => $category->id, 'locale' => Locale::Ar->value],
                    ['name' => $arName, 'slug' => $arSlug],
                );
            }
        });

        $this->command?->info('Sections: the six launch sections created.');
    }
}
