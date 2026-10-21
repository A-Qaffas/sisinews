<?php

namespace App\Providers\Filament;

use App\Filament\Http\Middleware\UseStaffLanguage;
use App\Filament\Pages\Auth\EditProfile;
use App\Filament\Pages\Auth\Login;
use App\Filament\Support\AdminNavigationGroup;
use App\Filament\Support\InitialsAvatarProvider;
use App\Filament\Support\StatusPresenter;
use Filament\Actions\Action;
use Filament\Http\Middleware\Authenticate;
use Filament\Http\Middleware\AuthenticateSession;
use Filament\Http\Middleware\DisableBladeIconComponents;
use Filament\Http\Middleware\DispatchServingFilamentEvent;
use Filament\Navigation\NavigationGroup;
use Filament\Pages\Dashboard;
use Filament\Panel;
use Filament\PanelProvider;
use Filament\Support\Colors\Color;
use Filament\View\PanelsRenderHook;
use Illuminate\Cookie\Middleware\AddQueuedCookiesToResponse;
use Illuminate\Cookie\Middleware\EncryptCookies;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\View\Middleware\ShareErrorsFromSession;

/**
 * Staff-only CMS. v1 sends no email, so there is deliberately no registration,
 * password reset or email verification: an admin creates every account and
 * User::canAccessPanel() keeps deactivated staff out.
 */
class AdminPanelProvider extends PanelProvider
{
    /** Navy ink sampled from public/brand/logo.png (#172A3A) at shade 600. */
    private const NAVY = [
        50 => '#F1F5F9', 100 => '#E2E9F0', 200 => '#C5D2DF', 300 => '#9DB2C7', 400 => '#5A7C97',
        500 => '#2C4A63', 600 => '#172A3A', 700 => '#122231', 800 => '#0E1B27', 900 => '#0A131B', 950 => '#060C11',
    ];

    /** Brand crimson #9B2C2C at shade 600; reserved for destructive actions. */
    private const CRIMSON = [
        50 => '#FBF1F1', 100 => '#F6DFDF', 200 => '#EEBFBF', 300 => '#E19494', 400 => '#CD6161',
        500 => '#B54040', 600 => '#9B2C2C', 700 => '#7F2323', 800 => '#671F1F', 900 => '#561E1E', 950 => '#2F0D0D',
    ];

    /** Neutral scale warmed towards the site's cream (#F7F2E9) at 50 and navy at 900. */
    private const INK = [
        50 => '#F7F3EB', 100 => '#EFEBE2', 200 => '#E0DDD6', 300 => '#C9CDD2', 400 => '#98A2AD',
        500 => '#6A7886', 600 => '#4B5B6B', 700 => '#364758', 800 => '#243648', 900 => '#172A3A', 950 => '#0E1B27',
    ];

    public function panel(Panel $panel): Panel
    {
        return $panel
            ->default()
            ->id('admin')
            ->path('admin')
            ->login(Login::class)
            ->profile(EditProfile::class, isSimple: false)
            ->brandName('sisi.news')
            // The 380 px WebP (9 KB), not the 219 KB logo.png master: the panel shows it 2.5rem high.
            ->brandLogo(fn (): string => asset('brand/logo-380.webp'))
            ->brandLogoHeight('2.5rem')
            ->favicon(fn (): string => asset('brand/icon.png'))
            // The logo is navy on a transparent background, so it would vanish on a dark theme.
            ->darkMode(false)
            ->colors([
                'primary' => self::NAVY,
                'danger' => self::CRIMSON,
                'gray' => self::INK,
                // "Published, but not live yet" (see StatusPresenter::SCHEDULED_COLOR).
                StatusPresenter::SCHEDULED_COLOR => Color::Violet,
            ])
            // The default avatar provider calls ui-avatars.com, which would leak staff names.
            ->defaultAvatarProvider(InitialsAvatarProvider::class)
            // A policy that lacks a method must deny, never silently allow.
            ->strictAuthorization()
            ->globalSearch(false)
            ->databaseTransactions()
            ->unsavedChangesAlerts()
            ->sidebarCollapsibleOnDesktop()
            // The default 20rem leaves a 1366px laptop too little room for the story tables.
            ->sidebarWidth('15rem')
            // Keyed by case name (how Filament matches a resource's enum group). The label is a closure: passing the enum
            // class would translate it once at boot, before UseStaffLanguage has picked the person's language.
            ->navigationGroups(collect(AdminNavigationGroup::cases())
                ->mapWithKeys(fn (AdminNavigationGroup $group): array => [
                    $group->name => NavigationGroup::make()->label(fn (): string => $group->getLabel()),
                ])
                ->all())
            ->renderHook(PanelsRenderHook::STYLES_AFTER, fn (): string => view('filament.hooks.admin-styles')->render())
            ->discoverResources(in: app_path('Filament/Resources'), for: 'App\Filament\Resources')
            ->discoverPages(in: app_path('Filament/Pages'), for: 'App\Filament\Pages')
            ->pages([
                Dashboard::class,
            ])
            ->discoverWidgets(in: app_path('Filament/Widgets'), for: 'App\Filament\Widgets')
            ->middleware([
                EncryptCookies::class,
                AddQueuedCookiesToResponse::class,
                StartSession::class,
                AuthenticateSession::class,
                ShareErrorsFromSession::class,
                PreventRequestForgery::class,
                SubstituteBindings::class,
                DisableBladeIconComponents::class,
                DispatchServingFilamentEvent::class,
            ])
            // After StartSession (it reads the signed-in person) and persistent, so Livewire requests speak it too.
            ->middleware([UseStaffLanguage::class], isPersistent: true)
            ->userMenuItems([
                // Offers the other language, written in that language, so it is readable whichever one is on screen.
                'language' => fn (): Action => Action::make('language')
                    ->label(app()->getLocale() === 'ar' ? 'English' : 'العربية')
                    ->icon('heroicon-o-language')
                    ->url(route('admin.language', ['locale' => app()->getLocale() === 'ar' ? 'en' : 'ar'])),
            ])
            ->renderHook(PanelsRenderHook::AUTH_LOGIN_FORM_AFTER, fn (): string => view('filament.hooks.login-language')->render())
            ->authMiddleware([
                Authenticate::class,
            ]);
    }
}
