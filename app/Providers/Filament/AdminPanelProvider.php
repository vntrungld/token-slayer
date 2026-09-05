<?php

namespace App\Providers\Filament;

use App\Filament\Auth\BattlefieldLogoutResponse;
use App\Filament\Pages\Dashboard;
use App\Filament\Resources\Shield\RoleResource;
use App\Filament\Widgets\ActivityHeatmap;
use App\Filament\Widgets\FleetQuotaOverview;
use App\Filament\Widgets\TokensByModelChart;
use App\Filament\Widgets\TokenVolumeChart;
use App\Filament\Widgets\TopAccountsLeaderboard;
use App\Filament\Widgets\TopUsersLeaderboard;
use App\Http\Middleware\RedirectGuestsToSlackLogin;
use BezhanSalleh\FilamentShield\FilamentShieldPlugin;
use Filament\Auth\Http\Responses\Contracts\LogoutResponse;
use Filament\Http\Middleware\Authenticate;
use Filament\Http\Middleware\AuthenticateSession;
use Filament\Http\Middleware\DisableBladeIconComponents;
use Filament\Http\Middleware\DispatchServingFilamentEvent;
use Filament\Panel;
use Filament\PanelProvider;
use Filament\Support\Colors\Color;
use Filament\View\PanelsRenderHook;
use Illuminate\Contracts\View\View;
use Illuminate\Cookie\Middleware\AddQueuedCookiesToResponse;
use Illuminate\Cookie\Middleware\EncryptCookies;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\View\Middleware\ShareErrorsFromSession;

/**
 * Registers the `/dashboard` Filament panel — account CRUD, member management,
 * and (in later phases) quota/usage dashboards. Access is gated by
 * `User::canAccessPanel()` — an assigned role, or any role flagged
 * `is_default` (which every user carries implicitly); per-action
 * authorization inside the panel is enforced by Shield's generated Policies.
 *
 * The panel id stays `admin` (so every `filament.admin.*` route name and
 * Shield permission keeps working) while the public path is the friendlier
 * `/dashboard`; `routes/web.php` redirects the legacy `/admin/*` URLs.
 */
class AdminPanelProvider extends PanelProvider
{
    /**
     * Bind the panel's post-logout redirect to the public battlefield
     * instead of Filament's default (which falls back to the panel's base
     * URL and immediately bounces back into the Slack OAuth redirect).
     *
     * @return void
     */
    public function register(): void
    {
        parent::register();

        $this->app->bind(LogoutResponse::class, BattlefieldLogoutResponse::class);
    }

    /**
     * Configure the admin panel: id/path, discovered resources/pages/widgets,
     * and the middleware stack Filament needs for auth/session handling.
     *
     * @param  Panel  $panel  the panel instance being configured
     * @return Panel
     */
    public function panel(Panel $panel): Panel
    {
        return $panel
            ->default()
            ->id('admin')
            ->path('dashboard')
            ->colors([
                'primary' => Color::Amber,
            ])
            ->discoverResources(in: app_path('Filament/Resources'), for: 'App\Filament\Resources')
            ->discoverPages(in: app_path('Filament/Pages'), for: 'App\Filament\Pages')
            ->pages([
                Dashboard::class,
            ])
            ->resources([
                RoleResource::class,
            ])
            // The battlefield is the app's home; without this the only way out
            // of the panel is the browser's Back button or logging out. It
            // lives in the topbar next to the avatar rather than the sidebar,
            // where it would read as just another admin section.
            ->renderHook(
                PanelsRenderHook::USER_MENU_BEFORE,
                fn (): View => view('filament.topbar-battlefield-link'),
            )
            // The panel has no global-search-worthy surface: accounts/users are
            // few and reachable from the nav, so the header search box is dead
            // weight.
            ->globalSearch(false)
            ->widgets([
                FleetQuotaOverview::class,
                ActivityHeatmap::class,
                TokenVolumeChart::class,
                TokensByModelChart::class,
                TopUsersLeaderboard::class,
                TopAccountsLeaderboard::class,
            ])
            ->plugins([
                FilamentShieldPlugin::make(),
            ])
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
            ->authMiddleware([
                RedirectGuestsToSlackLogin::class,
                Authenticate::class,
            ]);
    }
}
