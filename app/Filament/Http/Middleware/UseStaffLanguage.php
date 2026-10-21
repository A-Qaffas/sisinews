<?php

namespace App\Filament\Http\Middleware;

use App\Enums\Locale;
use App\Models\User;
use Carbon\Carbon;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * The staff panel speaks the signed-in person's language (users.locale); before sign-in, the one picked on the login
 * page (session), else the panel default (config site.admin_locale, Arabic). Filament's own Arabic strings also turn
 * the whole panel right-to-left. Registered after StartSession and persistent, so Livewire requests get it too.
 */
class UseStaffLanguage
{
    public const SESSION_KEY = 'admin_locale';

    public function handle(Request $request, Closure $next): Response
    {
        $locale = self::resolve($request);

        app()->setLocale($locale->value);
        Carbon::setLocale($locale->value);

        return $next($request);
    }

    public static function resolve(Request $request): Locale
    {
        $user = $request->user();

        if ($user instanceof User) {
            return $user->adminLocale();
        }

        $chosen = $request->hasSession() ? Locale::tryFrom((string) $request->session()->get(self::SESSION_KEY)) : null;

        return $chosen ?? Locale::tryFrom((string) config('site.admin_locale')) ?? Locale::default();
    }
}
