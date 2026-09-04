<?php

use App\Models\Event;
use App\Models\User;
use App\Services\Analytics\TokensByUserAndModelQuery;
use App\Services\Analytics\UsageFilters;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

test('builds a dense per-user per-model matrix', function () {
    $tung = User::factory()->create(['slack_handle' => 'tung']);
    $an = User::factory()->create(['slack_handle' => 'an']);

    Event::factory()->for($tung)->create(['model' => 'claude-opus-5', 'tokens' => 900]);
    Event::factory()->for($tung)->create(['model' => 'claude-sonnet-5', 'tokens' => 100]);
    Event::factory()->for($an)->create(['model' => 'claude-sonnet-5', 'tokens' => 500]);

    $result = (new TokensByUserAndModelQuery)->get(UsageFilters::fromPageFilters(['range' => 'all']), 10);

    expect($result['users'])->toBe([
        ['user_id' => $tung->id, 'handle' => 'tung'],
        ['user_id' => $an->id, 'handle' => 'an'],
    ]);

    // Cells with no events must be 0, not missing: Chart.js indexes datasets
    // positionally against the labels array, so a sparse row shifts the bars
    // onto the wrong people.
    expect($result['matrix'])->toBe([
        'Opus' => [900, 0],
        'Sonnet' => [100, 500],
    ]);
});

test('sums two raw ids that share a display family', function () {
    // claude-opus-4-8 and claude-opus-5 both render as "Opus"; overwriting
    // instead of summing would silently drop one of them.
    $tung = User::factory()->create(['slack_handle' => 'tung']);
    Event::factory()->for($tung)->create(['model' => 'claude-opus-4-8', 'tokens' => 300]);
    Event::factory()->for($tung)->create(['model' => 'claude-opus-5', 'tokens' => 700]);

    $result = (new TokensByUserAndModelQuery)->get(UsageFilters::fromPageFilters(['range' => 'all']), 10);

    expect($result['matrix'])->toBe(['Opus' => [1000]]);
});

test('restricts to the top N users by total tokens', function () {
    foreach (range(1, 3) as $i) {
        $user = User::factory()->create(['slack_handle' => "dev{$i}"]);
        Event::factory()->for($user)->create(['model' => 'claude-opus-5', 'tokens' => $i * 100]);
    }

    $result = (new TokensByUserAndModelQuery)->get(UsageFilters::fromPageFilters(['range' => 'all']), 2);

    expect($result['users'])->toHaveCount(2)
        ->and($result['matrix']['Opus'])->toBe([300, 200]);
});

test('returns empty structures when the range has no events', function () {
    expect((new TokensByUserAndModelQuery)->get(UsageFilters::fromPageFilters(['range' => 'all']), 10))
        ->toBe(['users' => [], 'matrix' => []]);
});

test('labels rows with no model as Unknown', function () {
    $tung = User::factory()->create(['slack_handle' => 'tung']);
    Event::factory()->for($tung)->create(['model' => null, 'tokens' => 400]);

    $result = (new TokensByUserAndModelQuery)->get(UsageFilters::fromPageFilters(['range' => 'all']), 10);

    expect($result['matrix'])->toBe(['Unknown' => [400]]);
});
