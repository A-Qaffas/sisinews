<?php

namespace Tests\Feature\Admin;

use App\Enums\Locale;
use App\Enums\TranslationStatus;
use App\Filament\Resources\Articles\ArticleResource;
use App\Filament\Resources\Articles\Pages\CreateArticle;
use App\Filament\Resources\Articles\Pages\EditArticle;
use App\Filament\Resources\Articles\Support\ArticleWriter;
use App\Models\Article;
use App\Models\ArticleTranslation;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;

class ArticlePermissionsTest extends AdminTestCase
{
    public function test_a_journalist_cannot_open_another_journalists_story(): void
    {
        $mine = User::factory()->journalist()->create();
        $foreign = $this->story(User::factory()->journalist()->create());

        $this->actingAs($mine)
            ->get(ArticleResource::getUrl('edit', ['record' => $foreign]))
            ->assertNotFound();
    }

    public function test_a_journalist_can_open_their_own_story_even_when_it_can_no_longer_be_edited(): void
    {
        $author = User::factory()->journalist()->create();
        $article = $this->story($author, TranslationStatus::InReview, TranslationStatus::Published);

        $this->actingAs($author)
            ->get(ArticleResource::getUrl('edit', ['record' => $article]))
            ->assertOk();
    }

    public function test_an_author_cannot_edit_a_version_that_is_in_review_even_with_a_crafted_payload(): void
    {
        $author = User::factory()->journalist()->create();
        $article = $this->story($author, TranslationStatus::InReview, TranslationStatus::Draft);
        $titleBefore = $this->version($article, Locale::Ar)->title;
        $this->actingAs($author);

        Livewire::test(EditArticle::class, ['record' => $article->getKey()])
            ->set('data.ar.title', 'Hacked while in review')
            ->set('data.en.title', 'Edited English draft')
            ->call('save');

        $this->assertSame($titleBefore, $this->version($article, Locale::Ar)->title);
        $this->assertSame('Edited English draft', $this->version($article, Locale::En)->title, 'the English draft is still theirs to edit');
    }

    public function test_the_shared_shell_is_locked_for_the_author_once_any_version_is_in_review(): void
    {
        $author = User::factory()->journalist()->create();
        $article = $this->story($author, TranslationStatus::InReview, TranslationStatus::Draft);
        $categoryBefore = $article->category_id;
        $this->actingAs($author);

        Livewire::test(EditArticle::class, ['record' => $article->getKey()])
            ->set('data.category_id', $this->category()->getKey())
            ->set('data.is_breaking', true)
            ->call('save');

        $article->refresh();
        $this->assertSame($categoryBefore, $article->category_id);
        $this->assertFalse($article->is_breaking);
    }

    public function test_an_author_can_add_the_other_language_while_one_is_in_review(): void
    {
        $author = User::factory()->journalist()->create();
        $article = $this->story($author, TranslationStatus::InReview, null);
        $this->actingAs($author);

        Livewire::test(EditArticle::class, ['record' => $article->getKey()])
            ->fillForm(['en' => ['title' => 'The English version', 'body' => '<p>Text</p>']])
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertSame(TranslationStatus::Draft, $this->version($article, Locale::En)->status);
        $this->assertSame(TranslationStatus::InReview, $this->version($article, Locale::Ar)->status);
    }

    public function test_a_reviewer_can_edit_any_story_in_any_state(): void
    {
        $editor = User::factory()->editor()->create();
        $article = $this->story(User::factory()->journalist()->create(), TranslationStatus::InReview, TranslationStatus::Published);
        $this->actingAs($editor);

        Livewire::test(EditArticle::class, ['record' => $article->getKey()])
            ->fillForm(['ar' => ['title' => 'عنوان صححه المحرر'], 'en' => ['title' => 'Corrected by the editor']])
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertSame('عنوان صححه المحرر', $this->version($article, Locale::Ar)->title);
        $this->assertSame('Corrected by the editor', $this->version($article, Locale::En)->title);
    }

    public function test_a_reviewer_can_reassign_the_author_when_editing(): void
    {
        $editor = User::factory()->editor()->create();
        $newAuthor = User::factory()->journalist()->create();
        $article = $this->story(User::factory()->journalist()->create());
        $this->actingAs($editor);

        Livewire::test(EditArticle::class, ['record' => $article->getKey()])
            ->fillForm(['author_id' => $newAuthor->getKey()])
            ->call('save');

        $this->assertSame($newAuthor->getKey(), $article->fresh()->author_id);
    }

    public function test_a_reviewer_cannot_hand_a_story_to_a_deactivated_account(): void
    {
        $editor = User::factory()->editor()->create();
        $inactive = User::factory()->journalist()->inactive()->create();
        $article = $this->story(User::factory()->journalist()->create());
        $original = $article->author_id;
        $this->actingAs($editor);

        Livewire::test(EditArticle::class, ['record' => $article->getKey()])
            ->set('data.author_id', $inactive->getKey())
            ->call('save');

        $this->assertSame($original, $article->fresh()->author_id);
    }

    public function test_status_cannot_be_forced_through_a_crafted_create_payload(): void
    {
        $category = $this->category();
        $this->actingAs($this->journalistIn($category));

        Livewire::test(CreateArticle::class)
            ->fillForm([
                'category_id' => $category->getKey(),
                'ar' => ['title' => 'عنوان', 'body' => '<p>نص</p>', 'status' => 'published', 'published_at' => now()->subDay()->toDateTimeString()],
                'status' => 'published',
            ])
            ->set('data.status', 'published')
            ->set('data.ar.status', 'published')
            ->set('data.ar.published_at', now()->subDay()->toDateTimeString())
            ->call('create');

        $version = ArticleTranslation::query()->firstOrFail();
        $this->assertSame(TranslationStatus::Draft, $version->status);
        $this->assertNull($version->published_at);
        $this->assertSame(0, ArticleTranslation::visible()->count());
    }

    public function test_status_cannot_be_forced_through_a_crafted_edit_payload(): void
    {
        $author = User::factory()->journalist()->create();
        $article = $this->story($author);
        $this->actingAs($author);

        Livewire::test(EditArticle::class, ['record' => $article->getKey()])
            ->set('data.ar.status', 'published')
            ->set('data.en.status', 'published')
            ->set('data.status', 'published')
            ->set('data.ar.published_at', now()->subDay()->toDateTimeString())
            ->call('save');

        foreach (Locale::cases() as $locale) {
            $version = $this->version($article, $locale);
            $this->assertSame(TranslationStatus::Draft, $version->status);
            $this->assertNull($version->published_at);
        }
    }

    public function test_the_writer_ignores_forged_data_for_parts_the_user_may_not_edit(): void
    {
        $author = User::factory()->journalist()->create();
        $article = $this->story($author, TranslationStatus::InReview, TranslationStatus::Draft);
        $other = User::factory()->journalist()->create();
        $original = $article->only(['category_id', 'author_id']);

        app(ArticleWriter::class)->update($article, [
            'category_id' => $this->category()->getKey(),
            'author_id' => $other->getKey(),
            'ar' => ['title' => 'forged', 'status' => 'published', 'published_at' => now()->toDateTimeString()],
            'en' => ['title' => 'allowed edit', 'status' => 'published'],
        ], $author);

        $this->assertSame($original, $article->fresh()->only(['category_id', 'author_id']));
        $this->assertNotSame('forged', $this->version($article, Locale::Ar)->title);
        $this->assertSame('allowed edit', $this->version($article, Locale::En)->title);
        $this->assertSame(TranslationStatus::InReview, $this->version($article, Locale::Ar)->status);
        $this->assertSame(TranslationStatus::Draft, $this->version($article, Locale::En)->status);
    }

    public function test_the_writer_refuses_someone_elses_story_translation_creation(): void
    {
        $owner = User::factory()->journalist()->create();
        $intruder = User::factory()->journalist()->create();
        $article = $this->story($owner, TranslationStatus::Draft, null);

        app(ArticleWriter::class)->update($article, ['en' => ['title' => 'Injected', 'body' => '<p>x</p>']], $intruder);

        $this->assertSame(1, $article->translations()->count());
    }

    public function test_hero_image_accepts_a_photo_and_rejects_svg_and_oversized_files(): void
    {
        Storage::fake('public');
        $category = $this->category();
        $this->actingAs($this->journalistIn($category));
        $base = ['category_id' => $category->getKey(), 'en' => ['title' => 'With image', 'body' => '<p>Text</p>']];

        Livewire::test(CreateArticle::class)
            ->fillForm([...$base, 'hero_image' => UploadedFile::fake()->create('logo.svg', 10, 'image/svg+xml')])
            ->call('create')
            ->assertHasFormErrors(['hero_image']);

        Livewire::test(CreateArticle::class)
            ->fillForm([...$base, 'hero_image' => UploadedFile::fake()->image('huge.jpg')->size(6000)])
            ->call('create')
            ->assertHasFormErrors(['hero_image']);

        $this->assertSame(0, Article::query()->count());

        Livewire::test(CreateArticle::class)
            ->fillForm([...$base, 'hero_image' => UploadedFile::fake()->image('photo.jpg', 800, 600)->size(300)])
            ->call('create')
            ->assertHasNoFormErrors();

        $path = Article::query()->firstOrFail()->hero_image;
        $this->assertStringStartsWith('articles/', $path);
        Storage::disk('public')->assertExists($path);
    }
}
