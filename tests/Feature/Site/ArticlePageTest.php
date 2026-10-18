<?php

namespace Tests\Feature\Site;

use App\Enums\Locale;
use App\Enums\TranslationStatus;
use App\Models\Article;
use App\Models\ArticleTranslation;
use App\Models\Category;
use App\Models\Tag;
use App\Models\TagTranslation;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Feature\Site\Concerns\MakesStories;
use Tests\TestCase;

class ArticlePageTest extends TestCase
{
    use MakesStories, RefreshDatabase;

    public function test_a_published_english_story_renders_for_a_guest(): void
    {
        $author = User::factory()->create(['name' => 'Salma Hassan', 'name_ar' => 'سلمى حسن']);
        $article = Article::factory()->create(['author_id' => $author->id]);
        $t = $this->makeStory(Locale::En, attributes: [
            'title' => 'Cabinet approves new budget',
            'body' => '<p>First paragraph.</p><h2>A subheading</h2><p>Second paragraph.</p>',
        ], article: $article);

        $this->get($t->url())
            ->assertOk()
            ->assertSee('<h1 class="sn-title">Cabinet approves new budget</h1>', false)
            ->assertSee('Salma Hassan')
            ->assertDontSee('سلمى حسن')
            ->assertSee('<h2>A subheading</h2>', false)
            ->assertSee('<time datetime=', false)
            ->assertSee('Home')
            ->assertSee($article->category->nameIn(Locale::En));
    }

    public function test_the_arabic_edition_uses_arabic_labels_and_the_arabic_byline(): void
    {
        $author = User::factory()->create(['name' => 'Salma Hassan', 'name_ar' => 'سلمى حسن']);
        $article = Article::factory()->create(['author_id' => $author->id]);
        $t = $this->makeStory(Locale::Ar, attributes: ['title' => 'مجلس الوزراء يوافق على الموازنة'], article: $article);

        $this->get($t->url())
            ->assertOk()
            ->assertSee('مجلس الوزراء يوافق على الموازنة')
            ->assertSee('سلمى حسن')
            ->assertSee('الرئيسية')
            ->assertSee('بقلم')
            ->assertSee($article->category->nameIn(Locale::Ar));
    }

    public function test_the_published_time_is_shown_in_cairo_time(): void
    {
        $t = $this->makeStory(Locale::En, attributes: ['published_at' => '2026-10-12 12:30:00']);

        // 12:30 UTC is 15:30 in Cairo (UTC+3 in October 2026).
        $this->get($t->url())->assertSee('3:30 PM')->assertSee('12 October 2026');
    }

    public function test_the_body_html_is_rendered_but_the_title_is_escaped(): void
    {
        $t = $this->makeStory(Locale::En, attributes: [
            'title' => 'Tom & Jerry <script>alert(1)</script>',
            'body' => '<p>Real <strong>markup</strong></p>',
        ]);

        $this->get($t->url())
            ->assertOk()
            ->assertSee('<strong>markup</strong>', false)
            ->assertSee('Tom &amp; Jerry &lt;script&gt;alert(1)&lt;/script&gt;', false)
            ->assertDontSee('<script>alert(1)</script>', false);
    }

    public function test_the_page_title_is_the_headline_escaped_exactly_once(): void
    {
        $t = $this->makeStory(Locale::En, attributes: ['title' => "Egypt's budget & growth"]);

        $html = $this->get($t->url())->getContent();

        // The page hands the layout an already-escaped title (the @section('title', $x) form). If the layout
        // escapes it a second time the apostrophe shows up as a literal "&#039;" in every browser tab.
        $this->assertStringContainsString('<title>Egypt&#039;s budget &amp; growth | sisi.news</title>', $html);
    }

    /** @return array<string, array{TranslationStatus}> */
    public static function hiddenStatuses(): array
    {
        return [
            'draft' => [TranslationStatus::Draft],
            'in review' => [TranslationStatus::InReview],
            'changes requested' => [TranslationStatus::ChangesRequested],
            'archived' => [TranslationStatus::Archived],
        ];
    }

    #[DataProvider('hiddenStatuses')]
    public function test_unpublished_stories_are_a_404_for_guests_in_both_languages(TranslationStatus $status): void
    {
        foreach ([Locale::En, Locale::Ar] as $locale) {
            $t = $this->makeStory($locale, $status);

            $this->get($t->url())->assertNotFound();
        }
    }

    public function test_a_future_dated_story_is_a_404_until_its_time_comes(): void
    {
        $t = $this->makeStory(Locale::En, attributes: ['published_at' => now()->addHour()]);

        $this->get($t->url())->assertNotFound();

        $this->travel(2)->hours();
        $this->get($t->url())->assertOk();
    }

    public function test_a_soft_deleted_article_is_a_404_even_though_its_translation_is_still_published(): void
    {
        $t = $this->makeStory(Locale::En);
        $t->article->delete();

        $this->get($t->url())->assertNotFound();
    }

    public function test_a_story_that_exists_only_in_the_other_language_is_a_404(): void
    {
        $t = $this->makeStory(Locale::Ar);

        $this->get("/en/news/{$t->article_id}-anything")->assertNotFound();
    }

    public function test_an_unknown_or_absurd_id_is_a_404_not_a_crash(): void
    {
        $this->get('/en/news/999999')->assertNotFound();
        $this->get('/en/news/99999999999999999999999-x')->assertNotFound();
    }

    public function test_a_wrong_slug_redirects_permanently_to_the_canonical_url(): void
    {
        $t = $this->makeStory(Locale::En, attributes: ['title' => 'Right headline here']);

        $this->get("/en/news/{$t->article_id}-some-old-headline")->assertStatus(301)->assertRedirect($t->url());
        $this->get("/en/news/{$t->article_id}")->assertStatus(301)->assertRedirect($t->url());
        $this->get($t->url())->assertOk();
    }

    public function test_an_arabic_slug_is_canonical_and_does_not_redirect(): void
    {
        $t = $this->makeStory(Locale::Ar, attributes: ['title' => 'عنوان الخبر الجديد']);

        $this->assertStringContainsString('%', $t->url(), 'the generated URL is percent-encoded');
        $this->get($t->url())->assertOk();
        $this->get("/ar/news/{$t->article_id}-خطأ")->assertStatus(301)->assertRedirect($t->url());
    }

    public function test_hero_image_renders_with_srcset_and_caption(): void
    {
        Storage::fake('public');
        $article = Article::factory()->create(['hero_image' => 'heroes/cabinet.jpg']);
        $t = $this->makeStory(Locale::En, attributes: ['hero_caption' => 'Ministers arrive at the meeting'], article: $article);

        $this->get($t->url())
            ->assertOk()
            ->assertSee('srcset="', false)
            ->assertSee('480w', false)
            ->assertSee('1280w', false)
            ->assertSee('<figcaption>Ministers arrive at the meeting</figcaption>', false);
    }

    public function test_a_story_without_a_hero_image_shows_the_current_brand_logo_instead_of_a_picture(): void
    {
        $t = $this->makeStory(Locale::En);

        $this->get($t->url())
            ->assertOk()
            ->assertDontSee('class="sn-hero"', false)
            ->assertSee('media--placeholder', false)
            ->assertSee(asset('brand/logo-380.webp'), false);
    }

    public function test_the_tools_bar_offers_every_share_target_and_the_page_tools(): void
    {
        $t = $this->makeStory(Locale::En, attributes: ['title' => 'Share me']);
        $encoded = rawurlencode($t->shortUrl());

        $this->get($t->url())
            ->assertOk()
            ->assertSee('https://wa.me/?text='.rawurlencode('Share me '.$t->shortUrl()), false)
            ->assertSee('https://x.com/intent/post?text=Share%20me&amp;url='.$encoded, false)
            ->assertSee('https://www.facebook.com/sharer/sharer.php?u='.$encoded, false)
            ->assertSee('https://www.linkedin.com/sharing/share-offsite/?url='.$encoded, false)
            ->assertSee('https://t.me/share/url?url='.$encoded, false)
            ->assertSee('mailto:?subject=Share%20me', false)
            ->assertSee('data-article-tool="copy"', false)
            ->assertSee('data-url="'.$t->shortUrl().'"', false)
            ->assertSee('data-article-tool="print"', false)
            ->assertSee('data-article-tool="text-smaller"', false)
            ->assertSee('data-article-tool="text-larger"', false)
            ->assertSee('rel="noopener noreferrer"', false);
    }

    public function test_shared_links_use_the_short_address_even_for_a_long_arabic_headline(): void
    {
        $t = $this->makeStory(Locale::Ar, attributes: ['title' => 'خالد فودة: توصيات مؤتمر الهوية الوطنية تخرج للنور الأسبوع المقبل']);

        $this->assertSame(route('article', ['locale' => 'ar', 'idSlug' => (string) $t->article_id]), $t->shortUrl());
        $this->assertLessThan(60, strlen($t->shortUrl()));
        // Encoded for a link (as every share target needs it), the full Arabic address runs to hundreds of characters.
        $this->assertLessThan(strlen(rawurlencode($t->url())) / 3, strlen($t->shortUrl()), 'the short link must be a fraction of the encoded full one');

        $html = $this->get($t->url())->assertOk()->getContent();
        $this->assertStringNotContainsString(rawurlencode($t->url()), $html, 'no share link may carry the long encoded address');
        $this->assertStringContainsString('sharer.php?u='.rawurlencode($t->shortUrl()), $html);
    }

    public function test_the_short_address_sends_readers_and_link_previews_to_the_full_story(): void
    {
        $t = $this->makeStory(Locale::Ar);

        $this->get($t->shortUrl())->assertStatus(301)->assertRedirect($t->url());
    }

    public function test_the_native_share_button_leads_ships_hidden_and_carries_the_short_link_and_title(): void
    {
        $t = $this->makeStory(Locale::Ar, attributes: ['title' => 'عنوان "مقتبس" & رمز']);

        $html = $this->get($t->url())->assertOk()->getContent();

        $this->assertSame(1, preg_match('/<button[^>]*data-article-tool="share"[^>]*>/', $html, $m));
        $button = $m[0];
        $this->assertStringContainsString('data-needs-native-share', $button);
        $this->assertMatchesRegularExpression('/\shidden[\s>]/', $button, 'revealed by script only where navigator.share exists');
        $this->assertStringContainsString('data-url="'.$t->shortUrl().'"', $button);
        $this->assertStringContainsString('data-title="'.e($t->title).'"', $button, 'the title is escaped in the attribute');
        $this->assertStringContainsString('<span data-tool-text>'.__('article.share_native', [], 'ar').'</span></button>', $html);

        // It comes first in the share group, before the per-network links.
        $this->assertLessThan(strpos($html, 'https://wa.me/'), strpos($html, 'data-article-tool="share"'));
    }

    public function test_each_network_is_an_icon_button_that_still_has_a_spoken_name(): void
    {
        $t = $this->makeStory(Locale::Ar);

        $html = $this->get($t->url())->assertOk()->getContent();

        preg_match_all('#<a class="sn-share-icon" data-network="([a-z]+)"[^>]*>(.*?)</a>#s', $html, $links, PREG_SET_ORDER);
        $this->assertSame(['whatsapp', 'x', 'facebook', 'linkedin', 'telegram', 'email'], array_column($links, 1));

        foreach ($links as [$tag, $network, $inner]) {
            $name = __('article.share_on', ['network' => __("article.networks.{$network}", [], 'ar')], 'ar');
            $this->assertStringContainsString('aria-label="'.$name.'"', $tag, "{$network}: read aloud");
            $this->assertStringContainsString('title="'.$name.'"', $tag, "{$network}: shown on hover");
            // The glyph is inline (nothing fetched from a third party) and hidden from screen readers.
            $this->assertMatchesRegularExpression('#^<svg class="sn-icon" viewBox="0 0 16 16"[^>]*aria-hidden="true"[^>]*><path d="[^"]+"/>(<path d="[^"]+"/>)*</svg>$#', trim($inner), $network);
        }
    }

    public function test_copy_and_print_keep_their_words_in_the_span_the_script_rewrites(): void
    {
        $t = $this->makeStory(Locale::En);

        $html = $this->get($t->url())->assertOk()->getContent();

        // article-tools.js swaps the text of [data-tool-text] for "Link copied", so the icon beside it survives.
        $this->assertMatchesRegularExpression('#data-article-tool="copy"[^>]*><svg class="sn-icon"[^>]*>.*?</svg>\s*<span data-tool-text>Copy link</span></button>#s', $html);
        $this->assertMatchesRegularExpression('#data-article-tool="print"[^>]*><svg class="sn-icon"[^>]*>.*?</svg>\s*<span data-tool-text>Print</span></button>#s', $html);
    }

    public function test_a_preview_has_no_native_share_button(): void
    {
        $editor = User::factory()->editor()->create();
        $article = Article::factory()->create();
        $draft = ArticleTranslation::factory()->create(['article_id' => $article->id]);

        $this->actingAs($editor)->get($draft->url())->assertOk()->assertDontSee('data-article-tool="share"', false);
    }

    public function test_script_dependent_tools_ship_hidden_so_they_are_not_dead_buttons_without_javascript(): void
    {
        $t = $this->makeStory(Locale::En);

        $html = $this->get($t->url())->getContent();

        // The text-size group, the copy-and-print group, and the copy and print buttons inside it.
        preg_match_all('/<[^>]*data-js-only[^>]*>/', $html, $scriptOnly);
        $this->assertCount(4, $scriptOnly[0]);
        foreach ($scriptOnly[0] as $element) {
            $this->assertMatchesRegularExpression('/\shidden[\s>]/', $element);
        }
    }

    public function test_tags_are_plain_labels_not_links(): void
    {
        $article = Article::factory()->create();
        $tag = Tag::create();
        TagTranslation::create(['tag_id' => $tag->id, 'locale' => Locale::En, 'name' => 'Economy', 'slug' => 'economy']);
        TagTranslation::create(['tag_id' => $tag->id, 'locale' => Locale::Ar, 'name' => 'اقتصاد', 'slug' => 'اقتصاد']);
        $article->tags()->attach($tag);
        $en = $this->makeStory(Locale::En, article: $article);
        $ar = $this->makeStory(Locale::Ar, article: $article);

        $this->get($en->url())->assertSee('<li>Economy</li>', false)->assertDontSee('اقتصاد');
        $this->get($ar->url())->assertSee('<li>اقتصاد</li>', false);
        $this->assertDoesNotMatchRegularExpression('#<a[^>]*>\s*Economy#', $this->get($en->url())->getContent());
    }

    public function test_related_stories_are_the_newest_four_visible_ones_in_the_same_category_and_language(): void
    {
        $category = Category::factory()->create();
        $other = Category::factory()->create();
        $current = $this->makeStory(Locale::En, article: Article::factory()->create(['category_id' => $category->id]));

        $related = [];
        foreach (range(1, 5) as $i) {
            $related[$i] = $this->makeStory(Locale::En, attributes: [
                'title' => "Related number {$i}",
                'published_at' => now()->subHours(10 - $i),
            ], article: Article::factory()->create(['category_id' => $category->id]));
        }
        // None of these may appear.
        $this->makeStory(Locale::En, TranslationStatus::Draft, ['title' => 'Related draft'], Article::factory()->create(['category_id' => $category->id]));
        $this->makeStory(Locale::En, attributes: ['title' => 'Related future', 'published_at' => now()->addDay()], article: Article::factory()->create(['category_id' => $category->id]));
        $this->makeStory(Locale::Ar, attributes: ['title' => 'خبر عربي مرتبط'], article: Article::factory()->create(['category_id' => $category->id]));
        $this->makeStory(Locale::En, attributes: ['title' => 'Related elsewhere'], article: Article::factory()->create(['category_id' => $other->id]));
        $trashed = $this->makeStory(Locale::En, attributes: ['title' => 'Related trashed'], article: Article::factory()->create(['category_id' => $category->id]));
        $trashed->article->delete();

        $response = $this->get($current->url())->assertOk();

        // Newest four of the five (the oldest, number 1, drops out).
        foreach ([2, 3, 4, 5] as $i) {
            $response->assertSee("Related number {$i}");
        }
        $response->assertDontSee('Related number 1')
            ->assertDontSee('Related draft')
            ->assertDontSee('Related future')
            ->assertDontSee('خبر عربي مرتبط')
            ->assertDontSee('Related elsewhere')
            ->assertDontSee('Related trashed');
        $this->assertSame(1, preg_match('#<section class="sn-related".*?</section>#s', $response->getContent(), $section));
        $this->assertStringNotContainsString($current->url(), $section[0], 'the story is not its own related story');
    }

    public function test_no_related_section_when_there_is_nothing_related(): void
    {
        $t = $this->makeStory(Locale::En);

        $this->get($t->url())->assertOk()->assertDontSee('class="sn-related"', false);
    }

    public function test_the_other_language_link_appears_only_when_that_version_is_live(): void
    {
        $article = Article::factory()->create();
        $en = $this->makeStory(Locale::En, article: $article);
        $ar = $this->makeStory(Locale::Ar, TranslationStatus::InReview, ['title' => 'عنوان لم ينشر بعد'], $article);

        $this->get($en->url())->assertOk()->assertDontSee('class="sn-alt"', false)->assertDontSee('عنوان لم ينشر بعد');

        $this->publish($ar);

        $this->get($en->url())
            ->assertSee('class="sn-alt"', false)
            ->assertSee('This story is also available in Arabic')
            ->assertSee('عنوان لم ينشر بعد')
            ->assertSee('href="'.$ar->url().'"', false);
        $this->get($ar->url())
            ->assertSee('هذا الخبر متوفر أيضًا بالإنجليزية')
            ->assertSee('href="'.$en->url().'"', false);
    }

    public function test_the_language_switcher_jumps_to_the_same_story_only_when_it_exists_in_the_other_language(): void
    {
        $article = Article::factory()->create();
        $en = $this->makeStory(Locale::En, article: $article);
        $this->makeStory(Locale::Ar, TranslationStatus::Draft, article: $article);

        $this->get($en->url())->assertDontSee('class="lang-switch" href="'.route('article', ['locale' => 'ar', 'idSlug' => $article->id]), false);

        $ar = $article->translations()->where('locale', 'ar')->first();
        $ar->forceFill(['status' => TranslationStatus::Published, 'published_at' => now()->subMinute()])->save();

        $this->get($en->url())->assertSee('class="lang-switch" href="'.$ar->fresh()->url().'"', false);
    }

    public function test_viewing_a_story_records_one_view_per_session(): void
    {
        $t = $this->makeStory(Locale::En);

        $this->get($t->url())->assertOk();

        $this->assertSame(1, (int) DB::table('article_daily_views')->where('article_translation_id', $t->id)->value('views'));
    }

    public function test_the_sidebar_lists_most_read_and_latest_stories_without_repeating_the_page_itself(): void
    {
        config(['site.cache' => array_map(fn () => 0, config('site.cache'))]);

        $page = $this->makeStory(Locale::En, attributes: ['title' => 'The story being read']);
        $popular = $this->makeStory(Locale::En, attributes: ['title' => 'Popular elsewhere', 'published_at' => now()->subHours(3)]);
        $this->makeStory(Locale::En, attributes: ['title' => 'Freshly published', 'published_at' => now()->subMinutes(5)]);
        $arabicOnly = $this->makeStory(Locale::Ar, attributes: ['title' => 'قصة عربية فقط']);
        foreach ([$page, $popular, $arabicOnly] as $story) {
            DB::table('article_daily_views')->insert(['article_translation_id' => $story->id, 'day' => now()->toDateString(), 'views' => 40]);
        }

        $response = $this->get($page->url())->assertOk();
        $html = $response->getContent();

        $this->assertStringContainsString('<aside class="sn-aside"', $html);
        $this->assertStringContainsString('id="panel-most-read"', $html);
        $this->assertStringContainsString('id="panel-latest"', $html);
        $this->assertSame(['Popular elsewhere'], $response->viewData('mostRead')->pluck('title')->all());
        $this->assertContains('Freshly published', $response->viewData('latest')->pluck('title')->all());
        $this->assertNotContains('The story being read', $response->viewData('latest')->pluck('title')->all());
        $this->assertStringNotContainsString('قصة عربية فقط', $html);
    }

    public function test_related_stories_sit_inside_the_sidebar_grid_so_css_can_put_them_before_it_on_phones(): void
    {
        config(['site.cache' => array_map(fn () => 0, config('site.cache'))]);

        $category = Category::factory()->create();
        $page = $this->makeStory(Locale::En, article: Article::factory()->create(['category_id' => $category->id]));
        $sibling = $this->makeStory(Locale::En, attributes: ['title' => 'Same section sibling'], article: Article::factory()->create(['category_id' => $category->id]));
        $popular = $this->makeStory(Locale::En, attributes: ['title' => 'Popular elsewhere']);
        foreach ([$sibling, $popular] as $story) {
            DB::table('article_daily_views')->insert(['article_translation_id' => $story->id, 'day' => now()->toDateString(), 'views' => 40]);
        }

        $html = $this->get($page->url())->assertOk()->getContent();

        $layout = strpos($html, 'class="sn-layout sn-layout--aside"');
        $this->assertNotFalse($layout);
        $this->assertGreaterThan($layout, strpos($html, '<section class="sn-related"'), 'related stories live inside the same grid as the sidebar');
        $this->assertMatchesRegularExpression('#<aside class="sn-aside".*?</aside>\s*<section class="sn-related"#s', $html);
    }

    public function test_the_sidebar_is_left_out_while_no_other_story_has_been_read(): void
    {
        config(['site.cache' => array_map(fn () => 0, config('site.cache'))]);

        $page = $this->makeStory(Locale::En);
        $this->makeStory(Locale::En);

        $this->get($page->url())
            ->assertOk()
            ->assertDontSee('class="sn-aside"', false)
            ->assertDontSee('id="panel-most-read"', false);
    }

    public function test_a_staff_preview_has_no_sidebar(): void
    {
        config(['site.cache' => array_map(fn () => 0, config('site.cache'))]);

        $popular = $this->makeStory(Locale::En);
        DB::table('article_daily_views')->insert(['article_translation_id' => $popular->id, 'day' => now()->toDateString(), 'views' => 40]);
        $draft = $this->makeStory(Locale::En, TranslationStatus::Draft);
        $editor = User::factory()->editor()->create();

        $this->actingAs($editor)->get($draft->url())->assertOk()->assertDontSee('class="sn-aside"', false);
    }

    private function publish(ArticleTranslation $t): void
    {
        $t->forceFill(['status' => TranslationStatus::Published, 'published_at' => now()->subMinute()])->save();
    }
}
