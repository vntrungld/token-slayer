<?php

use App\Filament\Pages\ExpiringAccounts;
use App\Models\Account;
use App\Models\ClaudeCredential;
use App\Models\CodexCredential;
use App\Models\User;
use App\Services\AccountConnectService;
use App\Services\Connect\ConnectResolution;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

it('the page loads for an admin and lists an expiring account', function (): void {
    $admin = User::factory()->admin()->create();
    $account = Account::create(['email' => 'soon@example.com', 'provider' => 'claude']);
    ClaudeCredential::create(['account_id' => $account->id, 'oauth_refresh_expires_at' => now()->addDay()]);

    Livewire::actingAs($admin)->test(ExpiringAccounts::class)
        ->assertOk()
        ->assertSee('soon@example.com');
});

it('is forbidden for a user without the view_usage_analytics permission', function (): void {
    $user = User::factory()->create();

    Livewire::actingAs($user)->test(ExpiringAccounts::class)->assertForbidden();
});

it('offers a reconnect action on a Claude row so the admin never has to open the account', function (): void {
    $admin = User::factory()->admin()->create();
    $account = Account::create(['email' => 'soon@example.com', 'provider' => 'claude']);
    ClaudeCredential::create(['account_id' => $account->id, 'oauth_refresh_expires_at' => now()->addDay()]);

    Livewire::actingAs($admin)->test(ExpiringAccounts::class)
        ->assertActionExists('reconnectAccount')
        ->assertSee('Reconnect');
});

it('completes a reconnect against the account named by the row, not whatever was authorized', function (): void {
    // The whole point of binding the button to a row: the admin clicked THIS
    // account, so authorizing a different one has to be rejected rather than
    // silently repairing someone else's credentials.
    $admin = User::factory()->admin()->create();
    $account = Account::create(['email' => 'soon@example.com', 'provider' => 'claude']);
    ClaudeCredential::create(['account_id' => $account->id, 'oauth_refresh_expires_at' => now()->addDay()]);

    $service = Mockery::mock(AccountConnectService::class);
    $service->shouldReceive('start')->andReturn(['url' => 'https://example.test/auth', 'state' => 'st4te']);
    $service->shouldReceive('resolve')
        ->once()
        ->withArgs(fn (string $state, string $code, Account $expected): bool => $expected->is($account))
        ->andReturn(ConnectResolution::existing($account));
    app()->instance(AccountConnectService::class, $service);

    Livewire::actingAs($admin)->test(ExpiringAccounts::class)
        ->mountAction('reconnectAccount', ['account' => $account->id])
        ->setActionData(['state' => 'st4te', 'code' => 'pasted-code'])
        ->callMountedAction()
        ->assertHasNoActionErrors();
});

it('offers a usage refresh on a Codex row instead of a reconnect', function (): void {
    // Codex has no per-row reconnect — its connect flow is device-code and
    // binds to whoever approves, not to the row. A re-probe is the repair
    // that can honestly be aimed at one account.
    $admin = User::factory()->admin()->create();
    $account = Account::create(['email' => 'stale@example.com', 'provider' => 'codex']);
    CodexCredential::create([
        'account_id' => $account->id,
        'last_refreshed_at' => now()->subDays(30),
    ]);

    Livewire::actingAs($admin)->test(ExpiringAccounts::class)
        ->assertOk()
        ->assertSee('stale@example.com')
        ->assertActionExists('refreshAccountUsage');
});
