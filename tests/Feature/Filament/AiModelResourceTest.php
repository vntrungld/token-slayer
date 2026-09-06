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

test('duration and color are not table columns -- only the animation popup edits them', function () {
    $admin = User::factory()->admin()->create();

    Livewire::actingAs($admin)->test(ListAiModels::class)
        ->assertTableColumnDoesNotExist('flair_duration_ms')
        ->assertTableColumnDoesNotExist('flair_color');
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

test('the preview x-data attribute is not truncated by a stray double quote', function () {
    // The whole Alpine component lives inside a double-quoted x-data
    // attribute, so ONE literal `"` anywhere in it -- including in a code
    // comment -- ends the attribute early, and the browser renders the rest
    // of the component as visible page text. This has now happened twice
    // (an escaped \" in a canvas font string, then a quoted word in a
    // comment), and neither broke any other test: the Blade still compiles,
    // the view still renders, it just silently ships a broken modal.
    $html = view('filament.flair-preview', [
        'color' => '#fbbf24',
        'durationMs' => 6000,
        'label' => 'FABLE',
    ])->render();

    $start = strpos($html, 'x-data="') + 8;
    $attribute = substr($html, $start, strpos($html, '"', $start) - $start);

    // The last statement of the component's own frame loop -- present only
    // if the attribute survived intact all the way to the end.
    expect($attribute)->toContain('requestAnimationFrame(next => this.frame(next))');
});

test('the sync action discovers new models and reports how many', function () {
    $admin = User::factory()->admin()->create();
    Event::factory()->for($admin)->create(['model' => 'claude-sonnet-5']);

    Livewire::actingAs($admin)->test(ListAiModels::class)
        ->callTableAction('sync')
        ->assertNotified();

    expect(AiModel::where('model', 'claude-sonnet-5')->exists())->toBeTrue();
});
