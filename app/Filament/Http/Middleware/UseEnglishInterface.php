<?php

namespace App\Filament\Http\Middleware;

use Carbon\Carbon;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * The app default locale is Arabic (for the public site), but the CMS chrome is
 * English; only the article fields are bilingual.
 */
class UseEnglishInterface
{
    public function handle(Request $request, Closure $next): Response
    {
        app()->setLocale('en');
        Carbon::setLocale('en');

        return $next($request);
    }
}
