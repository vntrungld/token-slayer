<?php

use App\Enums\ModelFamily;
use App\Models\Event;
use App\Models\User;
use App\Services\Analytics\ModelContributorsQuery;
use App\Services\Analytics\UsageFilters;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

test('returns one row per model, biggest spender first', function () {
    // The chart puts models on the x axis and reads each row's contributor
    // list straight into the hover card, so both orderings are load-bearing:
    // the row order is the bar order, the user order is the tooltip order.
    $tung = User::factory()->create(['slack_handle' => 'tung']);
    $an = User::factory()->create(['slack_handle' => 'an']);

    Event::factory()->for($tung)->create(['model' => 'claude-opus-5', 'tokens' => 900]);
    Event::factory()->for($an)->create(['model' => 'claude-opus-5', 'tokens' => 100]);
    Event::factory()->for($an)->create(['model' => 'claude-sonnet-5', 'tokens' => 500]);

    $rows = (new ModelContributorsQuery)->get(UsageFilters::fromPageFilters(['range' => 'all']), 5);

    expect($rows)->toBe([
        [
            'label' => 'Opus 5',
            'family' => ModelFamily::Opus,
            'tokens' => 1000,
            'top_users' => [
                ['handle' => 'tung', 'tokens' => 900],
                ['handle' => 'an', 'tokens' => 100],
            ],
        ],
        [
            'label' => 'Sonnet 5',
            'family' => ModelFamily::Sonnet,
            'tokens' => 500,
            'top_users' => [
                ['handle' => 'an', 'tokens' => 500],
            ],
        ],
    ]);
});

test('keeps two versions of one line as separate bars', function () {
    // Opus 4.8 and Opus 5 are different models with different costs; folding
    // them into one "Opus" bar hides exactly the migration an admin is
    // watching for. They share a family only so they share a colour.
    $tung = User::factory()->create(['slack_handle' => 'tung']);
    Event::factory()->for($tung)->create(['model' => 'claude-opus-4-8', 'tokens' => 300]);
    Event::factory()->for($tung)->create(['model' => 'claude-opus-5', 'tokens' => 700]);

    $rows = (new ModelContributorsQuery)->get(UsageFilters::fromPageFilters(['range' => 'all']), 5);

    expect(array_column($rows, 'label'))->toBe(['Opus 5', 'Opus 4.8'])
        ->and(array_column($rows, 'tokens'))->toBe([700, 300])
        ->and(array_column($rows, 'family'))->toBe([ModelFamily::Opus, ModelFamily::Opus]);
});

test('folds two raw ids that render as the same name', function () {
    // Two dated builds of Haiku 4.5 are one model to anyone reading the
    // chart; two bars carrying the identical label would be unreadable.
    $tung = User::factory()->create(['slack_handle' => 'tung']);
    Event::factory()->for($tung)->create(['model' => 'claude-haiku-4-5-20251001', 'tokens' => 300]);
    Event::factory()->for($tung)->create(['model' => 'claude-haiku-4-5-20260214', 'tokens' => 700]);

    $rows = (new ModelContributorsQuery)->get(UsageFilters::fromPageFilters(['range' => 'all']), 5);

    expect($rows)->toBe([[
        'label' => 'Haiku 4.5',
        'family' => ModelFamily::Haiku,
        'tokens' => 1000,
        'top_users' => [['handle' => 'tung', 'tokens' => 1000]],
    ]]);
});

test('separates GPT models instead of collapsing the whole vendor into one bar', function () {
    // "GPT" is the vendor, not a line: labelling by family put every OpenAI
    // model a developer could pick into a single bucket.
    $tung = User::factory()->create(['slack_handle' => 'tung']);
    Event::factory()->for($tung)->create(['model' => 'gpt-5.5', 'tokens' => 400]);
    Event::factory()->for($tung)->create(['model' => 'gpt-5.1-codex', 'tokens' => 100]);

    $rows = (new ModelContributorsQuery)->get(UsageFilters::fromPageFilters(['range' => 'all']), 5);

    expect(array_column($rows, 'label'))->toBe(['GPT-5.5', 'GPT-5.1 Codex']);
});

test('caps each row at the requested number of contributors', function () {
    // The hover card has to stay readable; the bar keeps the full total so
    // trimming the list never changes the height it is read against.
    foreach (range(1, 4) as $i) {
        $user = User::factory()->create(['slack_handle' => "dev{$i}"]);
        Event::factory()->for($user)->create(['model' => 'claude-opus-5', 'tokens' => $i * 100]);
    }

    $rows = (new ModelContributorsQuery)->get(UsageFilters::fromPageFilters(['range' => 'all']), 2);

    expect($rows[0]['tokens'])->toBe(1000)
        ->and($rows[0]['top_users'])->toBe([
            ['handle' => 'dev4', 'tokens' => 400],
            ['handle' => 'dev3', 'tokens' => 300],
        ]);
});

test('surfaces a contributor who is nowhere near the overall top spenders', function () {
    // This is the whole reason the query is per-model rather than a slice of
    // the global top-N: someone who only ever runs one model is invisible in
    // an overall ranking but is that model's entire story.
    $whale = User::factory()->create(['slack_handle' => 'whale']);
    $specialist = User::factory()->create(['slack_handle' => 'specialist']);

    Event::factory()->for($whale)->create(['model' => 'claude-opus-5', 'tokens' => 999_999]);
    Event::factory()->for($specialist)->create(['model' => 'claude-fable-5-1', 'tokens' => 10]);

    $rows = collect((new ModelContributorsQuery)->get(UsageFilters::fromPageFilters(['range' => 'all']), 5))
        ->keyBy('label');

    expect($rows['Fable 5.1']['top_users'])->toBe([['handle' => 'specialist', 'tokens' => 10]]);
});

test('labels events carrying no model as Unknown', function () {
    $tung = User::factory()->create(['slack_handle' => 'tung']);
    Event::factory()->for($tung)->create(['model' => null, 'tokens' => 400]);

    $rows = (new ModelContributorsQuery)->get(UsageFilters::fromPageFilters(['range' => 'all']), 5);

    expect($rows[0]['label'])->toBe('Unknown')
        ->and($rows[0]['family'])->toBeNull()
        ->and($rows[0]['top_users'])->toBe([['handle' => 'tung', 'tokens' => 400]]);
});

test('honors the shared analytics filter', function () {
    // Without this the chart would ignore the page's model/user/range picker
    // and quietly report all-time numbers under a filtered heading.
    $tung = User::factory()->create(['slack_handle' => 'tung']);
    $an = User::factory()->create(['slack_handle' => 'an']);
    Event::factory()->for($tung)->create(['model' => 'claude-opus-5', 'tokens' => 900]);
    Event::factory()->for($an)->create(['model' => 'claude-opus-5', 'tokens' => 100]);

    $rows = (new ModelContributorsQuery)->get(
        UsageFilters::fromPageFilters(['range' => 'all', 'user_id' => $an->id]),
        5,
    );

    expect($rows)->toBe([[
        'label' => 'Opus 5',
        'family' => ModelFamily::Opus,
        'tokens' => 100,
        'top_users' => [['handle' => 'an', 'tokens' => 100]],
    ]]);
});

test('returns an empty list when the range has no events', function () {
    expect((new ModelContributorsQuery)->get(UsageFilters::fromPageFilters(['range' => 'all']), 5))
        ->toBe([]);
});
