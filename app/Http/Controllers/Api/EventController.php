<?php

namespace App\Http\Controllers\Api;

use App\Events\BossKilled;
use App\Events\BossSpawned;
use App\Events\FighterChargeCleared;
use App\Events\FighterCharging;
use App\Events\FighterJoined;
use App\Events\HitDealt;
use App\Http\Controllers\Controller;
use App\Models\Boss;
use App\Models\Event;
use App\Services\AccountResolver;
use App\Services\Accounts\AccountMembershipRecorder;
use App\Services\DamageService;
use App\Services\Events\ModelUsageParser;
use App\Services\Events\TurnUsage;
use App\Services\FighterChargingCache;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class EventController extends Controller
{
    public function __construct(
        private DamageService $damage,
        private ModelUsageParser $models,
        private FighterChargingCache $chargingCache,
        private AccountResolver $accounts,
        private AccountMembershipRecorder $membership,
    ) {}

    public function store(Request $request): JsonResponse
    {
        $user = $request->user('hook');
        $payload = $request->all();

        $accountEmail = $this->trimmedStringOrNull($payload['account_email'] ?? null);
        $accountSource = $this->trimmedStringOrNull($payload['account_source'] ?? null);
        $accountOrgId = $this->trimmedStringOrNull($payload['account_org_id'] ?? null);
        $provider = $request->query('provider', 'claude-code');
        $accountId = $this->accounts->resolve($accountOrgId, $accountEmail, $provider);
        $clientVersion = $this->trimmedStringOrNull($payload['client_version'] ?? null);
        $customActivity = $this->trimmedStringOrNull($payload['custom_activity'] ?? null);

        $hookName = $payload['hook_event_name'] ?? 'unknown';
        $eventType = $this->normalizeEventType($hookName);
        $usage = $this->resolveStopUsage($eventType, $payload);
        $tokens = $usage?->tokens ?? 0;
        $model = $usage !== null ? $this->models->primaryModel($usage->modelTokens) : null;

        $user->forceFill([
            'last_event_at' => now(),
            'client_version' => $clientVersion ?? $user->client_version,
        ])->save();

        if ($eventType === 'user-prompt-submit' || $eventType === 'pre-invocation') {
            $activity = $this->truncateActivity($customActivity ?? 'thinking…');
            $this->chargingCache->put($user->id, $activity);
            $this->dispatchSafely(new FighterCharging($user, $activity, $this->aliveBoss()));
        }

        if ($eventType === 'pre-tool-use') {
            $activity = $this->truncateActivity($customActivity ?? $this->summarizeToolUse($payload));
            $this->chargingCache->put($user->id, $activity);
            $this->dispatchSafely(new FighterCharging($user, $activity, $this->aliveBoss()));
        }

        if ($eventType === 'session-start') {
            $this->dispatchSafely(new FighterJoined($user, $this->aliveBoss()));
        }

        if ($eventType === 'stop') {
            // Trackers that only emit Stop events (claude.ai, cowork) carry no
            // pre-action signal, so surface a persistent source label as their
            // charging activity instead of clearing the bubble outright.
            $activityLabel = $this->providerActivityLabel($provider);

            if ($tokens > 0) {
                $boss = $this->aliveBoss();

                Event::create([
                    'user_id' => $user->id,
                    'boss_id' => $boss?->id,
                    'provider' => $provider,
                    'model' => $model,
                    'tokens' => $tokens,
                    'session_id' => $payload['session_id'] ?? null,
                    'account_id' => $accountId,
                    'account_email' => $accountEmail,
                    'account_source' => $accountSource,
                    'account_org_id' => $accountOrgId,
                ]);

                try {
                    if ($accountId !== null) {
                        $this->membership->record($user->id, $accountId);
                    }
                } catch (\Throwable) {
                    // Membership recording is best-effort — it must never break ingest.
                }

                $result = $this->damage->apply($user, $tokens);

                foreach ($result->killedBosses as $killed) {
                    $this->dispatchSafely(new BossKilled($killed, $user));
                }

                if (! empty($result->killedBosses)) {
                    $this->dispatchSafely(new BossSpawned($result->boss));
                }

                $this->dispatchSafely(new HitDealt($user, $tokens, $result->boss));

                if ($activityLabel !== null) {
                    // Dispatched after HitDealt: the client clears the charge
                    // when the attack lands, so re-set the bubble afterwards.
                    $this->chargingCache->put($user->id, $activityLabel);
                    $this->dispatchSafely(new FighterCharging($user, $activityLabel, $result->boss));
                } else {
                    $this->chargingCache->forget($user->id);
                }
            } else {
                // Nothing to damage with — clear the charging visual only.
                // FighterChargeCleared (not FighterIdled) because this is a
                // routine "no tokens this turn" case, not genuine idleness —
                // FighterIdled fully removes the fighter client-side.
                $this->chargingCache->forget($user->id);
                $this->dispatchSafely(new FighterChargeCleared($user));
            }
        }

        return response()->json(['ok' => true], 201);
    }

    private function aliveBoss(): ?Boss
    {
        return Boss::where('status', 'alive')->orderByDesc('number')->first();
    }

    /**
     * Persistent charging-bubble label for trackers that only emit Stop events
     * and therefore have no per-action activity to show. Returns null for
     * providers (e.g. claude-code) whose charging state is managed elsewhere.
     */
    private function providerActivityLabel(string $provider): ?string
    {
        return match ($provider) {
            'claude-ai' => 'claude.ai',
            'cowork' => 'cowork',
            default => null,
        };
    }

    private function normalizeEventType(string $hookName): string
    {
        return strtolower(preg_replace('/([a-z])([A-Z])/', '$1-$2', $hookName));
    }

    /**
     * Build a privacy-safe "what is the agent doing" label from a PreToolUse
     * payload. Only the tool name is ever surfaced by default — no command
     * text, file paths, search patterns, or URLs, since the charging bubble
     * is visible to everyone watching the battlefield. A client-supplied
     * `custom_activity` payload field always takes priority over this and
     * is applied by the caller before this method is reached.
     *
     * @param  array<string, mixed>  $payload
     */
    private function summarizeToolUse(array $payload): string
    {
        $tool = (string) ($payload['tool_name'] ?? 'tool');

        if (str_starts_with($tool, 'mcp__')) {
            $segments = explode('__', $tool, 3);

            if (($segments[1] ?? '') !== '') {
                return $this->truncateActivity('MCP: '.$segments[1]);
            }
        }

        $label = match ($tool) {
            'Bash', 'BashOutput', 'KillShell', 'run_command' => 'Bash',
            'Read', 'read_file', 'view_file' => 'Read file',
            'Edit', 'Write', 'MultiEdit', 'NotebookEdit', 'write_file', 'write_to_file', 'replace_file_content', 'multi_replace_file_content' => 'Edit file',
            'Grep', 'grep_search', 'Glob' => 'Search',
            'WebFetch', 'WebSearch' => 'Web',
            'Task' => 'Agent',
            'TodoWrite' => 'TodoWrite',
            default => $tool,
        };

        return $this->truncateActivity($label);
    }

    /**
     * Truncate a charging-bubble activity label to a display-friendly
     * length, appending an ellipsis when truncation occurs. Applied to both
     * default tool-name labels and client-supplied `custom_activity` values.
     *
     * @param  string  $activity
     * @return string
     */
    private function truncateActivity(string $activity): string
    {
        return mb_strlen($activity) > 40 ? mb_substr($activity, 0, 39).'…' : $activity;
    }

    /**
     * Resolve a Stop event's usage from its payload. The hook computes both the
     * token total and the per-model split on the machine that owns the
     * transcript, so the server never opens a file and needs no retry loop —
     * the old server-side fallback only ever ran when the hook host and the
     * server were the same machine, and `transcript_path` is no longer sent.
     *
     * @param  string  $eventType  the normalized hook event name
     * @param  array<string, mixed>  $payload  the raw hook payload
     * @return ?TurnUsage null for anything that is not a Stop event
     */
    private function resolveStopUsage(string $eventType, array $payload): ?TurnUsage
    {
        if ($eventType !== 'stop') {
            return null;
        }

        return TurnUsage::fromPayload($payload, $this->models);
    }

    /**
     * Broadcasts are best-effort: the damage transaction has already committed,
     * so a downed websocket or misconfigured driver must not 500 the hook.
     */
    private function dispatchSafely(object $event): void
    {
        rescue(fn () => event($event));
    }

    /**
     * Normalize an incoming payload string: trimmed non-empty string or null.
     *
     * @param  mixed  $value
     * @return ?string
     */
    private function trimmedStringOrNull(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }
        $trimmed = trim($value);

        return $trimmed === '' ? null : mb_substr($trimmed, 0, 255);
    }
}
