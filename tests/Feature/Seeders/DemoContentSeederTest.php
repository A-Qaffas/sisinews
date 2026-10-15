<?php

namespace Tests\Feature\Seeders;

use App\Enums\Locale;
use App\Enums\Role;
use App\Enums\TranslationStatus;
use App\Models\Article;
use App\Models\ArticleStatusChange;
use App\Models\ArticleTranslation;
use App\Models\Category;
use App\Models\Tag;
use App\Models\User;
use App\Support\Media\HeroImages;
use Database\Seeders\DemoContentSeeder;
use Database\Seeders\Support\DemoStories;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;
use Tests\TestCase;

class DemoContentSeederTest extends TestCase
{
    use RefreshDatabase;

    private string $pictureRoot;

    /**
     * Storage::fake() shares one directory between every test process, and other suites run
     * at the same time in this working tree, so the pictures get a directory of their own.
     */
    protected function setUp(): void
    {
        parent::setUp();

        $this->pictureRoot = storage_path('framework/testing/disks/demo-seeder-'.Str::lower(Str::random(10)));
        config(['filesystems.disks.public.root' => $this->pictureRoot]);
        Storage::forgetDisk('public');
    }

    protected function tearDown(): void
    {
        (new Filesystem)->deleteDirectory($this->pictureRoot);
        Storage::forgetDisk('public');

        parent::tearDown();
    }

    /**
     * Placeholder files let the seeder skip the (slow) GD drawing; the last test leaves some out.
     *
     * @param  list<string>  $except  story keys that get no placeholder
     */
    private function putPlaceholderPictures(array $except = []): void
    {
        foreach (DemoStories::all() as $story) {
            if (($story['hero'] ?? true) && ! in_array($story['key'], $except, true)) {
                Storage::disk('public')->put("articles/demo/{$story['key']}.jpg", 'placeholder');
            }
        }
    }

    private function seedWithPlaceholderPictures(): void
    {
        $this->putPlaceholderPictures();
        $this->seed(DemoContentSeeder::class);
    }

    /** @return array<string, int> */
    private function rowCounts(): array
    {
        $counts = [];
        foreach (['users', 'categories', 'tags', 'tag_translations', 'articles', 'article_translations', 'article_tag', 'article_status_changes', 'article_daily_views'] as $table) {
            $counts[$table] = DB::table($table)->count();
        }

        return $counts;
    }

    public function test_it_refuses_to_run_in_production(): void
    {
        $this->app['env'] = 'production';

        $seeder = (new DemoContentSeeder)->setContainer($this->app);

        try {
            $seeder->__invoke();
            $this->fail('The seeder ran in production.');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('must never run in production', $e->getMessage());
            $this->assertStringContainsString('sisi:create-admin', $e->getMessage());
        }

        $this->assertSame(0, User::count());
        $this->assertSame(0, Article::count());
        $this->assertSame([], Storage::disk('public')->allFiles());
    }

    public function test_it_creates_the_documented_staff_accounts_and_prints_the_logins(): void
    {
        $this->putPlaceholderPictures();

        $this->artisan('db:seed', ['--class' => DemoContentSeeder::class])
            ->expectsOutputToContain('Password for all of them: password')
            ->expectsOutputToContain('admin@sisi.test')
            ->expectsOutputToContain('journalist2@sisi.test')
            ->assertSuccessful();

        $expected = [
            'admin@sisi.test' => Role::Admin,
            'editor@sisi.test' => Role::Editor,
            'journalist@sisi.test' => Role::Journalist,
            'journalist2@sisi.test' => Role::Journalist,
        ];
        $this->assertSame(count($expected), User::count());

        foreach ($expected as $email => $role) {
            $user = User::where('email', $email)->sole();
            $this->assertSame($role, $user->role);
            $this->assertTrue($user->is_active, $email);
            $this->assertTrue(Hash::check('password', $user->password), $email);
            $this->assertNotEmpty($user->name);
            $this->assertMatchesRegularExpression('/\p{Arabic}/u', $user->name_ar, "$email needs an Arabic name");
        }
    }

    public function test_it_creates_eighteen_stories_with_the_expected_status_mix(): void
    {
        $this->seedWithPlaceholderPictures();

        $this->assertSame(6, Category::count());
        $this->assertSame(18, Article::count());
        foreach (Category::withCount('articles')->get() as $category) {
            $this->assertSame(3, $category->articles_count, "section {$category->id}");
        }
        $this->assertSame(18, ArticleTranslation::inLocale(Locale::Ar)->count(), 'every story has an Arabic version');
        $this->assertSame(16, ArticleTranslation::inLocale(Locale::En)->count());

        $byStatus = ArticleTranslation::all()->countBy(fn (ArticleTranslation $t) => $t->status->value)->all();
        ksort($byStatus);
        $this->assertSame([
            'archived' => 2,
            'changes_requested' => 1,
            'draft' => 2,
            'in_review' => 3,
            'published' => 26,
        ], $byStatus);

        $live = fn (Locale $locale) => ArticleTranslation::visible()->inLocale($locale)->pluck('article_id');
        $this->assertSame(10, $live(Locale::Ar)->intersect($live(Locale::En))->count(), 'live in both languages');
        $this->assertSame(4, $live(Locale::Ar)->diff($live(Locale::En))->count(), 'live in Arabic only');
        $this->assertSame(0, $live(Locale::En)->diff($live(Locale::Ar))->count());
    }

    public function test_it_schedules_one_story_flags_featured_and_breaking_ones_and_spreads_the_dates(): void
    {
        $this->seedWithPlaceholderPictures();

        $scheduled = ArticleTranslation::where('status', TranslationStatus::Published->value)->where('published_at', '>', now())->get();
        $this->assertCount(2, $scheduled, 'the Arabic and the English version of one story');
        $this->assertSame(1, $scheduled->pluck('article_id')->unique()->count());
        $this->assertTrue($scheduled->every(fn (ArticleTranslation $t) => ! $t->isVisible()));

        $this->assertSame(2, Article::where('is_featured', true)->count());

        $breaking = Article::where('is_breaking', true)->get();
        $this->assertCount(2, $breaking);
        $this->assertSame(1, $breaking->filter->isBreakingNow()->count());
        $this->assertSame(1, $breaking->reject->isBreakingNow()->count(), 'one flag has run out');

        $dates = ArticleTranslation::visible()->pluck('published_at');
        $this->assertTrue($dates->every(fn ($d) => $d->gt(now()->subDays(14))));
        $this->assertGreaterThanOrEqual(8, $dates->map->toDateString()->unique()->count());

        $changes = ArticleTranslation::where('status', TranslationStatus::ChangesRequested->value)->sole();
        $this->assertNotEmpty($changes->latestChangeRequest->note);
        $this->assertSame('editor@sisi.test', $changes->latestChangeRequest->user->email);
    }

    public function test_every_status_was_reached_through_the_editorial_workflow(): void
    {
        $this->seedWithPlaceholderPictures();

        foreach (ArticleTranslation::with('statusChanges')->get() as $translation) {
            $trail = $translation->statusChanges->sortBy('id')->values();

            if ($translation->status === TranslationStatus::Draft) {
                $this->assertCount(0, $trail, 'a draft has never moved');

                continue;
            }

            $this->assertSame(TranslationStatus::Draft, $trail->first()->from_status);
            $this->assertSame($translation->status, $trail->last()->to_status);
            foreach ($trail->skip(1) as $i => $change) {
                $this->assertSame($trail[$i - 1]->to_status, $change->from_status, "translation {$translation->id} trail is unbroken");
            }
            $this->assertNotNull($trail->first()->user_id);
        }

        $this->assertSame(
            ArticleTranslation::where('status', '!=', TranslationStatus::Draft->value)->count(),
            ArticleStatusChange::distinct()->count('article_translation_id'),
        );

        $editor = User::where('email', 'editor@sisi.test')->sole();
        foreach (ArticleStatusChange::with(['user', 'translation.article.author'])->where('transition', 'approve')->get() as $approval) {
            $this->assertTrue($approval->user->isReviewer(), 'only reviewers approve');
            if ($approval->translation->article->author->role === Role::Journalist) {
                $this->assertSame($editor->id, $approval->user_id, 'a journalist\'s story is approved by the editor');
            }
        }

        $submitters = ArticleStatusChange::with('user')->where('transition', 'submit')->get()->pluck('user.email')->unique();
        $this->assertContains('journalist@sisi.test', $submitters);
        $this->assertContains('journalist2@sisi.test', $submitters);
    }

    public function test_bodies_are_structured_stories_in_the_right_script_with_tags_and_pictures(): void
    {
        $this->seedWithPlaceholderPictures();

        foreach (ArticleTranslation::all() as $t) {
            $this->assertStringContainsString('<h2>', $t->body, "translation {$t->id}");
            $this->assertStringContainsString('<blockquote>', $t->body, "translation {$t->id}");

            $withoutQuote = preg_replace('#<blockquote>.*?</blockquote>#s', '', $t->body);
            $paragraphs = preg_match_all('/<p[\s>]/', $withoutQuote);
            $this->assertGreaterThanOrEqual(4, $paragraphs);
            $this->assertLessThanOrEqual(6, $paragraphs);

            $this->assertNotEmpty($t->excerpt);
            $this->assertNotEmpty($t->slug);
            $this->assertStringContainsString('sisi.news', strip_tags($t->body), 'every body says it is fictional');

            $pattern = $t->locale === Locale::Ar ? '/\p{Arabic}/u' : '/^[\x00-\x7F\x{2018}-\x{201D}]+$/u';
            $this->assertMatchesRegularExpression($pattern, $t->title, "translation {$t->id}");
        }

        $this->assertSame(count(DemoStories::TAGS), Tag::count());
        foreach (Tag::with('translations')->get() as $tag) {
            $this->assertEqualsCanonicalizing(['ar', 'en'], $tag->translations->map(fn ($t) => $t->locale->value)->all());
        }
        foreach (Article::with('tags')->get() as $article) {
            $this->assertNotEmpty($article->tags, "article {$article->id} has tags");
        }

        $this->assertSame(2, Article::whereNull('hero_image')->count(), 'two stories deliberately have no picture');
        foreach (Article::whereNotNull('hero_image')->pluck('hero_image') as $path) {
            $this->assertStringStartsWith('articles/demo/', $path);
            Storage::disk('public')->assertExists($path);
        }
    }

    public function test_only_visible_translations_get_view_counts_for_the_last_two_weeks(): void
    {
        $this->seedWithPlaceholderPictures();

        $visible = ArticleTranslation::visible()->pluck('id');
        $viewed = DB::table('article_daily_views')->distinct()->pluck('article_translation_id');
        $this->assertEqualsCanonicalizing($visible->all(), $viewed->all());

        $this->assertSame(0, DB::table('article_daily_views')->where('views', '<=', 0)->count());
        $this->assertGreaterThan(
            0,
            DB::table('article_daily_views')->where('day', '>=', now()->subDays(6)->toDateString())->count(),
            'Most Read looks at the last seven days',
        );
        $this->assertSame(0, DB::table('article_daily_views')->where('day', '>', now()->toDateString())->count());
    }

    public function test_running_it_again_creates_nothing_twice_keeps_edits_and_repairs_a_missing_picture(): void
    {
        $this->seedWithPlaceholderPictures();
        $first = $this->rowCounts();
        $this->assertSame(4, $first['users']);
        $this->assertSame(18, $first['articles']);
        $this->assertSame(34, $first['article_translations']);

        $editor = User::where('email', 'editor@sisi.test')->sole();
        $editor->forceFill(['password' => 'my-own-dev-password'])->save();

        $translation = ArticleTranslation::visible()->inLocale(Locale::Ar)->first();
        $translation->update(['body' => '<p>Edited by a human.</p>']);
        $before = $translation->fresh()->only(['title', 'status', 'published_at', 'body']);

        $today = now()->toDateString();
        DB::table('article_daily_views')->updateOrInsert(
            ['article_translation_id' => $translation->id, 'day' => $today],
            ['views' => 987654],
        );

        $article = Article::whereNotNull('hero_image')->first();
        Storage::disk('public')->delete($article->hero_image);

        $this->seed(DemoContentSeeder::class);

        $this->assertSame($first, $this->rowCounts());
        $this->assertTrue(Hash::check('my-own-dev-password', $editor->fresh()->password), 'a developer\'s own password survives');
        $this->assertEquals($before, $translation->fresh()->only(['title', 'status', 'published_at', 'body']));
        $this->assertSame(
            987654,
            (int) DB::table('article_daily_views')->where('article_translation_id', $translation->id)->where('day', $today)->value('views'),
            'real view counts are not overwritten',
        );
        Storage::disk('public')->assertExists($article->hero_image);
    }

    public function test_it_draws_real_hero_images_with_gd_for_pictures_that_do_not_exist_yet(): void
    {
        $drawn = ['riverside-promenade', 'school-meals-pilot'];
        $this->putPlaceholderPictures($drawn);

        $this->seed(DemoContentSeeder::class);

        foreach ($drawn as $key) {
            $path = "articles/demo/{$key}.jpg";
            $info = getimagesizefromstring(Storage::disk('public')->get($path));
            $this->assertSame([1600, 900, IMAGETYPE_JPEG], [$info[0], $info[1], $info[2]], $key);

            foreach (array_keys(HeroImages::SIZES) as $size) {
                Storage::disk('public')->assertExists(HeroImages::variantPath($path, $size));
            }
        }

        $this->assertSame('placeholder', Storage::disk('public')->get('articles/demo/north-coast-rain-alert.jpg'), 'existing pictures are not redrawn');
        Storage::disk('public')->assertMissing('articles/demo/museum-night.jpg');
        $this->assertSame(16 + 15, ArticleTranslation::whereNotNull('hero_caption')->count(), 'a caption for each version of a story that has a picture');
    }
}
