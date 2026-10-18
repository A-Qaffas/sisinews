<?php

namespace Tests\Feature\Site;

use App\Models\Page;
use App\Services\SiteSettings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * The masthead credits (رئيس مجلس الإدارة / رئيس التحرير) beside the logo from md up, on a slim line of their own on
 * phones, and in the footer's bottom bar. Names come from the site settings an admin edits.
 */
class MastheadCreditsTest extends TestCase
{
    use RefreshDatabase;

    private const CHAIRMAN_AR = 'تغريد رفاعي';

    private const EDITOR_AR = 'ناجي أبو مغنم';

    private function storeName(string $key, ?string $value): void
    {
        app(SiteSettings::class)->set($key, $value);
    }

    private function arabicNames(): void
    {
        $this->storeName('masthead_chairman_ar', self::CHAIRMAN_AR);
        $this->storeName('masthead_editor_ar', self::EDITOR_AR);
    }

    /**
     * The lines of one variant, as "label: name [lang]", or null when that variant is not in the page at all.
     *
     * @return list<string>|null
     */
    private function lines(string $html, string $variant): ?array
    {
        if (preg_match('#<dl class="masthead-credits masthead-credits--'.$variant.'[^"]*">(.*?)</dl>#s', $html, $list) !== 1) {
            return null;
        }
        preg_match_all('#<div><dt>([^<]*)</dt> <dd><bdi lang="([a-z]{2})">([^<]*)</bdi></dd></div>#', $list[1], $lines, PREG_SET_ORDER);

        return array_map(fn (array $line): string => html_entity_decode($line[1]).' '.html_entity_decode($line[3]).' ['.$line[2].']', $lines);
    }

    /** @return array<string, list<string>|null> */
    private function everyVariant(string $html): array
    {
        return [
            'header' => $this->lines($html, 'header'),
            'strip' => $this->lines($html, 'strip'),
            'footer' => $this->lines($html, 'footer'),
        ];
    }

    public function test_nothing_is_printed_while_no_name_is_set(): void
    {
        foreach (['/ar', '/en'] as $url) {
            $this->get($url)->assertOk()->assertDontSee('masthead-credits', false);
        }
    }

    public function test_arabic_pages_print_both_credits_in_the_header_on_phones_and_in_the_footer(): void
    {
        $this->arabicNames();

        $html = $this->get('/ar')->assertOk()->getContent();

        $expected = ['رئيس مجلس الإدارة: تغريد رفاعي [ar]', 'رئيس التحرير: ناجي أبو مغنم [ar]'];
        $this->assertSame(['header' => $expected, 'strip' => $expected, 'footer' => $expected], $this->everyVariant($html));
    }

    public function test_the_credits_sit_beside_the_logo_under_the_header_row_on_phones_and_before_the_copyright(): void
    {
        $this->arabicNames();

        $html = $this->get('/ar')->assertOk()->getContent();

        // md up: in the logo's group, right after the logo, with the tagline still at the far end of the row. Below md
        // the group must not shrink, or the Live link and the Menu button would slide over the logo on a narrow phone.
        $this->assertSame(1, preg_match('#<div class="flex shrink-0 items-center gap-4 md:min-w-0 md:shrink lg:gap-5">\s*<a href="[^"]*/ar" class="shrink-0">\s*<img src="[^"]*logo-380\.webp"[^>]*>\s*</a>\s*<dl class="masthead-credits masthead-credits--header hidden md:grid">.*?</dl>\s*</div>\s*<p class="masthead-tagline hidden shrink-0 md:block">مصر في بؤرة الضوء</p>#s', $html));
        // Phones: the row keeps only the logo, the Live link and the Menu button; the credits follow it, before the menu.
        $this->assertSame(1, preg_match('#data-nav-toggle.*?</button>\s*</div>\s*</div>\s*<dl class="masthead-credits masthead-credits--strip container-site md:hidden">.*?</dl>\s*<nav id="site-nav"#s', $html));
        // Footer: the imprint line comes before the copyright in the bottom bar.
        $this->assertSame(1, preg_match('#<div class="footer-base on-dark">\s*<div[^>]*>\s*<dl class="masthead-credits masthead-credits--footer">.*?</dl>\s*<p>©#su', $html));
    }

    public function test_english_pages_never_borrow_the_arabic_names(): void
    {
        $this->arabicNames();

        $html = $this->get('/en')->assertOk()->getContent();

        $this->assertSame(['header' => null, 'strip' => null, 'footer' => null], $this->everyVariant($html));
        $this->assertStringNotContainsString(self::CHAIRMAN_AR, $html);
        $this->assertStringNotContainsString(self::EDITOR_AR, $html);
        $this->assertStringNotContainsString('رئيس', $html);
    }

    public function test_english_pages_print_the_english_names_that_are_filled_in(): void
    {
        $this->arabicNames();
        $this->storeName('masthead_editor_en', 'Nagy Abou Meghnem');

        $en = $this->get('/en')->assertOk()->getContent();
        $expected = ['Editor-in-Chief: Nagy Abou Meghnem [en]'];
        $this->assertSame(['header' => $expected, 'strip' => $expected, 'footer' => $expected], $this->everyVariant($en));
        $this->assertStringNotContainsString('Chairman of the Board', $en);

        $this->storeName('masthead_chairman_en', 'Taghreed Refaie');
        $this->assertSame(
            ['Chairman of the Board: Taghreed Refaie [en]', 'Editor-in-Chief: Nagy Abou Meghnem [en]'],
            $this->lines($this->get('/en')->getContent(), 'header'),
        );

        // The Arabic pages keep their own names.
        $this->assertSame(
            ['رئيس مجلس الإدارة: تغريد رفاعي [ar]', 'رئيس التحرير: ناجي أبو مغنم [ar]'],
            $this->lines($this->get('/ar')->getContent(), 'footer'),
        );
    }

    public function test_each_line_shows_only_while_its_name_is_set(): void
    {
        $this->storeName('masthead_editor_ar', self::EDITOR_AR);
        $this->assertSame(['رئيس التحرير: ناجي أبو مغنم [ar]'], $this->lines($this->get('/ar')->getContent(), 'header'));

        $this->storeName('masthead_editor_ar', '');
        $this->get('/ar')->assertOk()->assertDontSee('masthead-credits', false);
    }

    public function test_a_name_is_escaped_and_isolated_from_the_label(): void
    {
        $this->storeName('masthead_editor_ar', '<img src=x onerror=alert(1)> Nagy');

        $this->get('/ar')
            ->assertOk()
            ->assertSee('<bdi lang="ar">&lt;img src=x onerror=alert(1)&gt; Nagy</bdi>', false)
            ->assertDontSee('<img src=x', false);
    }

    public function test_the_credits_come_from_the_cache_without_a_query_of_their_own(): void
    {
        $this->arabicNames();
        $count = 0;
        DB::listen(function ($query) use (&$count): void {
            $count += (int) str_contains($query->sql, 'site_settings');
        });
        $settingsQueries = function (string $url) use (&$count): int {
            $count = 0;
            $this->get($url)->assertOk();

            return $count;
        };

        // The header (beside the logo and the phone line) and the footer share one read of every setting.
        $this->assertSame(1, $settingsQueries('/ar'), 'cold cache');

        $this->assertSame(0, $settingsQueries('/ar'), 'warm cache');
        $this->assertSame(0, $settingsQueries('/en'), 'the other language is in the same cached array');
    }

    public function test_other_public_pages_print_them_too(): void
    {
        $this->arabicNames();
        $this->storeName('masthead_chairman_en', 'Taghreed Refaie');
        Page::factory()->bilingual()->create(['slug' => 'about']);

        $this->assertNotNull($this->lines($this->get('/ar/search?q=news')->assertOk()->getContent(), 'header'));
        $this->assertNotNull($this->lines($this->get('/en/live')->assertOk()->getContent(), 'footer'));
        $this->assertNotNull($this->lines($this->get('/en/page/about')->assertOk()->getContent(), 'strip'));
    }

    public function test_error_pages_print_no_credits_and_still_touch_no_database(): void
    {
        $this->arabicNames();
        // Saving dropped the cached settings, so reading them now would show up as a query.
        $this->storeName('masthead_editor_en', 'Nagy Abou Meghnem');

        $queries = [];
        DB::listen(function ($query) use (&$queries): void {
            $queries[] = $query->sql;
        });

        $this->get('/ar/no-such-page')->assertNotFound()->assertDontSee('masthead-credits', false);
        $this->get('/en/no-such-page')->assertNotFound()->assertDontSee('masthead-credits', false);
        foreach ([404, 419, 429, 500, 503] as $status) {
            $this->assertStringNotContainsString('masthead-credits', view("errors.{$status}")->render(), (string) $status);
        }

        $this->assertSame([], $queries);
    }
}
