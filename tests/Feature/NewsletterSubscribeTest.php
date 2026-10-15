<?php

namespace Tests\Feature;

use App\Enums\Locale;
use App\Models\NewsletterSubscriber;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

class NewsletterSubscribeTest extends TestCase
{
    use RefreshDatabase;

    private function subscribe(array $data, string $from = 'http://localhost/ar')
    {
        return $this->from($from)->post(route('newsletter.subscribe'), $data);
    }

    public function test_a_new_address_is_stored_lowercased_with_a_token_and_the_page_language(): void
    {
        $response = $this->subscribe(['email' => '  Reader@Example.COM ', 'locale' => 'en'], 'http://localhost/en');

        $response->assertRedirect('http://localhost/en#newsletter')
            ->assertSessionHas('newsletter_status', __('newsletter.subscribed', [], 'en'))
            ->assertSessionHasNoErrors();

        $subscriber = NewsletterSubscriber::sole();
        $this->assertSame('reader@example.com', $subscriber->email);
        $this->assertSame(Locale::En, $subscriber->locale);
        $this->assertMatchesRegularExpression('/^[A-Za-z0-9]{40}$/', $subscriber->unsubscribe_token);
        $this->assertNull($subscriber->unsubscribed_at);
    }

    public function test_the_confirmation_is_in_the_language_of_the_form(): void
    {
        $this->subscribe(['email' => 'a@example.com', 'locale' => 'ar'])
            ->assertSessionHas('newsletter_status', 'شكرًا لك! تمت إضافة بريدك الإلكتروني إلى نشرتنا البريدية.');
    }

    public function test_locale_defaults_to_arabic_when_absent(): void
    {
        $this->subscribe(['email' => 'a@example.com'])->assertSessionHas('newsletter_status', __('newsletter.subscribed', [], 'ar'));

        $this->assertSame(Locale::Ar, NewsletterSubscriber::sole()->locale);
    }

    public function test_an_unknown_locale_is_rejected_and_nothing_is_stored(): void
    {
        $this->subscribe(['email' => 'a@example.com', 'locale' => 'fr'])
            ->assertRedirect('http://localhost/ar#newsletter')
            ->assertSessionHasErrors('locale');

        $this->assertDatabaseCount('newsletter_subscribers', 0);
    }

    public function test_an_existing_active_address_gets_the_identical_response_and_no_duplicate(): void
    {
        $first = $this->subscribe(['email' => 'dup@example.com', 'locale' => 'ar']);
        $token = NewsletterSubscriber::sole()->unsubscribe_token;

        $second = $this->subscribe(['email' => 'DUP@example.com', 'locale' => 'en']);

        $this->assertSame($first->headers->get('Location'), $second->headers->get('Location'));
        $second->assertSessionHas('newsletter_status', __('newsletter.subscribed', [], 'en'))->assertSessionHasNoErrors();
        $this->assertDatabaseCount('newsletter_subscribers', 1);
        $subscriber = NewsletterSubscriber::sole();
        $this->assertSame($token, $subscriber->unsubscribe_token);
        $this->assertSame(Locale::Ar, $subscriber->locale, 'a stranger re-submitting an address must not change its preferences');
    }

    public function test_resubscribing_after_unsubscribing_reactivates_the_address(): void
    {
        $subscriber = NewsletterSubscriber::create(['email' => 'back@example.com', 'locale' => Locale::Ar, 'unsubscribed_at' => now()->subWeek()]);

        $this->subscribe(['email' => 'back@example.com', 'locale' => 'en'])
            ->assertRedirect('http://localhost/ar#newsletter')
            ->assertSessionHas('newsletter_status');

        $subscriber->refresh();
        $this->assertNull($subscriber->unsubscribed_at);
        $this->assertSame(Locale::En, $subscriber->locale);
        $this->assertDatabaseCount('newsletter_subscribers', 1);
    }

    public function test_new_existing_and_unsubscribed_addresses_cannot_be_told_apart(): void
    {
        NewsletterSubscriber::create(['email' => 'active@example.com', 'locale' => Locale::Ar]);
        NewsletterSubscriber::create(['email' => 'gone@example.com', 'locale' => Locale::Ar, 'unsubscribed_at' => now()]);

        $seen = [];
        foreach (['fresh@example.com', 'active@example.com', 'gone@example.com'] as $email) {
            $response = $this->subscribe(['email' => $email, 'locale' => 'ar']);
            $seen[] = [$response->getStatusCode(), $response->headers->get('Location'), session('newsletter_status'), session('errors')?->all()];
        }

        $this->assertCount(1, array_unique($seen, SORT_REGULAR), 'every outcome must look the same');
    }

    public function test_the_honeypot_pretends_success_and_stores_nothing(): void
    {
        $response = $this->subscribe(['email' => 'bot@example.com', 'locale' => 'ar', 'website' => 'https://spam.example']);

        $response->assertRedirect('http://localhost/ar#newsletter')
            ->assertSessionHas('newsletter_status', __('newsletter.subscribed', [], 'ar'))
            ->assertSessionHasNoErrors();
        $this->assertDatabaseCount('newsletter_subscribers', 0);
    }

    public function test_an_empty_honeypot_is_ignored(): void
    {
        $this->subscribe(['email' => 'human@example.com', 'locale' => 'ar', 'website' => ''])->assertSessionHas('newsletter_status');

        $this->assertDatabaseCount('newsletter_subscribers', 1);
    }

    public function test_invalid_addresses_are_rejected_with_a_message_in_the_page_language(): void
    {
        $this->withoutMiddleware(ThrottleRequests::class);

        foreach (['', 'not-an-email', 'a@', '@example.com', 'a b@example.com', str_repeat('a', 250).'@example.com'] as $email) {
            $this->subscribe(['email' => $email, 'locale' => 'en'], 'http://localhost/en')
                ->assertRedirect('http://localhost/en#newsletter')
                ->assertSessionHasErrors(['email' => __('newsletter.invalid_email', [], 'en')])
                ->assertSessionMissing('newsletter_status');
        }

        $this->assertDatabaseCount('newsletter_subscribers', 0);
    }

    public function test_the_arabic_error_message_is_shown_to_arabic_visitors(): void
    {
        $this->subscribe(['email' => 'nope', 'locale' => 'ar'])
            ->assertSessionHasErrors(['email' => 'يرجى إدخال عنوان بريد إلكتروني صحيح.']);
    }

    public function test_the_254_character_limit_is_inclusive(): void
    {
        $email = str_repeat('a', 64).'@'.str_repeat('b', 61).'.'.str_repeat('c', 61).'.'.str_repeat('d', 61).'.com';
        $this->assertSame(254, strlen($email));

        $this->subscribe(['email' => $email, 'locale' => 'ar'])->assertSessionHasNoErrors()->assertSessionHas('newsletter_status');

        $this->assertDatabaseHas('newsletter_subscribers', ['email' => $email]);
    }

    public function test_a_non_string_email_is_rejected(): void
    {
        $this->subscribe(['email' => ['a@example.com'], 'locale' => 'ar'])->assertSessionHasErrors('email');

        $this->assertDatabaseCount('newsletter_subscribers', 0);
    }

    public function test_the_typed_address_is_flashed_back_for_correction(): void
    {
        $this->subscribe(['email' => 'oops', 'locale' => 'ar'])->assertSessionHasInput('email', 'oops');
    }

    public function test_the_visitor_returns_to_the_page_they_came_from_at_the_form(): void
    {
        $this->subscribe(['email' => 'a@example.com', 'locale' => 'ar'], 'http://localhost/ar/news/12-some-story?utm=x')
            ->assertRedirect('http://localhost/ar/news/12-some-story?utm=x#newsletter');
    }

    public function test_a_foreign_referer_cannot_turn_the_form_into_an_open_redirect(): void
    {
        $this->withoutMiddleware(ThrottleRequests::class);

        $this->subscribe(['email' => 'a@example.com', 'locale' => 'en'], 'https://evil.example/phish')
            ->assertRedirect(route('home', ['locale' => 'en']).'#newsletter');
        $this->subscribe(['email' => 'bad', 'locale' => 'en'], 'https://evil.example/phish')
            ->assertRedirect(route('home', ['locale' => 'en']).'#newsletter');
    }

    public function test_json_clients_get_200_with_the_message(): void
    {
        $this->postJson(route('newsletter.subscribe'), ['email' => 'api@example.com', 'locale' => 'en'])
            ->assertOk()
            ->assertExactJson(['message' => __('newsletter.subscribed', [], 'en')]);

        $this->assertDatabaseHas('newsletter_subscribers', ['email' => 'api@example.com']);
    }

    public function test_json_clients_get_422_with_field_errors(): void
    {
        $this->postJson(route('newsletter.subscribe'), ['email' => 'nope', 'locale' => 'ar'])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['email'])
            ->assertJsonPath('errors.email.0', __('newsletter.invalid_email', [], 'ar'));

        $this->postJson(route('newsletter.subscribe'), ['email' => 'a@example.com', 'locale' => 'xx'])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['locale']);
    }

    public function test_json_honeypot_also_pretends_success(): void
    {
        $this->postJson(route('newsletter.subscribe'), ['email' => 'bot@example.com', 'website' => 'x'])->assertOk();

        $this->assertDatabaseCount('newsletter_subscribers', 0);
    }

    public function test_the_form_is_rate_limited_per_ip(): void
    {
        for ($i = 0; $i < 5; $i++) {
            $this->postJson(route('newsletter.subscribe'), ['email' => "u{$i}@example.com"])->assertOk();
        }

        $this->postJson(route('newsletter.subscribe'), ['email' => 'late@example.com'])->assertStatus(429);
        $this->assertDatabaseMissing('newsletter_subscribers', ['email' => 'late@example.com']);
    }

    public function test_v1_sends_no_email_at_all(): void
    {
        Mail::fake();
        Notification::fake();

        $this->subscribe(['email' => 'a@example.com', 'locale' => 'ar']);

        Mail::assertNothingSent();
        Mail::assertNothingQueued();
        Notification::assertNothingSent();
    }
}
