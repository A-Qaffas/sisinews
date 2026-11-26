<?php

namespace Tests\Feature\Site;

use App\Enums\Locale;
use App\Enums\TranslationStatus;
use App\Models\Article;
use App\Models\User;
use App\Translation\EnglishEdition;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Site\Concerns\MakesStories;
use Tests\TestCase;

/** Mona, 2026-11-26: clicking a story's writer opens all of that writer's stories. */
class WriterPageTest extends TestCase
{
    use MakesStories, RefreshDatabase;

    private function writer(): User
    {
        return User::factory()->journalist()->create(['name' => 'Mona Adel', 'name_ar' => 'منى عادل']);
    }

    private function by(User $writer): Article
    {
        return Article::factory()->create(['author_id' => $writer->id]);
    }

    public function test_it_lists_the_writers_visible_stories_newest_first_in_both_languages(): void
    {
        $writer = $this->writer();
        $older = $this->by($writer);
        $newer = $this->by($writer);
        $this->makeStory(Locale::Ar, attributes: ['title' => 'خبر أقدم', 'published_at' => now()->subDays(2)], article: $older);
        $this->makeStory(Locale::Ar, attributes: ['title' => 'خبر أحدث', 'published_at' => now()->subHour()], article: $newer);
        $this->makeStory(Locale::En, attributes: ['title' => 'Older story', 'published_at' => now()->subDays(2)], article: $older);
        $this->makeStory(Locale::En, attributes: ['title' => 'Newer story', 'published_at' => now()->subHour()], article: $newer);

        $this->get($writer->writerUrl(Locale::Ar))
            ->assertOk()
            ->assertSee('منى عادل')
            ->assertSeeInOrder(['خبر أحدث', 'خبر أقدم'])
            ->assertDontSee('Newer story');

        $this->get($writer->writerUrl(Locale::En))
            ->assertOk()
            ->assertSee('Mona Adel')
            ->assertSeeInOrder(['Newer story', 'Older story'])
            ->assertSee('<link rel="alternate" hreflang="ar" href="'.e($writer->writerUrl(Locale::Ar)).'">', false);
    }

    public function test_the_arabic_url_carries_the_arabic_name_and_a_wrong_slug_redirects(): void
    {
        $writer = $this->writer();
        $this->makeStory(Locale::Ar, article: $this->by($writer));

        $this->assertSame(route('writer', ['locale' => 'ar', 'idSlug' => "{$writer->id}-منى-عادل"]), $writer->writerUrl(Locale::Ar));
        $this->assertSame(url("/en/writer/{$writer->id}-mona-adel"), $writer->writerUrl(Locale::En));

        $this->get("/ar/writer/{$writer->id}-old-name")->assertStatus(301)->assertRedirect($writer->writerUrl(Locale::Ar));
        $this->get("/ar/writer/{$writer->id}")->assertStatus(301);
    }

    public function test_it_hides_drafts_future_stories_other_writers_and_trashed_articles(): void
    {
        $writer = $this->writer();
        $this->makeStory(Locale::En, attributes: ['title' => 'Shown story'], article: $this->by($writer));
        $this->makeStory(Locale::En, TranslationStatus::Draft, ['title' => 'Hidden draft'], $this->by($writer));
        $this->makeStory(Locale::En, TranslationStatus::InReview, ['title' => 'Hidden review'], $this->by($writer));
        $this->makeStory(Locale::En, attributes: ['title' => 'Hidden future', 'published_at' => now()->addDay()], article: $this->by($writer));
        $this->makeStory(Locale::En, attributes: ['title' => 'Hidden other writer']);
        $trashed = $this->makeStory(Locale::En, attributes: ['title' => 'Hidden trashed'], article: $this->by($writer));
        $trashed->article->delete();

        $response = $this->get($writer->writerUrl(Locale::En))->assertOk()->assertSee('Shown story');
        foreach (['Hidden draft', 'Hidden review', 'Hidden future', 'Hidden other writer', 'Hidden trashed'] as $hidden) {
            $response->assertDontSee($hidden);
        }
    }

    public function test_it_paginates_twelve_per_page_and_404s_past_the_end(): void
    {
        $writer = $this->writer();
        foreach (range(1, 13) as $i) {
            $this->makeStory(Locale::En, attributes: ['title' => "Story number {$i}", 'published_at' => now()->subMinutes($i)], article: $this->by($writer));
        }

        $url = $writer->writerUrl(Locale::En);
        $this->get($url)->assertOk()->assertSee('Story number 12<', false)->assertDontSee('Story number 13<', false);
        $this->get("{$url}?page=2")->assertOk()->assertSee('Story number 13<', false)
            ->assertSee('<link rel="canonical" href="'.e("{$url}?page=2").'">', false);
        $this->get("{$url}?page=3")->assertNotFound();
    }

    public function test_unknown_writers_and_writers_with_nothing_published_are_a_404(): void
    {
        $this->get('/en/writer/999-nobody')->assertNotFound();

        $writer = $this->writer();
        $this->makeStory(Locale::En, TranslationStatus::Draft, article: $this->by($writer));
        $this->get($writer->writerUrl(Locale::En))->assertNotFound();

        // Published in Arabic only: no English page.
        $this->makeStory(Locale::Ar, article: $this->by($writer));
        $this->get($writer->writerUrl(Locale::Ar))->assertOk();
        $this->get($writer->writerUrl(Locale::En))->assertNotFound();
    }

    public function test_the_auto_translation_account_never_gets_a_writer_page(): void
    {
        $system = User::factory()->create(['email' => EnglishEdition::ACCOUNT_EMAIL, 'name' => 'Auto-translation']);
        $this->makeStory(Locale::En, article: $this->by($system));

        $this->assertNull($system->writerUrl(Locale::En));
        $this->get("/en/writer/{$system->id}-auto-translation")->assertNotFound();
    }

    public function test_the_story_byline_links_to_the_writer_and_an_english_story_credits_the_arabic_writer(): void
    {
        $writer = $this->writer();
        $article = $this->by($writer);
        $arabic = $this->makeStory(Locale::Ar, attributes: ['title' => 'الخبر'], article: $article);
        // The English version is published by the system account, but the byline is the article's writer.
        $english = $this->makeStory(Locale::En, attributes: ['title' => 'The story'], article: $article);

        $this->get($arabic->url())->assertOk()
            ->assertSee('href="'.e($writer->writerUrl(Locale::Ar)).'" rel="author"', false)
            ->assertSee('منى عادل');

        $response = $this->get($english->url())->assertOk()
            ->assertSee('href="'.e($writer->writerUrl(Locale::En)).'" rel="author"', false)
            ->assertSee('Mona Adel')
            ->assertDontSee('Auto-translation');
        $this->assertSame($writer->writerUrl(Locale::En), $this->jsonLd($response->getContent())['author'][0]['url'] ?? null);

        // Cards with a byline (the writer page's own grid) link the name too.
        $this->get($writer->writerUrl(Locale::En))
            ->assertSee('class="story-byline-link" href="'.e($writer->writerUrl(Locale::En)).'"', false);
    }

    public function test_a_story_by_the_system_account_shows_its_name_without_a_link(): void
    {
        $system = User::factory()->create(['email' => EnglishEdition::ACCOUNT_EMAIL, 'name' => 'Auto-translation']);
        $story = $this->makeStory(Locale::En, article: $this->by($system));

        $this->get($story->url())->assertOk()->assertDontSee('rel="author"', false);
    }
}
