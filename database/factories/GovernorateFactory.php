<?php

namespace Database\Factories;

use App\Models\Governorate;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * A made-up governorate for tests (the real 27 come from GovernorateSeeder); ->named() builds a specific one.
 *
 * @extends Factory<Governorate>
 */
class GovernorateFactory extends Factory
{
    public function definition(): array
    {
        $n = fake()->unique()->numberBetween(1000, 999_999);

        return [
            'slug' => "governorate-{$n}",
            'name_en' => "Governorate {$n}",
            'name_ar' => "محافظة {$n}",
            'sort_order' => 0,
        ];
    }

    public function named(string $slug, string $english, string $arabic, int $sortOrder = 0): static
    {
        return $this->state([
            'slug' => $slug,
            'name_en' => $english,
            'name_ar' => $arabic,
            'sort_order' => $sortOrder,
        ]);
    }
}
