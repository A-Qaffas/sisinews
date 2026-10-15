<?php

namespace Database\Factories;

use App\Enums\Locale;
use App\Models\Page;
use App\Models\PageTranslation;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<PageTranslation>
 */
class PageTranslationFactory extends Factory
{
    public function definition(): array
    {
        return [
            'page_id' => Page::factory(),
            'locale' => Locale::En,
            'title' => fake()->sentence(3),
            'body' => '<p>'.fake()->paragraph(4).'</p><h2>'.fake()->sentence(3).'</h2><p>'.fake()->paragraph(4).'</p>',
            'meta_description' => null,
            'is_published' => true,
        ];
    }

    public function arabic(): static
    {
        return $this->state([
            'locale' => Locale::Ar,
            'title' => 'صفحة تجريبية '.fake()->numberBetween(100, 999),
            'body' => '<p>هذا نص تجريبي لصفحة ثابتة باللغة العربية.</p><h2>عنوان فرعي</h2><p>فقرة ثانية من الصفحة.</p>',
        ]);
    }

    public function unpublished(): static
    {
        return $this->state(['is_published' => false]);
    }
}
