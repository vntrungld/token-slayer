<?php

namespace App\Filament\Concerns;

use App\Filament\Actions\ClaudeReconnectModal;
use App\Filament\Pages\ExpiringAccounts;
use App\Models\Account;
use App\Services\ProviderServiceFactory;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Support\Icons\Heroicon;

/**
 * Hosts the two repairs an admin can aim at a single account from a list that
 * is not the Accounts table — re-connecting a Claude account whose grant is
 * running out, and re-probing a Codex account that has gone stale.
 *
 * The Accounts table has both of these already, as record actions. These are
 * the same repairs reached by account id in `$arguments` instead of by an
 * injected `$record`, which is what a page rendering its own markup (see
 * {@see ExpiringAccounts}) can offer. Filament resolves
 * `reconnectAccount`/`refreshAccountUsage` by the `{name}Action` method
 * convention, so any Filament page can host them by using this trait, the
 * same way {@see ConnectsAccounts} and {@see ConnectsCodexAccounts} work.
 *
 * There is deliberately no Codex counterpart to the reconnect: the Codex
 * connect flow is a device code that binds to whichever account the admin
 * approves as, not to the row they clicked, so offering it per row would
 * promise an aim it cannot keep.
 */
trait RepairsAccounts
{
    /**
     * The per-row "Reconnect" action for a Claude account: starts a fresh
     * PKCE attempt, shows the authorize URL, and resolves the pasted code
     * against the account the row names.
     *
     * The modal itself lives in {@see ClaudeReconnectModal}, shared with the
     * Accounts table's own record action so the two cannot drift; this method
     * only supplies the account, which it takes from the clicked row.
     *
     * @return Action
     */
    public function reconnectAccountAction(): Action
    {
        return Action::make('reconnectAccount')
            ->label('Reconnect')
            ->icon(Heroicon::OutlinedLink)
            ->modalHeading('Re-connect Claude account')
            ->modalDescription('Open the authorize URL, approve access, then paste the code back here. You must authorize the same account this row represents.')
            ->modalSubmitActionLabel('Complete connect')
            ->fillForm(fn (): array => ClaudeReconnectModal::start())
            ->schema(ClaudeReconnectModal::schema())
            ->action(fn (array $arguments, array $data) => ClaudeReconnectModal::complete(
                Account::findOrFail($arguments['account']),
                $data,
                'Reconnect',
            ));
    }

    /**
     * The per-row "Refresh now" action: runs the provider's usage prober
     * against the account the row names and reports the fresh utilization,
     * or the probe error it recorded.
     *
     * @return Action
     */
    public function refreshAccountUsageAction(): Action
    {
        return Action::make('refreshAccountUsage')
            ->label('Refresh now')
            ->icon(Heroicon::OutlinedArrowPath)
            ->action(function (array $arguments): void {
                $account = Account::findOrFail($arguments['account']);
                $snapshot = app(ProviderServiceFactory::class)->proberFor($account)->probe($account);

                if ($snapshot === null) {
                    Notification::make()
                        ->warning()
                        ->title('Probe did not complete')
                        ->body($account->refresh()->probe_error ?? 'The account is not probeable right now.')
                        ->send();

                    return;
                }

                Notification::make()
                    ->success()
                    ->title('Usage refreshed')
                    ->body("5h: {$snapshot->util_5h}% · 7d: {$snapshot->util_7d}%")
                    ->send();
            });
    }
}
