<?php

namespace Tests\Feature\Site;

use App\Enums\Locale;
use App\Enums\TranslationStatus;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Site\Concerns\MakesStories;
use Tests\TestCase;

class SearchPageTest extends TestCase
{
    use MakesStories, RefreshDatabase;

    public function test_the_empty_search_page_invites_a_query(): void
    {
        $this->get('/en/search')
            ->assertOk()
            ->assertSee('Type a word or phrase to search the news.')
            ->assertSee('name="q"', false)
            ->assertSee('<meta name="robots" content="noindex, follow">', false);
        $this->get('/ar/search')->assertOk()->assertSee('اكتب كلمة أو عبارة للبحث في الأخبار.');
    }

    public function test_it_matches_title_excerpt_and_body_case_insensitively(): void
    {
        $this->makeStory(Locale::En, attributes: ['title' => 'Pyramids reopen to visitors', 'excerpt' => 'x', 'body' => '<p>x</p>']);
        $this->makeStory(Locale::En, attributes: ['title' => 'Headline two', 'excerpt' => 'The Nile flood season starts', 'body' => '<p>x</p>']);
        $this->makeStory(Locale::En, attributes: ['title' => 'Headline three', 'excerpt' => 'x', 'body' => '<p>Delta farmers adopt new irrigation</p>']);
        $this->makeStory(Locale::En, attributes: ['title' => 'Unrelated', 'excerpt' => 'x', 'body' => '<p>x</p>']);

        $this->get('/en/search?q=PYRAMIDS')->assertOk()->assertSee('Pyramids reopen to visitors')->assertDontSee('Unrelated');
        $this->get('/en/search?q=nile')->assertOk()->assertSee('Headline two')->assertDontSee('Unrelated');
        $this->get('/en/search?q=irrigation')->assertOk()->assertSee('Headline three')->assertDontSee('Unrelated');
    }

    public function test_it_searches_arabic_text_in_the_arabic_edition_only(): void
    {
        $this->makeStory(Locale::Ar, attributes: ['title' => 'افتتاح المتحف المصري الكبير']);
        $this->makeStory(Locale::En, attributes: ['title' => 'Grand museum opens', 'body' => '<p>المتحف</p>']);

        $this->get('/ar/search?q=المتحف')->assertOk()->assertSee('افتتاح المتحف المصري الكبير')->assertDontSee('Grand museum opens');
        $this->get('/en/search?q=المتحف')->assertOk()->assertSee('Grand museum opens')->assertDontSee('افتتاح المتحف المصري الكبير');
    }

    public function test_it_only_finds_visible_stories(): void
    {
        $this->makeStory(Locale::En, attributes: ['title' => 'Zebra live story']);
        $this->makeStory(Locale::En, TranslationStatus::Draft, ['title' => 'Zebra draft story']);
        $this->makeStory(Locale::En, TranslationStatus::InReview, ['title' => 'Zebra review story']);
        $this->makeStory(Locale::En, TranslationStatus::Archived, ['title' => 'Zebra archived story']);
        $this->makeStory(Locale::En, attributes: ['title' => 'Zebra future story', 'published_at' => now()->addDay()]);
        $this->makeStory(Locale::En, attributes: ['title' => 'Zebra trashed story'])->article->delete();

        $this->get('/en/search?q=zebra')
            ->assertOk()
            ->assertSee('Zebra live story')
            ->assertSee('One result for')
            ->assertDontSee('Zebra draft story')
            ->assertDontSee('Zebra review story')
            ->assertDontSee('Zebra archived story')
            ->assertDontSee('Zebra future story')
            ->assertDontSee('Zebra trashed story');
    }

    public function test_it_shows_the_result_count_in_both_languages(): void
    {
        foreach (range(1, 3) as $i) {
            $this->makeStory(Locale::En, attributes: ['title' => "Counted story {$i}"]);
            $this->makeStory(Locale::Ar, attributes: ['title' => "خبر محسوب {$i}"]);
        }

        $this->get('/en/search?q=counted')->assertSee('3 results for “counted”');
        $this->get('/ar/search?q=محسوب')->assertSee('3 نتائج عن «محسوب»');
    }

    public function test_a_search_with_no_match_shows_a_friendly_empty_state(): void
    {
        $this->makeStory(Locale::En);

        $this->get('/en/search?q=nothingmatchesthis')
            ->assertOk()
            ->assertSee('No results for “nothingmatchesthis”')
            ->assertSee('We found nothing for that search')
            ->assertSee('Check the spelling');
        $this->get('/ar/search?q=لاشيءيطابق')->assertOk()->assertSee('لم نعثر على نتائج لبحثك');
    }

    public function test_percent_and_underscore_are_literal_not_wildcards(): void
    {
        $this->makeStory(Locale::En, attributes: ['title' => 'Inflation hits 5% this year', 'excerpt' => 'x', 'body' => '<p>x</p>']);
        $this->makeStory(Locale::En, attributes: ['title' => 'Five percent plain', 'excerpt' => 'x', 'body' => '<p>x</p>']);
        $this->makeStory(Locale::En, attributes: ['title' => 'Config key a_b explained', 'excerpt' => 'x', 'body' => '<p>x</p>']);
        $this->makeStory(Locale::En, attributes: ['title' => 'Letters aXb only', 'excerpt' => 'x', 'body' => '<p>x</p>']);
        $this->makeStory(Locale::En, attributes: ['title' => 'Wow! Exclamation', 'excerpt' => 'x', 'body' => '<p>x</p>']);

        $this->get('/en/search?q=5%25')->assertOk()->assertSee('Inflation hits 5% this year')->assertDontSee('Five percent plain');
        $this->get('/en/search?q=%25%25')->assertOk()->assertDontSee('Five percent plain')->assertDontSee('Inflation hits');
        $this->get('/en/search?q=a_b')->assertOk()->assertSee('Config key a_b explained')->assertDontSee('Letters aXb only');
        $this->get('/en/search?q=wow!')->assertOk()->assertSee('Wow! Exclamation');
        $this->get('/en/search?q=!!')->assertOk()->assertDontSee('Wow! Exclamation');
        $this->get('/en/search?q='.rawurlencode('\\'.'\\'))->assertOk()->assertSee('No results');
    }

    public function test_sql_injection_attempts_are_inert(): void
    {
        $this->makeStory(Locale::En, attributes: ['title' => 'Ordinary story']);

        foreach (["' OR '1'='1", "'; DROP TABLE articles; --", '" OR 1=1 --', "%' OR title LIKE '%"] as $attack) {
            $this->get('/en/search?q='.rawurlencode($attack))->assertOk()->assertDontSee('Ordinary story');
        }

        $this->get('/en/search?q=ordinary')->assertOk()->assertSee('Ordinary story');
    }

    public function test_the_query_is_trimmed_and_length_limited(): void
    {
        $this->makeStory(Locale::En, attributes: ['title' => 'Trimmed query story']);

        $this->get('/en/search?q='.rawurlencode("   trimmed    query\t\n"))->assertOk()->assertSee('Trimmed query story');

        $this->get('/en/search?q=a')->assertOk()->assertSee('That search is too short')->assertDontSee('Trimmed query story');
        $this->get('/en/search?q='.rawurlencode('  a  '))->assertOk()->assertSee('That search is too short');
        $this->get('/ar/search?q=ا')->assertOk()->assertSee('عبارة البحث قصيرة جدًا');

        $this->get('/en/search?q=ab')->assertOk()->assertDontSee('too short');
        $this->get('/en/search?q='.str_repeat('a', 80))->assertOk()->assertDontSee('too long');
        $this->get('/en/search?q='.str_repeat('a', 81))->assertOk()->assertSee('That search is too long. Please keep it to 80 characters or fewer.');
        $this->get('/ar/search?q='.str_repeat('ب', 81))->assertOk()->assertSee('عبارة البحث طويلة جدًا');
    }

    public function test_the_length_limit_counts_characters_not_bytes(): void
    {
        // 80 Arabic letters are 160 bytes: still allowed.
        $this->get('/ar/search?q='.rawurlencode(str_repeat('ب', 80)))->assertOk()->assertDontSee('طويلة جدًا');
    }

    public function test_malformed_input_never_crashes(): void
    {
        $this->get('/en/search?q[]=x&q[]=y')->assertOk()->assertSee('Type a word or phrase');
        $this->get('/en/search?q=%FF%FE%FD')->assertOk();
        $this->get('/en/search?q=%00%01%02')->assertOk();
        $this->get('/en/search?q=ab&page=abc')->assertOk();
        $this->get('/en/search?q=ab&page=99999999999999999999')->assertOk();
    }

    public function test_results_are_paginated_ten_per_page_and_the_pager_keeps_the_query(): void
    {
        foreach (range(1, 12) as $i) {
            $this->makeStory(Locale::En, attributes: ['title' => sprintf('Paged result %02d', $i), 'published_at' => now()->subMinutes(100 - $i)]);
        }

        $first = $this->get('/en/search?q=paged')->assertOk()->assertSee('12 results for “paged”');
        $this->assertSame(10, substr_count($first->getContent(), 'Paged result'));
        $first->assertSee('Paged result 12')->assertDontSee('Paged result 01');
        $this->assertStringContainsString('?q=paged&amp;page=2', $first->getContent());

        $second = $this->get('/en/search?q=paged&page=2')->assertOk();
        $this->assertSame(2, substr_count($second->getContent(), 'Paged result'));
        $second->assertSee('Paged result 01');
    }

    public function test_the_search_term_is_escaped_in_the_page(): void
    {
        $this->get('/en/search?q='.rawurlencode('<script>alert(1)</script>'))
            ->assertOk()
            ->assertDontSee('<script>alert(1)</script>', false)
            ->assertSee('&lt;script&gt;alert(1)&lt;/script&gt;', false);
    }

    public function test_search_is_rate_limited_per_client(): void
    {
        foreach (range(1, 30) as $i) {
            $this->get('/en/search?q=ab')->assertOk();
        }

        $this->get('/en/search?q=ab')->assertStatus(429);
    }
}
