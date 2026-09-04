<?php

use App\Filament\Widgets\TokensByModelChart;
use App\Models\Event;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;

uses(RefreshDatabase::class);

test('the widget is gated by its own permission', function () {
    expect(Permission::where('name', 'View:TokensByModelChart')->exists())->toBeTrue();
});

test('the description warns that bars are not cost-equivalent', function () {
    // Without this, a tall Sonnet bar beside a short Opus bar reads as "this
    // person is fine" when the opposite may be true. The project has no price
    // table, so saying so is the mitigation.
    expect((new TokensByModelChart)->getDescription())
        ->toContain('not cost- or quota-equivalent');
});

test('the description reports the unknown share', function () {
    $user = User::factory()->create();
    Event::factory()->for($user)->create(['model' => null, 'tokens' => 300]);
    Event::factory()->for($user)->create(['model' => 'claude-opus-5', 'tokens' => 100]);

    expect((new TokensByModelChart)->getDescription())->toContain('75%');
});

test('builds one stacked dataset per model family', function () {
    $tung = User::factory()->create(['slack_handle' => 'tung']);
    Event::factory()->for($tung)->create(['model' => 'claude-opus-5', 'tokens' => 900]);
    Event::factory()->for($tung)->create(['model' => 'claude-fable-5-1', 'tokens' => 100]);

    $data = (new TokensByModelChart)->getChartData();

    expect($data['labels'])->toBe(['tung'])
        ->and(collect($data['datasets'])->pluck('label')->all())->toBe(['Fable', 'Opus'])
        ->and($data['datasets'][0]['data'])->toBe([100])
        ->and($data['datasets'][1]['data'])->toBe([900]);
});

test('colours datasets from the family enum, not chart defaults', function () {
    // Models are open-ended, so library-default colours would shuffle as new
    // ids appear and the chart would stop being readable at a glance.
    $tung = User::factory()->create(['slack_handle' => 'tung']);
    Event::factory()->for($tung)->create(['model' => 'claude-fable-5-1', 'tokens' => 100]);
    Event::factory()->for($tung)->create(['model' => 'some-future-model', 'tokens' => 50]);

    $colors = collect((new TokensByModelChart)->getChartData()['datasets'])
        ->pluck('backgroundColor');

    expect($colors)->toHaveCount(2)
        ->and($colors->filter()->count())->toBe(2)
        ->and($colors->unique()->count())->toBe(2);
});
