<?php

namespace Tests\Feature\Admin;

use App\Enums\Locale;
use App\Enums\TranslationStatus;
use App\Filament\Resources\Articles\Pages\CreateArticle;
use App\Filament\Resources\Articles\Pages\EditArticle;
use App\Filament\Resources\Articles\Pages\ListArticles;
use App\Models\Article;
use App\Models\User;
use Livewire\Livewire;

class ArticleAuthoringTest extends AdminTestCase
{
    public function test_journalist_creates_a_bilingual_story_and_submits_arabic_only(): void
    {
        $category = $this->category();
        $journalist = $this->journalistIn($category);
        $this->actingAs($journalist);

        Livewire::test(CreateArticle::class)
            ->fillForm([
                'category_id' => $category->getKey(),
                'ar' => ['title' => 'عنوان الخبر الأول', 'body' => '<p>نص الخبر</p>'],
                'en' => ['title' => 'The first headline', 'body' => '<p>The story text</p>'],
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $article = Article::query()->firstOrFail();
        $this->assertSame($journalist->getKey(), $article->author_id);
        $this->assertSame(TranslationStatus::Draft, $this->version($article, Locale::Ar)->status);
        $this->assertSame(TranslationStatus::Draft, $this->version($article, Locale::En)->status);
        $this->assertSame('عنوان-الخبر-الأول', $this->version($article, Locale::Ar)->slug, 'the slug is built from the headline when left blank');

        Livewire::test(EditArticle::class, ['record' => $article->getKey()])
            ->assertActionVisible('ar_submit')
            ->assertActionVisible('en_submit')
            ->assertActionHidden('ar_approve')
            ->assertActionHidden('ar_publish_now')
            ->callAction('ar_submit');

        $this->assertSame(TranslationStatus::InReview, $this->version($article, Locale::Ar)->status);
        $this->assertSame(TranslationStatus::Draft, $this->version($article, Locale::En)->status, 'the English version is untouched');
        $this->assertSame(1, $this->version($article, Locale::Ar)->statusChanges()->count(), 'the move is audited');
    }

    public function test_a_story_may_exist_in_one_language_only(): void
    {
        $category = $this->category();
        $this->actingAs($this->journalistIn($category));

        Livewire::test(CreateArticle::class)
            ->fillForm([
                'category_id' => $category->getKey(),
                'en' => ['title' => 'English only', 'body' => '<p>Text</p>'],
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $article = Article::query()->firstOrFail();
        $this->assertCount(1, $article->translations);
        $this->assertSame(Locale::En, $article->translations->first()->locale);
    }

    public function test_a_story_needs_a_headline_in_at_least_one_language(): void
    {
        $category = $this->category();
        $this->actingAs($this->journalistIn($category));

        Livewire::test(CreateArticle::class)
            ->fillForm(['category_id' => $category->getKey()])
            ->call('create')
            ->assertHasFormErrors(['ar.title' => 'required']);

        $this->assertSame(0, Article::query()->count());
    }

    public function test_a_half_filled_language_must_have_a_headline(): void
    {
        $category = $this->category();
        $this->actingAs($this->journalistIn($category));

        Livewire::test(CreateArticle::class)
            ->fillForm([
                'category_id' => $category->getKey(),
                'ar' => ['title' => 'عنوان', 'body' => '<p>نص</p>'],
                'en' => ['excerpt' => 'A summary without a headline'],
            ])
            ->call('create')
            ->assertHasFormErrors(['en.title' => 'required']);
    }

    public function test_an_explicit_slug_is_kept_and_normalised(): void
    {
        $category = $this->category();
        $this->actingAs($this->journalistIn($category));

        Livewire::test(CreateArticle::class)
            ->fillForm([
                'category_id' => $category->getKey(),
                'en' => ['title' => 'Budget talks stall', 'slug' => 'Budget  Talks!!', 'body' => '<p>Text</p>'],
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $this->assertSame('budget-talks', Article::query()->firstOrFail()->translations->first()->slug);
    }

    public function test_the_author_is_the_signed_in_user_even_if_a_payload_names_someone_else(): void
    {
        $category = $this->category();
        $journalist = $this->journalistIn($category);
        $other = User::factory()->journalist()->create();
        $this->actingAs($journalist);

        Livewire::test(CreateArticle::class)
            ->fillForm([
                'category_id' => $category->getKey(),
                'author_id' => $other->getKey(),
                'en' => ['title' => 'Mine', 'body' => '<p>Text</p>'],
            ])
            ->call('create');

        $this->assertSame($journalist->getKey(), Article::query()->firstOrFail()->author_id);
    }

    public function test_a_reviewer_may_reassign_the_author(): void
    {
        $editor = User::factory()->editor()->create();
        $writer = User::factory()->journalist()->create();
        $this->actingAs($editor);

        Livewire::test(CreateArticle::class)
            ->fillForm([
                'category_id' => $this->category()->getKey(),
                'author_id' => $writer->getKey(),
                'en' => ['title' => 'Assigned', 'body' => '<p>Text</p>'],
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $this->assertSame($writer->getKey(), Article::query()->firstOrFail()->author_id);
    }

    public function test_an_author_cannot_reassign_a_story_when_editing(): void
    {
        $journalist = User::factory()->journalist()->create();
        $other = User::factory()->journalist()->create();
        $article = $this->story($journalist);
        $this->actingAs($journalist);

        Livewire::test(EditArticle::class, ['record' => $article->getKey()])
            ->fillForm(['author_id' => $other->getKey()])
            ->call('save');

        $this->assertSame($journalist->getKey(), $article->fresh()->author_id);
    }

    public function test_editing_saves_one_language_without_touching_the_other(): void
    {
        $journalist = User::factory()->journalist()->create();
        $article = $this->story($journalist);
        $before = $this->version($article, Locale::En)->only(['title', 'body']);
        $this->actingAs($journalist);

        Livewire::test(EditArticle::class, ['record' => $article->getKey()])
            ->fillForm(['ar' => ['title' => 'عنوان معدل']])
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertSame('عنوان معدل', $this->version($article, Locale::Ar)->title);
        $this->assertSame($before, $this->version($article, Locale::En)->only(['title', 'body']));
    }

    public function test_tags_are_attached_and_a_new_tag_can_be_created_from_the_form(): void
    {
        $category = $this->category();
        $this->actingAs($this->journalistIn($category));

        $component = Livewire::test(CreateArticle::class)
            ->fillForm([
                'category_id' => $category->getKey(),
                'en' => ['title' => 'Tagged', 'body' => '<p>Text</p>'],
            ])
            ->mountFormComponentAction('tags', 'createOption')
            ->setFormComponentActionData(['name_en' => 'Economy', 'name_ar' => 'اقتصاد'])
            ->callMountedFormComponentAction();

        $component->call('create')->assertHasNoFormErrors();

        $article = Article::query()->with('tags.translations')->firstOrFail();
        $this->assertCount(1, $article->tags);
        $this->assertEqualsCanonicalizing(['Economy', 'اقتصاد'], $article->tags->first()->translations->pluck('name')->all());
    }

    public function test_a_journalist_sees_only_their_own_stories_in_the_list(): void
    {
        $mine = User::factory()->journalist()->create();
        $other = User::factory()->journalist()->create();
        $ownStory = $this->story($mine);
        $foreignStory = $this->story($other);
        $this->actingAs($mine);

        Livewire::test(ListArticles::class)
            ->assertCanSeeTableRecords([$ownStory])
            ->assertCanNotSeeTableRecords([$foreignStory]);
    }

    public function test_the_list_can_be_searched_by_either_headline(): void
    {
        $author = User::factory()->journalist()->create();
        $article = $this->story($author);
        $this->version($article, Locale::Ar)->update(['title' => 'ارتفاع الصادرات المصرية']);
        $this->version($article, Locale::En)->update(['title' => 'Egyptian exports climb']);
        $other = $this->story($author);
        $this->version($other, Locale::En)->update(['title' => 'Unrelated headline']);
        $this->version($other, Locale::Ar)->update(['title' => 'خبر آخر']);
        $this->actingAs($author);

        Livewire::test(ListArticles::class)
            ->searchTable('exports')
            ->assertCanSeeTableRecords([$article])
            ->assertCanNotSeeTableRecords([$other])
            ->searchTable('الصادرات')
            ->assertCanSeeTableRecords([$article])
            ->assertCanNotSeeTableRecords([$other]);
    }

    public function test_the_list_filters_by_language_status(): void
    {
        $author = User::factory()->journalist()->create();
        $review = $this->story($author, TranslationStatus::InReview, TranslationStatus::Draft);
        $drafts = $this->story($author, TranslationStatus::Draft, TranslationStatus::Draft);
        $this->actingAs($author);

        Livewire::test(ListArticles::class)
            ->filterTable('status', TranslationStatus::InReview->value)
            ->assertCanSeeTableRecords([$review])
            ->assertCanNotSeeTableRecords([$drafts]);
    }

    public function test_the_list_does_not_run_a_query_per_row(): void
    {
        $editor = User::factory()->editor()->create();
        $author = User::factory()->journalist()->create();
        $this->actingAs($editor);

        foreach (range(1, 3) as $i) {
            $this->story($author);
        }
        $few = $this->queryCount(fn () => Livewire::test(ListArticles::class)->assertSuccessful());

        foreach (range(1, 12) as $i) {
            $this->story($author);
        }
        $many = $this->queryCount(fn () => Livewire::test(ListArticles::class)->assertSuccessful());

        $this->assertSame($few, $many, 'query count must not grow with the number of rows');
    }

    public function test_a_reviewer_sees_every_story_in_the_list(): void
    {
        $editor = User::factory()->editor()->create();
        $a = $this->story(User::factory()->journalist()->create());
        $b = $this->story(User::factory()->journalist()->create());
        $this->actingAs($editor);

        Livewire::test(ListArticles::class)->assertCanSeeTableRecords([$a, $b]);
    }
}
