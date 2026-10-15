<?php

namespace Tests\Feature\Site;

use App\Enums\Locale;
use App\Enums\TranslationStatus;
use App\Models\Article;
use App\Models\Category;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use SimpleXMLElement;
use Tests\Feature\Site\Concerns\MakesStories;
use Tests\TestCase;

class FeedTest extends TestCase
{
    use MakesStories, RefreshDatabase;

    private function feed(string $locale = 'en'): SimpleXMLElement
    {
        $response = $this->get("/{$locale}/feed.xml")->assertOk();

        $previous = libxml_use_internal_errors(true);
        $xml = simplexml_load_string($response->getContent());
        $errors = libxml_get_errors();
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        $this->assertNotFalse($xml, 'feed is not well-formed XML: '.json_encode(array_map(fn ($e) => trim($e->message), $errors)));

        return $xml;
    }

    public function test_it_is_rss_2_with_the_right_content_type_in_both_languages(): void
    {
        $this->makeStory(Locale::En);
        $this->makeStory(Locale::Ar);

        foreach (['en', 'ar'] as $locale) {
            $this->get("/{$locale}/feed.xml")
                ->assertOk()
                ->assertHeader('Content-Type', 'application/rss+xml; charset=utf-8');

            $xml = $this->feed($locale);
            $this->assertSame('rss', $xml->getName());
            $this->assertSame('2.0', (string) $xml['version']);
            $this->assertSame($locale, (string) $xml->channel->language);
            $this->assertSame(route('home', ['locale' => $locale]), (string) $xml->channel->link);
            $this->assertCount(1, $xml->channel->item);
        }
    }

    public function test_the_channel_has_an_atom_self_link_and_the_current_logo(): void
    {
        $xml = $this->feed('en');

        $atom = $xml->channel->children('http://www.w3.org/2005/Atom')->link->attributes();
        $this->assertSame('self', (string) $atom['rel']);
        $this->assertSame('application/rss+xml', (string) $atom['type']);
        $this->assertSame(route('feed', ['locale' => 'en']), (string) $atom['href']);
        $this->assertSame(asset('brand/logo.png'), (string) $xml->channel->image->url);
        $this->assertNotSame('', (string) $xml->channel->lastBuildDate);
    }

    public function test_an_item_carries_title_link_guid_date_category_and_excerpt(): void
    {
        $category = Category::factory()->create();
        $t = $this->makeStory(Locale::En, attributes: [
            'title' => 'Feed headline',
            'excerpt' => 'The <b>excerpt</b> & more',
            'published_at' => '2026-10-12 08:00:00',
        ], article: Article::factory()->create(['category_id' => $category->id]));

        $item = $this->feed('en')->channel->item[0];

        $this->assertSame('Feed headline', (string) $item->title);
        $this->assertSame($t->url(), (string) $item->link);
        $this->assertSame($t->url(), (string) $item->guid);
        $this->assertSame('true', (string) $item->guid['isPermaLink']);
        $this->assertSame('Mon, 12 Oct 2026 08:00:00 +0000', (string) $item->pubDate);
        $this->assertSame('Section '.$category->id, (string) $item->category);
        $this->assertSame('The excerpt & more', (string) $item->description);
        $this->assertEmpty($item->enclosure);
    }

    public function test_the_arabic_feed_uses_arabic_text_and_arabic_urls(): void
    {
        $category = Category::factory()->create();
        $t = $this->makeStory(Locale::Ar, attributes: ['title' => 'عنوان الخلاصة'], article: Article::factory()->create(['category_id' => $category->id]));

        $item = $this->feed('ar')->channel->item[0];

        $this->assertSame('عنوان الخلاصة', (string) $item->title);
        $this->assertSame($t->url(), (string) $item->link);
        $this->assertSame('قسم '.$category->id, (string) $item->category);
    }

    public function test_only_visible_stories_of_that_language_appear_newest_first(): void
    {
        $this->makeStory(Locale::En, attributes: ['title' => 'Older live', 'published_at' => now()->subDays(2)]);
        $this->makeStory(Locale::En, attributes: ['title' => 'Newer live', 'published_at' => now()->subHour()]);
        $this->makeStory(Locale::En, TranslationStatus::Draft, ['title' => 'A draft']);
        $this->makeStory(Locale::En, TranslationStatus::InReview, ['title' => 'In review']);
        $this->makeStory(Locale::En, TranslationStatus::Archived, ['title' => 'Archived']);
        $this->makeStory(Locale::En, attributes: ['title' => 'Scheduled', 'published_at' => now()->addDay()]);
        $this->makeStory(Locale::En, attributes: ['title' => 'Trashed'])->article->delete();
        $this->makeStory(Locale::Ar, attributes: ['title' => 'عربي']);

        $titles = [];
        foreach ($this->feed('en')->channel->item as $item) {
            $titles[] = (string) $item->title;
        }

        $this->assertSame(['Newer live', 'Older live'], $titles);
    }

    public function test_it_lists_at_most_thirty_stories(): void
    {
        foreach (range(1, 32) as $i) {
            $this->makeStory(Locale::En, attributes: ['published_at' => now()->subMinutes(100 - $i)]);
        }

        $this->assertCount(30, $this->feed('en')->channel->item);
    }

    public function test_a_hero_image_becomes_an_enclosure_with_type_and_length(): void
    {
        Storage::fake('public')->put('heroes/lead.jpg', str_repeat('x', 1234));
        $this->makeStory(Locale::En, article: Article::factory()->create(['hero_image' => 'heroes/lead.jpg']));

        $enclosure = $this->feed('en')->channel->item[0]->enclosure;

        $this->assertSame(url('/storage/heroes/lead.jpg'), (string) $enclosure['url']);
        $this->assertSame('1234', (string) $enclosure['length']);
        $this->assertSame('image/jpeg', (string) $enclosure['type']);
    }

    public function test_text_that_is_hostile_to_xml_still_produces_a_well_formed_feed(): void
    {
        $this->makeStory(Locale::En, attributes: [
            'title' => "Fish & chips <b>\"quoted\"</b> ]]> end \x0B\x0C\x1F",
            'excerpt' => "A & B < C > D \x00 'single'",
        ]);

        $item = $this->feed('en')->channel->item[0];

        $this->assertSame('Fish & chips <b>"quoted"</b> ]]> end ', (string) $item->title);
        $this->assertSame("A & B < C > D 'single'", (string) $item->description);
    }

    public function test_the_feed_is_cached_for_a_few_minutes(): void
    {
        $this->makeStory(Locale::En, attributes: ['title' => 'First story']);
        $this->assertCount(1, $this->feed('en')->channel->item);

        $this->makeStory(Locale::En, attributes: ['title' => 'Second story']);
        $this->assertCount(1, $this->feed('en')->channel->item, 'served from cache');

        $this->travel(6)->minutes();
        $this->assertCount(2, $this->feed('en')->channel->item, 'cache expired');
    }

    public function test_the_cache_is_per_language(): void
    {
        $this->makeStory(Locale::En, attributes: ['title' => 'English only']);
        $this->feed('en');

        $this->assertCount(0, $this->feed('ar')->channel->item);
    }
}
