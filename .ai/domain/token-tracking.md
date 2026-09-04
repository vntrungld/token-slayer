# Domain: Token Tracking (hook → event → damage pipeline)

## Ingestion

`POST /api/events` (`EventController@store`), authenticated by `hook.token` middleware — `Authorization: Bearer <users.hook_token>` identifies the **user**. Provider comes from the `?provider=` query param baked into each install script: `claude-code` (default), `codex`, `cowork`, `claude-ai`.

**Only four hook events are registered**, because those are the only ones `EventController` handles: `SessionStart`, `UserPromptSubmit`, `PreToolUse`, `Stop` (Antigravity's equivalents: `SessionStart`, `PreInvocation`, `PreToolUse`, `Stop`; Codex: `SessionStart`, `Stop`). `PostToolUse`, `SubagentStop`, `SessionEnd` and `Notification` used to be registered and fell through to a bare 201. `PostToolUse` was also the highest-frequency event and the one carrying `tool_response`, so dropping it stops that content at the source rather than filtering it afterwards. Liveness is unaffected: `PreToolUse` fires immediately before every `PostToolUse`.

When changing that list, the re-registration loop must strip our fingerprint from **every** key already in `~/.claude/settings.json` before re-adding — it once iterated only the events still in its own list, so shrinking the list would have left the stale registrations in place, still firing, with no error anywhere.

**The payload is a fixed twelve-field whitelist**, applied unconditionally: `hook_event_name`, `session_id`, `tokens`, `models`, `tool_name`, `custom_activity`, `client_version`, `hook_version`, `account_email`, `account_uuid`, `account_source`, `account_org_id`. Everything else the hook receives on stdin — the prompt, `tool_input`, `tool_response`, the last assistant message, `cwd`, `permission_mode`, `transcript_path` — never leaves the machine. The filter used to be opt-in behind `SLAYER_MINIMAL_PAYLOAD`, which meant content left the machine unless a developer knew to set an env var; that flag is gone.

**Ordering matters and is load-bearing:** the filter runs *after* `custom.sh` is sourced, and `custom.sh` shares the shell, so it still sees the full body. The `custom_activity` recipes documented on the guide page read `tool_input` locally and only the label they build is sent. `account_uuid` is on the wire but read by nothing; it is kept deliberately rather than removed.

Hook event names arrive as `hook_event_name` (e.g. `Stop`, `PreToolUse`) and are normalized to kebab-case. Behavior per type:

- `user-prompt-submit` / `pre-invocation` / `pre-tool-use` → charging bubble broadcasts (`FighterCharging`), activity summarized from tool payload.
- `session-start` → `FighterJoined`.
- `stop` → the only type that creates an `Event` row, and only when resolved tokens > 0.

## Token resolution for Stop events

**The hook owns token extraction; the server never opens a transcript.** The helper computes both the token total and a `{model: tokens}` map on the machine that owns the file, and sends them inline. There is no server-side fallback: it only ever ran when the hook host and the server were the same machine, so production never used it, and `transcript_path` is no longer sent at all. `TranscriptReader` and its retry loop were removed with it.

**Two extractors, dispatched by provider.** One shared Claude-shaped walk for every provider is exactly why Codex ingestion was silently dead from 2026-06-28 to 2026-09-04 — that walk returns `0` on every Codex rollout, and `EventController` answers `201` with no row, so nothing surfaced.

- **Claude-shaped** (`claude-code`, and `antigravity`, whose shape is unverified and deliberately untouched): walk backwards accumulating `assistant` / `PLANNER_RESPONSE` / `source == "MODEL"` entries, stopping at the first `user` entry that is not a `tool_result` wrapper. The model lives at `message.model` — **not** at a top level `model` key; 0 of 75,811 assistant entries in a real corpus had one. The bare `$e.model` branch is a fallback for the unverified non-Claude shapes only.
- **Codex**: rollout JSONL shares no shape with a Claude transcript. Sum `event_msg.payload.info.last_token_usage.output_tokens`, stop at `task_started`, take the model from the first `turn_context` seen walking back. Three traps, each verified against real rollouts: `total_token_usage` is cumulative for the whole session; `total_tokens` includes input and cached input, which for Codex is ~30× output; `reasoning_output_tokens` is a **subset** of `output_tokens`, so adding it double-counts.

**The capture and the merge must change together.** The jq emits an object (`{tokens, models}`). Merged with the old scalar `. + {tokens:$t}` it would nest as `{"tokens":{"tokens":478,…}}`, and PHP's `(int)` cast on an array yields `1` with no warning — every event would deal 1 token of damage into the append-only ledger.

A turn is recorded under a single `events.model`: the most expensive family it touched (`fable > opus > gpt > sonnet > haiku`), then the highest token count within that family. Ranking by token count would be wrong for the case that matters — in a limit-fallback turn the cheap model produces more tokens because it finished the work. Multi-model turns are logged; measured at 2 in 4,524 turns on one machine, so the real team-wide rate is meant to be observed rather than trusted.

Zero tokens → no Event row; fighter gets `FighterIdled` so the charge visual clears.

**Known blind spot:** subagent turns live in separate `*/subagents/*.jsonl` files that the hook never reads, so roughly 21% of real output tokens never reach `events` at all. Every per-model total is a floor, not a measurement.

## After a hit

`DamageService::apply(user, tokens)` mutates boss HP transactionally, returns killed bosses + the (possibly new) live boss. Controller then broadcasts `BossKilled`/`BossSpawned`/`HitDealt`. Broadcasts are best-effort (`rescue()`) — a downed websocket must never 500 the hook.

## Aggregation

`DamageTotals` — global/per-user/per-account sums over rolling windows (hourly/daily/monthly), 60 s cache on the global key. All aggregates derive from `events`; there are no mutable counters.

## Install scripts

Blade-rendered shell scripts served from web routes (`/install`, `/install.ps1`, `/install-cowork`, `/tracker.user.js`), one POSIX (`install-script.blade.php`) and one PowerShell (`install-script-ps1.blade.php`) — kept in lockstep, same conventions in both. The lockstep rule is enforced by tests that run against **both** routes; a whole-script `toContain` is not enough, since field names also appear in comments and in the lines that *remove* a stale registration. The rendered hook is additionally parsed with `sh -n`, because a misplaced `fi` would break it silently — the hook is fire-and-forget with its output discarded, so the only symptom would be missing events.

**Two version lines, deliberately independent.** `client_version` is the CLI wheel's release tag, resolved from a repo this project does not publish; `hook_version` (`config('token_slayer.hook_version')`) is owned here. A hook-only change bumps the latter, so developers can be told to update without waiting on a CLI release. Both are stamped into `~/.config/{namespace}/` (`version` and `hook-version`) and reported back on every event. Idempotent by design: Claude hooks are **assigned** per event key in `~/.claude/settings.json` (not appended); the Codex `config.toml` block is marker-delimited and replaced. Re-running the install URL is the upgrade path. Hook token is read at runtime from `~/.config/{namespace}/token`.

**jq is always the installer's own pinned binary, never the system's.** Every install downloads a version-pinned, SHA256-checksum-verified `jq` into `~/.config/{namespace}/bin/` (`jq` on POSIX, `jq.exe` on Windows) and self-heals (re-downloads) on a checksum mismatch. The hook template resolves it once as `$JQ` and every call site guards with `[ -x "$JQ" ]`, never `[ -n "$JQ" ]` (a non-empty literal path is not proof the file exists) and never a bare `command -v jq` / system `jq` — cross-machine/version drift in a system-installed jq was silently corrupting attribution on prod, which is why this is pinned instead of "check if installed."

**The installer is fail-fast and all-or-nothing.** Every setup step (jq bootstrap, venv/pip/wheel install, Git-for-Windows presence on the PS1 path) exits/throws immediately on failure with the real captured error (curl/pip stderr, not a generic summary) — a partially-working install is treated as no install. This reverses an earlier deliberate design where Python-toolchain failures were non-blocking; there is no rollback on failure, recovery is just re-running the idempotent install URL. The user-facing `custom.sh` example in `resources/views/livewire/profile.blade.php` follows the same `$JQ`/`-x` convention — keep it in sync if the hook's jq-guard convention changes again.

The `token-slayer setup` CLI pulls any admin-provisioned accounts and then confirms them back to the server via `POST /api/provisioned/confirm` (same `hook.token` bearer), which promotes the `pending` memberships to `tracked`. See the *Provisioning* section of `accounts.md`.
