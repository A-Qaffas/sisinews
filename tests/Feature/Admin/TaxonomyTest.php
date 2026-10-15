<?php

namespace Tests\Feature\Admin;

use App\Enums\Locale;
use App\Filament\Resources\Categories\Pages\CreateCategory;
use App\Filament\Resources\Categories\Pages\EditCategory;
use App\Filament\Resources\Categories\Pages\ListCategories;
use App\Filament\Resources\Tags\Pages\CreateTag;
use App\Filament\Resources\Tags\Pages\EditTag;
use App\Filament\Resources\Tags\Pages\ListTags;
use App\Filament\Support\Taxonomy\TagWriter;
use App\Models\Article;
use App\Models\Category;
use App\Models\CategoryTranslation;
use App\Models\Tag;
use App\Models\TagTranslation;
use App\Models\User;
use Livewire\Livewire;

class TaxonomyTest extends AdminTestCase
{
    public function test_an_editor_creates_a_category_with_both_languages_and_builds_slugs(): void
    {
        $this->actingAs(User::factory()->editor()->create());

        Livewire::test(CreateCategory::class)
            ->fillForm([
                'ar' => ['name' => 'اقتصاد مصر'],
                'en' => ['name' => 'Egyptian Economy'],
                'sort_order' => 7,
                'show_in_nav' => false,
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $category = Category::query()->with('translations')->firstOrFail();
        $this->assertSame(7, $category->sort_order);
        $this->assertFalse($category->show_in_nav);
        $this->assertSame('اقتصاد-مصر', $category->translate(Locale::Ar)->slug);
        $this->assertSame('egyptian-economy', $category->translate(Locale::En)->slug);
    }

    public function test_a_category_needs_both_names(): void
    {
        $this->actingAs(User::factory()->editor()->create());

        Livewire::test(CreateCategory::class)
            ->fillForm(['ar' => ['name' => 'اقتصاد'], 'en' => ['name' => '']])
            ->call('create')
            ->assertHasFormErrors(['en.name' => 'required']);

        $this->assertSame(0, Category::query()->count());
    }

    public function test_a_slug_cannot_be_reused_in_the_same_language_but_can_across_languages(): void
    {
        $this->actingAs(User::factory()->editor()->create());
        $existing = Category::factory()->create();
        $existing->translations()->where('locale', 'en')->update(['slug' => 'sport']);

        Livewire::test(CreateCategory::class)
            ->fillForm(['ar' => ['name' => 'رياضة'], 'en' => ['name' => 'Sport', 'slug' => 'Sport']])
            ->call('create')
            ->assertHasFormErrors(['en.slug']);

        Livewire::test(CreateCategory::class)
            ->fillForm(['ar' => ['name' => 'رياضة', 'slug' => 'sport'], 'en' => ['name' => 'Sports', 'slug' => 'sports']])
            ->call('create')
            ->assertHasNoFormErrors();
    }

    public function test_editing_a_category_keeps_its_own_slug_without_a_clash(): void
    {
        $this->actingAs(User::factory()->editor()->create());
        $category = Category::factory()->create();

        Livewire::test(EditCategory::class, ['record' => $category->getKey()])
            ->fillForm(['en' => ['name' => 'Renamed section'], 'sort_order' => 3])
            ->call('save')
            ->assertHasNoFormErrors();

        $category->refresh()->load('translations');
        $this->assertSame('Renamed section', $category->translate(Locale::En)->name);
        $this->assertSame("section-{$category->getKey()}", $category->translate(Locale::En)->slug);
        $this->assertSame(3, $category->sort_order);
    }

    public function test_an_automatic_slug_that_collides_gets_a_suffix(): void
    {
        $this->actingAs(User::factory()->editor()->create());
        $first = Category::factory()->create();
        $first->translations()->where('locale', 'en')->update(['slug' => 'world-news']);

        Livewire::test(CreateCategory::class)
            ->fillForm(['ar' => ['name' => 'أخبار العالم'], 'en' => ['name' => 'World News']])
            ->call('create')
            ->assertHasNoFormErrors();

        $this->assertTrue(CategoryTranslation::query()->where('locale', 'en')->where('slug', 'world-news-2')->exists());
    }

    public function test_a_category_in_use_cannot_be_deleted_but_an_empty_one_can(): void
    {
        $this->actingAs(User::factory()->editor()->create());
        $used = Category::factory()->create();
        $empty = Category::factory()->create();
        Article::factory()->create(['category_id' => $used->getKey()]);

        Livewire::test(ListCategories::class)
            ->callTableAction('delete', $used)
            ->assertNotified('This category still has articles');
        $this->assertModelExists($used);

        Livewire::test(ListCategories::class)->callTableAction('delete', $empty);
        $this->assertModelMissing($empty);
    }

    public function test_a_journalist_can_read_categories_and_tags_but_not_change_them(): void
    {
        $this->actingAs(User::factory()->journalist()->create());
        $category = Category::factory()->create();
        $tag = app(TagWriter::class)->findOrCreate('Economy', null);

        Livewire::test(ListCategories::class)
            ->assertSuccessful()
            ->assertTableActionHidden('delete', $category)
            ->assertTableActionHidden('edit', $category);
        Livewire::test(CreateCategory::class)->assertForbidden();
        Livewire::test(EditCategory::class, ['record' => $category->getKey()])->assertForbidden();

        Livewire::test(ListTags::class)
            ->assertSuccessful()
            ->assertTableActionHidden('delete', $tag)
            ->assertTableActionHidden('edit', $tag);
        Livewire::test(CreateTag::class)->assertForbidden();
        Livewire::test(EditTag::class, ['record' => $tag->getKey()])->assertForbidden();
    }

    public function test_an_editor_reorders_categories_by_dragging_but_a_journalist_cannot(): void
    {
        [$first, $second] = [Category::factory()->create(['sort_order' => 1]), Category::factory()->create(['sort_order' => 2])];

        $this->actingAs(User::factory()->journalist()->create());
        Livewire::test(ListCategories::class)->call('reorderTable', [$second->getKey(), $first->getKey()]);
        $this->assertSame([1, 2], [$first->fresh()->sort_order, $second->fresh()->sort_order]);

        $this->actingAs(User::factory()->editor()->create());
        Livewire::test(ListCategories::class)->call('reorderTable', [$second->getKey(), $first->getKey()]);
        $this->assertSame([2, 1], [$first->fresh()->sort_order, $second->fresh()->sort_order]);
    }

    public function test_the_category_and_tag_lists_do_not_run_a_query_per_row(): void
    {
        $this->actingAs(User::factory()->editor()->create());
        Category::factory()->count(2)->create();
        foreach (['A', 'B'] as $name) {
            app(TagWriter::class)->findOrCreate($name, null);
        }
        $categoriesFew = $this->queryCount(fn () => Livewire::test(ListCategories::class)->assertSuccessful());
        $tagsFew = $this->queryCount(fn () => Livewire::test(ListTags::class)->assertSuccessful());

        Category::factory()->count(6)->create();
        foreach (['C', 'D', 'E', 'F', 'G', 'H'] as $name) {
            app(TagWriter::class)->findOrCreate($name, 'عربي '.$name);
        }

        $this->assertSame($categoriesFew, $this->queryCount(fn () => Livewire::test(ListCategories::class)->assertSuccessful()));
        $this->assertSame($tagsFew, $this->queryCount(fn () => Livewire::test(ListTags::class)->assertSuccessful()));
    }

    public function test_an_editor_creates_a_tag_in_one_language(): void
    {
        $this->actingAs(User::factory()->editor()->create());

        Livewire::test(CreateTag::class)
            ->fillForm(['ar' => ['name' => 'اقتصاد']])
            ->call('create')
            ->assertHasNoFormErrors();

        $tag = Tag::query()->with('translations')->firstOrFail();
        $this->assertCount(1, $tag->translations);
        $this->assertSame('اقتصاد', $tag->translations->first()->slug);
    }

    public function test_a_tag_needs_at_least_one_name(): void
    {
        $this->actingAs(User::factory()->editor()->create());

        Livewire::test(CreateTag::class)
            ->fillForm([])
            ->call('create')
            ->assertHasFormErrors(['ar.name', 'en.name']);

        $this->assertSame(0, Tag::query()->count());
    }

    public function test_clearing_one_name_of_a_tag_removes_that_language_only(): void
    {
        $this->actingAs(User::factory()->editor()->create());
        $tag = app(TagWriter::class)->findOrCreate('Economy', 'اقتصاد');

        Livewire::test(EditTag::class, ['record' => $tag->getKey()])
            ->fillForm(['ar' => ['name' => '', 'slug' => '']])
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertSame(['en'], $tag->translations()->get()->map(fn ($t) => $t->locale->value)->all());
    }

    public function test_deleting_a_tag_detaches_it_from_articles(): void
    {
        $this->actingAs(User::factory()->editor()->create());
        $tag = app(TagWriter::class)->findOrCreate('Economy', null);
        $article = Article::factory()->create();
        $article->tags()->attach($tag);

        Livewire::test(ListTags::class)->callTableAction('delete', $tag);

        $this->assertModelMissing($tag);
        $this->assertSame(0, $article->tags()->count());
        $this->assertModelExists($article);
    }

    public function test_creating_a_tag_twice_reuses_the_existing_one(): void
    {
        $writer = app(TagWriter::class);

        $first = $writer->findOrCreate('Economy', 'اقتصاد');
        $again = $writer->findOrCreate('economy', null);
        $arabicOnly = $writer->findOrCreate(null, 'اقتصاد');

        $this->assertTrue($first->is($again));
        $this->assertTrue($first->is($arabicOnly));
        $this->assertSame(1, Tag::query()->count());
        $this->assertSame(2, TagTranslation::query()->count());
    }
}
