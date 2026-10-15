<?php

namespace Tests\Feature\Site;

use App\Enums\Locale;
use App\Enums\TranslationStatus;
use App\Models\ArticleTranslation;
use App\Services\ViewCounter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Session\ArraySessionHandler;
use Illuminate\Session\Store;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Feature\Site\Concerns\MakesStories;
use Tests\TestCase;

class ViewCounterTest extends TestCase
{
    use MakesStories, RefreshDatabase;

    private const BROWSER = 'Mozilla/5.0 (Linux; Android 14) AppleWebKit/537.36 Chrome/126.0 Mobile Safari/537.36';

    private function counter(): ViewCounter
    {
        return app(ViewCounter::class);
    }

    private function newSession(): Store
    {
        return new Store('test', new ArraySessionHandler(120));
    }

    private function request(Store $session, string $agent = self::BROWSER, string $method = 'GET'): Request
    {
        $request = Request::create('/en/news/1', $method, server: ['HTTP_USER_AGENT' => $agent]);
        $request->setLaravelSession($session);

        return $request;
    }

    private function views(ArticleTranslation $t): int
    {
        return (int) DB::table('article_daily_views')->where('article_translation_id', $t->id)->sum('views');
    }

    public function test_it_counts_a_visible_story_and_creates_today_row(): void
    {
        $t = $this->makeStory(Locale::En);

        $this->counter()->record($t, $this->request($this->newSession()));

        $row = DB::table('article_daily_views')->where('article_translation_id', $t->id)->first();
        $this->assertSame(1, (int) $row->views);
        $this->assertSame(now()->toDateString(), $row->day);
    }

    public function test_the_same_session_counts_a_story_only_once(): void
    {
        $t = $this->makeStory(Locale::En);
        $session = $this->newSession();

        foreach (range(1, 5) as $ignored) {
            $this->counter()->record($t, $this->request($session));
        }

        $this->assertSame(1, $this->views($t));
    }

    public function test_the_same_session_counts_different_stories_separately(): void
    {
        $a = $this->makeStory(Locale::En);
        $b = $this->makeStory(Locale::En);
        $session = $this->newSession();

        $this->counter()->record($a, $this->request($session));
        $this->counter()->record($b, $this->request($session));
        $this->counter()->record($a, $this->request($session));

        $this->assertSame(1, $this->views($a));
        $this->assertSame(1, $this->views($b));
    }

    public function test_different_sessions_accumulate_on_the_same_daily_row(): void
    {
        $t = $this->makeStory(Locale::En);

        foreach (range(1, 3) as $ignored) {
            $this->counter()->record($t, $this->request($this->newSession()));
        }

        $this->assertSame(1, DB::table('article_daily_views')->where('article_translation_id', $t->id)->count(), 'one row per story per day');
        $this->assertSame(3, $this->views($t));
    }

    public function test_a_new_day_starts_a_new_row(): void
    {
        $t = $this->makeStory(Locale::En);

        $this->counter()->record($t, $this->request($this->newSession()));
        $this->travel(1)->days();
        $this->counter()->record($t, $this->request($this->newSession()));

        $this->assertSame(2, DB::table('article_daily_views')->where('article_translation_id', $t->id)->count());
    }

    public function test_bots_and_link_previewers_are_ignored(): void
    {
        $t = $this->makeStory(Locale::En);

        foreach ([
            'Mozilla/5.0 (compatible; Googlebot/2.1; +http://www.google.com/bot.html)',
            'Mozilla/5.0 (compatible; bingbot/2.0)',
            'Mozilla/5.0 (compatible; AhrefsBot/7.0)',
            'Mozilla/5.0 (compatible; Yahoo! Slurp; http://help.yahoo.com/help/us/ysearch/slurp)',
            'Mozilla/5.0 (compatible; SomeCRAWLER/1.0)',
            'Mozilla/5.0 (compatible; Yandex Spider)',
            'Slackbot-LinkExpanding 1.0',
            'Mozilla/5.0 (Windows NT 10.0) Chrome/120 Safari/537.36 Link Preview',
        ] as $agent) {
            $this->counter()->record($t, $this->request($this->newSession(), $agent));
        }

        $this->assertSame(0, $this->views($t));
    }

    public function test_a_request_with_no_user_agent_is_not_mistaken_for_a_bot_or_a_crash(): void
    {
        $t = $this->makeStory(Locale::En);

        $this->counter()->record($t, $this->request($this->newSession(), ''));

        $this->assertSame(1, $this->views($t));
    }

    /** @return array<string, array{TranslationStatus}> */
    public static function invisibleStatuses(): array
    {
        return [
            'draft' => [TranslationStatus::Draft],
            'in review' => [TranslationStatus::InReview],
            'changes requested' => [TranslationStatus::ChangesRequested],
            'archived' => [TranslationStatus::Archived],
        ];
    }

    #[DataProvider('invisibleStatuses')]
    public function test_unpublished_stories_are_never_counted(TranslationStatus $status): void
    {
        $t = $this->makeStory(Locale::En, $status);

        $this->counter()->record($t, $this->request($this->newSession()));

        $this->assertSame(0, DB::table('article_daily_views')->count());
    }

    public function test_a_scheduled_story_is_not_counted_until_its_time(): void
    {
        $t = $this->makeStory(Locale::En, attributes: ['published_at' => now()->addHour()]);

        $this->counter()->record($t, $this->request($this->newSession()));
        $this->assertSame(0, $this->views($t));

        $this->travel(2)->hours();
        $this->counter()->record($t->fresh(), $this->request($this->newSession()));
        $this->assertSame(1, $this->views($t));
    }

    public function test_head_requests_and_sessionless_requests_are_not_counted(): void
    {
        $t = $this->makeStory(Locale::En);

        $this->counter()->record($t, $this->request($this->newSession(), method: 'HEAD'));
        $this->counter()->record($t, Request::create('/en/news/1', 'GET', server: ['HTTP_USER_AGENT' => self::BROWSER]));

        $this->assertSame(0, $this->views($t));
    }

    public function test_a_database_failure_is_reported_but_never_thrown_into_the_page(): void
    {
        Exceptions::fake();
        $t = $this->makeStory(Locale::En);
        Schema::drop('article_daily_views');
        $session = $this->newSession();

        $this->counter()->record($t, $this->request($session));

        Exceptions::assertReportedCount(1);
        $this->assertSame([], $session->get('viewed_translations', []), 'a failed count is retried on the next view');
    }

    public function test_a_page_view_over_http_counts_once_per_session_and_skips_bots(): void
    {
        $t = $this->makeStory(Locale::En);

        $this->get($t->url(), ['User-Agent' => self::BROWSER])->assertOk();
        $this->assertSame(1, $this->views($t));

        // A different client (no session cookie carried over) is a second reader.
        $this->flushSession();
        $this->get($t->url(), ['User-Agent' => self::BROWSER])->assertOk();
        $this->assertSame(2, $this->views($t));

        $this->get($t->url(), ['User-Agent' => 'Googlebot/2.1'])->assertOk();
        $this->assertSame(2, $this->views($t));
    }

    public function test_reloading_inside_one_session_over_http_does_not_inflate_the_count(): void
    {
        $t = $this->makeStory(Locale::En);

        $first = $this->get($t->url(), ['User-Agent' => self::BROWSER])->assertOk();
        $cookie = $first->getCookie(config('session.cookie'));
        $this->assertNotNull($cookie, 'the public site starts a session for the de-duplication');

        for ($i = 0; $i < 3; $i++) {
            $this->withCookie($cookie->getName(), $cookie->getValue())
                ->get($t->url(), ['User-Agent' => self::BROWSER])->assertOk();
        }

        $this->assertSame(1, $this->views($t));
    }
}
