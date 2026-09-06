<?php

use App\Events\BossKilled;
use App\Events\BossSpawned;
use App\Events\FighterCharacterChanged;
use App\Events\FighterChargeCleared;
use App\Events\FighterCharging;
use App\Events\FighterIdled;
use App\Events\FighterJoined;
use App\Events\FighterMoved;
use App\Events\HitDealt;
use App\Models\Boss;
use App\Models\User;
use App\Services\FighterPositionCache;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

test('HitDealt broadcasts on the battlefield channel with expected payload', function () {
    $user = User::factory()->create();
    $boss = Boss::factory()->create(['number' => 1]);
    $event = new HitDealt($user, 1234, $boss);

    expect($event->broadcastOn()[0]->name)->toBe('battlefield')
        ->and($event->broadcastAs())->toBe('HitDealt')
        ->and($event->broadcastWith())->toMatchArray([
            'user_id' => $user->id,
            'damage' => 1234,
            'boss_id' => $boss->id,
        ]);
});

test('every battlefield event broadcasts now on the battlefield channel with a short broadcastAs name', function () {
    $user = User::factory()->create();
    $boss = Boss::factory()->create();

    $events = [
        'HitDealt' => new HitDealt($user, 1, $boss),
        'BossKilled' => new BossKilled($boss, $user),
        'BossSpawned' => new BossSpawned($boss),
        'FighterCharging' => new FighterCharging($user),
        'FighterJoined' => new FighterJoined($user),
        'FighterIdled' => new FighterIdled($user),
        'FighterChargeCleared' => new FighterChargeCleared($user),
        'FighterCharacterChanged' => new FighterCharacterChanged($user),
        'FighterMoved' => new FighterMoved($user, 0.5, 0.7),
    ];

    foreach ($events as $shortName => $event) {
        expect($event)->toBeInstanceOf(ShouldBroadcastNow::class)
            ->and($event->broadcastOn()[0]->name)->toBe('battlefield')
            ->and($event->broadcastAs())->toBe($shortName);
    }
});

test('FighterJoined broadcasts the character assigned for the given boss', function () {
    $user = User::factory()->create();
    $boss = Boss::factory()->create();

    $withBoss = new FighterJoined($user, $boss);
    $withoutBoss = new FighterJoined($user);

    expect($withBoss->broadcastWith())->toMatchArray([
        'user_id' => $user->id,
        'character' => $user->characterForBoss($boss->id),
    ])->and($withoutBoss->broadcastWith()['character'])->toBe($user->characterForBoss(null));
});

test('FighterJoined payload includes display_name and avatar_url', function () {
    $user = User::factory()->create(['display_name' => 'Alice']);
    $boss = Boss::factory()->create();

    $payload = (new FighterJoined($user, $boss))->broadcastWith();

    expect($payload)->toHaveKey('display_name', 'Alice')
        ->and($payload)->toHaveKey('avatar_url')
        ->and($payload['avatar_url'])->toStartWith('http');
});

test('FighterJoined carries the rejoining fighter saved position', function () {
    $user = User::factory()->create();
    $boss = Boss::factory()->create();
    app(FighterPositionCache::class)->put($user->id, 0.42, 0.66);

    $payload = (new FighterJoined($user, $boss))->broadcastWith();

    expect($payload['position'])->toBe(['x' => 0.42, 'y' => 0.66]);
});

test('FighterJoined carries a null position for a fighter that never moved', function () {
    $user = User::factory()->create();
    $boss = Boss::factory()->create();

    $payload = (new FighterJoined($user, $boss))->broadcastWith();

    expect($payload)->toHaveKey('position')
        ->and($payload['position'])->toBeNull();
});

test('FighterCharging broadcasts character for the given boss', function () {
    $user = User::factory()->create();
    $boss = Boss::factory()->create();

    $withBoss = new FighterCharging($user, 'thinking…', $boss);
    $withoutBoss = new FighterCharging($user, '$ npm install');

    expect($withBoss->broadcastWith())->toMatchArray([
        'user_id' => $user->id,
        'character' => $user->characterForBoss($boss->id),
        'activity' => 'thinking…',
    ])->and($withoutBoss->broadcastWith()['character'])->toBe($user->characterForBoss(null));
});

test('FighterCharging carries the fighter saved position, so a synthetic client-side rejoin restores it', function () {
    $user = User::factory()->create();
    app(FighterPositionCache::class)->put($user->id, 0.42, 0.66);

    $payload = (new FighterCharging($user, 'thinking…'))->broadcastWith();

    expect($payload['position'])->toBe(['x' => 0.42, 'y' => 0.66]);
});

test('FighterCharging carries a null position for a fighter that never moved', function () {
    $user = User::factory()->create();

    $payload = (new FighterCharging($user, 'thinking…'))->broadcastWith();

    expect($payload)->toHaveKey('position')
        ->and($payload['position'])->toBeNull();
});

test('FighterChargeCleared broadcasts on the battlefield channel with expected payload', function () {
    $user = User::factory()->create();
    $event = new FighterChargeCleared($user);

    expect($event->broadcastOn()[0]->name)->toBe('battlefield')
        ->and($event->broadcastAs())->toBe('FighterChargeCleared')
        ->and($event->broadcastWith())->toBe([
            'user_id' => $user->id,
        ]);
});

test('FighterMoved broadcasts on the battlefield channel with expected payload', function () {
    $user = User::factory()->create();
    $event = new FighterMoved($user, 0.35, 0.72);

    expect($event)->toBeInstanceOf(ShouldBroadcastNow::class)
        ->and($event->broadcastOn()[0]->name)->toBe('battlefield')
        ->and($event->broadcastAs())->toBe('FighterMoved')
        ->and($event->broadcastWith())->toBe([
            'user_id' => $user->id,
            'x' => 0.35,
            'y' => 0.72,
        ]);
});

test('FighterCharacterChanged broadcasts on the battlefield channel with the user\'s current character', function () {
    $user = User::factory()->create(['equipped_character' => 'archer']);
    $event = new FighterCharacterChanged($user);

    expect($event->broadcastOn()[0]->name)->toBe('battlefield')
        ->and($event->broadcastAs())->toBe('FighterCharacterChanged')
        ->and($event->broadcastWith())->toBe([
            'user_id' => $user->id,
            'character' => 'archer',
        ]);
});

test('HitDealt carries a nullable model, flair, flair duration, and flair color', function () {
    // All four must be nullable end to end: HitDealt is also dispatched for
    // cowork/claude-ai Stops, where no model exists at all.
    $user = User::factory()->create();
    $boss = Boss::factory()->create(['number' => 1]);

    $plain = (new HitDealt($user, 100, $boss))->broadcastWith();

    expect($plain)->toHaveKeys(['model', 'flair', 'flair_duration_ms', 'flair_color'])
        ->and($plain['model'])->toBeNull()
        ->and($plain['flair'])->toBeNull()
        ->and($plain['flair_duration_ms'])->toBeNull()
        ->and($plain['flair_color'])->toBeNull();

    $fable = (new HitDealt($user, 100, $boss, 'claude-fable-5-1', 'fable', 9000, '#a855f7'))->broadcastWith();

    expect($fable['model'])->toBe('claude-fable-5-1')
        ->and($fable['flair'])->toBe('fable')
        ->and($fable['flair_duration_ms'])->toBe(9000)
        ->and($fable['flair_color'])->toBe('#a855f7');
});

test('HitDealt sends only scalars, per the payload rule', function () {
    $user = User::factory()->create();
    $boss = Boss::factory()->create(['number' => 1]);

    foreach ((new HitDealt($user, 100, $boss, 'claude-fable-5-1', 'fable', 9000, '#a855f7'))->broadcastWith() as $key => $value) {
        expect(is_scalar($value) || $value === null)->toBeTrue("payload key {$key} is not a scalar");
    }
});
