<?php

namespace App\Http\Controllers\Site;

use App\Enums\Locale;
use App\Http\Controllers\Controller;
use App\Http\Middleware\SetLocale;
use App\Http\Requests\SubscribeRequest;
use App\Models\NewsletterSubscriber;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Newsletter sign-up stores addresses only (v1 sends no email). Every outcome looks the same to the
 * visitor, so the form cannot be used to find out who is already subscribed.
 */
class NewsletterController extends Controller
{
    public function subscribe(SubscribeRequest $request): RedirectResponse|JsonResponse
    {
        // A human never sees the "website" field; a bot that fills it is told it worked and nothing is stored.
        if (! $request->filled('website')) {
            $this->store($request->validated('email'), $request->locale());
        }

        $message = __('newsletter.subscribed', [], $request->locale()->value);

        if ($request->expectsJson()) {
            return response()->json(['message' => $message]);
        }

        return redirect()->to($request->formUrl())->with('newsletter_status', $message);
    }

    public function unsubscribe(Request $request, string $token): Response
    {
        return $this->page($request, $token, done: false);
    }

    public function confirmUnsubscribe(Request $request, string $token): Response
    {
        NewsletterSubscriber::where('unsubscribe_token', $token)
            ->whereNull('unsubscribed_at')
            ->update(['unsubscribed_at' => now()]);

        return $this->page($request, $token, done: true);
    }

    private function store(string $email, Locale $locale): void
    {
        $subscriber = NewsletterSubscriber::firstOrCreate(['email' => $email], ['locale' => $locale]);

        if (! $subscriber->wasRecentlyCreated && $subscriber->unsubscribed_at !== null) {
            $subscriber->update(['unsubscribed_at' => null, 'locale' => $locale]);
        }
    }

    /** The same page whether or not the token exists, so it cannot be probed. */
    private function page(Request $request, string $token, bool $done): Response
    {
        // These routes sit outside the /{locale} group, so pick the language from the browser and
        // let SetLocale do the rest (app locale, URL defaults, shared $locale).
        $locale = Locale::tryFromRequestLanguage($request->header('Accept-Language'));
        $request->route()->setParameter('locale', $locale->value);

        return app(SetLocale::class)->handle($request, fn () => response()->view('site.newsletter-unsubscribe', [
            'done' => $done,
            'token' => $token,
        ]));
    }
}
