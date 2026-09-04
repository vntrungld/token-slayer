<?php

use App\Events\BossKilled;
use App\Events\BossSpawned;
use App\Events\FighterChargeCleared;
use App\Events\FighterCharging;
use App\Events\FighterJoined;
use App\Events\HitDealt;
use App\Models\Account;
use App\Models\Boss;
use App\Models\Event;
use App\Models\User;
use App\Services\FighterChargingCache;
use App\Services\TranscriptReader;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Schema;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->user = User::factory()->create(['hook_token' => hash('sha256', 'tok')]);
    Boss::factory()->create(['number' => 1, 'max_hp' => 1_000_000, 'current_hp' => 1_000_000]);
    Cache::flush();
});

test('rejects unauthenticated requests', function () {
    $this->postJson('/api/events', ['hook_event_name' => 'SessionStart'])->assertStatus(401);
});

test('non-Stop event is not persisted but still bumps last_event_at', function () {
    $this->withHeader('Authorization', 'Bearer tok')
        ->postJson('/api/events', [
            'hook_event_name' => 'SessionStart',
            'session_id' => 'sess-abc',
            'cwd' => '/home/dev/project',
        ])
        ->assertCreated();

    expect(Event::count())->toBe(0)
        ->and($this->user->fresh()->last_event_at)->not->toBeNull();
});

test('Stop event with tokens damages the current boss and broadcasts HitDealt', function () {
    Illuminate\Support\Facades\Event::fake([HitDealt::class]);

    $this->withHeader('Authorization', 'Bearer tok')
        ->postJson('/api/events', [
            'hook_event_name' => 'Stop',
            'session_id' => 'sess-1',
            'tokens' => 250_000,
        ])
        ->assertCreated();

    $boss = Boss::sole();
    expect($boss->current_hp)->toBe(750_000);

    Illuminate\Support\Facades\Event::assertDispatched(HitDealt::class, function ($e) {
        return $e->damage === 250_000 && $e->boss->current_hp === 750_000;
    });
});

test('Stop event without inline tokens reads damage from the transcript file', function () {
    $transcript = tempnam(sys_get_temp_dir(), 'transcript-');
    file_put_contents($transcript, collect([
        ['type' => 'user', 'message' => ['content' => [['type' => 'text', 'text' => 'go']]]],
        ['type' => 'assistant', 'message' => ['usage' => ['output_tokens' => 120_000]]],
        ['type' => 'user', 'message' => ['content' => [['type' => 'tool_result', 'content' => 'ok']]]],
        ['type' => 'assistant', 'message' => ['usage' => ['output_tokens' => 80_000]]],
    ])->map(fn ($e) => json_encode($e))->implode("\n"));

    $this->withHeader('Authorization', 'Bearer tok')
        ->postJson('/api/events', [
            'hook_event_name' => 'Stop',
            'session_id' => 'sess-transcript',
            'transcript_path' => $transcript,
        ])
        ->assertCreated();

    expect(Boss::sole()->current_hp)->toBe(800_000)
        ->and(Event::sole()->tokens)->toBe(200_000);

    @unlink($transcript);
});

test('Stop event without inline tokens reads damage from the Antigravity transcript file', function () {
    $transcript = tempnam(sys_get_temp_dir(), 'transcript-agy-');
    file_put_contents($transcript, collect([
        ['source' => 'USER_EXPLICIT', 'type' => 'USER_INPUT', 'content' => 'hello'],
        ['source' => 'MODEL', 'type' => 'PLANNER_RESPONSE', 'usage' => ['output_tokens' => 150_000]],
        ['source' => 'SYSTEM', 'type' => 'TOOL_RESULT', 'content' => 'tool done'],
        ['source' => 'MODEL', 'type' => 'PLANNER_RESPONSE', 'usage' => ['output_tokens' => 100_000]],
    ])->map(fn ($e) => json_encode($e))->implode("\n"));

    $this->withHeader('Authorization', 'Bearer tok')
        ->postJson('/api/events?provider=antigravity', [
            'hook_event_name' => 'Stop',
            'session_id' => 'sess-agy-transcript',
            'transcriptPath' => $transcript,
        ])
        ->assertCreated();

    expect(Boss::sole()->current_hp)->toBe(750_000)
        ->and(Event::sole()->tokens)->toBe(250_000);

    @unlink($transcript);
});

test('Stop event still applies damage when a broadcast listener throws', function () {
    Illuminate\Support\Facades\Event::listen(HitDealt::class, function () {
        throw new RuntimeException('simulated broadcast failure');
    });

    $this->withHeader('Authorization', 'Bearer tok')
        ->postJson('/api/events', [
            'hook_event_name' => 'Stop',
            'session_id' => 'sess-broadcast-down',
            'tokens' => 100_000,
        ])
        ->assertCreated();

    expect(Boss::sole()->current_hp)->toBe(900_000);
});

test('Stop event with no tokens still broadcasts FighterChargeCleared to clear charging state', function () {
    Illuminate\Support\Facades\Event::fake([FighterChargeCleared::class, HitDealt::class]);

    $this->withHeader('Authorization', 'Bearer tok')
        ->postJson('/api/events', [
            'hook_event_name' => 'Stop',
            'session_id' => 'sess-empty',
            'tokens' => 0,
        ])
        ->assertCreated();

    Illuminate\Support\Facades\Event::assertDispatched(FighterChargeCleared::class, function ($e) {
        return $e->user->is($this->user);
    });
    Illuminate\Support\Facades\Event::assertNotDispatched(HitDealt::class);
    expect(Event::count())->toBe(0);
});

test('Stop event retries the transcript read until the assistant entry lands', function () {
    Illuminate\Support\Facades\Event::fake([HitDealt::class]);

    $transcript = tempnam(sys_get_temp_dir(), 'transcript-race-');
    // At the instant the Stop hook would have fired, only the user prompt
    // is on disk; the assistant entry lands a moment later.
    file_put_contents($transcript, json_encode([
        'type' => 'user', 'message' => ['content' => [['type' => 'text', 'text' => 'go']]],
    ]));

    $reader = $this->mock(TranscriptReader::class);
    $reader->shouldReceive('latestTurnOutputTokens')
        ->times(2)
        ->andReturnValues([0, 75_000]);

    $this->withHeader('Authorization', 'Bearer tok')
        ->postJson('/api/events', [
            'hook_event_name' => 'Stop',
            'session_id' => 'sess-race',
            'transcript_path' => $transcript,
        ])
        ->assertCreated();

    expect(Boss::sole()->current_hp)->toBe(925_000);
    Illuminate\Support\Facades\Event::assertDispatched(HitDealt::class);

    @unlink($transcript);
});

test('Stop event killing the boss broadcasts BossKilled then BossSpawned', function () {
    Boss::query()->delete();
    Boss::factory()->create(['number' => 1, 'max_hp' => 100, 'current_hp' => 100]);
    Illuminate\Support\Facades\Event::fake([
        HitDealt::class,
        BossKilled::class,
        BossSpawned::class,
    ]);

    $this->withHeader('Authorization', 'Bearer tok')
        ->postJson('/api/events', ['hook_event_name' => 'Stop', 'tokens' => 350])
        ->assertCreated();

    Illuminate\Support\Facades\Event::assertDispatched(BossKilled::class);
    Illuminate\Support\Facades\Event::assertDispatched(BossSpawned::class);
});

test('BossSpawned includes each active fighter\'s character for the new boss', function () {
    Boss::query()->delete();
    $oldBoss = Boss::factory()->create(['number' => 1, 'max_hp' => 100, 'current_hp' => 100]);
    Illuminate\Support\Facades\Event::fake([
        HitDealt::class,
        BossKilled::class,
        BossSpawned::class,
    ]);

    $this->withHeader('Authorization', 'Bearer tok')
        ->postJson('/api/events', ['hook_event_name' => 'Stop', 'tokens' => 350])
        ->assertCreated();

    $newBoss = Boss::where('status', 'alive')->orderByDesc('number')->first();

    Illuminate\Support\Facades\Event::assertDispatched(BossSpawned::class, function (BossSpawned $e) use ($oldBoss, $newBoss) {
        $fighter = collect($e->broadcastWith()['fighters'])->firstWhere('user_id', $this->user->id);

        return $fighter !== null
            && $fighter['character'] === $this->user->characterForBoss($newBoss->id)
            && $fighter['character'] !== $this->user->characterForBoss($oldBoss->id);
    });
});

test('session-start broadcasts FighterJoined with the character for the alive boss', function () {
    Illuminate\Support\Facades\Event::fake([FighterJoined::class]);
    $boss = Boss::sole();

    $this->withHeader('Authorization', 'Bearer tok')
        ->postJson('/api/events', [
            'hook_event_name' => 'SessionStart',
            'session_id' => 'sess-join',
        ])
        ->assertCreated();

    Illuminate\Support\Facades\Event::assertDispatched(FighterJoined::class, function (FighterJoined $e) use ($boss) {
        return $e->broadcastWith()['character'] === $this->user->characterForBoss($boss->id);
    });
});

test('user-prompt-submit caches the fighter activity', function () {
    $this->withHeader('Authorization', 'Bearer tok')
        ->postJson('/api/events', [
            'hook_event_name' => 'UserPromptSubmit',
            'session_id' => 'sess-1',
        ])
        ->assertCreated();

    $entry = app(FighterChargingCache::class)->many([$this->user->id])[$this->user->id];
    expect($entry['activity'])->toBe('thinking…');
});

test('pre-tool-use caches the privacy-safe default tool name activity', function () {
    $this->withHeader('Authorization', 'Bearer tok')
        ->postJson('/api/events', [
            'hook_event_name' => 'PreToolUse',
            'tool_name' => 'Bash',
            'tool_input' => ['command' => 'npm install'],
        ])
        ->assertCreated();

    $entry = app(FighterChargingCache::class)->many([$this->user->id])[$this->user->id];
    expect($entry['activity'])->toBe('Bash');
});

test('pre-tool-use summarizes an MCP tool name to the server label', function () {
    $this->withHeader('Authorization', 'Bearer tok')
        ->postJson('/api/events', [
            'hook_event_name' => 'PreToolUse',
            'tool_name' => 'mcp__jira__jira_search',
        ])
        ->assertCreated();

    $entry = app(FighterChargingCache::class)->many([$this->user->id])[$this->user->id];
    expect($entry['activity'])->toBe('MCP: jira');
});

test('pre-tool-use falls back to the bare tool name for unrecognized tools', function () {
    $this->withHeader('Authorization', 'Bearer tok')
        ->postJson('/api/events', [
            'hook_event_name' => 'PreToolUse',
            'tool_name' => 'SomeCustomTool',
        ])
        ->assertCreated();

    $entry = app(FighterChargingCache::class)->many([$this->user->id])[$this->user->id];
    expect($entry['activity'])->toBe('SomeCustomTool');
});

test('pre-tool-use uses the client-provided custom_activity over the default tool name', function () {
    $this->withHeader('Authorization', 'Bearer tok')
        ->postJson('/api/events', [
            'hook_event_name' => 'PreToolUse',
            'tool_name' => 'Bash',
            'tool_input' => ['command' => 'npm install'],
            'custom_activity' => 'installing deps',
        ])
        ->assertCreated();

    $entry = app(FighterChargingCache::class)->many([$this->user->id])[$this->user->id];
    expect($entry['activity'])->toBe('installing deps');
});

test('pre-tool-use truncates an overlong custom_activity to 40 characters', function () {
    $this->withHeader('Authorization', 'Bearer tok')
        ->postJson('/api/events', [
            'hook_event_name' => 'PreToolUse',
            'tool_name' => 'Bash',
            'custom_activity' => str_repeat('a', 60),
        ])
        ->assertCreated();

    $entry = app(FighterChargingCache::class)->many([$this->user->id])[$this->user->id];
    expect(mb_strlen($entry['activity']))->toBe(40)
        ->and($entry['activity'])->toEndWith('…');
});

test('user-prompt-submit uses the client-provided custom_activity over the default thinking label', function () {
    $this->withHeader('Authorization', 'Bearer tok')
        ->postJson('/api/events', [
            'hook_event_name' => 'UserPromptSubmit',
            'session_id' => 'sess-1',
            'custom_activity' => 'planning refactor',
        ])
        ->assertCreated();

    $entry = app(FighterChargingCache::class)->many([$this->user->id])[$this->user->id];
    expect($entry['activity'])->toBe('planning refactor');
});

test('custom_activity has no effect on event types other than pre-tool-use and user-prompt-submit', function () {
    Illuminate\Support\Facades\Event::fake([FighterChargeCleared::class]);

    $this->withHeader('Authorization', 'Bearer tok')
        ->postJson('/api/events', [
            'hook_event_name' => 'Stop',
            'session_id' => 'sess-custom-stop',
            'tokens' => 0,
            'custom_activity' => 'should be ignored',
        ])
        ->assertCreated();

    $entry = app(FighterChargingCache::class)->many([$this->user->id])[$this->user->id];
    expect($entry)->toBeNull();
});

test('stop with tokens clears the cached charging entry', function () {
    app(FighterChargingCache::class)->put($this->user->id, 'thinking…');

    $this->withHeader('Authorization', 'Bearer tok')
        ->postJson('/api/events', [
            'hook_event_name' => 'Stop',
            'session_id' => 'sess-1',
            'tokens' => 250_000,
        ])
        ->assertCreated();

    $entry = app(FighterChargingCache::class)->many([$this->user->id])[$this->user->id];
    expect($entry)->toBeNull();
});

test('stop with zero tokens clears the cached charging entry', function () {
    app(FighterChargingCache::class)->put($this->user->id, 'thinking…');

    $this->withHeader('Authorization', 'Bearer tok')
        ->postJson('/api/events', [
            'hook_event_name' => 'Stop',
            'session_id' => 'sess-1',
            'tokens' => 0,
        ])
        ->assertCreated();

    $entry = app(FighterChargingCache::class)->many([$this->user->id])[$this->user->id];
    expect($entry)->toBeNull();
});

test('Stop event from the claude.ai tracker records the claude-ai provider and damages the boss', function () {
    $this->withHeader('Authorization', 'Bearer tok')
        ->postJson('/api/events?provider=claude-ai', [
            'hook_event_name' => 'Stop',
            'session_id' => 'conv-uuid-1',
            'tokens' => 50_000,
        ])
        ->assertCreated();

    expect(Event::sole())
        ->provider->toBe('claude-ai')
        ->tokens->toBe(50_000)
        ->session_id->toBe('conv-uuid-1')
        ->and(Boss::sole()->current_hp)->toBe(950_000);
});

test('Stop event from the cowork watcher records the cowork provider and damages the boss', function () {
    $this->withHeader('Authorization', 'Bearer tok')
        ->postJson('/api/events?provider=cowork', [
            'hook_event_name' => 'Stop',
            'session_id' => 'cowork-task-1',
            'tokens' => 40_000,
        ])
        ->assertCreated();

    expect(Event::sole())
        ->provider->toBe('cowork')
        ->tokens->toBe(40_000)
        ->session_id->toBe('cowork-task-1')
        ->and(Boss::sole()->current_hp)->toBe(960_000);
});

test('Stop event from the claude.ai tracker shows a persistent source-label bubble', function () {
    Illuminate\Support\Facades\Event::fake([FighterCharging::class]);

    $this->withHeader('Authorization', 'Bearer tok')
        ->postJson('/api/events?provider=claude-ai', [
            'hook_event_name' => 'Stop',
            'session_id' => 'conv-uuid-1',
            'tokens' => 50_000,
        ])
        ->assertCreated();

    $entry = app(FighterChargingCache::class)->many([$this->user->id])[$this->user->id];
    expect($entry['activity'])->toBe('claude.ai');

    Illuminate\Support\Facades\Event::assertDispatched(FighterCharging::class, function (FighterCharging $e) {
        return $e->user->is($this->user) && $e->activity === 'claude.ai';
    });
});

test('Stop event from the cowork watcher shows a persistent source-label bubble', function () {
    $this->withHeader('Authorization', 'Bearer tok')
        ->postJson('/api/events?provider=cowork', [
            'hook_event_name' => 'Stop',
            'session_id' => 'cowork-task-1',
            'tokens' => 40_000,
        ])
        ->assertCreated();

    $entry = app(FighterChargingCache::class)->many([$this->user->id])[$this->user->id];
    expect($entry['activity'])->toBe('cowork');
});

test('Stop event from a single-emit tracker with zero tokens still clears the bubble', function () {
    app(FighterChargingCache::class)->put($this->user->id, 'claude.ai');

    $this->withHeader('Authorization', 'Bearer tok')
        ->postJson('/api/events?provider=claude-ai', [
            'hook_event_name' => 'Stop',
            'session_id' => 'conv-uuid-1',
            'tokens' => 0,
        ])
        ->assertCreated();

    $entry = app(FighterChargingCache::class)->many([$this->user->id])[$this->user->id];
    expect($entry)->toBeNull();
});

it('persists attribution columns on events when provided directly', function () {
    $account = Account::factory()->create(['email' => 'org@ownego.com']);
    $event = Event::factory()->create([
        'user_id' => $this->user->id,
        'account_id' => $account->id,
        'account_email' => 'org@ownego.com',
        'account_source' => 'credential',
    ]);

    expect($event->fresh()->account_id)->toBe($account->id)
        ->and($event->fresh()->account_source)->toBe('credential');
});

it('attributes a stop event to the matching org account', function () {
    $account = Account::factory()->create(['email' => 'org@ownego.com']);

    $this->withHeader('Authorization', 'Bearer tok')
        ->postJson('/api/events', [
            'hook_event_name' => 'Stop',
            'tokens' => 500,
            'account_email' => 'ORG@ownego.com',
            'account_uuid' => 'uuid-1',
            'account_source' => 'credential',
            'client_version' => '2',
        ])
        ->assertCreated();

    $event = Event::latest('id')->first();
    expect($event->account_id)->toBe($account->id)
        ->and($event->account_email)->toBe('ORG@ownego.com')
        ->and($event->account_source)->toBe('credential')
        ->and($this->user->fresh()->client_version)->toBe('2');
});

it('records unknown account emails with a null account id', function () {
    $this->withHeader('Authorization', 'Bearer tok')
        ->postJson('/api/events', [
            'hook_event_name' => 'Stop', 'tokens' => 100,
            'account_email' => 'personal@gmail.com', 'account_source' => 'auto',
        ])
        ->assertCreated();

    $event = Event::latest('id')->first();
    expect($event->account_id)->toBeNull()
        ->and($event->account_email)->toBe('personal@gmail.com');
});

it('accepts legacy payloads with no attribution fields', function () {
    $this->withHeader('Authorization', 'Bearer tok')
        ->postJson('/api/events', [
            'hook_event_name' => 'Stop', 'tokens' => 100,
        ])
        ->assertCreated();

    $event = Event::latest('id')->first();
    expect($event->account_id)->toBeNull()
        ->and($event->account_email)->toBeNull()
        ->and($this->user->fresh()->client_version)->toBeNull();
});

it('stores the raw account org id on the event', function () {
    $this->withHeader('Authorization', 'Bearer tok')
        ->postJson('/api/events', [
            'hook_event_name' => 'Stop', 'tokens' => 100,
            'account_org_id' => 'org-raw-x',
        ])
        ->assertCreated();

    $event = Event::latest('id')->first();
    expect($event->account_org_id)->toBe('org-raw-x')
        ->and($event->account_id)->toBeNull();
});

it('does not persist custom_activity on the event row', function () {
    $this->withHeader('Authorization', 'Bearer tok')
        ->postJson('/api/events', [
            'hook_event_name' => 'Stop', 'tokens' => 100,
            'custom_activity' => 'reviewing PR',
        ])
        ->assertCreated();

    $event = Event::latest('id')->first();
    expect($event->getAttributes())->not->toHaveKey('custom_activity');
});

it('attributes the event to the account matching the organization uuid', function () {
    $account = Account::factory()->withOrganizationUuid('org-match-1')->create();

    $this->withHeader('Authorization', 'Bearer tok')
        ->postJson('/api/events', [
            'hook_event_name' => 'Stop', 'tokens' => 100,
            'account_org_id' => 'org-match-1',
        ])
        ->assertCreated();

    $event = Event::latest('id')->first();
    expect($event->account_id)->toBe($account->id);
});

it('attributes a provider-sourced stop event by organization uuid', function () {
    $account = Account::factory()->withOrganizationUuid('org-provider-1')->create();

    $this->withHeader('Authorization', 'Bearer tok')
        ->postJson('/api/events', [
            'hook_event_name' => 'Stop',
            'session_id' => 's-provider',
            'tokens' => 1200,
            'account_org_id' => 'org-provider-1',
            'account_source' => 'provider',
        ])
        ->assertCreated();

    $event = Event::query()->latest('id')->first();
    expect($event->account_id)->toBe($account->id);
    expect($event->account_source)->toBe('provider');
    expect($event->account_org_id)->toBe('org-provider-1');
});

it('attributes a detector-sourced stop event by account email', function () {
    $account = Account::factory()->create(['email' => 'detected@ownego.com']);

    $this->withHeader('Authorization', 'Bearer tok')
        ->postJson('/api/events', [
            'hook_event_name' => 'Stop',
            'session_id' => 's-detector',
            'tokens' => 900,
            'account_email' => 'detected@ownego.com',
            'account_source' => 'detector',
        ])
        ->assertCreated();

    $event = Event::query()->latest('id')->first();
    expect($event->account_id)->toBe($account->id);
    expect($event->account_source)->toBe('detector');
    expect($event->account_email)->toBe('detected@ownego.com');
});

test('events table has a nullable model column', function () {
    expect(Schema::hasColumn('events', 'model'))->toBeTrue();

    // events.user_id is NOT NULL (foreignId()->constrained()), and EventFactory
    // supplies only provider/tokens/session_id — omitting the user would fail
    // on the constraint rather than on the missing column.
    $event = Event::factory()->create(['user_id' => $this->user->id, 'model' => null]);

    expect($event->fresh()->model)->toBeNull();
});
