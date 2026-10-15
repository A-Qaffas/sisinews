<?php

namespace Tests\Feature\Seeders;

use App\Models\Category;
use App\Models\CategoryTranslation;
use Database\Seeders\CategorySeeder;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** Every deploy runs db:seed, so the sections are seeded once and then belong to the panel. */
class CategorySeederTest extends TestCase
{
    use RefreshDatabase;

    private function names(string $locale): array
    {
        return CategoryTranslation::query()->where('locale', $locale)->orderBy('category_id')->pluck('name', 'slug')->all();
    }

    public function test_an_empty_database_gets_the_six_launch_sections_in_both_languages(): void
    {
        $this->seed(CategorySeeder::class);

        $this->assertSame(6, Category::query()->count());
        $this->assertSame(
            ['egypt' => 'Egypt', 'politics' => 'Politics', 'business' => 'Business', 'regional-affairs' => 'Regional affairs', 'world' => 'World', 'culture' => 'Culture'],
            $this->names('en'),
        );
        $this->assertSame('شؤون إقليمية', $this->names('ar')['شؤون-إقليمية']);
        $this->assertSame([1, 2, 3, 4, 5, 6], Category::query()->orderBy('id')->pluck('sort_order')->map(fn ($s) => (int) $s)->all());
    }

    public function test_a_section_renamed_in_the_panel_survives_the_next_deploy(): void
    {
        $this->seed(DatabaseSeeder::class);
        $egypt = CategoryTranslation::query()->where('locale', 'en')->where('slug', 'egypt')->firstOrFail();
        $egypt->update(['name' => 'Egypt news']);

        $this->seed(DatabaseSeeder::class);

        $this->assertSame('Egypt news', $egypt->fresh()->name);
        $this->assertSame(6, Category::query()->count());
    }

    public function test_a_changed_slug_does_not_create_a_half_duplicate_or_stop_the_deploy(): void
    {
        $this->seed(CategorySeeder::class);
        CategoryTranslation::query()->where('locale', 'en')->where('slug', 'egypt')->update(['slug' => 'egypt-news']);

        // Before: no "egypt" slug, so a new section was created and its Arabic row broke unique(locale, slug).
        $this->seed(CategorySeeder::class);

        $this->assertSame(6, Category::query()->count());
        $this->assertSame(0, CategoryTranslation::query()->where('slug', 'egypt')->count());
    }

    public function test_a_database_that_already_has_any_section_is_left_alone(): void
    {
        Category::factory()->create();

        $this->seed(CategorySeeder::class);

        $this->assertSame(1, Category::query()->count());
    }
}
