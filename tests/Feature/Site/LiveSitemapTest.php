<?php

namespace Tests\Feature\Site;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Site\Concerns\BuildsLiveBroadcasts;
use Tests\TestCase;

class LiveSitemapTest extends TestCase
{
    use BuildsLiveBroadcasts, RefreshDatabase;

    public function test_the_sitemap_lists_the_live_page_in_both_languages_with_alternates(): void
    {
        $ar = route('live', ['locale' => 'ar']);
        $en = route('live', ['locale' => 'en']);

        $xml = $this->get('/sitemap.xml')->assertOk()->getContent();

        foreach ([$ar, $en] as $loc) {
            $this->assertSame(1, preg_match('#<url>\s*<loc>'.preg_quote($loc, '#').'</loc>(.*?)</url>#s', $xml, $entry), "{$loc} is missing");
            $this->assertStringContainsString('<xhtml:link rel="alternate" hreflang="ar" href="'.$ar.'"/>', $entry[1]);
            $this->assertStringContainsString('<xhtml:link rel="alternate" hreflang="en" href="'.$en.'"/>', $entry[1]);
            $this->assertStringContainsString('<xhtml:link rel="alternate" hreflang="x-default" href="'.$ar.'"/>', $entry[1]);
        }
    }

    public function test_the_governorate_views_are_not_listed_separately(): void
    {
        $this->governorate('cairo', 'Cairo', 'القاهرة');

        $xml = $this->get('/sitemap.xml')->assertOk()->getContent();

        $this->assertStringNotContainsString('g=cairo', $xml);
        $this->assertStringNotContainsString('?g=', $xml);
    }

    public function test_the_existing_pages_are_still_listed(): void
    {
        $xml = $this->get('/sitemap.xml')->assertOk()->getContent();

        $this->assertStringContainsString('<loc>'.route('home', ['locale' => 'ar']).'</loc>', $xml);
        $this->assertStringContainsString('<loc>'.route('home', ['locale' => 'en']).'</loc>', $xml);
    }
}
