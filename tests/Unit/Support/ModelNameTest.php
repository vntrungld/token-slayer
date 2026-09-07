<?php

use App\Enums\ModelFamily;
use App\Support\ModelName;

test('renders the version a raw model id carries', function (string $modelId, string $expected) {
    expect(ModelName::for($modelId))->toBe($expected);
})->with([
    // Anthropic writes its lines with a space before the version; the id
    // spells a point release with a hyphen, which has to become a dot or
    // "Fable 5-1" reads as a range.
    'opus' => ['claude-opus-5', 'Opus 5'],
    'point release' => ['claude-fable-5-1', 'Fable 5.1'],
    'sonnet point release' => ['claude-sonnet-4-6', 'Sonnet 4.6'],
    // The training-date suffix is noise in a label — two Haiku 4.5 builds are
    // the same model to everyone reading a chart.
    'dated build' => ['claude-haiku-4-5-20251001', 'Haiku 4.5'],
    // OpenAI hyphenates: GPT-5.5, not "GPT 5.5".
    'gpt' => ['gpt-5.5', 'GPT-5.5'],
    'gpt variant' => ['gpt-5.1-codex', 'GPT-5.1 Codex'],
    // A bare alias appears in real transcripts and has no version to show.
    'bare alias' => ['sonnet', 'Sonnet'],
]);

test('labels an event with no model as Unknown', function (?string $modelId) {
    expect(ModelName::for($modelId))->toBe('Unknown');
})->with([
    'null' => [null],
    'empty' => [''],
    'blank' => ['   '],
]);

test('returns an unrecognised id verbatim rather than guessing at it', function () {
    // A model line this app has never seen must still be countable and
    // findable by the exact string stored in events.model. Prettifying a
    // shape we do not understand would invent a name that matches nothing.
    expect(ModelName::for('some-future-model'))->toBe('some-future-model')
        ->and(ModelName::for('o3-mini'))->toBe('o3-mini');
});

test('falls back to the raw id when the line is not a segment of its own', function () {
    // The family matches on a substring, so an id can be recognised as GPT
    // while offering nothing to split a version off. Rendering "GPT" here
    // would be the exact collapse this class exists to stop.
    expect(ModelName::for('gpt5-mini'))->toBe('gpt5-mini');
});

test('keeps the family, and therefore the colour, of the id it renamed', function () {
    // The chart splits bars per version but colours them per line, so Opus 5
    // and Opus 4.8 sit side by side in the same colour. That only works if
    // the family survives the renaming.
    expect(ModelName::familyOf('claude-opus-5'))->toBe(ModelFamily::Opus)
        ->and(ModelName::familyOf('gpt-5.1-codex'))->toBe(ModelFamily::Gpt)
        ->and(ModelName::familyOf('some-future-model'))->toBeNull();
});
