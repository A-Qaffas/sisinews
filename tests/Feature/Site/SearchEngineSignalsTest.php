<?php

namespace Tests\Feature\Site;

use App\Enums\Locale;
use App\Enums\TranslationStatus;
use App\Models\Article;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Site\Concerns\MakesStories;
use Tests\TestCase;

/** What the public pages tell search engines beyond titles and descriptions. */
class SearchEngineSignalsTest extends TestCase
{
    use MakesStories, RefreshDatabase;

    /** @return list<string> the content of every robots meta tag on the page */
    private function robots(string $html): array
    {
        preg_match_all('#<meta name="robots" content="([^"]*)">#', $html, $m);

        return $m[1];
    }

    public function test_public_pages_allow_large_image_previews(): void
    {
        $t = $this->makeStory(Locale::En);

        $this->assertSame(['max-image-preview:large'], $this->robots($this->get($t->url())->assertOk()->getContent()));
        $this->assertSame(['max-image-preview:large'], $this->robots($this->get('/ar')->assertOk()->getContent()));
    }

    public function test_pages_with_their_own_noindex_keep_only_that_rule(): void
    {
        $this->assertSame(['noindex, follow'], $this->robots($this->get('/en/search?q=cairo')->assertOk()->getContent()));

        $author = User::factory()->journalist()->create();
        $draft = $this->makeStory(Locale::En, TranslationStatus::Draft, article: Article::factory()->create(['author_id' => $author->id]));
        $this->assertSame(['noindex, nofollow'], $this->robots($this->actingAs($author)->get($draft->url())->assertOk()->getContent()));
    }

    public function test_error_pages_carry_no_robots_rule(): void
    {
        $this->assertSame([], $this->robots($this->get('/en/no-such-page-at-all')->assertNotFound()->getContent()));
    }

    public function test_the_home_page_says_who_publishes_the_site(): void
    {
        config(['site.social' => ['facebook' => 'https://facebook.com/sisinews', 'x' => 'javascript:alert(1)', 'instagram' => '']]);

        $graph = $this->jsonLd($this->get('/en')->assertOk()->getContent())['@graph'];

        $this->assertSame('NewsMediaOrganization', $graph[0]['@type']);
        $this->assertSame(config('site.name.en'), $graph[0]['name']);
        $this->assertSame(asset('brand/logo.png'), $graph[0]['logo']['url']);
        $this->assertSame(['https://facebook.com/sisinews'], $graph[0]['sameAs'], 'blank and non-http(s) profiles are not listed');
        $this->assertSame('WebSite', $graph[1]['@type']);
        $this->assertSame(route('home', ['locale' => 'en']), $graph[1]['url']);
        $this->assertSame($graph[0]['@id'], $graph[1]['publisher']['@id']);
    }
}
