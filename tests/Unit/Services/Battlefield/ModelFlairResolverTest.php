<?php

use App\Services\Battlefield\ModelFlairResolver;

beforeEach(function () {
    config(['game.flair_models' => ['claude-fable']]);
    $this->resolver = new ModelFlairResolver;
});

test('awards flair to a configured model family', function () {
    expect($this->resolver->resolve('claude-fable-5-1'))->toBe('fable');
});

test('awards no flair to an ordinary model', function () {
    expect($this->resolver->resolve('claude-opus-5'))->toBeNull();
});

test('awards no flair when the event carries no model', function () {
    expect($this->resolver->resolve(null))->toBeNull();
});

test('the flair key is the family, not the raw id', function () {
    // The JS keys its badge on the flair string, so a point release must not
    // change it -- otherwise claude-fable-5-2 would silently stop rendering.
    expect($this->resolver->resolve('claude-fable-5-2'))->toBe('fable');
});

test('adding a model to the config needs no code change', function () {
    config(['game.flair_models' => ['claude-fable', 'gpt-5']]);

    expect($this->resolver->resolve('gpt-5.5'))->toBe('gpt');
});
