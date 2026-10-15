<?php

namespace Tests\Feature\Site;

use App\Enums\Locale;
use App\Enums\TranslationStatus;
use App\Models\Article;
use App\Models\Category;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Feature\Site\Concerns\MakesStories;
use Tests\TestCase;

class CategoryPageTest extends TestCase
{
    use MakesStories, RefreshDatabase;

    private function inCategory(Category $category): Article
    {
        return Article::factory()->create(['category_id' => $category->id]);
    }

    public function test_it_lists_visible_stories_newest_first_in_english(): void
    {
        $category = Category::factory()->create();
        $this->makeStory(Locale::En, attributes: ['title' => 'Older story', 'published_at' => now()->subDays(2)], article: $this->inCategory($category));
        $this->makeStory(Locale::En, attributes: ['title' => 'Newer story', 'published_at' => now()->subHour()], article: $this->inCategory($category));

        $response = $this->get("/en/category/section-{$category->id}")->assertOk()->assertSee('Section '.$category->id);

        $response->assertSeeInOrder(['Newer story', 'Older story']);
    }

    public function test_it_lists_arabic_stories_under_the_arabic_slug(): void
    {
        $category = Category::factory()->create();
        $this->makeStory(Locale::Ar, attributes: ['title' => 'خبر القسم'], article: $this->inCategory($category));

        $this->get("/ar/category/قسم-{$category->id}")
            ->assertOk()
            ->assertSee('قسم '.$category->id)
            ->assertSee('خبر القسم');
    }

    public function test_it_hides_drafts_future_stories_other_languages_other_categories_and_trashed_articles(): void
    {
        $category = Category::factory()->create();
        $other = Category::factory()->create();
        $this->makeStory(Locale::En, attributes: ['title' => 'Shown story'], article: $this->inCategory($category));
        $this->makeStory(Locale::En, TranslationStatus::Draft, ['title' => 'Hidden draft'], $this->inCategory($category));
        $this->makeStory(Locale::En, TranslationStatus::InReview, ['title' => 'Hidden review'], $this->inCategory($category));
        $this->makeStory(Locale::En, TranslationStatus::Archived, ['title' => 'Hidden archived'], $this->inCategory($category));
        $this->makeStory(Locale::En, attributes: ['title' => 'Hidden future', 'published_at' => now()->addDay()], article: $this->inCategory($category));
        $this->makeStory(Locale::Ar, attributes: ['title' => 'خبر عربي مخفي'], article: $this->inCategory($category));
        $this->makeStory(Locale::En, attributes: ['title' => 'Hidden elsewhere'], article: $this->inCategory($other));
        $trashed = $this->makeStory(Locale::En, attributes: ['title' => 'Hidden trashed'], article: $this->inCategory($category));
        $trashed->article->delete();

        $this->get("/en/category/section-{$category->id}")
            ->assertOk()
            ->assertSee('Shown story')
            ->assertDontSee('Hidden draft')
            ->assertDontSee('Hidden review')
            ->assertDontSee('Hidden archived')
            ->assertDontSee('Hidden future')
            ->assertDontSee('خبر عربي مخفي')
            ->assertDontSee('Hidden elsewhere')
            ->assertDontSee('Hidden trashed');
    }

    public function test_an_unknown_slug_is_a_404_and_the_other_languages_slug_is_too(): void
    {
        $category = Category::factory()->create();

        $this->get('/en/category/no-such-section')->assertNotFound();
        $this->get("/en/category/قسم-{$category->id}")->assertNotFound();
        $this->get("/ar/category/section-{$category->id}")->assertNotFound();
    }

    public function test_an_empty_category_shows_a_friendly_empty_state(): void
    {
        $category = Category::factory()->create();

        $this->get("/en/category/section-{$category->id}")
            ->assertOk()
            ->assertSee('No stories in this section yet')
            ->assertDontSee('class="sn-pager"', false);
        $this->get("/ar/category/قسم-{$category->id}")
            ->assertOk()
            ->assertSee('لا توجد أخبار في هذا القسم بعد');
    }

    public function test_it_paginates_twelve_per_page_and_404s_past_the_last_page(): void
    {
        $category = Category::factory()->create();
        foreach (range(1, 13) as $i) {
            $this->makeStory(Locale::En, attributes: ['title' => sprintf('Paged story %02d', $i), 'published_at' => now()->subMinutes(100 - $i)], article: $this->inCategory($category));
        }
        $url = "/en/category/section-{$category->id}";

        $first = $this->get($url)->assertOk();
        $this->assertSame(12, substr_count($first->getContent(), 'Paged story'));
        $first->assertSee('Paged story 13')->assertDontSee('Paged story 01');
        $first->assertSee('class="sn-pager"', false)->assertSee('rel="next"', false);

        $second = $this->get("{$url}?page=2")->assertOk();
        $this->assertSame(1, substr_count($second->getContent(), 'Paged story'));
        $second->assertSee('Paged story 01')->assertSee('rel="prev"', false);

        $this->get("{$url}?page=3")->assertNotFound();
    }

    public function test_hostile_page_numbers_do_not_crash_the_page(): void
    {
        $category = Category::factory()->create();
        $this->makeStory(Locale::En, article: $this->inCategory($category));
        $url = "/en/category/section-{$category->id}";

        $this->get("{$url}?page=abc")->assertOk();
        $this->get("{$url}?page=-4")->assertOk();
        $this->get("{$url}?page[]=2")->assertOk();
        $this->assertContains($this->get("{$url}?page=99999999999999999999")->getStatusCode(), [200, 404]);
    }

    public function test_head_carries_canonical_hreflang_and_a_description(): void
    {
        $category = Category::factory()->create();
        $en = "http://localhost:8000/en/category/section-{$category->id}";

        $response = $this->get($en)->assertOk();

        $response->assertSee('<link rel="canonical" href="'.$en.'">', false)
            ->assertSee('<link rel="alternate" hreflang="en" href="'.$en.'">', false)
            ->assertSee('<link rel="alternate" hreflang="ar" href="'.route('category', ['locale' => 'ar', 'slug' => "قسم-{$category->id}"]).'">', false)
            ->assertSee('hreflang="x-default" href="'.route('category', ['locale' => 'ar', 'slug' => "قسم-{$category->id}"]).'"', false)
            ->assertSee('<meta name="description" content="The latest Section '.$category->id.' news and reports from sisi.news.">', false);
    }

    public function test_the_language_switcher_jumps_to_the_same_section(): void
    {
        $category = Category::factory()->create();

        $this->get("/en/category/section-{$category->id}")
            ->assertSee('class="lang-switch" href="'.route('category', ['locale' => 'ar', 'slug' => "قسم-{$category->id}"]).'"', false);
    }

    public function test_later_pages_have_their_own_canonical_and_no_hreflang(): void
    {
        $category = Category::factory()->create();
        foreach (range(1, 13) as $i) {
            $this->makeStory(Locale::En, article: $this->inCategory($category));
        }
        $base = "http://localhost:8000/en/category/section-{$category->id}";

        $this->get("{$base}?page=2")
            ->assertOk()
            ->assertSee('<link rel="canonical" href="'.$base.'?page=2">', false)
            ->assertDontSee('<link rel="alternate" hreflang="ar"', false);
    }

    public function test_the_arabic_pager_is_labelled_in_arabic(): void
    {
        $category = Category::factory()->create();
        foreach (range(1, 13) as $i) {
            $this->makeStory(Locale::Ar, article: $this->inCategory($category));
        }

        $this->get("/ar/category/قسم-{$category->id}")
            ->assertOk()
            ->assertSee('الصفحة 1 من 2')
            ->assertSee('التالي')
            ->assertSee('السابق');
    }

    public function test_query_count_does_not_grow_with_the_number_of_stories(): void
    {
        $category = Category::factory()->create();
        $url = "/en/category/section-{$category->id}";
        foreach (range(1, 3) as $i) {
            $this->makeStory(Locale::En, article: $this->inCategory($category));
        }

        $this->get($url);
        DB::flushQueryLog();
        DB::enableQueryLog();
        $this->get($url)->assertOk();
        $few = count(DB::getQueryLog());

        foreach (range(1, 9) as $i) {
            $this->makeStory(Locale::En, article: $this->inCategory($category));
        }
        DB::flushQueryLog();
        $this->get($url)->assertOk();

        $this->assertSame($few, count(DB::getQueryLog()), 'listing queries are eager-loaded, not per story');
    }
}
