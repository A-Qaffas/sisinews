<?php

namespace Tests\Feature\Deploy;

use App\Models\Page;
use App\Models\PageTranslation;
use App\Support\HtmlSanitizer;
use Database\Seeders\PageSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * deploy/one-off/2026-10-13-privacy-live-embeds.php adds the "Live video" text to the privacy page that is already in
 * the production database (PageSeeder never changes an existing page). database/pages/pages.json must carry the very
 * same text, so a fresh install and the updated live site say the same thing.
 */
class PrivacyLiveEmbedsOneOffTest extends TestCase
{
    use RefreshDatabase;

    public static function setUpBeforeClass(): void
    {
        parent::setUpBeforeClass();

        if (! defined('SISI_ONE_OFF_LIBRARY_ONLY')) {
            define('SISI_ONE_OFF_LIBRARY_ONLY', true);
        }
        require_once dirname(__DIR__, 3).'/deploy/one-off/2026-10-13-privacy-live-embeds.php';
    }

    /** @return string the privacy body in pages.json */
    private function seeded(string $locale): string
    {
        $data = json_decode((string) file_get_contents(database_path('pages/pages.json')), true, flags: JSON_THROW_ON_ERROR);

        return collect($data['pages'])->firstWhere('slug', 'privacy')[$locale]['body'];
    }

    /** The body as it was before this update: no section, the old bullet. */
    private function before(string $locale): string
    {
        $text = sisi_privacy_live_embeds_texts()[$locale];
        $body = str_replace("\n".$text['section'], '', $this->seeded($locale));

        return str_replace($text['new_bullet'], $text['old_bullet'], $body);
    }

    /** @return array<string, array{0: string}> */
    public static function locales(): array
    {
        return ['English' => ['en'], 'Arabic' => ['ar']];
    }

    #[DataProvider('locales')]
    public function test_pages_json_already_says_it_so_the_script_finds_nothing_to_change(string $locale): void
    {
        $text = sisi_privacy_live_embeds_texts()[$locale];
        $body = $this->seeded($locale);

        $this->assertStringContainsString($text['section'], $body);
        $this->assertStringContainsString('<li>'.$text['new_bullet'].'</li>', $body);
        $this->assertStringNotContainsString($text['old_bullet'], $body);

        [$after, $notes] = sisi_privacy_live_embeds_apply($body, $locale);

        $this->assertSame($body, $after);
        $this->assertCount(2, $notes);
        $this->assertStringContainsString('already there', $notes[0]);
        $this->assertStringContainsString('already there', $notes[1]);
    }

    #[DataProvider('locales')]
    public function test_the_old_live_text_becomes_exactly_the_pages_json_text(string $locale): void
    {
        $text = sisi_privacy_live_embeds_texts()[$locale];
        $old = $this->before($locale);
        $this->assertStringNotContainsString($text['section'], $old);
        $this->assertStringContainsString('<li>'.$text['old_bullet'].'</li>', $old);

        [$after, $notes] = sisi_privacy_live_embeds_apply($old, $locale);

        $this->assertSame($this->seeded($locale), $after);
        $this->assertStringContainsString('after the text-size paragraph', $notes[0]);
        $this->assertStringContainsString('replaced', $notes[1]);
        $this->assertLessThan(strpos($after, '<h2>'.$text['next_heading'].'</h2>'), strpos($after, $text['section']), 'the section comes before "What we do not collect"');
        $this->assertGreaterThan(strpos($after, $text['anchor']), strpos($after, $text['section']), 'and after the text-size paragraph');
    }

    public function test_a_body_reflowed_by_the_panel_editor_is_still_updated(): void
    {
        $text = sisi_privacy_live_embeds_texts()['en'];
        // The rich editor drops the line breaks and wraps list items in paragraphs.
        $body = '<p>Intro.</p><p>Your browser keeps any text size you choose in its local storage,  on your own device. We never receive it.</p>'
            .'<h2>What we do not collect</h2><ul><li><p>No reader accounts.</p></li><li><p>'.$text['old_bullet'].'</p></li></ul>';

        [$after, $notes] = sisi_privacy_live_embeds_apply($body, 'en');

        $this->assertStringContainsString("We never receive it.</p>\n".$text['section'].'<h2>What we do not collect</h2>', $after);
        $this->assertStringContainsString('<li><p>'.$text['new_bullet'].'</p></li>', $after);
        $this->assertStringNotContainsString('do not currently load', $after);
        $this->assertSame($after, sisi_privacy_live_embeds_apply($after, 'en')[0], 'a second pass changes nothing');
        $this->assertStringNotContainsString('CHECK BY HAND', implode(' ', $notes));
    }

    public function test_without_the_text_size_paragraph_the_section_goes_before_what_we_do_not_collect(): void
    {
        $text = sisi_privacy_live_embeds_texts()['ar'];
        $body = "<p>مقدمة.</p>\n<h2>ما لا نجمعه</h2>\n<ul>\n<li>{$text['old_bullet']}</li>\n</ul>";

        [$after] = sisi_privacy_live_embeds_apply($body, 'ar');

        $this->assertStringContainsString($text['section']."\n<h2>ما لا نجمعه</h2>", $after);
    }

    public function test_a_page_rewritten_beyond_recognition_is_left_alone_and_reported(): void
    {
        $body = '<p>A completely different privacy policy.</p>';

        [$after, $notes] = sisi_privacy_live_embeds_apply($body, 'en');

        $this->assertSame($body, $after);
        $this->assertCount(2, $notes);
        $this->assertStringStartsWith('CHECK BY HAND', $notes[0]);
        $this->assertStringStartsWith('CHECK BY HAND', $notes[1]);
    }

    public function test_it_updates_the_stored_privacy_page_through_the_model_and_a_second_run_changes_nothing(): void
    {
        (new PageSeeder)->setContainer($this->app)->__invoke();
        $privacy = Page::query()->where('slug', 'privacy')->firstOrFail();
        // The live site still has the text from before this update.
        foreach (['en', 'ar'] as $locale) {
            $privacy->translations()->where('locale', $locale)->firstOrFail()->update(['body' => $this->before($locale)]);
        }
        $stored = fn (string $locale): string => (string) PageTranslation::query()->where('page_id', $privacy->id)->where('locale', $locale)->value('body');
        $this->get('/en/page/privacy')->assertOk()->assertDontSee('Live video');

        [$lines, $complete] = sisi_privacy_live_embeds_run(write: false);
        $this->assertTrue($complete);
        $this->assertStringContainsString('dry run', $lines[0]);
        $this->assertSame(HtmlSanitizer::clean($this->before('en')), $stored('en'), 'a dry run writes nothing');

        [$lines, $complete] = sisi_privacy_live_embeds_run(write: true);
        $this->assertTrue($complete);
        $this->assertCount(2, $lines);
        $this->assertStringStartsWith('privacy [ar]: added', $lines[0]);
        $this->assertStringEndsWith('Saved.', $lines[1]);
        foreach (['en', 'ar'] as $locale) {
            $this->assertSame(HtmlSanitizer::clean($this->seeded($locale)), $stored($locale), "{$locale}: the same text as a fresh install");
        }
        $this->get('/en/page/privacy')->assertOk()->assertSee('Live video')->assertSee('youtube-nocookie.com')->assertDontSee('do not currently load');
        $this->get('/ar/page/privacy')->assertOk()->assertSee('<h2>البث المباشر</h2>', false);

        [$lines, $complete] = sisi_privacy_live_embeds_run(write: true);
        $this->assertTrue($complete);
        $this->assertStringEndsWith('Nothing to change.', $lines[0]);
        $this->assertStringEndsWith('Nothing to change.', $lines[1]);
    }

    public function test_without_a_privacy_page_it_changes_nothing_and_says_so(): void
    {
        [$lines, $complete] = sisi_privacy_live_embeds_run(write: true);

        $this->assertFalse($complete);
        $this->assertStringContainsString('no page with the slug "privacy"', $lines[0]);
    }
}
