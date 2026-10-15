<?php

namespace Database\Factories;

use App\Models\Page;
use App\Models\PageTranslation;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * The shell alone; ->bilingual() also writes a published Arabic and English version.
 *
 * @extends Factory<Page>
 */
class PageFactory extends Factory
{
    public function definition(): array
    {
        return [
            'slug' => 'page-'.fake()->unique()->numberBetween(1, 999_999),
            'sort_order' => 0,
            'show_in_footer' => true,
        ];
    }

    public function bilingual(): static
    {
        return $this->afterCreating(function (Page $page): void {
            PageTranslation::factory()->for($page)->create();
            PageTranslation::factory()->for($page)->arabic()->create();
        });
    }

    public function hiddenFromFooter(): static
    {
        return $this->state(['show_in_footer' => false]);
    }
}
