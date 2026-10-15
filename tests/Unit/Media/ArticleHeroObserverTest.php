<?php

namespace Tests\Unit\Media;

use App\Models\Article;
use App\Support\Media\HeroImages;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;
use Tests\Unit\Media\Concerns\MakesImages;

class ArticleHeroObserverTest extends TestCase
{
    use MakesImages, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->fakePublicDisk();
    }

    private function upload(string $name, int $width = 900, int $height = 600): string
    {
        $path = "articles/{$name}.jpg";
        Storage::disk('public')->put($path, $this->jpegBytes($width, $height));

        return $path;
    }

    private function assertVariantsExist(string $path): void
    {
        foreach (array_keys(HeroImages::SIZES) as $size) {
            Storage::disk('public')->assertExists(HeroImages::variantPath($path, $size));
        }
    }

    private function assertNothingLeftOf(string $path): void
    {
        Storage::disk('public')->assertMissing($path);
        foreach (array_keys(HeroImages::SIZES) as $size) {
            Storage::disk('public')->assertMissing(HeroImages::variantPath($path, $size));
        }
    }

    public function test_creating_an_article_with_a_hero_builds_the_variants(): void
    {
        $path = $this->upload('first');

        Article::factory()->create(['hero_image' => $path]);

        $this->assertVariantsExist($path);
        Storage::disk('public')->assertExists($path);
    }

    public function test_creating_an_article_without_a_hero_touches_no_files(): void
    {
        Article::factory()->create(['hero_image' => null]);

        $this->assertSame([], Storage::disk('public')->allFiles());
    }

    public function test_replacing_the_hero_builds_the_new_variants_and_removes_the_old_files(): void
    {
        $old = $this->upload('old');
        $new = $this->upload('new');
        $article = Article::factory()->create(['hero_image' => $old]);
        $this->assertVariantsExist($old);

        $article->update(['hero_image' => $new]);

        $this->assertVariantsExist($new);
        Storage::disk('public')->assertExists($new);
        $this->assertNothingLeftOf($old);
    }

    public function test_removing_the_hero_deletes_its_files(): void
    {
        $path = $this->upload('gone');
        $article = Article::factory()->create(['hero_image' => $path]);

        $article->update(['hero_image' => null]);

        $this->assertNothingLeftOf($path);
    }

    public function test_adding_a_hero_to_an_existing_article_builds_the_variants(): void
    {
        $article = Article::factory()->create(['hero_image' => null]);
        $path = $this->upload('late');

        $article->update(['hero_image' => $path]);

        $this->assertVariantsExist($path);
    }

    public function test_other_changes_leave_the_files_alone(): void
    {
        $path = $this->upload('stable');
        $article = Article::factory()->create(['hero_image' => $path]);
        Storage::disk('public')->put(HeroImages::variantPath($path, 'card'), 'sentinel');

        $article->update(['is_featured' => true]);

        $this->assertSame('sentinel', Storage::disk('public')->get(HeroImages::variantPath($path, 'card')));
    }

    public function test_a_shared_image_survives_while_another_article_still_uses_it(): void
    {
        $shared = $this->upload('shared');
        $first = Article::factory()->create(['hero_image' => $shared]);
        Article::factory()->create(['hero_image' => $shared]);

        $first->update(['hero_image' => $this->upload('other')]);

        Storage::disk('public')->assertExists($shared);
        $this->assertVariantsExist($shared);
    }

    public function test_a_soft_deleted_article_keeps_its_files(): void
    {
        $path = $this->upload('soft');
        $article = Article::factory()->create(['hero_image' => $path]);

        $article->delete();

        Storage::disk('public')->assertExists($path);
        $this->assertVariantsExist($path);
    }

    public function test_force_deleting_removes_the_original_and_the_variants(): void
    {
        $path = $this->upload('forever');
        $article = Article::factory()->create(['hero_image' => $path]);
        Storage::disk('public')->put('articles/unrelated.jpg', 'keep');

        $article->forceDelete();

        $this->assertNothingLeftOf($path);
        Storage::disk('public')->assertExists('articles/unrelated.jpg');
    }

    public function test_force_deleting_keeps_files_another_article_still_uses(): void
    {
        $shared = $this->upload('shared');
        $article = Article::factory()->create(['hero_image' => $shared]);
        Article::factory()->create(['hero_image' => $shared]);

        $article->forceDelete();

        Storage::disk('public')->assertExists($shared);
    }

    public function test_force_deleting_an_article_without_a_hero_is_a_no_op(): void
    {
        $article = Article::factory()->create(['hero_image' => null]);

        $article->forceDelete();

        $this->assertModelMissing($article);
    }

    public function test_a_broken_upload_never_stops_the_article_from_saving(): void
    {
        Log::spy();
        Storage::disk('public')->put('articles/broken.jpg', 'definitely not a jpeg');

        $article = Article::factory()->create(['hero_image' => 'articles/broken.jpg']);

        $this->assertModelExists($article);
        Log::shouldHaveReceived('warning')->once();
        $this->assertStringEndsWith('/articles/broken.jpg', HeroImages::url($article->hero_image));
    }

    public function test_a_hostile_stored_path_is_logged_and_nothing_is_touched(): void
    {
        Log::spy();
        Storage::disk('public')->put('keep.txt', 'safe');

        $article = Article::factory()->create(['hero_image' => '../../.env']);
        $article->update(['hero_image' => null]);
        $article->forceDelete();

        $this->assertSame(['keep.txt'], Storage::disk('public')->allFiles());
    }
}
