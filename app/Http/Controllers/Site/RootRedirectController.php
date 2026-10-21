<?php

namespace App\Http\Controllers\Site;

use App\Enums\Locale;
use App\Http\Controllers\Controller;

class RootRedirectController extends Controller
{
    /**
     * "/" always opens the Arabic site, whatever language the browser prefers (the owner's choice: sisi.news is an
     * Arabic news site first). Readers switch to English from the top bar. 302, so the choice can change again later
     * without browsers holding on to a permanent redirect.
     */
    public function __invoke()
    {
        return redirect()->route('home', ['locale' => Locale::default()->value], 302);
    }
}
