<?php

namespace Tests\Feature\Admin;

use App\Models\Article;
use App\Models\ArticleTranslation;
use App\Models\User;
use Illuminate\Support\Facades\Storage;

/** Small launch fixes that do not belong to one panel screen. */
class LaunchPolishTest extends AdminTestCase
{
    public function test_the_article_hero_picture_has_an_empty_alt_because_headline_and_caption_say_it_already(): void
    {
        Storage::fake('public');
        $article = Article::factory()->create(['hero_image' => 'heroes/cabinet.jpg']);
        $translation = ArticleTranslation::factory()->published()->create([
            'article_id' => $article->getKey(),
            'title' => 'Ministers meet in Cairo',
            'hero_caption' => 'Ministers arrive at the meeting',
        ]);

        $html = $this->get($translation->url())->assertOk()->getContent();

        $this->assertMatchesRegularExpression('#<figure class="sn-hero">\s*<img[^>]*\salt=""#', $html);
        $this->assertDoesNotMatchRegularExpression('#<figure class="sn-hero">\s*<img[^>]*\salt="[^"]+"#', $html, 'the picture must not repeat the headline as alt text');
        $this->assertStringContainsString('<figcaption>Ministers arrive at the meeting</figcaption>', $html);
        $this->assertStringContainsString('Ministers meet in Cairo', $html, 'the headline is still on the page');
    }

    public function test_the_panel_brand_logo_is_the_small_webp_not_the_219_kb_png(): void
    {
        $this->actingAs(User::factory()->editor()->create());

        $this->get('/admin')->assertOk()
            ->assertSee('brand/logo-380.webp', false)
            ->assertDontSee('brand/logo.png', false);

        $this->assertFileExists(public_path('brand/logo-380.webp'));
        $this->assertLessThan(20_000, filesize(public_path('brand/logo-380.webp')));
    }
}
