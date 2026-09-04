<?php

use App\Enums\ModelFamily;

test('maps a raw model id to its family', function (string $modelId, ?ModelFamily $expected) {
    expect(ModelFamily::fromModelId($modelId))->toBe($expected);
})->with([
    'fable point release' => ['claude-fable-5-1', ModelFamily::Fable],
    'opus' => ['claude-opus-5', ModelFamily::Opus],
    'older opus' => ['claude-opus-4-8', ModelFamily::Opus],
    'sonnet' => ['claude-sonnet-5', ModelFamily::Sonnet],
    'sonnet alias' => ['sonnet', ModelFamily::Sonnet],
    'haiku dated' => ['claude-haiku-4-5-20251001', ModelFamily::Haiku],
    'codex' => ['gpt-5.5', ModelFamily::Gpt],
    'codex mini' => ['gpt-5.4-mini', ModelFamily::Gpt],
    'unmapped model' => ['some-future-model', null],
    'synthetic sentinel' => ['<synthetic>', null],
]);

test('returns null for a null model id', function () {
    expect(ModelFamily::fromModelId(null))->toBeNull();
});

test('ranks families by cost, most expensive first', function () {
    expect(ModelFamily::Fable->rank())->toBeGreaterThan(ModelFamily::Opus->rank())
        ->and(ModelFamily::Opus->rank())->toBeGreaterThan(ModelFamily::Gpt->rank())
        ->and(ModelFamily::Gpt->rank())->toBeGreaterThan(ModelFamily::Sonnet->rank())
        ->and(ModelFamily::Sonnet->rank())->toBeGreaterThan(ModelFamily::Haiku->rank());
});

test('every family carries a label and a colour', function (ModelFamily $family) {
    expect($family->getLabel())->not->toBeEmpty()
        ->and($family->getColor())->not->toBeEmpty();
})->with(ModelFamily::cases());
