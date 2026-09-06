<?php

use App\Filament\Resources\AiModels\Pages\ListAiModels;
use App\Models\AiModel;
use App\Models\Event;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

test('a non-admin cannot open the page', function () {
    $user = User::factory()->create();

    Livewire::actingAs($user)->test(ListAiModels::class)->assertForbidden();
});

test('lists every registered model with its total tokens and events', function () {
    $admin = User::factory()->admin()->create();
    Event::factory()->for($admin)->create(['model' => 'claude-fable-5-1', 'tokens' => 600]);
    Event::factory()->for($admin)->create(['model' => 'claude-fable-5-1', 'tokens' => 28]);
    $row = AiModel::create(['model' => 'claude-fable-5-1']);

    Livewire::actingAs($admin)->test(ListAiModels::class)
        ->assertCanSeeTableRecords([$row])
        ->assertTableColumnStateSet('tokens', '628', $row);
});

test('toggling the badge column persists flair_enabled', function () {
    $admin = User::factory()->admin()->create();
    $row = AiModel::create(['model' => 'claude-opus-5', 'flair_enabled' => false]);

    Livewire::actingAs($admin)->test(ListAiModels::class)
        ->call('updateTableColumnState', 'flair_enabled', (string) $row->getKey(), true);

    expect($row->fresh()->flair_enabled)->toBeTrue();
});

test('the table shows the current color and duration read-only', function () {
    // Duration/color are edited via the "Edit animation" popup now, not an
    // inline column -- the table columns are informational only.
    $admin = User::factory()->admin()->create();
    $row = AiModel::create(['model' => 'claude-opus-5', 'flair_duration_ms' => 6000, 'flair_color' => '#a855f7']);

    Livewire::actingAs($admin)->test(ListAiModels::class)
        ->assertTableColumnStateSet('flair_duration_ms', 6000, $row)
        ->assertTableColumnStateSet('flair_color', '#a855f7', $row);
});

test('the animation popup prefills the row current duration and color', function () {
    $admin = User::factory()->admin()->create();
    $row = AiModel::create(['model' => 'claude-opus-5', 'flair_duration_ms' => 6000, 'flair_color' => '#a855f7']);

    Livewire::actingAs($admin)->test(ListAiModels::class)
        ->mountTableAction('edit-animation', $row)
        ->assertTableActionDataSet(['flair_duration_ms' => 6000, 'flair_color' => '#a855f7']);
});

test('saving the animation popup persists the new duration and color', function () {
    $admin = User::factory()->admin()->create();
    $row = AiModel::create(['model' => 'claude-opus-5', 'flair_duration_ms' => 6000, 'flair_color' => '#a855f7']);

    Livewire::actingAs($admin)->test(ListAiModels::class)
        ->callTableAction('edit-animation', $row, ['flair_duration_ms' => 9000, 'flair_color' => '#22d3ee']);

    $row->refresh();
    expect($row->flair_duration_ms)->toBe(9000)
        ->and($row->flair_color)->toBe('#22d3ee');
});

test('the sync action discovers new models and reports how many', function () {
    $admin = User::factory()->admin()->create();
    Event::factory()->for($admin)->create(['model' => 'claude-sonnet-5']);

    Livewire::actingAs($admin)->test(ListAiModels::class)
        ->callTableAction('sync')
        ->assertNotified();

    expect(AiModel::where('model', 'claude-sonnet-5')->exists())->toBeTrue();
});
