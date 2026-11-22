<?php

namespace Tests\Feature;

use App\Editorial\EditorialWorkflow;
use App\Editorial\Transition;
use App\Enums\Locale;
use App\Enums\TranslationStatus;
use App\Filament\Resources\Users\Support\TeamGuard;
use App\Models\Article;
use App\Models\ArticleTranslation;
use App\Models\User;
use App\Translation\EnglishEdition;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/** The English edition follows the Arabic one automatically (App\Translation\EnglishEdition), with Gemini faked. */
class AutoTranslationTest extends TestCase
{
    use RefreshDatabase;

    private const GEMINI = 'generativelanguage.googleapis.com/*';

    private EditorialWorkflow $workflow;

    private User $editor;

    /** @var list<array<string, string>> answers the fake returns, in order */
    private array $answers = [];

    /** When set, the fake answers with this HTTP error instead. */
    private ?int $failWith = null;

    protected function setUp(): void
    {
        parent::setUp();
        app()->setLocale('en');
        config(['services.translator.gemini_key' => 'test-key', 'services.translator.model' => null]);
        // Run the "after the response" job at once, so a test sees its result.
        Bus::withoutDispatchingAfterResponses();
        $this->workflow = app(EditorialWorkflow::class);
        $this->editor = User::factory()->editor()->create();
        Http::fake([self::GEMINI => fn () => $this->failWith !== null
            ? Http::response(['error' => 'refused'], $this->failWith)
            : Http::response($this->geminiAnswer(array_shift($this->answers) ?? $this->english()))]);
    }

    /** @return array<string, string> */
    private function english(string $title = 'President El-Sisi Opens New Hospital in Giza'): array
    {
        return [
            'title' => $title,
            'excerpt' => 'A short summary.',
            'body' => '<p>The president opened the hospital on Saturday.</p><p>Second paragraph.</p>',
            'hero_caption' => '',
            'meta_description' => '',
        ];
    }

    private function geminiAnswer(array $fields): array
    {
        return ['candidates' => [['content' => ['parts' => [['text' => json_encode($fields)]]], 'finishReason' => 'STOP']]];
    }

    private function arabicDraft(): ArticleTranslation
    {
        return ArticleTranslation::factory()->arabic()->create([
            'article_id' => Article::factory()->create(['author_id' => $this->editor->id])->id,
        ])->fresh();
    }

    private function publishedArabic(): ArticleTranslation
    {
        return $this->workflow->publishNow($this->arabicDraft(), $this->editor)->fresh();
    }

    private function englishOf(ArticleTranslation $arabic): ?ArticleTranslation
    {
        return ArticleTranslation::query()->where('article_id', $arabic->article_id)->where('locale', 'en')->first();
    }

    // --- publication ------------------------------------------------------------------------

    public function test_publishing_the_arabic_publishes_an_english_translation_with_it(): void
    {
        $arabic = $this->publishedArabic();

        $english = $this->englishOf($arabic);
        $this->assertNotNull($english);
        $this->assertSame('President El-Sisi Opens New Hospital in Giza', $english->title);
        $this->assertSame('president-el-sisi-opens-new-hospital-in-giza', $english->slug);
        $this->assertSame(TranslationStatus::Published, $english->status);
        $this->assertTrue($english->isVisible());
        $this->assertSame($arabic->published_at->toDateTimeString(), $english->published_at->toDateTimeString(), 'same place in both line-ups');
        $this->assertNull($english->hero_caption, 'an empty Arabic field stays empty');
        $this->assertTrue(EnglishEdition::isMachineMade($english));
    }

    public function test_the_english_publication_goes_through_the_workflow_as_the_system_account(): void
    {
        $english = $this->englishOf($this->publishedArabic());

        $changes = $english->statusChanges()->with('user')->get();
        $this->assertSame([Transition::Approve, Transition::Submit], $changes->pluck('transition')->all());
        $this->assertSame([EnglishEdition::ACCOUNT_EMAIL], $changes->pluck('user.email')->unique()->values()->all());
    }

    public function test_the_story_is_sent_as_json_with_the_key_in_a_header_never_in_the_url(): void
    {
        $arabic = $this->publishedArabic();

        Http::assertSent(function (Request $request) use ($arabic): bool {
            return str_contains($request->url(), 'models/gemini-flash-latest:generateContent')
                && ! str_contains($request->url(), 'test-key')
                && $request->hasHeader('x-goog-api-key', 'test-key')
                && str_contains($request['contents'][0]['parts'][0]['text'], $arabic->title)
                && $request['generationConfig']['responseMimeType'] === 'application/json';
        });
    }

    public function test_the_translated_body_is_sanitized_like_any_other(): void
    {
        $this->answers[] = ['body' => '<p>Text</p><script>alert(1)</script>'] + $this->english();

        $english = $this->englishOf($this->publishedArabic());

        $this->assertStringNotContainsString('<script', (string) $english->body);
        $this->assertStringContainsString('<p>Text</p>', (string) $english->body);
    }

    public function test_a_scheduled_arabic_story_schedules_the_english_for_the_same_moment(): void
    {
        $at = now()->addDay()->startOfMinute();
        $arabic = $this->workflow->publishNow($this->arabicDraft(), $this->editor, $at);

        $english = $this->englishOf($arabic);
        $this->assertSame(TranslationStatus::Published, $english->status);
        $this->assertSame($at->toDateTimeString(), $english->published_at->toDateTimeString());
        $this->assertFalse($english->isVisible());
    }

    public function test_nothing_happens_without_a_key(): void
    {
        config(['services.translator.gemini_key' => null]);

        $arabic = $this->publishedArabic();

        $this->assertSame(TranslationStatus::Published, $arabic->status);
        $this->assertNull($this->englishOf($arabic));
        Http::assertNothingSent();
    }

    public function test_a_failing_service_leaves_the_arabic_published_and_no_english(): void
    {
        $this->failWith = 500;

        $arabic = $this->publishedArabic();

        $this->assertSame(TranslationStatus::Published, $arabic->status);
        $this->assertNull($this->englishOf($arabic));
    }

    public function test_an_answer_without_a_body_is_refused(): void
    {
        $this->answers[] = ['body' => ''] + $this->english();

        $this->assertNull($this->englishOf($this->publishedArabic()));
    }

    public function test_a_draft_or_review_does_not_translate(): void
    {
        $draft = $this->arabicDraft();
        $this->workflow->submit($draft, $this->editor);

        $this->assertNull($this->englishOf($draft));
        Http::assertNothingSent();
    }

    // --- later changes -----------------------------------------------------------------------

    public function test_editing_the_published_arabic_translates_again(): void
    {
        $arabic = $this->publishedArabic();
        $this->answers[] = $this->english('Corrected Headline');

        $arabic->update(['title' => 'عنوان مصحح للخبر']);

        $english = $this->englishOf($arabic);
        $this->assertSame('Corrected Headline', $english->title);
        $this->assertSame(TranslationStatus::Published, $english->status);
        Http::assertSentCount(2);
    }

    public function test_an_english_text_a_person_edited_is_never_overwritten(): void
    {
        $arabic = $this->publishedArabic();
        $this->englishOf($arabic)->update(['title' => 'Headline Polished by the Desk']);

        $arabic->update(['title' => 'عنوان مصحح للخبر']);

        $this->assertSame('Headline Polished by the Desk', $this->englishOf($arabic)->title);
        Http::assertSentCount(1);
    }

    public function test_an_english_version_written_by_a_person_is_left_alone(): void
    {
        $arabic = $this->arabicDraft();
        $english = ArticleTranslation::factory()->create(['article_id' => $arabic->article_id, 'locale' => Locale::En, 'title' => 'Written by Hand']);

        $this->workflow->publishNow($arabic, $this->editor);

        $this->assertSame('Written by Hand', $english->fresh()->title);
        $this->assertSame(TranslationStatus::Draft, $english->fresh()->status);
        Http::assertNothingSent();
    }

    public function test_unpublishing_the_arabic_unpublishes_the_machine_english_only(): void
    {
        $arabic = $this->publishedArabic();

        $this->workflow->archive($arabic, $this->editor);

        $english = $this->englishOf($arabic);
        $this->assertSame(TranslationStatus::Archived, $english->status);
        $this->assertSame(EnglishEdition::ACCOUNT_EMAIL, $english->statusChanges()->first()->user->email);
    }

    public function test_an_english_version_an_editor_unpublished_stays_unpublished(): void
    {
        $arabic = $this->publishedArabic();
        $this->workflow->archive($this->englishOf($arabic), $this->editor);

        $arabic->update(['title' => 'عنوان مصحح للخبر']);

        $this->assertSame(TranslationStatus::Archived, $this->englishOf($arabic)->status);
        Http::assertSentCount(1);
    }

    // --- catching up (cron) -------------------------------------------------------------------

    private function publishedArabicWithoutEnglish(string $publishedAt): ArticleTranslation
    {
        config(['services.translator.gemini_key' => null]);
        $arabic = $this->publishedArabic();
        $arabic->forceFill(['published_at' => $publishedAt])->saveQuietly();
        config(['services.translator.gemini_key' => 'test-key']);

        return $arabic;
    }

    public function test_the_command_translates_the_newest_stories_first_up_to_the_limit(): void
    {
        $old = $this->publishedArabicWithoutEnglish('2026-11-01 08:00:00');
        $new = $this->publishedArabicWithoutEnglish('2026-11-20 08:00:00');

        $this->artisan('sisi:translate-english', ['--limit' => 1, '--pause' => 0])
            ->expectsOutputToContain('2 stories need an English version')
            ->assertSuccessful();

        $this->assertNotNull($this->englishOf($new));
        $this->assertNull($this->englishOf($old));
        $this->assertSame('2026-11-20 08:00:00', $this->englishOf($new)->published_at->toDateTimeString(), 'not dated today');

        $this->artisan('sisi:translate-english', ['--pause' => 0])->assertSuccessful();
        $this->assertNotNull($this->englishOf($old));

        $this->artisan('sisi:translate-english')
            ->expectsOutputToContain('Every published Arabic story has an up-to-date English version')
            ->assertSuccessful();
    }

    public function test_the_command_can_leave_the_back_catalogue_alone(): void
    {
        $old = $this->publishedArabicWithoutEnglish(now()->subDays(3)->toDateTimeString());
        $recent = $this->publishedArabicWithoutEnglish(now()->subHours(2)->toDateTimeString());

        $this->artisan('sisi:translate-english', ['--hours' => 24, '--pause' => 0])
            ->expectsOutputToContain('1 stories need an English version')
            ->assertSuccessful();

        $this->assertNotNull($this->englishOf($recent));
        $this->assertNull($this->englishOf($old));
    }

    public function test_the_command_dry_run_changes_nothing(): void
    {
        $arabic = $this->publishedArabicWithoutEnglish('2026-11-20 08:00:00');

        $this->artisan('sisi:translate-english', ['--dry-run' => true])->assertSuccessful();

        $this->assertNull($this->englishOf($arabic));
        Http::assertNothingSent();
    }

    public function test_the_command_stops_when_the_service_asks_to_slow_down(): void
    {
        $this->publishedArabicWithoutEnglish('2026-11-01 08:00:00');
        $this->publishedArabicWithoutEnglish('2026-11-20 08:00:00');
        $this->failWith = 429;

        $this->artisan('sisi:translate-english', ['--pause' => 0])
            ->expectsOutputToContain('asked to slow down')
            ->assertFailed();

        Http::assertSentCount(1);
    }

    public function test_the_command_refuses_to_run_without_a_key(): void
    {
        config(['services.translator.gemini_key' => null]);

        $this->artisan('sisi:translate-english')->assertFailed();
    }

    // --- the system account -----------------------------------------------------------------

    public function test_the_system_account_cannot_sign_in_and_is_not_in_the_team_area(): void
    {
        $this->publishedArabic();
        $account = User::query()->where('email', EnglishEdition::ACCOUNT_EMAIL)->sole();

        $this->assertSame(TeamGuard::AUTO_TRANSLATION_EMAIL, EnglishEdition::ACCOUNT_EMAIL);
        $this->assertFalse($account->canAccessPanel(Filament::getPanel('admin')));
        $this->assertTrue(TeamGuard::isReservedEmail($account->email));
        $this->assertFalse(TeamGuard::withoutProtectedAccounts(User::query())->whereKey($account->id)->exists());
        $this->assertTrue($this->editor->canAccessPanel(Filament::getPanel('admin')));
    }
}
