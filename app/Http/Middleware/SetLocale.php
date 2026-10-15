<?php

namespace App\Http\Middleware;

use App\Enums\Locale;
use Carbon\Carbon;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Facades\View;
use Symfony\Component\HttpFoundation\Response;

/**
 * Binds the {locale} route segment (ar|en) to the app locale, the URL generator
 * default, Carbon's month/day names, and a shared `$locale` (App\Enums\Locale)
 * available in every Blade view.
 */
class SetLocale
{
    public function handle(Request $request, Closure $next): Response
    {
        $locale = Locale::tryFrom((string) $request->route('locale')) ?? Locale::default();

        app()->setLocale($locale->value);
        Carbon::setLocale($locale->value);
        URL::defaults(['locale' => $locale->value]);
        View::share('locale', $locale);
        $request->attributes->set('locale', $locale);

        return $next($request);
    }
}
