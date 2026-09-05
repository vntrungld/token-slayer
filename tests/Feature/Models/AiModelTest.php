<?php

use App\Models\AiModel;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

test('has the expected columns and defaults', function () {
    // Model::create() only carries the attributes explicitly passed; DB-level
    // defaults for unset columns are not reflected until the row is reloaded.
    $row = AiModel::create(['model' => 'claude-fable-5-1'])->fresh();

    expect($row->model)->toBe('claude-fable-5-1')
        ->and($row->flair_enabled)->toBeFalse()
        ->and($row->flair_duration_ms)->toBe(6000);
});

test('model is unique', function () {
    AiModel::create(['model' => 'claude-fable-5-1']);

    expect(fn () => AiModel::create(['model' => 'claude-fable-5-1']))
        ->toThrow(QueryException::class);
});

test('casts flair_enabled to a real boolean', function () {
    $row = AiModel::create(['model' => 'claude-opus-5', 'flair_enabled' => 1]);

    expect($row->fresh()->flair_enabled)->toBeTrue();
});
