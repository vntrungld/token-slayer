<?php

use App\Enums\AccountStatus;
use App\Enums\Provider;
use App\Models\Account;
use App\Models\ClaudeCredential;
use App\Models\CodexCredential;
use App\Services\Attribution\ExpiringAccountsQuery;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('includes a claude account whose refresh token expires within 3 days', function (): void {
    $account = Account::create(['email' => 'soon@example.com', 'provider' => 'claude']);
    ClaudeCredential::create(['account_id' => $account->id, 'oauth_refresh_expires_at' => now()->addDays(2)]);

    $rows = app(ExpiringAccountsQuery::class)->get();

    expect(collect($rows)->pluck('account_id'))->toContain($account->id);
    $row = collect($rows)->firstWhere('account_id', $account->id);
    expect($row['provider'])->toBe(Provider::Claude)
        ->and($row['deadline'])->not->toBeNull();
});

it('excludes a claude account whose refresh token does not expire soon', function (): void {
    $account = Account::create(['email' => 'safe@example.com', 'provider' => 'claude']);
    ClaudeCredential::create(['account_id' => $account->id, 'oauth_refresh_expires_at' => now()->addDays(20)]);

    $rows = app(ExpiringAccountsQuery::class)->get();

    expect(collect($rows)->pluck('account_id'))->not->toContain($account->id);
});

it('excludes a claude account with no refresh_expires_at at all', function (): void {
    $account = Account::create(['email' => 'unknown@example.com', 'provider' => 'claude']);
    ClaudeCredential::create(['account_id' => $account->id]);

    $rows = app(ExpiringAccountsQuery::class)->get();

    expect(collect($rows)->pluck('account_id'))->not->toContain($account->id);
});

it('includes a codex account whose earliest_refresh_at has passed', function (): void {
    $account = Account::create(['email' => 'codex-stale@example.com', 'provider' => 'codex']);
    CodexCredential::create(['account_id' => $account->id, 'earliest_refresh_at' => now()->subHour()]);

    $rows = app(ExpiringAccountsQuery::class)->get();
    $row = collect($rows)->firstWhere('account_id', $account->id);

    expect($row)->not->toBeNull()
        ->and($row['provider'])->toBe(Provider::Codex)
        ->and($row['deadline'])->toBeNull();
});

it('includes a codex account with no earliest_refresh_at whose last_refreshed_at is over 8 days old', function (): void {
    $account = Account::create(['email' => 'codex-old@example.com', 'provider' => 'codex']);
    CodexCredential::create(['account_id' => $account->id, 'last_refreshed_at' => now()->subDays(9)]);

    $rows = app(ExpiringAccountsQuery::class)->get();

    expect(collect($rows)->pluck('account_id'))->toContain($account->id);
});

it('excludes a codex account refreshed recently with no earliest_refresh_at', function (): void {
    $account = Account::create(['email' => 'codex-fresh@example.com', 'provider' => 'codex']);
    CodexCredential::create(['account_id' => $account->id, 'last_refreshed_at' => now()->subDays(2)]);

    $rows = app(ExpiringAccountsQuery::class)->get();

    expect(collect($rows)->pluck('account_id'))->not->toContain($account->id);
});

it('includes a claude account already flagged as needing re-auth', function (): void {
    // The grant is dead now, not soon. Without this the sidebar badge reads 0
    // while every account is broken: a dead account is dropped from the probe
    // batch, so its deadline freezes and only drifts into the 3-day window
    // weeks later — and never at all when it died before that deadline was
    // ever captured, which is the state every real account is in.
    $account = Account::create(['email' => 'dead@example.com', 'provider' => 'claude']);
    ClaudeCredential::create(['account_id' => $account->id, 'status' => AccountStatus::NeedsReauth]);

    $row = collect(app(ExpiringAccountsQuery::class)->get())->firstWhere('account_id', $account->id);

    expect($row)->not->toBeNull()
        ->and($row['label'])->toContain('re-auth')
        ->and($row['deadline'])->toBeNull();
});

it('includes a claude account that has not refreshed successfully in over 2 days', function (): void {
    // A healthy grant refreshes every few hours, so a two-day gap means the
    // refresh has been failing in a way that does not flip the status —
    // a rate limit or a network error, both of which leave it Active on
    // purpose so the next cycle retries. Catching it here is ~26 days of
    // warning that waiting for the frozen deadline would not give.
    $account = Account::create(['email' => 'stalled@example.com', 'provider' => 'claude']);
    ClaudeCredential::create([
        'account_id' => $account->id,
        'oauth_refresh_expires_at' => now()->addDays(20),
        'last_refreshed_at' => now()->subDays(3),
    ]);

    $row = collect(app(ExpiringAccountsQuery::class)->get())->firstWhere('account_id', $account->id);

    expect($row)->not->toBeNull()
        ->and($row['label'])->toContain("hasn't refreshed");
});

it('leaves a claude account alone while its refresh is still rotating', function (): void {
    $account = Account::create(['email' => 'rotating@example.com', 'provider' => 'claude']);
    ClaudeCredential::create([
        'account_id' => $account->id,
        'oauth_refresh_expires_at' => now()->addDays(20),
        'last_refreshed_at' => now()->subHours(6),
    ]);

    expect(collect(app(ExpiringAccountsQuery::class)->get())->pluck('account_id'))
        ->not->toContain($account->id);
});

it('does not call a claude account stale merely for never having recorded a refresh', function (): void {
    // last_refreshed_at is null on every row that predates the column. Reading
    // that as "stale" would flag the whole fleet the moment this ships, which
    // is the same as flagging nothing.
    $account = Account::create(['email' => 'legacy@example.com', 'provider' => 'claude']);
    ClaudeCredential::create(['account_id' => $account->id, 'oauth_refresh_expires_at' => now()->addDays(20)]);

    expect(collect(app(ExpiringAccountsQuery::class)->get())->pluck('account_id'))
        ->not->toContain($account->id);
});

it('leaves a disabled claude account out of the list entirely', function (): void {
    // Disabled is a deliberate admin choice; it is not something to repair,
    // so it must not sit in the badge count nagging forever.
    $account = Account::create(['email' => 'off@example.com', 'provider' => 'claude']);
    ClaudeCredential::create([
        'account_id' => $account->id,
        'status' => AccountStatus::Disabled,
        'last_refreshed_at' => now()->subDays(30),
        'oauth_refresh_expires_at' => now()->addDay(),
    ]);

    expect(collect(app(ExpiringAccountsQuery::class)->get())->pluck('account_id'))
        ->not->toContain($account->id);
});
