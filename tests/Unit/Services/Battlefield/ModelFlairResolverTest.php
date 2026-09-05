<?php

use App\Models\AiModel;
use App\Services\Battlefield\ModelFlairResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;

uses(RefreshDatabase::class);

beforeEach(function () {
    Cache::flush();
    $this->resolver = new ModelFlairResolver;
});

test('awards flair with the row duration when a model is enabled', function () {
    AiModel::create(['model' => 'claude-fable-5-1', 'flair_enabled' => true, 'flair_duration_ms' => 9000]);

    $decision = $this->resolver->resolve('claude-fable-5-1');

    expect($decision->flair)->toBe('fable')
        ->and($decision->durationMs)->toBe(9000);
});

test('awards no flair to a model with no row at all', function () {
    expect($this->resolver->resolve('claude-opus-5'))->toBeNull();
});

test('awards no flair to a known but disabled model', function () {
    AiModel::create(['model' => 'claude-opus-5', 'flair_enabled' => false]);

    expect($this->resolver->resolve('claude-opus-5'))->toBeNull();
});

test('awards no flair when the event carries no model', function () {
    expect($this->resolver->resolve(null))->toBeNull();
});

test('matching is exact, not by family or prefix', function () {
    // The registry is keyed by raw id (owner decision): a sibling point
    // release is a separate, unreviewed row until an admin enables it too.
    AiModel::create(['model' => 'claude-fable-5-1', 'flair_enabled' => true]);

    expect($this->resolver->resolve('claude-fable-5-2'))->toBeNull();
});

test('the flair key is the family, not the raw id', function () {
    // The JS keys its badge on this string, so a point release enabled
    // separately still renders the badge the JS already knows.
    AiModel::create(['model' => 'claude-fable-5-2', 'flair_enabled' => true]);

    expect($this->resolver->resolve('claude-fable-5-2')->flair)->toBe('fable');
});

test('falls back to the raw id when the model belongs to no known family', function () {
    AiModel::create(['model' => 'some-future-model', 'flair_enabled' => true]);

    expect($this->resolver->resolve('some-future-model')->flair)->toBe('some-future-model');
});

test('enabling a model needs no code change, only the enabled cache to expire', function () {
    AiModel::create(['model' => 'gpt-5.5', 'flair_enabled' => true, 'flair_duration_ms' => 4000]);
    Cache::flush();

    $decision = $this->resolver->resolve('gpt-5.5');

    expect($decision->flair)->toBe('gpt')
        ->and($decision->durationMs)->toBe(4000);
});

test('does not query the database again within the cache window', function () {
    AiModel::create(['model' => 'claude-fable-5-1', 'flair_enabled' => true]);
    $this->resolver->resolve('claude-fable-5-1');

    AiModel::query()->delete();

    // Still resolves from the cached snapshot, not a fresh (now-empty) query.
    expect($this->resolver->resolve('claude-fable-5-1'))->not->toBeNull();
});
