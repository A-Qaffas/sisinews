<?php

namespace Tests\Feature\Site;

use Tests\TestCase;

/**
 * Layout rules of the Live page that a rendered-HTML test cannot see. They read the sources Vite bundles
 * (resources/css/live.css is imported by app.css; every resources/js/*.js is bundled by app.js).
 */
class LiveStylesTest extends TestCase
{
    private function css(): string
    {
        return (string) file_get_contents(resource_path('css/live.css'));
    }

    /** @return string the body of the first `@media (width < 40rem)` block that styles .live-chips */
    private function phoneChipRules(): string
    {
        $this->assertSame(1, preg_match('/@media \(width < 40rem\) \{\s*\.live-chips \{(.*?)\n\}\n/s', $this->css(), $match), 'the phone block for the chips is missing');

        return $match[1];
    }

    public function test_on_a_phone_the_governorate_chips_are_one_sideways_row_not_eight_rows_above_the_player(): void
    {
        $rules = $this->phoneChipRules();

        $this->assertStringContainsString('flex-wrap: nowrap;', $rules);
        $this->assertStringContainsString('overflow-x: auto;', $rules);
        $this->assertStringContainsString('white-space: nowrap;', $rules);
    }

    public function test_the_selected_chip_is_scrolled_into_view_without_moving_the_page(): void
    {
        $js = (string) file_get_contents(resource_path('js/live-filter.js'));

        $this->assertStringContainsString('.live-chips [aria-current="page"]', $js);
        $this->assertStringContainsString('row.scrollBy(', $js);
        $this->assertStringNotContainsString('scrollIntoView', $js, 'scrollIntoView can scroll the whole page');
        $this->assertStringContainsString("import.meta.glob(['./*.js', '!./app.js']", (string) file_get_contents(resource_path('js/app.js')), 'app.js bundles every feature script');
    }

    public function test_a_fallback_title_keeps_its_direction_but_lines_up_with_the_page(): void
    {
        $css = $this->css();

        $this->assertSame(1, preg_match('/((?:\.[a-z_-]+\[dir\],?\s*)+)\{\s*text-align: match-parent;/', $css, $match));
        foreach (['.live-card__title', '.live-row__title', '.live-ended__title', '.live-desc', '.live-strip__title'] as $selector) {
            $this->assertStringContainsString("{$selector}[dir]", $match[1]);
        }
    }

    public function test_the_phone_live_link_only_shows_below_md_and_only_with_javascript(): void
    {
        $css = $this->css();

        $this->assertSame(1, preg_match('/\.live-quick \{\s*display: none;/', $css));
        $this->assertSame(1, preg_match('/@media \(width < 48rem\) \{\s*\.js \.live-quick \{\s*display: inline-flex;/', $css));
    }
}
