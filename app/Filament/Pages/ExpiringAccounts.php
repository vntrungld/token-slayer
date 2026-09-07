<?php

namespace App\Filament\Pages;

use App\Filament\Concerns\RepairsAccounts;
use App\Services\Attribution\ExpiringAccountsQuery;
use BackedEnum;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Carbon;
use UnitEnum;

/**
 * Admin page listing accounts an admin should look at soon: a Claude
 * account whose refresh token expires within 3 days, or a Codex account
 * whose staleness signal has tripped. See {@see ExpiringAccountsQuery} for
 * the exact predicate per provider. Access is gated by the same
 * `view_usage_analytics` permission as {@see UnrecognizedAccounts}.
 */
class ExpiringAccounts extends Page
{
    use RepairsAccounts;

    /**
     * Only users granted the usage-analytics permission may open this page.
     * super_admin passes via filament-shield's Gate::before bypass.
     *
     * @return bool
     */
    public static function canAccess(): bool
    {
        return auth()->user()?->can('view_usage_analytics') ?? false;
    }

    /**
     * Sidebar navigation icon.
     *
     * @var string|BackedEnum|null
     */
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedClock;

    /**
     * Navigation group this page belongs to.
     *
     * @var string|UnitEnum|null
     */
    protected static string|UnitEnum|null $navigationGroup = 'Analytics';

    /**
     * Navigation label + page title.
     *
     * @var string|null
     */
    protected static ?string $navigationLabel = 'Expiring';

    /**
     * The page title.
     *
     * @var string|null
     */
    protected ?string $heading = 'Expiring Accounts';

    /**
     * The Blade view rendering the page body.
     *
     * @var string
     */
    protected string $view = 'filament.pages.expiring-accounts';

    /**
     * The expiring-account rows for the Blade view.
     *
     * @return array<int, array{account_id:int, email:?string, name:?string, provider:string, label:string, deadline:?Carbon}>
     */
    public function rows(): array
    {
        return app(ExpiringAccountsQuery::class)->get();
    }

    /**
     * A single combined count across both providers — the admin's
     * actionable question is "how many accounts need attention," not which
     * provider each one belongs to, until they open the page.
     *
     * @return string|null
     */
    public static function getNavigationBadge(): ?string
    {
        $count = count(app(ExpiringAccountsQuery::class)->get());

        return $count > 0 ? (string) $count : null;
    }
}
