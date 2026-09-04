<?php

use App\Filament\Resources\Accounts\RelationManagers\EventsRelationManager as AccountEvents;
use App\Filament\Resources\Users\RelationManagers\EventsRelationManager as UserEvents;
use App\Models\Event;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

test('renders the display family for a known model', function (string $manager) {
    $user = User::factory()->create();
    Event::factory()->for($user)->create(['model' => 'claude-fable-5-1']);

    expect($manager::modelLabel(Event::latest('id')->first()))->toBe('Fable');
})->with(['user table' => [UserEvents::class], 'account table' => [AccountEvents::class]]);

test('renders the raw id for a model no family knows yet', function (string $manager) {
    // A newly released model must stay visible rather than collapsing into an
    // "other" bucket -- the raw id is what analytics counts it under anyway.
    $user = User::factory()->create();
    Event::factory()->for($user)->create(['model' => 'some-future-model']);

    expect($manager::modelLabel(Event::latest('id')->first()))->toBe('some-future-model');
})->with(['user table' => [UserEvents::class], 'account table' => [AccountEvents::class]]);

test('renders a dash for an event from a client that predates model tracking', function (string $manager) {
    $user = User::factory()->create();
    Event::factory()->for($user)->create(['model' => null]);

    expect($manager::modelLabel(Event::latest('id')->first()))->toBe('—');
})->with(['user table' => [UserEvents::class], 'account table' => [AccountEvents::class]]);

test('colours a known family and falls back to grey otherwise', function (string $manager) {
    $user = User::factory()->create();

    $fable = Event::factory()->for($user)->create(['model' => 'claude-fable-5-1']);
    $unknown = Event::factory()->for($user)->create(['model' => null]);

    expect($manager::modelColor($fable))->toBe('warning')
        ->and($manager::modelColor($unknown))->toBe('gray');
})->with(['user table' => [UserEvents::class], 'account table' => [AccountEvents::class]]);
