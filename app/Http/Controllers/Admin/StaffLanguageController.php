<?php

namespace App\Http\Controllers\Admin;

use App\Enums\Locale;
use App\Filament\Http\Middleware\UseStaffLanguage;
use App\Http\Controllers\Controller;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * Switches the staff panel between Arabic and English (user menu, login page). A signed-in person's choice is saved
 * on their account, so it follows them to every device; it is also kept in the session for the login page.
 * It changes only the panel's language, so a forged link can do no more than flip someone's interface.
 */
class StaffLanguageController extends Controller
{
    public function __invoke(Request $request, string $locale): RedirectResponse
    {
        $chosen = Locale::from($locale);
        $request->session()->put(UseStaffLanguage::SESSION_KEY, $chosen->value);

        $user = $request->user();
        if ($user instanceof User && $user->is_active) {
            $user->forceFill(['locale' => $chosen])->save();
        }

        return redirect()->to($this->backToThePanel($request));
    }

    /** Back where the person was, but only to a page of this panel on this host (the Referer is not trusted). */
    private function backToThePanel(Request $request): string
    {
        $panel = Filament::getPanel('admin')->getUrl();
        $previous = (string) $request->headers->get('referer');
        $host = parse_url($previous, PHP_URL_HOST);
        $path = (string) parse_url($previous, PHP_URL_PATH);

        if ($host === $request->getHost() && ($path === '/admin' || str_starts_with($path, '/admin/')) && ! str_starts_with($path, '/admin/language/')) {
            return $previous;
        }

        return $panel;
    }
}
