<?php

use App\Services\Events\ModelUsageParser;
use Illuminate\Support\Facades\Log;

beforeEach(function () {
    $this->parser = new ModelUsageParser;
});

test('keeps well-formed entries untouched', function () {
    expect($this->parser->sanitize(['claude-opus-5' => 478]))
        ->toBe(['claude-opus-5' => 478]);
});

test('discards hostile or malformed input', function (mixed $input) {
    expect($this->parser->sanitize($input))->toBe([]);
})->with([
    'not an array' => ['claude-opus-5'],
    'null' => [null],
    'empty array' => [[]],
    'empty key' => [['' => 100]],
    'non-numeric value' => [['claude-opus-5' => 'lots']],
    'zero value' => [['claude-opus-5' => 0]],
    'negative value' => [['claude-opus-5' => -5]],
    'list not map' => [[100, 200]],
]);

test('drops a key longer than the column rather than truncating it', function () {
    // Truncating could merge two distinct model ids into one bucket and
    // silently corrupt the totals; dropping the entry only loses that entry.
    $long = str_repeat('m', 65);

    expect($this->parser->sanitize([$long => 100, 'claude-opus-5' => 50]))
        ->toBe(['claude-opus-5' => 50]);
});

test('keeps only the twelve highest-token entries', function () {
    $models = [];
    for ($i = 1; $i <= 15; $i++) {
        $models["model-{$i}"] = $i * 10;
    }

    $sanitized = $this->parser->sanitize($models);

    expect($sanitized)->toHaveCount(12)
        ->and($sanitized)->toHaveKey('model-15')
        ->and($sanitized)->not->toHaveKey('model-1');
});

test('resolves a single-model turn to that model', function () {
    expect($this->parser->primaryModel(['claude-sonnet-5' => 6540]))
        ->toBe('claude-sonnet-5');
});

test('labels a mixed turn by the most expensive model it touched, not the largest', function () {
    // The observed real case: an Opus turn that hit its limit and fell back to
    // Sonnet, which then produced MORE tokens because it finished the work.
    // Labelling by token count would hide the Opus spend in the cheap bucket.
    expect($this->parser->primaryModel(['claude-opus-5' => 9199, 'claude-sonnet-5' => 5054]))
        ->toBe('claude-opus-5');

    expect($this->parser->primaryModel(['claude-fable-5-1' => 800, 'claude-sonnet-5' => 9000]))
        ->toBe('claude-fable-5-1');
});

test('falls back to token count between models of the same family', function () {
    expect($this->parser->primaryModel(['claude-opus-4-8' => 100, 'claude-opus-5' => 900]))
        ->toBe('claude-opus-5');
});

test('breaks a total tie deterministically on key order', function () {
    expect($this->parser->primaryModel(['claude-opus-5' => 500, 'claude-opus-4-8' => 500]))
        ->toBe('claude-opus-4-8');
});

test('prefers a ranked model over an unmapped one', function () {
    expect($this->parser->primaryModel(['some-future-model' => 9000, 'claude-haiku-4-5' => 10]))
        ->toBe('claude-haiku-4-5');
});

test('resolves an unmapped model when it is the only one present', function () {
    expect($this->parser->primaryModel(['some-future-model' => 9000]))
        ->toBe('some-future-model');
});

test('returns null when there is nothing to resolve', function () {
    expect($this->parser->primaryModel([]))->toBeNull();
});

test('warns when a turn touched more than one model', function () {
    // The 0.04% mixed-turn rate this design rests on was measured on one
    // machine; this warning is how the real team-wide rate becomes observable.
    Log::spy();

    $this->parser->primaryModel(['claude-opus-5' => 9199, 'claude-sonnet-5' => 5054]);

    Log::shouldHaveReceived('warning')
        ->once()
        ->withArgs(fn (string $message, array $context): bool => $message === 'Turn used multiple models'
            && $context['models'] === ['claude-opus-5' => 9199, 'claude-sonnet-5' => 5054]);
});

test('does not warn on a single-model turn', function () {
    Log::spy();

    $this->parser->primaryModel(['claude-sonnet-5' => 6540]);

    Log::shouldNotHaveReceived('warning');
});
