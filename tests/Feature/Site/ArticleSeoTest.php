<?php

namespace Tests\Feature\Site;

use App\Enums\Locale;
use App\Enums\TranslationStatus;
use App\Models\Article;
use App\Models\User;
use App\Support\ArticleSeo;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\Feature\Site\Concerns\MakesStories;
use Tests\TestCase;

class ArticleSeoTest extends TestCase
{
    use MakesStories, RefreshDatabase;

    public function test_the_description_prefers_meta_then_excerpt_then_the_start_of_the_body(): void
    {
        $withMeta = $this->makeStory(Locale::En, attributes: ['meta_description' => 'Hand written summary', 'excerpt' => 'The excerpt']);
        $withExcerpt = $this->makeStory(Locale::En, attributes: ['meta_description' => null, 'excerpt' => 'The excerpt']);
        $bodyOnly = $this->makeStory(Locale::En, attributes: [
            'meta_description' => null,
            'excerpt' => null,
            'body' => '<p>First &amp; foremost.</p><p>Second paragraph.</p><script>nope()</script>',
        ]);

        $this->get($withMeta->url())->assertSee('<meta name="description" content="Hand written summary">', false);
        $this->get($withExcerpt->url())->assertSee('<meta name="description" content="The excerpt">', false);
        $this->get($bodyOnly->url())->assertSee('<meta name="description" content="First &amp; foremost. Second paragraph.">', false);
    }

    public function test_a_long_body_is_trimmed_to_a_snippet_on_a_word_boundary(): void
    {
        $description = ArticleSeo::description($this->makeStory(Locale::En, attributes: [
            'meta_description' => null,
            'excerpt' => null,
            'body' => '<p>'.str_repeat('wordy ', 80).'</p>',
        ]));

        $this->assertLessThanOrEqual(161, mb_strlen($description));
        $this->assertStringEndsWith('…', $description);
        $this->assertStringNotContainsString('wordy…wordy', $description);
    }

    public function test_canonical_hreflang_alternates_and_x_default_point_at_arabic(): void
    {
        $article = Article::factory()->create();
        $en = $this->makeStory(Locale::En, article: $article);
        $ar = $this->makeStory(Locale::Ar, article: $article);

        foreach ([$en, $ar] as $page) {
            $this->get($page->url())
                ->assertOk()
                ->assertSee('<link rel="canonical" href="'.$page->url().'">', false)
                ->assertSee('<link rel="alternate" hreflang="ar" href="'.$ar->url().'">', false)
                ->assertSee('<link rel="alternate" hreflang="en" href="'.$en->url().'">', false)
                ->assertSee('<link rel="alternate" hreflang="x-default" href="'.$ar->url().'">', false);
        }
    }

    public function test_an_unpublished_sibling_is_not_advertised_and_x_default_falls_back_to_the_live_edition(): void
    {
        $article = Article::factory()->create();
        $en = $this->makeStory(Locale::En, article: $article);
        $this->makeStory(Locale::Ar, TranslationStatus::Draft, article: $article);

        $this->get($en->url())
            ->assertOk()
            ->assertDontSee('<link rel="alternate" hreflang="ar"', false)
            ->assertSee('<link rel="alternate" hreflang="en" href="'.$en->url().'">', false)
            ->assertSee('<link rel="alternate" hreflang="x-default" href="'.$en->url().'">', false);
    }

    public function test_open_graph_and_twitter_tags_are_present(): void
    {
        $article = Article::factory()->create();
        $en = $this->makeStory(Locale::En, attributes: ['title' => 'Open graph headline', 'excerpt' => 'Summary line'], article: $article);
        $this->makeStory(Locale::Ar, article: $article);

        $this->get($en->url())
            ->assertSee('<meta property="og:type" content="article">', false)
            ->assertSee('<meta property="og:title" content="Open graph headline">', false)
            ->assertSee('<meta property="og:description" content="Summary line">', false)
            ->assertSee('<meta property="og:url" content="'.$en->url().'">', false)
            ->assertSee('<meta property="og:locale" content="en_US">', false)
            ->assertSee('<meta property="og:locale:alternate" content="ar_EG">', false)
            ->assertSee('<meta property="article:published_time"', false)
            ->assertSee('<meta property="article:section"', false)
            ->assertSee('<meta name="twitter:card" content="summary_large_image">', false)
            ->assertSee('<meta name="twitter:title" content="Open graph headline">', false);
    }

    public function test_the_share_image_is_the_hero_when_there_is_one_and_the_brand_card_when_there_is_not(): void
    {
        Storage::fake('public');
        $withHero = $this->makeStory(Locale::En, article: Article::factory()->create(['hero_image' => 'heroes/lead.jpg']));
        $withoutHero = $this->makeStory(Locale::En);

        $this->get($withHero->url())->assertSee('<meta property="og:image" content="'.url('/storage/heroes/lead.jpg').'">', false);
        $this->get($withoutHero->url())->assertSee('<meta property="og:image" content="'.asset('brand/og-default.png').'">', false);
    }

    public function test_json_ld_describes_the_news_article(): void
    {
        $author = User::factory()->create(['name' => 'Salma Hassan', 'name_ar' => 'سلمى حسن']);
        $article = Article::factory()->create(['author_id' => $author->id]);
        $ar = $this->makeStory(Locale::Ar, attributes: [
            'title' => 'عنوان الخبر',
            'published_at' => '2026-10-12 08:00:00',
            'updated_at' => '2026-10-12 09:30:00',
        ], article: $article);

        $data = $this->jsonLd($this->get($ar->url())->assertOk()->getContent());

        $this->assertSame('https://schema.org', $data['@context']);
        $this->assertSame('NewsArticle', $data['@type']);
        $this->assertSame('عنوان الخبر', $data['headline']);
        $this->assertSame($ar->url(), $data['mainEntityOfPage']['@id']);
        $this->assertSame('ar', $data['inLanguage']);
        $this->assertSame('2026-10-12T08:00:00+00:00', $data['datePublished']);
        $this->assertSame('2026-10-12T09:30:00+00:00', $data['dateModified']);
        $this->assertSame('سلمى حسن', $data['author'][0]['name']);
        $this->assertSame('Person', $data['author'][0]['@type']);
        $this->assertNotEmpty($data['image'][0]);
        $this->assertSame('Organization', $data['publisher']['@type']);
        $this->assertSame(asset('brand/logo.png'), $data['publisher']['logo']['url']);
        $this->assertNotEmpty($data['description']);
    }

    public function test_json_ld_in_english_names_the_english_language_and_author(): void
    {
        $author = User::factory()->create(['name' => 'Salma Hassan', 'name_ar' => 'سلمى حسن']);
        $en = $this->makeStory(Locale::En, article: Article::factory()->create(['author_id' => $author->id]));

        $data = $this->jsonLd($this->get($en->url())->getContent());

        $this->assertSame('en', $data['inLanguage']);
        $this->assertSame('Salma Hassan', $data['author'][0]['name']);
    }

    public function test_story_text_cannot_break_out_of_the_json_ld_script(): void
    {
        $en = $this->makeStory(Locale::En, attributes: [
            'title' => 'Evil </script><script>alert(1)</script> title',
            'excerpt' => '<img src=x onerror=alert(2)> "quoted" & \'single\'',
        ]);

        $html = $this->get($en->url())->getContent();
        $data = $this->jsonLd($html);

        $this->assertStringContainsString('</script><script>alert(1)', $data['headline'], 'the text survives as data');
        $this->assertSame(0, substr_count($html, '<script>alert(1)'), 'but never as markup');
        $this->assertSame(1, substr_count($html, 'application/ld+json'));
    }
}
