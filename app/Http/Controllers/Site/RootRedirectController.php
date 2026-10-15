<?php

namespace App\Http\Controllers\Site;

use App\Enums\Locale;
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;

class RootRedirectController extends Controller
{
    /** "/" -> /ar by default, /en only when the browser's first language is English. */
    public function __invoke(Request $request)
    {
        $locale = Locale::tryFromRequestLanguage($request->header('Accept-Language'));

        return redirect()->route('home', ['locale' => $locale->value], 302);
    }
}
