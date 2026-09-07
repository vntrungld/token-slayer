<?php

use App\Enums\AccountStatus;
use App\Events\AccountTokenRejected;
use App\Models\Account;
use App\Services\AccountTokenRefresher;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->refresher = app(AccountTokenRefresher::class);
});

test('a disabled account is not fresh and makes no HTTP call', function () {
    fakeAnthropic();
    $account = Account::factory()->connected()->create(['status' => AccountStatus::Disabled]);

    expect($this->refresher->ensureFreshToken($account))->toBeFalse();
    Http::assertNothingSent();
});

test('an account without a refresh token is not fresh and makes no HTTP call', function () {
    fakeAnthropic();
    $account = Account::factory()->create(['oauth_refresh_token' => null]);

    expect($this->refresher->ensureFreshToken($account))->toBeFalse();
    Http::assertNothingSent();
});

test('a fresh token needs no refresh call', function () {
    fakeAnthropic();
    $account = Account::factory()->connected()->create(['oauth_expires_at' => now()->addHours(8)]);

    expect($this->refresher->ensureFreshToken($account))->toBeTrue();
    Http::assertNothingSent();
});

test('a near-expiry token is refreshed and reported fresh', function () {
    fakeAnthropic();
    $account = Account::factory()->connected()->create(['oauth_expires_at' => now()->addMinutes(30)]);

    expect($this->refresher->ensureFreshToken($account))->toBeTrue();
    expect($account->fresh()->oauth_refresh_token)->not->toBeNull();
});

test('a refresh persists refresh_token_expires_in onto oauth_refresh_expires_at', function () {
    fakeAnthropic();
    $account = Account::factory()->connected()->create(['oauth_expires_at' => now()->addMinutes(30)]);

    expect($this->refresher->ensureFreshToken($account))->toBeTrue();
    expect($account->fresh()->oauth_refresh_expires_at)->not->toBeNull();
});

test('a successful refresh stamps last_refreshed_at', function () {
    // This is the signal the Expiring page reads to tell a healthy grant from
    // one whose refresh has quietly started failing, so it has to move on
    // every real rotation.
    fakeAnthropic();
    $account = Account::factory()->connected()->create([
        'oauth_expires_at' => now()->addMinutes(30),
        'last_refreshed_at' => now()->subDays(10),
    ]);

    expect($this->refresher->ensureFreshToken($account))->toBeTrue()
        ->and($account->fresh()->last_refreshed_at->diffInMinutes(now()))->toBeLessThan(1);
});

test('a failed refresh leaves last_refreshed_at where it was', function () {
    // Stamping on failure would make a permanently broken account look
    // permanently healthy — the staleness rule would never fire for the one
    // case it exists to catch.
    $stalledSince = now()->subDays(10);
    fakeAnthropic(['token' => Http::response(['error' => 'invalid_grant'], 400)]);
    $account = Account::factory()->connected()->create([
        'oauth_expires_at' => now()->addMinutes(30),
        'last_refreshed_at' => $stalledSince,
    ]);

    expect($this->refresher->ensureFreshToken($account))->toBeFalse()
        ->and($account->fresh()->last_refreshed_at->timestamp)->toBe($stalledSince->timestamp);
});

test('an invalid_grant refresh flags NeedsReauth, dispatches the alert, and reports not fresh', function () {
    Event::fake([AccountTokenRejected::class]);
    fakeAnthropic(['token' => Http::response('', 400)]);
    $account = Account::factory()->connected()->create(['oauth_expires_at' => now()->subMinute()]);

    expect($this->refresher->ensureFreshToken($account))->toBeFalse();
    expect($account->fresh()->status)->toBe(AccountStatus::NeedsReauth);
    Event::assertDispatched(AccountTokenRejected::class);
});
