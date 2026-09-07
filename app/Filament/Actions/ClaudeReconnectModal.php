<?php

namespace App\Filament\Actions;

use App\Exceptions\AccountConnectException;
use App\Filament\Concerns\RepairsAccounts;
use App\Filament\Resources\Accounts\AccountResource;
use App\Models\Account;
use App\Services\AccountConnectService;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Schemas\Components\Component;

/**
 * The body of the "re-connect this Claude account" modal, shared by the two
 * places that offer it: the Accounts table's record action
 * ({@see AccountResource}) and the per-row
 * button on the Expiring page ({@see RepairsAccounts}).
 *
 * Only the way the account is reached differs between them — an injected
 * record in a table, an id in the action's arguments on a page. Everything
 * the admin actually sees and every failure they can hit lives here, so the
 * two surfaces cannot drift into telling them different things.
 */
final class ClaudeReconnectModal
{
    /**
     * The form the modal shows: the authorize URL to open, the opaque state
     * carried back to {@see complete()}, and the code the admin pastes.
     *
     * @return array<int, Component>
     */
    public static function schema(): array
    {
        return [
            TextInput::make('authorize_url')
                ->label('Authorize URL')
                ->readOnly()
                ->copyable(),
            Hidden::make('state'),
            TextInput::make('code')
                ->label('Paste the code here')
                ->required(),
        ];
    }

    /**
     * Open a fresh PKCE attempt and return the form state the modal fills
     * with. Called on mount, so every open is its own attempt — a stale
     * state from a previous open is rejected rather than reused.
     *
     * @return array{authorize_url: string, state: string, code: string}
     */
    public static function start(): array
    {
        $started = app(AccountConnectService::class)->start();

        return [
            'authorize_url' => $started['url'],
            'state' => $started['state'],
            'code' => '',
        ];
    }

    /**
     * Resolve the pasted code against `$account` and notify the admin.
     *
     * Passing the account in — rather than letting the service match on the
     * authorized identity — is what makes both surfaces honest: the admin
     * asked to repair THIS account, so authorizing a different one is
     * rejected and writes nothing instead of quietly repairing whichever
     * account they happened to be signed into.
     *
     * @param  Account  $account  the account the clicked row or record names
     * @param  array<string, mixed>  $data  the submitted modal form state
     * @param  string  $restartLabel  the button's own label, used in the expiry message so it names a control the admin can actually see
     * @return void
     */
    public static function complete(Account $account, array $data, string $restartLabel): void
    {
        try {
            app(AccountConnectService::class)->resolve($data['state'] ?? '', $data['code'], $account);
        } catch (AccountConnectException $exception) {
            Notification::make()
                ->danger()
                ->title('Connect failed')
                ->body(match ($exception->reason) {
                    'connect_identity_mismatch' => $exception->getMessage(),
                    'connect_state_expired' => "This connect link expired or was already used. Click {$restartLabel} to start again.",
                    'connect_no_identity' => 'Could not read an email from the authorized Claude account.',
                    default => 'Something went wrong completing the connect.',
                })
                ->send();

            return;
        }

        Notification::make()
            ->success()
            ->title('Account re-connected')
            ->send();
    }
}
