<?php

use App\Livewire\Profile;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function () {
    Cache::flush();   // the badge reads through CachedLatestVersion — never let it bleed between tests
    config(['github.token' => 'ghp_test', 'github.cli_repo' => 'acme/slayer-cli']);
});

test('hides the outdated badge when the latest version is unknown', function () {
    Http::fake(['api.github.com/*' => Http::response(['message' => 'down'], 500)]);

    $user = User::factory()->create(['client_version' => '1.0.0']);

    Livewire::actingAs($user)
        ->test(Profile::class)
        ->assertOk()
        ->assertViewHas(
            'attribution',
            fn ($attribution) => $attribution['latestVersion'] === null
                && $attribution['outdated'] === false,
        );
});

test('flags the client as outdated when its version is older', function () {
    Http::fake(['api.github.com/*' => Http::response([
        'tag_name' => 'v1.0.4',
        'assets' => [['id' => 1, 'name' => 'slayer_cli-latest.whl']],
    ], 200)]);

    $user = User::factory()->create(['client_version' => '1.0.0']);

    Livewire::actingAs($user)
        ->test(Profile::class)
        ->assertViewHas(
            'attribution',
            fn ($attribution) => $attribution['latestVersion'] === '1.0.4'
                && $attribution['outdated'] === true,
        );
});

test('is not outdated when the client already runs the latest version', function () {
    Http::fake(['api.github.com/*' => Http::response([
        'tag_name' => 'v1.0.4',
        'assets' => [['id' => 1, 'name' => 'slayer_cli-latest.whl']],
    ], 200)]);

    $user = User::factory()->create(['client_version' => '1.0.4']);

    Livewire::actingAs($user)
        ->test(Profile::class)
        ->assertViewHas(
            'attribution',
            fn ($attribution) => $attribution['latestVersion'] === '1.0.4'
                && $attribution['outdated'] === false,
        );
});

test('flags an outdated hook separately from an outdated CLI', function () {
    // The two versions move independently: the CLI wheel is released from
    // another repo, so a hook-only change ships with an unchanged client_version
    // and would otherwise be invisible.
    Http::fake(['api.github.com/*' => Http::response([
        'tag_name' => 'v1.0.4',
        'assets' => [['id' => 1, 'name' => 'slayer_cli-latest.whl']],
    ], 200)]);
    config(['token_slayer.hook_version' => '7']);

    $user = User::factory()->create(['client_version' => '1.0.4', 'hook_version' => '6']);

    Livewire::actingAs($user)
        ->test(Profile::class)
        ->assertViewHas('attribution', fn ($a) => $a['outdated'] === false && $a['hookOutdated'] === true)
        ->assertSee('Your hook is on v6');
});

test('says nothing about the hook to someone who has never sent an event', function () {
    // hook_version stays null until the first event lands; nagging someone
    // before they have installed anything is noise, not a nudge.
    Http::fake(['api.github.com/*' => Http::response([
        'tag_name' => 'v1.0.4',
        'assets' => [['id' => 1, 'name' => 'slayer_cli-latest.whl']],
    ], 200)]);
    config(['token_slayer.hook_version' => '7']);

    $user = User::factory()->create(['client_version' => '1.0.4', 'hook_version' => null]);

    Livewire::actingAs($user)
        ->test(Profile::class)
        ->assertViewHas('attribution', fn ($a) => $a['hookOutdated'] === false)
        ->assertDontSee('Your hook is on');
});
