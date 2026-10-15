<?php

namespace Tests\Feature\Site;

use App\Enums\Locale;
use App\Models\Page;
use App\Models\PageTranslation;
use App\Support\PageBody;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/** [[contact]] is replaced when the page is rendered: a mailto link when an address is configured, else a plain phrase. */
class PageContactTokenTest extends TestCase
{
    use RefreshDatabase;

    private function page(string $enBody, string $arBody = '<p>راسلوا [[contact]] للتواصل.</p>'): void
    {
        $page = Page::factory()->create(['slug' => 'about']);
        PageTranslation::factory()->for($page)->create(['body' => $enBody]);
        PageTranslation::factory()->for($page)->arabic()->create(['body' => $arBody]);
    }

    public function test_the_token_becomes_a_mailto_link_when_an_address_is_configured(): void
    {
        config(['site.contact.email' => 'desk@example.org']);
        $this->page('<p>Write to [[contact]] with tips.</p>');

        $this->get('/en/page/about')
            ->assertOk()
            ->assertSee('<p>Write to <a href="mailto:desk@example.org" dir="ltr">desk@example.org</a> with tips.</p>', false)
            ->assertDontSee('[[contact]]', false);
        $this->get('/ar/page/about')
            ->assertOk()
            ->assertSee('<p>راسلوا <a href="mailto:desk@example.org" dir="ltr">desk@example.org</a> للتواصل.</p>', false);
    }

    public function test_without_an_address_the_token_becomes_the_plain_phrase_in_each_language(): void
    {
        config(['site.contact.email' => null]);
        $this->page('<p>Write to [[contact]] with tips.</p>');

        $this->get('/en/page/about')
            ->assertOk()
            ->assertSee('<p>Write to the editorial team with tips.</p>', false)
            ->assertDontSee('[[contact]]', false)
            ->assertDontSee('mailto:', false);
        $this->get('/ar/page/about')
            ->assertOk()
            ->assertSee('<p>راسلوا فريق التحرير للتواصل.</p>', false)
            ->assertDontSee('mailto:', false);
    }

    #[DataProvider('unusableAddresses')]
    public function test_an_address_that_is_blank_or_not_a_clean_email_falls_back_to_the_phrase(mixed $configured): void
    {
        config(['site.contact.email' => $configured]);

        $this->assertNull(PageBody::contactEmail());
        $this->assertSame('<p>the editorial team</p>', PageBody::render('<p>[[contact]]</p>', Locale::En));
    }

    public static function unusableAddresses(): array
    {
        return [
            'empty' => [''],
            'spaces' => ['   '],
            'not an address' => ['not-an-address'],
            'quote breakout' => ['a"onmouseover="alert(1)@example.org'],
            'markup' => ['<script>alert(1)</script>@example.org'],
            'query string' => ['a?cc=x@example.org'],
            'an array' => [['desk@example.org']],
        ];
    }

    public function test_the_address_is_trimmed_and_escaped_into_the_link(): void
    {
        config(['site.contact.email' => '  desk+tips@example.org ']);

        $this->assertSame(
            '<p><a href="mailto:desk+tips@example.org" dir="ltr">desk+tips@example.org</a></p>',
            PageBody::render('<p>[[contact]]</p>', Locale::En),
        );
    }

    public function test_every_occurrence_is_replaced_including_inside_headings_and_lists(): void
    {
        config(['site.contact.email' => null]);

        $html = PageBody::render('<h2>Ask [[contact]]</h2><ul><li>[[contact]]</li></ul><p>[[contact]] or [[contact]]</p>', Locale::En);

        $this->assertSame('<h2>Ask the editorial team</h2><ul><li>the editorial team</li></ul><p>the editorial team or the editorial team</p>', $html);
    }

    public function test_inside_an_existing_link_the_token_is_text_so_links_never_nest(): void
    {
        config(['site.contact.email' => 'desk@example.org']);

        $html = PageBody::render('<p><a href="https://example.com/corrections">email [[contact]] now</a> or [[contact]]</p>', Locale::En);

        $this->assertSame(
            '<p><a href="https://example.com/corrections">email desk@example.org now</a> or <a href="mailto:desk@example.org" dir="ltr">desk@example.org</a></p>',
            $html,
        );
        $this->assertSame(1, substr_count($html, 'mailto:'));
    }

    public function test_a_token_inside_a_tag_attribute_is_left_alone_so_it_cannot_break_out_of_the_attribute(): void
    {
        config(['site.contact.email' => 'desk@example.org']);
        $this->page('<p><a href="https://example.com/" title="[[contact]]">link</a></p>');

        $stored = PageTranslation::query()->where('locale', 'en')->firstOrFail()->body;
        $this->assertStringContainsString('title="[[contact]]"', $stored, 'the sanitizer keeps the title attribute, so the test is meaningful');

        $html = PageBody::render($stored, Locale::En);

        $this->assertStringContainsString('title="[[contact]]"', $html);
        $this->assertStringNotContainsString('mailto:', $html);
    }

    public function test_a_body_without_the_token_is_returned_untouched(): void
    {
        $body = '<p>Plain &amp; simple.</p>';

        $this->assertSame($body, PageBody::render($body, Locale::Ar));
    }

    public function test_the_token_never_shows_in_the_meta_description_or_share_tags(): void
    {
        config(['site.contact.email' => null]);
        $this->page('<p>Write to [[contact]] with tips.</p>');

        $this->get('/en/page/about')
            ->assertOk()
            ->assertSee('<meta name="description" content="Write to the editorial team with tips.">', false);

        PageTranslation::query()->where('locale', 'en')->update(['meta_description' => 'Ask [[contact]] anything.']);
        config(['site.contact.email' => 'desk@example.org']);

        $this->get('/en/page/about')
            ->assertOk()
            ->assertSee('<meta name="description" content="Ask desk@example.org anything.">', false)
            ->assertSee('<meta property="og:description" content="Ask desk@example.org anything.">', false)
            ->assertDontSee('[[contact]]', false);
    }

    public function test_the_token_cannot_be_used_to_inject_markup_through_the_text_around_it(): void
    {
        config(['site.contact.email' => 'desk@example.org']);
        $this->page('<p>&lt;script&gt;alert(1)&lt;/script&gt; [[contact]]</p>');

        $html = $this->get('/en/page/about')->assertOk()->getContent();

        $this->assertStringContainsString('&lt;script&gt;alert(1)&lt;/script&gt; <a href="mailto:desk@example.org"', $html);
        $this->assertStringNotContainsString('<script>alert(1)', $html);
    }
}
