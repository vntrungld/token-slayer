<?php

use App\Models\AiModel;
use App\Models\Event;
use App\Models\User;
use App\Services\Battlefield\AiModelSyncer;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

test('discovers a new model from events and defaults its flair off', function () {
    $user = User::factory()->create();
    Event::factory()->for($user)->create(['model' => 'claude-fable-5-1']);

    $found = (new AiModelSyncer)->sync();

    expect($found)->toBe(1)
        ->and(AiModel::where('model', 'claude-fable-5-1')->first()?->flair_enabled)->toBeFalse();
});

test('leaves an already-known model untouched, including a manually enabled one', function () {
    $user = User::factory()->create();
    Event::factory()->for($user)->create(['model' => 'claude-fable-5-1']);
    AiModel::create(['model' => 'claude-fable-5-1', 'flair_enabled' => true, 'flair_duration_ms' => 9000]);

    $found = (new AiModelSyncer)->sync();

    expect($found)->toBe(0);
    $row = AiModel::where('model', 'claude-fable-5-1')->first();
    expect($row->flair_enabled)->toBeTrue()
        ->and($row->flair_duration_ms)->toBe(9000);
});

test('ignores events with no recorded model', function () {
    $user = User::factory()->create();
    Event::factory()->for($user)->create(['model' => null]);

    expect((new AiModelSyncer)->sync())->toBe(0)
        ->and(AiModel::count())->toBe(0);
});

test('discovers every distinct model in one pass', function () {
    $user = User::factory()->create();
    Event::factory()->for($user)->create(['model' => 'claude-opus-5']);
    Event::factory()->for($user)->create(['model' => 'claude-opus-5']);
    Event::factory()->for($user)->create(['model' => 'gpt-5.5']);

    expect((new AiModelSyncer)->sync())->toBe(2)
        ->and(AiModel::count())->toBe(2);
});
