<?php

namespace Database\Factories;

use App\Enums\Locale;
use App\Enums\TranslationStatus;
use App\Models\Article;
use App\Models\ArticleTranslation;
use App\Support\Slugger;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * Status and published_at are guarded on the model (only EditorialWorkflow may
 * change them), so the factory bypasses mass-assignment via an afterMaking-style
 * forceFill in newModel().
 *
 * @extends Factory<ArticleTranslation>
 */
class ArticleTranslationFactory extends Factory
{
    protected $model = ArticleTranslation::class;

    public function definition(): array
    {
        $title = fake()->sentence(6);

        return [
            'article_id' => Article::factory(),
            'locale' => Locale::En,
            'title' => $title,
            'slug' => Slugger::make($title),
            'excerpt' => fake()->sentence(18),
            'body' => '<p>'.fake()->paragraph(6).'</p><p>'.fake()->paragraph(6).'</p>',
            'hero_caption' => null,
            'meta_description' => null,
        ];
    }

    public function newModel(array $attributes = [])
    {
        $model = parent::newModel([]);
        $model->forceFill($attributes);

        return $model;
    }

    public function arabic(): static
    {
        $title = 'عنوان تجريبي لخبر رقم '.fake()->numberBetween(100, 999);

        return $this->state([
            'locale' => Locale::Ar,
            'title' => $title,
            'slug' => Slugger::make($title),
            'excerpt' => 'ملخص قصير للخبر يوضح أهم ما جاء فيه.',
            'body' => '<p>هذا نص تجريبي للخبر باللغة العربية، يحتوي على فقرات متعددة.</p><p>الفقرة الثانية من الخبر.</p>',
        ]);
    }

    public function status(TranslationStatus $status): static
    {
        return $this->state([
            'status' => $status,
            'published_at' => $status === TranslationStatus::Published ? now()->subMinute() : null,
        ]);
    }

    public function published(): static
    {
        return $this->status(TranslationStatus::Published);
    }

    public function inReview(): static
    {
        return $this->status(TranslationStatus::InReview);
    }
}
