<?php

namespace Tests\Feature\Site;

use App\Enums\Locale;
use App\Enums\TranslationStatus;
use App\Models\Article;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Feature\Site\Concerns\MakesStories;
use Tests\TestCase;

class ArticlePreviewTest extends TestCase
{
    use MakesStories, RefreshDatabase;

    public function test_the_author_can_proof_their_own_draft_with_a_banner_and_noindex(): void
    {
        $author = User::factory()->journalist()->create();
        $t = $this->makeStory(Locale::En, TranslationStatus::Draft, ['title' => 'Unfinished draft'], Article::factory()->create(['author_id' => $author->id]));

        $response = $this->actingAs($author)->get($t->url());

        $response->assertOk()
            ->assertSee('Unfinished draft')
            ->assertSee('Preview only. Status: Draft.')
            ->assertSee('<meta name="robots" content="noindex, nofollow">', false)
            ->assertHeader('X-Robots-Tag', 'noindex, nofollow')
            ->assertDontSee('application/ld+json', false)
            ->assertDontSee('rel="canonical"', false)
            ->assertDontSee('data-article-tool="copy"', false);
        $this->assertStringContainsString('no-store', $response->headers->get('Cache-Control'));
    }

    public function test_the_banner_is_in_arabic_on_the_arabic_edition(): void
    {
        $author = User::factory()->journalist()->create();
        $t = $this->makeStory(Locale::Ar, TranslationStatus::InReview, article: Article::factory()->create(['author_id' => $author->id]));

        $this->actingAs($author)->get($t->url())
            ->assertOk()
            ->assertSee('معاينة فقط. الحالة: قيد المراجعة.');
    }

    public function test_an_editor_can_proof_any_unpublished_status(): void
    {
        $editor = User::factory()->editor()->create();

        foreach ([TranslationStatus::Draft, TranslationStatus::InReview, TranslationStatus::ChangesRequested, TranslationStatus::Archived] as $status) {
            $t = $this->makeStory(Locale::En, $status);

            $this->actingAs($editor)->get($t->url())->assertOk()->assertSee('Preview only.');
        }
    }

    public function test_an_editor_can_proof_a_scheduled_story_and_the_banner_says_so(): void
    {
        $editor = User::factory()->editor()->create();
        $t = $this->makeStory(Locale::En, attributes: ['published_at' => now()->addDay()]);

        $this->actingAs($editor)->get($t->url())->assertOk()->assertSee('Preview only. Status: Scheduled.');
    }

    public function test_another_journalist_gets_a_404(): void
    {
        $author = User::factory()->journalist()->create();
        $other = User::factory()->journalist()->create();
        $t = $this->makeStory(Locale::En, TranslationStatus::InReview, article: Article::factory()->create(['author_id' => $author->id]));

        $this->actingAs($other)->get($t->url())->assertNotFound();
    }

    public function test_a_guest_gets_a_404(): void
    {
        $t = $this->makeStory(Locale::En, TranslationStatus::Draft);

        $this->get($t->url())->assertNotFound();
    }

    public function test_a_deactivated_staff_account_gets_a_404_even_for_their_own_draft(): void
    {
        $author = User::factory()->journalist()->inactive()->create();
        $t = $this->makeStory(Locale::En, TranslationStatus::Draft, article: Article::factory()->create(['author_id' => $author->id]));

        $this->actingAs($author)->get($t->url())->assertNotFound();
    }

    public function test_a_trashed_article_is_a_404_even_for_an_editor(): void
    {
        $editor = User::factory()->editor()->create();
        $t = $this->makeStory(Locale::En, TranslationStatus::Draft);
        $t->article->delete();

        $this->actingAs($editor)->get($t->url())->assertNotFound();
    }

    public function test_a_preview_is_not_counted_as_a_reader_view(): void
    {
        $editor = User::factory()->editor()->create();
        $t = $this->makeStory(Locale::En, TranslationStatus::Draft);

        $this->actingAs($editor)->get($t->url())->assertOk();

        $this->assertSame(0, DB::table('article_daily_views')->count());
    }

    public function test_preview_shows_no_related_stories_or_other_language_link(): void
    {
        $editor = User::factory()->editor()->create();
        $article = Article::factory()->create();
        $draft = $this->makeStory(Locale::En, TranslationStatus::Draft, article: $article);
        $this->makeStory(Locale::Ar, article: $article, attributes: ['title' => 'نسخة منشورة']);

        // The live Arabic sibling is public knowledge, so the link is fine; related stories are skipped in preview.
        $this->actingAs($editor)->get($draft->url())
            ->assertOk()
            ->assertDontSee('class="sn-related"', false);
    }

    public function test_a_published_story_viewed_by_staff_is_a_normal_public_page(): void
    {
        $editor = User::factory()->editor()->create();
        $t = $this->makeStory(Locale::En);

        $this->actingAs($editor)->get($t->url())
            ->assertOk()
            ->assertDontSee('Preview only.')
            ->assertSee('rel="canonical"', false);
    }
}
