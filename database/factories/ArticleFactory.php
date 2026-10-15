<?php

namespace Database\Factories;

use App\Models\Article;
use App\Models\Category;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Article>
 */
class ArticleFactory extends Factory
{
    public function definition(): array
    {
        return [
            'category_id' => Category::factory(),
            'author_id' => User::factory(),
            'hero_image' => null,
            'is_featured' => false,
            'is_breaking' => false,
            'breaking_until' => null,
        ];
    }

    public function breaking(?\DateTimeInterface $until = null): static
    {
        return $this->state(['is_breaking' => true, 'breaking_until' => $until]);
    }

    public function featured(): static
    {
        return $this->state(['is_featured' => true]);
    }
}
