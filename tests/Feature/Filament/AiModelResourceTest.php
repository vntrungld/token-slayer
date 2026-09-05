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

test('editing the duration column persists flair_duration_ms', function () {
    $admin = User::factory()->admin()->create();
    $row = AiModel::create(['model' => 'claude-opus-5', 'flair_duration_ms' => 6000]);

    Livewire::actingAs($admin)->test(ListAiModels::class)
        ->call('updateTableColumnState', 'flair_duration_ms', (string) $row->getKey(), 9000);

    expect($row->fresh()->flair_duration_ms)->toBe(9000);
});

test('the sync action discovers new models and reports how many', function () {
    $admin = User::factory()->admin()->create();
    Event::factory()->for($admin)->create(['model' => 'claude-sonnet-5']);

    Livewire::actingAs($admin)->test(ListAiModels::class)
        ->callTableAction('sync')
        ->assertNotified();

    expect(AiModel::where('model', 'claude-sonnet-5')->exists())->toBeTrue();
});
