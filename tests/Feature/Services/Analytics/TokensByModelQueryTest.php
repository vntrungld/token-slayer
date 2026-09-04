<?php

use App\Models\Event;
use App\Models\User;
use App\Services\Analytics\TokensByModelQuery;
use App\Services\Analytics\TopUsersQuery;
use App\Services\Analytics\UsageFilters;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

test('totals tokens per model, largest first', function () {
    $user = User::factory()->create();
    Event::factory()->for($user)->create(['model' => 'claude-sonnet-5', 'tokens' => 360_244]);
    Event::factory()->for($user)->create(['model' => 'claude-fable-5-1', 'tokens' => 628_161]);
    Event::factory()->for($user)->create(['model' => 'claude-fable-5-1', 'tokens' => 1_000]);

    $rows = (new TokensByModelQuery)->get(UsageFilters::fromPageFilters(['range' => 'all']));

    expect($rows)->toBe([
        ['model' => 'claude-fable-5-1', 'label' => 'Fable', 'tokens' => 629_161, 'events' => 2],
        ['model' => 'claude-sonnet-5', 'label' => 'Sonnet', 'tokens' => 360_244, 'events' => 1],
    ]);
});

test('groups rows with no model into an unknown bucket', function () {
    // Every pre-rollout row and every stale-client row is NULL, so this is the
    // most populated bucket the day this ships.
    $user = User::factory()->create();
    Event::factory()->for($user)->create(['model' => null, 'tokens' => 4_070]);

    expect((new TokensByModelQuery)->get(UsageFilters::fromPageFilters(['range' => 'all'])))
        ->toBe([['model' => 'unknown', 'label' => 'Unknown', 'tokens' => 4_070, 'events' => 1]]);
});

test('labels an unmapped model with its raw id', function () {
    $user = User::factory()->create();
    Event::factory()->for($user)->create(['model' => 'some-future-model', 'tokens' => 10]);

    expect((new TokensByModelQuery)->get(UsageFilters::fromPageFilters(['range' => 'all'])))
        ->toBe([['model' => 'some-future-model', 'label' => 'some-future-model', 'tokens' => 10, 'events' => 1]]);
});

test('honours the shared provider filter', function () {
    $user = User::factory()->create();
    Event::factory()->for($user)->create(['provider' => 'claude-code', 'model' => 'claude-opus-5', 'tokens' => 100]);
    Event::factory()->for($user)->create(['provider' => 'codex', 'model' => 'gpt-5.5', 'tokens' => 900]);

    $rows = (new TokensByModelQuery)->get(
        UsageFilters::fromPageFilters(['range' => 'all', 'provider' => 'claude-code'])
    );

    expect($rows)->toBe([
        ['model' => 'claude-opus-5', 'label' => 'Opus', 'tokens' => 100, 'events' => 1],
    ]);
});

test('reports the share of tokens with no recorded model', function () {
    // The unknown share is the only feedback signal that the client rollout is
    // working; without it nobody notices the bucket never shrinking.
    $user = User::factory()->create();
    Event::factory()->for($user)->create(['model' => null, 'tokens' => 300]);
    Event::factory()->for($user)->create(['model' => 'claude-opus-5', 'tokens' => 100]);

    expect((new TokensByModelQuery)->unknownShare(UsageFilters::fromPageFilters(['range' => 'all'])))
        ->toBe(75.0);
});

test('reports a zero unknown share when there are no events at all', function () {
    expect((new TokensByModelQuery)->unknownShare(UsageFilters::fromPageFilters(['range' => 'all'])))
        ->toBe(0.0);
});

test('filters events down to one model', function () {
    $user = User::factory()->create();
    Event::factory()->for($user)->create(['model' => 'claude-opus-5', 'tokens' => 100]);
    Event::factory()->for($user)->create(['model' => 'claude-sonnet-5', 'tokens' => 50]);

    $filters = UsageFilters::fromPageFilters(['range' => 'all', 'model' => 'claude-opus-5']);

    expect((new TokensByModelQuery)->get($filters))->toBe([
        ['model' => 'claude-opus-5', 'label' => 'Opus', 'tokens' => 100, 'events' => 1],
    ]);
});

test('the unknown selection matches NULL rows, not the literal string', function () {
    // A plain where model = 'unknown' returns zero rows on both Postgres and
    // SQLite, and `unknown` is the bucket people click first at rollout.
    $user = User::factory()->create();
    Event::factory()->for($user)->create(['model' => null, 'tokens' => 70]);
    Event::factory()->for($user)->create(['model' => 'claude-opus-5', 'tokens' => 100]);

    $filters = UsageFilters::fromPageFilters(['range' => 'all', 'model' => UsageFilters::UNKNOWN_MODEL]);

    expect((new TokensByModelQuery)->get($filters))->toBe([
        ['model' => 'unknown', 'label' => 'Unknown', 'tokens' => 70, 'events' => 1],
    ]);
});

test('a cleared model select means no filter at all', function () {
    // A Filament select reset to its placeholder submits an empty string.
    expect(UsageFilters::fromPageFilters(['range' => 'all', 'model' => ''])->model)->toBeNull();
});

test('the model filter reaches the other analytics queries too', function () {
    $user = User::factory()->create();
    Event::factory()->for($user)->create(['model' => 'claude-opus-5', 'tokens' => 100]);
    Event::factory()->for($user)->create(['model' => 'claude-sonnet-5', 'tokens' => 900]);

    $filters = UsageFilters::fromPageFilters(['range' => 'all', 'model' => 'claude-opus-5']);

    // TopUsersQuery shares the same scope, so the filter rides along for free.
    expect((new TopUsersQuery)->get($filters, 10))
        ->toHaveCount(1)
        ->and((new TopUsersQuery)->get($filters, 10)[0]['tokens'])->toBe(100);
});
