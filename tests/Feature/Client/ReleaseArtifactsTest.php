<?php

use App\Services\Client\ReleaseArtifacts;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

uses(RefreshDatabase::class);

beforeEach(function () {
    Cache::flush();
    config(['github.token' => 'ghp_test', 'github.cli_repo' => 'acme/slayer-cli']);
});

test('the served script and the published digest never drift', function () {
    // /install resolves clientVersion from GitHub fresh on EVERY request, so
    // hashing a separately-rendered copy would publish a digest for bytes
    // nobody was served -- and every client would then fail closed on a
    // checksum mismatch, forever, with no error anywhere.
    Http::fake(['api.github.com/*' => Http::sequence()
        ->push(['tag_name' => 'v1.0.0', 'assets' => [['id' => 1, 'name' => 'slayer_cli-latest.whl']]])
        ->whenEmpty(Http::response(['tag_name' => 'v2.0.0', 'assets' => [['id' => 2, 'name' => 'slayer_cli-latest.whl']]]))]);

    $digest = app(ReleaseArtifacts::class)->digest('install-script');

    expect(hash('sha256', $this->get('/install')->getContent()))->toBe($digest);
});

test('a digest is published for each install script separately', function () {
    Http::fake(['api.github.com/*' => Http::response(['tag_name' => 'v1.0.0', 'assets' => [['id' => 1, 'name' => 'slayer_cli-latest.whl']]])]);

    $artifacts = app(ReleaseArtifacts::class);

    // One digest for both platforms would make the Windows self-update fail
    // closed on every run, forever, with no symptom but nobody updating.
    expect($artifacts->digest('install-script'))
        ->not->toBe($artifacts->digest('install-script-ps1'));
});

test('a digest lookup never throws, so ingest cannot 500 on it', function () {
    // /api/events is the single append-only write path; losing a digest must
    // never cost an event.
    Http::fake(fn () => throw new RuntimeException('GitHub down'));

    expect(fn () => app(ReleaseArtifacts::class)->wheelDigest())->not->toThrow(Throwable::class);
});

test('warming the cache means later reads make no GitHub call', function () {
    Http::fake(['api.github.com/*' => Http::response(['tag_name' => 'v1.0.0', 'assets' => [['id' => 1, 'name' => 'slayer_cli-latest.whl']]])]);
    app(ReleaseArtifacts::class)->warm();

    Http::fake(fn () => throw new RuntimeException('must not reach GitHub'));

    expect(app(ReleaseArtifacts::class)->digest('install-script'))->toMatch('/^[0-9a-f]{64}$/');
});
