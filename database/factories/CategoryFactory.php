<?php

namespace Database\Factories;

use App\Enums\Locale;
use App\Models\Category;
use App\Models\CategoryTranslation;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Category>
 */
class CategoryFactory extends Factory
{
    public function definition(): array
    {
        return ['sort_order' => 0, 'show_in_nav' => true];
    }

    /** Creates both language rows so the category is usable in /ar and /en. */
    public function configure(): static
    {
        return $this->afterCreating(function (Category $category) {
            $n = $category->getKey();
            CategoryTranslation::create(['category_id' => $n, 'locale' => Locale::En, 'name' => "Section {$n}", 'slug' => "section-{$n}"]);
            CategoryTranslation::create(['category_id' => $n, 'locale' => Locale::Ar, 'name' => "قسم {$n}", 'slug' => "قسم-{$n}"]);
        });
    }
}
