<?php

namespace Tests\Feature;

use App\Enums\Locale;
use App\Models\NewsletterSubscriber;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class NewsletterUnsubscribeTest extends TestCase
{
    use RefreshDatabase;

    /** Symfony's test client sends "en-us" unless told otherwise, so Arabic has to be asked for. */
    private const AR = ['Accept-Language' => 'ar-EG,ar;q=0.9'];

    private function subscriber(array $attributes = []): NewsletterSubscriber
    {
        return NewsletterSubscriber::create($attributes + ['email' => 'reader@example.com', 'locale' => Locale::Ar]);
    }

    private function unknownToken(): string
    {
        return Str::random(40);
    }

    public function test_the_link_shows_a_confirmation_form_and_changes_nothing(): void
    {
        $subscriber = $this->subscriber();

        $this->get(route('newsletter.unsubscribe', $subscriber->unsubscribe_token), self::AR)
            ->assertOk()
            ->assertSee(__('newsletter.unsubscribe.title', [], 'ar'), false)
            ->assertSee(route('newsletter.unsubscribe.confirm', $subscriber->unsubscribe_token), false)
            ->assertSee('name="_token"', false)
            ->assertSee('noindex', false);

        $this->assertNull($subscriber->fresh()->unsubscribed_at);
    }

    public function test_confirming_unsubscribes_the_address(): void
    {
        $subscriber = $this->subscriber();
        $other = $this->subscriber(['email' => 'other@example.com']);

        $this->post(route('newsletter.unsubscribe.confirm', $subscriber->unsubscribe_token), [], self::AR)
            ->assertOk()
            ->assertSee(__('newsletter.unsubscribe.done_title', [], 'ar'), false);

        $this->assertNotNull($subscriber->fresh()->unsubscribed_at);
        $this->assertNull($other->fresh()->unsubscribed_at);
    }

    public function test_confirming_twice_keeps_the_first_timestamp(): void
    {
        $subscriber = $this->subscriber();

        $this->post(route('newsletter.unsubscribe.confirm', $subscriber->unsubscribe_token));
        $first = $subscriber->fresh()->unsubscribed_at;
        $this->travel(1)->hour();
        $this->post(route('newsletter.unsubscribe.confirm', $subscriber->unsubscribe_token))->assertOk();

        $this->assertEquals($first, $subscriber->fresh()->unsubscribed_at);
    }

    public function test_an_unknown_token_gets_the_same_neutral_pages_not_a_404(): void
    {
        $known = $this->subscriber();
        $unknown = $this->unknownToken();

        $knownPage = $this->get(route('newsletter.unsubscribe', $known->unsubscribe_token));
        $unknownPage = $this->get(route('newsletter.unsubscribe', $unknown));
        $this->assertSame(200, $unknownPage->getStatusCode());
        $this->assertSame(
            str_replace($known->unsubscribe_token, 'TOKEN', $this->withoutCsrf($knownPage->getContent())),
            str_replace($unknown, 'TOKEN', $this->withoutCsrf($unknownPage->getContent())),
        );

        $knownDone = $this->post(route('newsletter.unsubscribe.confirm', $known->unsubscribe_token));
        $unknownDone = $this->post(route('newsletter.unsubscribe.confirm', $unknown));
        $this->assertSame(200, $unknownDone->getStatusCode());
        $this->assertSame(
            str_replace($known->unsubscribe_token, 'TOKEN', $this->withoutCsrf($knownDone->getContent())),
            str_replace($unknown, 'TOKEN', $this->withoutCsrf($unknownDone->getContent())),
        );
    }

    public function test_a_malformed_token_never_reaches_the_controller(): void
    {
        $this->get('/newsletter/unsubscribe/short')->assertNotFound();
        $this->get('/newsletter/unsubscribe/'.str_repeat('a', 41))->assertNotFound();
        $this->get('/newsletter/unsubscribe/'.str_repeat('a', 39).'!')->assertNotFound();
        $this->post('/newsletter/unsubscribe/short')->assertNotFound();
    }

    public function test_the_page_follows_the_browser_language(): void
    {
        $token = $this->subscriber()->unsubscribe_token;

        $this->get(route('newsletter.unsubscribe', $token), ['Accept-Language' => 'en-US,en;q=0.9'])
            ->assertOk()
            ->assertSee(__('newsletter.unsubscribe.title', [], 'en'), false)
            ->assertSee('dir="ltr"', false);

        $this->get(route('newsletter.unsubscribe', $token), ['Accept-Language' => 'ar-EG,ar;q=0.9'])
            ->assertOk()
            ->assertSee(__('newsletter.unsubscribe.title', [], 'ar'), false)
            ->assertSee('dir="rtl"', false);
    }

    public function test_the_language_does_not_depend_on_whether_the_token_exists(): void
    {
        $known = $this->subscriber(['locale' => Locale::En]);

        $this->get(route('newsletter.unsubscribe', $known->unsubscribe_token), self::AR)->assertSee('dir="rtl"', false);
        $this->get(route('newsletter.unsubscribe', $this->unknownToken()), self::AR)->assertSee('dir="rtl"', false);
    }

    public function test_an_unsubscribed_address_can_subscribe_again_later(): void
    {
        $subscriber = $this->subscriber();
        $this->post(route('newsletter.unsubscribe.confirm', $subscriber->unsubscribe_token));

        $this->post(route('newsletter.subscribe'), ['email' => 'reader@example.com', 'locale' => 'ar']);

        $this->assertNull($subscriber->fresh()->unsubscribed_at);
    }

    /** Just the page's own section, without the CSRF token (and without layout details such as Vite's once-per-process preloads). */
    private function withoutCsrf(string $html): string
    {
        preg_match('#<section.*?</section>#s', $html, $section);

        return preg_replace('/name="_token" value="[^"]+"/', 'name="_token" value="X"', $section[0]);
    }
}
