<?php

namespace Database\Seeders;

use App\Editorial\EditorialWorkflow;
use App\Enums\Locale;
use App\Enums\Role;
use App\Models\Article;
use App\Models\ArticleStatusChange;
use App\Models\ArticleTranslation;
use App\Models\Category;
use App\Models\CategoryTranslation;
use App\Models\Tag;
use App\Models\TagTranslation;
use App\Models\User;
use App\Support\Media\HeroImages;
use App\Support\Slugger;
use Carbon\CarbonInterface;
use Database\Seeders\Support\DemoHeroImage;
use Database\Seeders\Support\DemoStories;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * DEV ONLY. Fills a local database with staff accounts, tags and eighteen invented
 * bilingual stories in every workflow state, so the public site and the admin panel
 * have something to show.
 *
 *   php artisan db:seed --class=DemoContentSeeder
 *
 * Safe to repeat: nothing is created twice and existing stories are left untouched.
 * A demo account keeps its password (its names, role and active flag are reset). A story
 * is recognised by its Arabic headline, so renaming a demo story's Arabic title makes
 * the next run create it again.
 */
class DemoContentSeeder extends Seeder
{
    /** Documented dev password for every demo account. Never valid in production (see run()). */
    public const PASSWORD = 'password';

    /** key => [email, Latin name, Arabic name, role] */
    public const STAFF = [
        'admin' => ['admin@sisi.test', 'Nadia Farouk', 'نادية فاروق', Role::Admin],
        'editor' => ['editor@sisi.test', 'Karim Mansour', 'كريم منصور', Role::Editor],
        'journalist' => ['journalist@sisi.test', 'Salma Hassan', 'سلمى حسن', Role::Journalist],
        'journalist2' => ['journalist2@sisi.test', 'Omar Adel', 'عمر عادل', Role::Journalist],
    ];

    public function run(): void
    {
        if (app()->environment('production')) {
            throw new RuntimeException(
                'DemoContentSeeder creates accounts with a publicly known password and must never run in production. '
                .'Create the first real admin with `php artisan sisi:create-admin` instead.'
            );
        }

        $this->call(CategorySeeder::class);

        $staff = $this->seedStaff();
        $tags = $this->seedTags();
        $categories = CategoryTranslation::query()
            ->where('locale', Locale::En->value)
            ->orderBy('category_id')
            ->pluck('category_id', 'slug');

        foreach (DemoStories::all() as $index => $story) {
            $palette = $categories->keys()->search($story['category']);
            $heroPath = $this->ensureHeroImage($story, $palette);

            $articleId = $this->existingArticleId($story)
                ?? DB::transaction(fn () => $this->createStory($story, $index, $heroPath, $staff, $tags, $categories[$story['category']]));

            $this->seedViews($articleId, $story['pop']);
        }

        $this->printLogins();
    }

    /** @return array<string, User> */
    private function seedStaff(): array
    {
        $staff = [];

        foreach (self::STAFF as $key => [$email, $name, $nameAr, $role]) {
            // The password is set only on creation so a developer's own change survives a re-run.
            $user = User::query()->where('email', $email)->first()
                ?? new User(['email' => $email, 'password' => self::PASSWORD]);

            $user->fill(['name' => $name, 'name_ar' => $nameAr, 'role' => $role, 'is_active' => true]);
            $user->forceFill(['email_verified_at' => $user->email_verified_at ?? now()])->save();

            // A journalist may only write in assigned sections: the demo ones get all of them (real accounts: see Team).
            if ($role === Role::Journalist) {
                $user->allowedCategories()->syncWithoutDetaching(Category::query()->pluck('id')->all());
            }

            $staff[$key] = $user;
        }

        return $staff;
    }

    /** @return array<string, int> tag key => tag id */
    private function seedTags(): array
    {
        $ids = [];

        foreach (DemoStories::TAGS as $key => [$en, $ar]) {
            $slug = Slugger::make($en);
            $tag = TagTranslation::query()->where('locale', Locale::En->value)->where('slug', $slug)->first()?->tag
                ?? Tag::query()->create();

            TagTranslation::query()->updateOrCreate(
                ['tag_id' => $tag->id, 'locale' => Locale::En->value],
                ['name' => $en, 'slug' => $slug],
            );
            TagTranslation::query()->updateOrCreate(
                ['tag_id' => $tag->id, 'locale' => Locale::Ar->value],
                ['name' => $ar, 'slug' => Slugger::make($ar)],
            );

            $ids[$key] = $tag->id;
        }

        return $ids;
    }

    /** Writes the picture if it is not on disk yet; returns its path on the public disk. */
    private function ensureHeroImage(array $story, int $palette): ?string
    {
        if (! ($story['hero'] ?? true)) {
            return null;
        }

        $path = "articles/demo/{$story['key']}.jpg";
        $disk = Storage::disk('public');

        if (! $disk->exists($path)) {
            $disk->put($path, DemoHeroImage::jpeg($story['key'], $palette));
            HeroImages::process($path);
        }

        return $path;
    }

    private function existingArticleId(array $story): ?int
    {
        return ArticleTranslation::query()
            ->inLocale(Locale::Ar)
            ->where('title', $story['ar']['title'])
            ->value('article_id');
    }

    /**
     * @param  array<string, User>  $staff
     * @param  array<string, int>  $tags
     */
    private function createStory(array $story, int $index, ?string $heroPath, array $staff, array $tags, int $categoryId): int
    {
        $author = $staff[$story['author']];
        $when = now()->subMinutes((int) ($story['hours'] * 60));
        // A scheduled story was written (and approved) in the past; only its publish date is ahead.
        $written = ($when->isFuture() ? now()->subDay() : $when->copy())->subHours(4);

        $article = new Article([
            'category_id' => $categoryId,
            'author_id' => $author->id,
            'hero_image' => $heroPath,
            'is_featured' => $story['featured'] ?? false,
            'is_breaking' => isset($story['breaking']),
            'breaking_until' => ($story['breaking'] ?? null) === 'expired' ? $when->copy()->addDay() : null,
        ]);
        $article->forceFill(['created_at' => $written, 'updated_at' => $written])->save();
        $article->tags()->sync(array_map(fn (string $key) => $tags[$key], $story['tags']));

        foreach ([Locale::Ar, Locale::En] as $locale) {
            $text = $story[$locale->value] ?? null;
            if ($text === null) {
                continue;
            }

            // The English version goes live a little after the Arabic one, but never in the future
            // for a story that is already published.
            $lag = $locale === Locale::En
                ? min(30 + ($index * 17) % 90, max(0, (int) ($story['hours'] * 60) - 10))
                : 0;

            $translation = $this->createTranslation($article, $locale, $text, $heroPath !== null, $written);
            $this->advance($translation, $text, $author, $staff['editor'], $when->copy()->addMinutes($lag));
        }

        return $article->id;
    }

    /** @param array<string, mixed> $text */
    private function createTranslation(Article $article, Locale $locale, array $text, bool $hasHero, CarbonInterface $written): ArticleTranslation
    {
        $translation = new ArticleTranslation([
            'article_id' => $article->id,
            'locale' => $locale,
            'title' => $text['title'],
            'slug' => Slugger::make($text['title']),
            'excerpt' => $text['excerpt'],
            // The model's body mutator runs the HTML sanitizer, as for any staff-written story.
            'body' => DemoStories::bodyHtml($text, $locale),
            'hero_caption' => $hasHero ? $text['caption'] : null,
            'meta_description' => Str::limit($text['excerpt'], 160),
        ]);
        $translation->forceFill(['created_at' => $written, 'updated_at' => $written])->save();

        // Reload so the database default status ('draft') is on the model, then hand
        // the workflow the article it would otherwise lazy-load for the policy check.
        $translation->refresh()->setRelation('article', $article);

        return $translation;
    }

    /**
     * Moves a fresh draft to the state the story asks for, strictly through
     * EditorialWorkflow so the audit trail and the policies behave as in production.
     *
     * @param  array<string, mixed>  $text
     */
    private function advance(ArticleTranslation $translation, array $text, User $author, User $editor, CarbonInterface $at): void
    {
        $workflow = app(EditorialWorkflow::class);
        $state = $text['state'];

        if ($state === 'draft') {
            return;
        }

        if (in_array($state, ['published', 'scheduled', 'archived'], true)) {
            // approve() keeps a date that is already set, which is how scheduling works.
            $translation->forceFill(['published_at' => $at])->save();
        }

        match ($state) {
            'in_review' => $workflow->submit($translation, $author),
            'changes_requested' => $workflow->requestChanges($workflow->submit($translation, $author), $editor, $text['note']),
            'published', 'scheduled' => $this->publish($translation, $author, $editor),
            'archived' => $workflow->archive($this->publish($translation, $author, $editor), $editor),
        };

        $this->backdate($translation, $at->isFuture() ? now()->subHours(20) : $at);
    }

    /** Reviewers publish their own text in one step; journalists need an editor's approval. */
    private function publish(ArticleTranslation $translation, User $author, User $editor): ArticleTranslation
    {
        $workflow = app(EditorialWorkflow::class);

        if ($author->isReviewer()) {
            return $workflow->publishNow($translation, $author);
        }

        return $workflow->approve($workflow->submit($translation, $author), $editor);
    }

    /** Spreads the audit rows backwards from $anchor so the history reads like a real one. */
    private function backdate(ArticleTranslation $translation, CarbonInterface $anchor): void
    {
        $ids = ArticleStatusChange::query()
            ->where('article_translation_id', $translation->id)
            ->orderBy('id')
            ->pluck('id');

        foreach ($ids as $position => $id) {
            ArticleStatusChange::query()->whereKey($id)->update([
                'created_at' => $anchor->copy()->subMinutes(($ids->count() - 1 - $position) * 40),
            ]);
        }

        ArticleTranslation::query()->whereKey($translation->id)->update(['updated_at' => $anchor]);
    }

    /**
     * Gives every visible translation a daily view count from the day it went live, so
     * Most Read has data. Existing rows are kept: real visits must not be overwritten.
     */
    private function seedViews(int $articleId, int $popularity): void
    {
        $today = now()->startOfDay();
        $rows = [];

        foreach (ArticleTranslation::query()->where('article_id', $articleId)->visible()->get() as $translation) {
            $share = $translation->locale === Locale::Ar ? 1.0 : 0.35;

            for ($day = $translation->published_at->copy()->startOfDay(); $day->lte($today); $day = $day->addDay()) {
                $age = $day->diffInDays($today);
                $wobble = 30 + crc32($translation->id.'|'.$day->toDateString()) % 50;

                $rows[] = [
                    'article_translation_id' => $translation->id,
                    'day' => $day->toDateString(),
                    'views' => (int) round($popularity * $wobble * $share * max(0.2, 1 - 0.1 * $age)),
                ];
            }
        }

        foreach (array_chunk($rows, 200) as $chunk) {
            DB::table('article_daily_views')->insertOrIgnore($chunk);
        }
    }

    private function printLogins(): void
    {
        if ($this->command === null) {
            return;
        }

        $this->command->newLine();
        $this->command->info('Demo staff accounts. Password for all of them: '.self::PASSWORD);
        $this->command->table(
            ['Role', 'Email', 'Name'],
            array_map(fn (array $row) => [$row[3]->value, $row[0], "{$row[1]} / {$row[2]}"], array_values(self::STAFF)),
        );
        $this->command->line('Sign in at '.url('/admin'));
    }
}
