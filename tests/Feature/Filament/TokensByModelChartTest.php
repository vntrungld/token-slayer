<?php

use App\Filament\Widgets\TokensByModelChart;
use App\Models\Event;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Spatie\Permission\Models\Permission;

uses(RefreshDatabase::class);

test('the widget is gated by its own permission', function () {
    expect(Permission::where('name', 'View:TokensByModelChart')->exists())->toBeTrue();
});

test('the description warns that bars are not cost-equivalent', function () {
    // Without this, a tall Sonnet bar beside a short Opus bar reads as "this
    // person is fine" when the opposite may be true. The project has no price
    // table, so saying so is the mitigation.
    expect((new TokensByModelChart)->getDescription())
        ->toContain('not cost- or quota-equivalent');
});

test('the description reports the unknown share', function () {
    $user = User::factory()->create();
    Event::factory()->for($user)->create(['model' => null, 'tokens' => 300]);
    Event::factory()->for($user)->create(['model' => 'claude-opus-5', 'tokens' => 100]);

    expect((new TokensByModelChart)->getDescription())->toContain('75%');
});

test('puts one bar per model on the x axis, biggest first', function () {
    $tung = User::factory()->create(['slack_handle' => 'tung']);
    Event::factory()->for($tung)->create(['model' => 'claude-opus-5', 'tokens' => 900]);
    Event::factory()->for($tung)->create(['model' => 'claude-fable-5-1', 'tokens' => 100]);

    $data = (new TokensByModelChart)->getChartData();

    expect($data['labels'])->toBe(['Opus 5', 'Fable 5.1'])
        ->and($data['datasets'])->toHaveCount(1)
        ->and($data['datasets'][0]['data'])->toBe([900, 100]);
});

test('colours each bar from the family enum, not chart defaults', function () {
    // Models are open-ended, so library-default colours would shuffle as new
    // ids appear and the chart would stop being readable at a glance. One
    // dataset means the colours travel per bar, not per dataset.
    $tung = User::factory()->create(['slack_handle' => 'tung']);
    Event::factory()->for($tung)->create(['model' => 'claude-fable-5-1', 'tokens' => 100]);
    Event::factory()->for($tung)->create(['model' => 'some-future-model', 'tokens' => 50]);

    $colors = (new TokensByModelChart)->getChartData()['datasets'][0]['backgroundColor'];

    expect($colors)->toHaveCount(2)
        ->and(array_filter($colors))->toHaveCount(2)
        ->and(array_unique($colors))->toHaveCount(2);
});

test('gives two versions of one line the same colour', function () {
    // The bars split per version, so colour is the only thing left telling
    // the reader that Opus 4.8 and Opus 5 are the same line. If it split too,
    // a line's migration would read as two unrelated models appearing.
    $tung = User::factory()->create(['slack_handle' => 'tung']);
    Event::factory()->for($tung)->create(['model' => 'claude-opus-5', 'tokens' => 900]);
    Event::factory()->for($tung)->create(['model' => 'claude-opus-4-8', 'tokens' => 100]);

    $data = (new TokensByModelChart)->getChartData();

    expect($data['labels'])->toBe(['Opus 5', 'Opus 4.8'])
        ->and(array_unique($data['datasets'][0]['backgroundColor']))->toHaveCount(1);
});

test('lines up a contributor list with each bar', function () {
    // The tooltip reads these positionally against the labels, so a row in
    // the wrong slot would credit one model's spend to another.
    $tung = User::factory()->create(['slack_handle' => 'tung']);
    $an = User::factory()->create(['slack_handle' => 'an']);
    Event::factory()->for($tung)->create(['model' => 'claude-opus-5', 'tokens' => 900]);
    Event::factory()->for($an)->create(['model' => 'claude-opus-5', 'tokens' => 100]);
    Event::factory()->for($an)->create(['model' => 'claude-fable-5-1', 'tokens' => 50]);

    expect((new TokensByModelChart)->getContributorLines())->toBe([
        ['tung — 900', 'an — 100'],
        ['an — 50'],
    ]);
});

test('the chart options carry no double quote', function () {
    // Filament drops getOptions()'s RawJs verbatim into x-data="chart({...})",
    // a double-quoted HTML attribute. A single literal " anywhere in it —
    // including inside a user's Slack handle — closes that attribute early
    // and spills the rest of the options onto the page as text.
    $user = User::factory()->create(['slack_handle' => 'quote"injector']);
    Event::factory()->for($user)->create(['model' => 'claude-opus-5', 'tokens' => 100]);

    expect((string) (new TokensByModelChart)->getChartOptions())->not->toContain('"');
});

test('the chart options still carry the handle they escaped', function () {
    // The escaping above must not silently drop the contributor: a handle
    // that vanishes from the tooltip is a wrong answer, not a safe one.
    $user = User::factory()->create(['slack_handle' => 'quote"injector']);
    Event::factory()->for($user)->create(['model' => 'claude-opus-5', 'tokens' => 100]);

    expect((string) (new TokensByModelChart)->getChartOptions())
        ->toContain('quote')
        ->toContain('injector');
});

test('renders the options inside the x-data attribute even for a hostile handle', function () {
    // The unit assertions above check the string Filament is handed; this one
    // checks what the browser actually parses out of the page. Read through a
    // real HTML parser, not a regex: a stray quote ends the attribute early,
    // and a regex scanning the raw source happily matches across that break
    // and reports the options as intact when the browser would not.
    $user = User::factory()->create(['slack_handle' => 'quote"injector']);
    Event::factory()->for($user)->create(['model' => 'claude-opus-5', 'tokens' => 100]);

    $html = Livewire::test(TokensByModelChart::class, ['filters' => ['range' => 'all']])
        ->assertOk()
        ->html();

    $document = new DOMDocument;
    $document->loadHTML($html, LIBXML_NOERROR);

    $xData = collect(iterator_to_array((new DOMXPath($document))->query('//*[@x-data]')))
        ->map(fn (DOMElement $node): string => $node->getAttribute('x-data'))
        ->first(fn (string $value): bool => str_contains($value, 'chart({'));

    expect($xData)->toContain('afterBody')
        ->and($xData)->toContain('injector');
});
