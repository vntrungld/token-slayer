<?php

use Illuminate\Support\Facades\Http;

beforeEach(fn () => config(['app.hook_namespace' => 'token_slayer']));

test('install.sh is publicly accessible as a shell script', function () {
    $response = $this->get('/install');

    $response->assertOk();
    expect($response->headers->get('Content-Type'))->toStartWith('text/x-shellscript');
});

test('install.sh embeds the events URL and points the hook command at the local token file', function () {
    $script = $this->get('/install')->getContent();

    expect($script)
        ->toContain('#!/bin/sh')
        ->toContain(url('/api/events'))
        ->toContain('TOKEN_FILE="$HOME/.config/token_slayer/token"')
        ->toContain('Bearer $(cat "$TOKEN_FILE")');
});

test('install.sh drops a hook helper script that enriches Stop events with transcript tokens', function () {
    $script = $this->get('/install')->getContent();

    expect($script)
        ->toContain('HELPER="$HOME/.config/token_slayer/send-hook.sh"')
        ->toContain("cat > \"\$HELPER.tmp\" <<'HOOK_SH'")
        ->toContain('chmod +x "$HELPER.tmp"')
        ->toContain('mv -f "$HELPER.tmp" "$HELPER"')
        ->toContain('transcript_path')
        ->toContain('output_tokens')
        ->toContain('CLAUDE_CMD="bash $HELPER"')
        ->toContain('CODEX_CMD="PROVIDER=codex bash $HELPER"');
});

test('install.sh registers the claude code hook events the server handles', function () {
    // A bare toContain($event) is not enough: these names also appear in
    // comments and in the line that REMOVES a stale registration, so such a
    // test stays green while registering nothing. Assert the list itself.
    $script = $this->get('/install')->getContent();

    expect($script)->toContain('events = ["SessionStart", "UserPromptSubmit", "PreToolUse", "Stop"]');
});

test('install.sh registers the antigravity CLI hook events the server handles', function () {
    $script = $this->get('/install')->getContent();

    expect($script)->toContain('for event in ["SessionStart", "PreInvocation", "Stop"]:')
        ->and($script)->toContain('for event in ["PreToolUse"]:');
});

test('install.sh writes to claude settings, codex hooks, and antigravity hooks, and heals legacy codex config.toml with idempotent markers', function () {
    $script = $this->get('/install')->getContent();

    expect($script)
        ->toContain('$HOME/.claude/settings.json')
        ->toContain('$HOME/.codex/config.toml')
        ->toContain('$HOME/.codex/hooks.json')
        ->toContain('$HOME/.gemini/config/hooks.json')
        // The legacy-block-strip regex is a runtime python format string (the
        // namespace is interpolated via `{ns}`, not Blade), so the rendered
        // script contains this literal pattern, not the resolved marker text.
        ->toContain('# >>> {ns} hooks')
        ->toContain('# <<< {ns} hooks');
});

test('install.sh no longer writes [[hooks]] TOML into codex config.toml', function () {
    $script = $this->get('/install')->getContent();

    expect($script)
        ->not->toContain('[[hooks]]')
        ->not->toContain('cat >> "$CODEX_CONFIG"');
});

test('install.sh only heals an existing codex config.toml and never creates one', function () {
    $script = $this->get('/install')->getContent();

    expect($script)
        ->toContain('if [ -f "$CODEX_CONFIG" ]')
        ->not->toContain('touch "$CODEX_CONFIG"');
});

test('install.sh merges codex hooks into hooks.json in the modern shape', function () {
    $script = $this->get('/install')->getContent();

    expect($script)
        ->toContain('CODEX_HOOKS="$HOME/.codex/hooks.json"')
        ->toContain('events = ["SessionStart", "Stop"]')
        ->toContain('"type": "command", "command": cmd')
        ->toContain('installed Codex CLI hooks -> $CODEX_HOOKS');
});

test('install.sh dedupes codex hooks per namespace via a fingerprint substring match', function () {
    $script = $this->get('/install')->getContent();

    expect($script)
        ->toContain('HOOK_FINGERPRINT="token_slayer/send-hook.sh"')
        ->toContain('fingerprint not in json.dumps(h)')
        ->toContain('if handlers:');
});

test('install.sh handles a missing or corrupt codex hooks.json without crashing the installer', function () {
    $script = $this->get('/install')->getContent();

    expect($script)
        ->toContain('[ -s "$CODEX_HOOKS" ] || echo \'{"hooks": {}}\' > "$CODEX_HOOKS"')
        ->toContain('except (ValueError, OSError):')
        ->toContain('.bak.%d');
});

test('install.sh reminds the user to trust codex hooks via the /hooks command', function () {
    $script = $this->get('/install')->getContent();

    expect($script)->toContain('Codex: run /hooks inside Codex once to trust the token-slayer hooks (required before they fire).');
});

test('install.sh saves TOKEN_SLAYER_TOKEN to the token file when present', function () {
    $script = $this->get('/install')->getContent();

    expect($script)
        ->toContain('${TOKEN_SLAYER_TOKEN:-}')
        ->toContain('printf \'%s\' "$TOKEN_SLAYER_TOKEN"')
        ->toContain('chmod 600 "$TOKEN_FILE"');
});

test('install.sh uses the configured hook namespace in paths, env var, and the codex hooks fingerprint', function () {
    config(['app.hook_namespace' => 'acme']);

    $script = $this->get('/install')->getContent();

    expect($script)
        ->toContain('~/.config/acme/token')
        ->toContain('${ACME_TOKEN:-}')
        ->toContain('HOOK_FINGERPRINT="acme/send-hook.sh"')
        ->not->toContain('token_slayer')
        ->not->toContain('TOKEN_SLAYER_TOKEN');
});

it('upserts hooks instead of replacing foreign entries', function () {
    $script = $this->get(route('install-script'))->content();

    expect($script)->toContain('send-hook.sh" not in json.dumps')   // fingerprint filter
        ->and($script)->not->toContain('data["hooks"][event] = [{');  // old clobbering assignment
});

it('ships account resolution and version stamping', function () {
    $script = $this->get(route('install-script'))->content();

    expect($script)->toContain('resolve_account')
        ->and($script)->toContain('account.json')
        ->and($script)->toContain('identity-cache.json')
        ->and($script)->toContain('/api/oauth/profile')
        ->and($script)->toContain('ANTHROPIC_BASE_URL')
        ->and($script)->toContain(config('token_slayer.client_version'));
});

it('installs the token-slayer CLI helper with update and status commands', function () {
    $script = $this->get(route('install-script'))->content();

    expect($script)->toContain('.local/bin/token-slayer')
        ->and($script)->toContain('already up to date');
});

it('sources the user custom.sh before sending', function () {
    $script = $this->get(route('install-script'))->content();

    expect($script)
        ->toContain('CUSTOM_SH="$HOME/.config/token_slayer/custom.sh"')
        ->toContain('[ -r "$CUSTOM_SH" ] && . "$CUSTOM_SH"');

    $customShPosition = strpos($script, 'CUSTOM_SH="$HOME/.config/token_slayer/custom.sh"');
    $sendPosition = strpos($script, 'curl -sf --max-time 3 -X POST "$URL"');

    expect($customShPosition)->toBeLessThan($sendPosition);
});

it('filters the payload to usage fields unconditionally, after custom.sh and before sending', function () {
    // The ordering is the load-bearing part: the filter runs AFTER custom.sh,
    // so a custom.sh following the guide's documented tool_input recipes still
    // sees the full body and only its resulting label leaves the machine.
    $script = $this->get(route('install-script'))->content();

    expect($script)
        ->toContain('FILTERED=$(printf \'%s\' "$BODY" | "$JQ" -c \'{')
        ->toContain('case "$FILTERED" in \'{\'*) BODY="$FILTERED" ;; esac');

    $customShPosition = strpos($script, '[ -r "$CUSTOM_SH" ] && . "$CUSTOM_SH"');
    $filterPosition = strpos($script, 'FILTERED=$(');
    $sendPosition = strpos($script, 'curl -sf --max-time 3 -X POST "$URL"');

    expect($customShPosition)->toBeLessThan($filterPosition);
    expect($filterPosition)->toBeLessThan($sendPosition);
});

it('keeps only usage and attribution fields in the payload allowlist', function () {
    $script = $this->get(route('install-script'))->content();

    foreach (['hook_event_name', 'session_id', 'tokens', 'models', 'tool_name', 'custom_activity', 'client_version', 'account_email', 'account_uuid', 'account_source', 'account_org_id'] as $kept) {
        expect($script)->toContain($kept);
    }
});

it('pipes the event body into curl over stdin instead of passing it as an argv argument', function () {
    // Windows Git Bash converts argv through the ANSI codepage before spawning
    // the native curl.exe: every non-ASCII byte of the payload is mangled, and
    // the server rejects the request as malformed UTF-8 (observed on prod).
    // Passing a multi-KB body as one argument also risks E2BIG. stdin is
    // neither converted nor length-capped.
    $script = $this->get(route('install-script'))->content();

    expect($script)
        ->toContain('printf \'%s\' "$BODY" | curl -sf --max-time 3 -X POST "$URL"')
        ->toContain('--data-binary @-')
        ->not->toContain('-d "$BODY"');
});

it('stores a sha256 checksum of send-hook.sh after writing it', function () {
    $script = $this->get(route('install-script'))->content();

    expect($script)
        ->toContain('CHECKSUM_FILE="$HOME/.config/token_slayer/.hook-checksum"')
        ->toContain('sha256 < "$HELPER" > "$CHECKSUM_FILE"');
});

it('compares the existing send-hook.sh against the stored checksum before overwriting', function () {
    $script = $this->get(route('install-script'))->content();

    expect($script)
        ->toContain('if [ -f "$HELPER" ]')
        ->toContain('OLD_SHA=$(sha256 < "$HELPER")')
        ->toContain('STORED_SHA=$(cat "$CHECKSUM_FILE")')
        ->toContain('[ -z "$STORED_SHA" ] || [ "$OLD_SHA" != "$STORED_SHA" ]');

    $compareBlockPosition = strpos($script, 'if [ -f "$HELPER" ]');
    $overwritePosition = strpos($script, "cat > \"\$HELPER.tmp\" <<'HOOK_SH'");

    expect($compareBlockPosition)->toBeLessThan($overwritePosition);
});

it('backs up a hand-modified send-hook.sh before overwriting it', function () {
    $script = $this->get(route('install-script'))->content();

    expect($script)
        ->toContain('HOOK_BACKUP="$HELPER.bak.$(date +%Y%m%d%H%M%S)"')
        ->toContain('cp "$HELPER" "$HOOK_BACKUP"');

    $backupPosition = strpos($script, 'HOOK_BACKUP="$HELPER.bak.$(date +%Y%m%d%H%M%S)"');
    $overwritePosition = strpos($script, "cat > \"\$HELPER.tmp\" <<'HOOK_SH'");

    expect($backupPosition)->toBeLessThan($overwritePosition);
});

it('warns loudly and points to custom.sh when a hand-modified hook was backed up', function () {
    $script = $this->get(route('install-script'))->content();

    expect($script)
        ->toContain('if [ -n "$HOOK_BACKUP" ]')
        ->toContain('WARNING')
        ->toContain('backup saved to: $HOOK_BACKUP')
        ->toContain('~/.config/token_slayer/custom.sh')
        ->toContain('survives every update');
});

it('reports custom.sh and hook modification status in the token-slayer CLI', function () {
    $script = $this->get(route('install-script'))->content();

    expect($script)
        ->toContain('custom.sh: active')
        ->toContain('custom.sh: none')
        ->toContain('send-hook.sh: stock')
        ->toContain('send-hook.sh: modified')
        ->toContain('send-hook.sh: unknown');
});

it('prunes old send-hook.sh backups to the newest 3', function () {
    $script = $this->get(route('install-script'))->content();

    expect($script)
        ->toContain('ls -1t "$HOME/.config/token_slayer"/send-hook.sh.bak.* 2>/dev/null | tail -n +4 | xargs rm -f --');
});

it('tries the account identity provider before skipping Claude-specific resolution for non-Claude providers', function () {
    $script = $this->get(route('install-script'))->content();

    $resolveDefinitionPosition = strpos($script, 'resolve_account() {');
    $providerCallPosition = strpos($script, 'provider_account && return');
    $guardPosition = strpos($script, '[ -n "${PROVIDER:-}" ] && return');

    expect($resolveDefinitionPosition)->not->toBeFalse()
        ->and($providerCallPosition)->not->toBeFalse()
        ->and($guardPosition)->not->toBeFalse()
        // both live inside resolve_account()
        ->and($providerCallPosition)->toBeGreaterThan($resolveDefinitionPosition)
        ->and($guardPosition)->toBeGreaterThan($resolveDefinitionPosition)
        // provider_account is tried FIRST -- this is the fix
        ->and($providerCallPosition)->toBeLessThan($guardPosition);
});

it('sends an org-id beacon request that costs zero tokens and never touches quota', function () {
    $script = $this->get(route('install-script'))->content();

    expect($script)
        ->toContain('"max_tokens":0')
        ->toContain('https://api.anthropic.com/v1/messages')
        ->toContain('claude-haiku-4-5-20251001');
});

it('parses the anthropic-organization-id response header from the beacon', function () {
    $script = $this->get(route('install-script'))->content();

    expect($script)
        ->toContain('anthropic-organization-id')
        ->toContain("grep -i '^anthropic-organization-id:'");
});

it('uses the x-api-key header for the beacon when only an API key is available', function () {
    $script = $this->get(route('install-script'))->content();

    expect($script)->toContain('ANTHROPIC_API_KEY')
        ->and($script)->toContain('x-api-key');
});

it('negatively caches identity lookups so repeat events skip the network', function () {
    $script = $this->get(route('install-script'))->content();

    expect($script)
        ->toContain('restricted')
        ->toContain('identity-cache.json')
        ->toContain('checked_at');
});

it('merges account_org_id into the outgoing event body when resolved', function () {
    $script = $this->get(route('install-script'))->content();

    expect($script)->toContain('account_org_id');

    $bodyAssignPosition = strpos($script, 'BODY=$(printf \'%s\' "$BODY" | "$JQ" -c --arg e "$ACC_EMAIL"');
    expect($bodyAssignPosition)->not->toBeFalse();

    $mergeBlock = substr($script, $bodyAssignPosition, 700);
    expect($mergeBlock)->toContain('account_org_id');
});

it('stamps the resolver-derived client version (semver) into the script, UA, and version file', function () {
    config(['github.token' => 'ghp_test', 'github.cli_repo' => 'acme/slayer-cli']);
    Http::fake(['api.github.com/*' => Http::response([
        'tag_name' => 'v1.2.3',
        'assets' => [['id' => 1, 'name' => 'slayer_cli-latest.whl']],
    ], 200)]);

    $script = $this->get(route('install-script'))->content();

    expect($script)->toContain("CLIENT_VERSION='1.2.3'")
        ->and($script)->toContain('token-slayer-hook/1.2.3')
        ->and($script)->toContain("LATEST='1.2.3'");
});

it('stamps an empty client version when the release cannot be resolved (fail-soft render)', function () {
    Http::fake(['api.github.com/*' => Http::response(['message' => 'down'], 500)]);

    $script = $this->get(route('install-script'))->content();

    // The script must still render and run — an empty stamp beats a stale wrong one.
    expect($script)->toContain("CLIENT_VERSION=''");
});

it('tips users toward custom.sh to customize what their fighter shows, at the end of a successful install', function () {
    $script = $this->get(route('install-script'))->content();

    expect($script)
        ->toContain('~/.config/token_slayer/custom.sh')
        ->toContain('customize what your fighter shows')
        ->toContain('survives every update');

    $tipPosition = strpos($script, 'customize what your fighter shows');
    $lastHookInstallPosition = strpos($script, 'installed Antigravity CLI hooks');

    expect($tipPosition)->not->toBeFalse()
        ->and($lastHookInstallPosition)->not->toBeFalse()
        ->and($tipPosition)->toBeGreaterThan($lastHookInstallPosition);
});

it('sends an explicit User-Agent on every Anthropic curl call', function () {
    $script = $this->get('/install')->getContent();

    expect($script)->toContain("HOOK_UA='token-slayer-hook/");
    // Both the beacon and the profile lookup must carry it.
    expect(substr_count($script, '-A "$HOOK_UA"'))->toBeGreaterThanOrEqual(2);
});

it('retries a transient beacon error after a short self-heal window, not an hour', function () {
    $script = $this->get('/install')->getContent();

    expect($script)->toContain('BEACON_ERROR_RETRY_SECS=300');
    expect($script)->toContain('-le "$BEACON_ERROR_RETRY_SECS"');
    expect($script)->not->toContain('-le 3600');
});

it('forces bash for Claude Code hooks so Windows uses Git Bash deterministically', function () {
    $script = $this->get('/install')->getContent();

    // The Python merge appends the command dict with an explicit shell.
    expect($script)->toContain('"type": "command", "command": cmd, "shell": "bash"');
});

it('consults an account identity provider before the beacon, by-session then active', function () {
    $script = $this->get('/install')->getContent();

    expect($script)->toContain('provider_account()');
    expect($script)->toContain('CLAUDE_ACCOUNT_PROVIDER');
    expect($script)->toContain('account-provider/sessions/$SESSION_ID.json');
    expect($script)->toContain('account-provider/active.json');
    expect($script)->toContain('ACC_SOURCE="provider"');

    // provider runs before the credential beacon
    expect(strpos($script, 'provider_account && return'))
        ->toBeLessThan(strpos($script, 'OAUTH_TOKEN=$(current_access_token)'));

    // session id is extracted from the payload before resolve_account runs
    expect($script)->toContain('.session_id // .sessionId // ""');
    expect(strpos($script, 'SESSION_ID=$(printf'))
        ->toBeLessThan(strpos($script, "\n  resolve_account\n"));
});

it('reads the provider-scoped active file, generic account_id/user_id fields, before the legacy path', function () {
    $script = $this->get(route('install-script'))->content();

    expect($script)->toContain('account-provider/active-${PROVIDER:-claude}.json')
        ->and($script)->toContain('.account_id // .org_uuid // ""')
        ->and($script)->toContain('.user_id // .uuid // ""');
});

it('only falls back to the legacy shared active.json when PROVIDER is unset', function () {
    $script = $this->get(route('install-script'))->content();

    // the legacy fallback branch is gated on an empty PROVIDER, not
    // unconditionally reachable -- this pins the guard so a future edit
    // can't accidentally let a Codex-labeled event read Claude's file.
    expect($script)->toContain('[ -z "${PROVIDER:-}" ]')
        ->and($script)->toContain('account-provider/active.json');
});

it('still exposes ACC_ORG_ID and ACC_UUID for downstream attribution after the field rename', function () {
    $script = $this->get(route('install-script'))->content();

    // downstream code (the POST body builder) reads these two variable
    // names -- the rename only touches the JSON field names being read
    // INTO them, not the shell variable names themselves.
    expect($script)->toContain('ACC_ORG_ID="$_org"')
        ->and($script)->toContain('ACC_SOURCE="provider"');
});

it('bundles a detector-config and scans a proxy log by session id before giving up', function () {
    $script = $this->get('/install')->getContent();

    // Bundled config is written by the installer.
    expect($script)->toContain('detector-config.json');
    expect($script)->toContain('"teamclaude"');
    expect($script)->toContain('"join": "session"');

    // Generic scanner exists and runs inside the proxy branch, before NULL.
    expect($script)->toContain('detector_scan()');
    expect($script)->toContain('ACC_SOURCE="detector"');
    expect(strpos($script, 'detector_scan && return'))
        ->toBeLessThan(strpos($script, 'ACC_SOURCE="proxy"'));
});

it('attributes a ts_tokens window only when exactly one account served it', function () {
    $script = $this->get('/install')->getContent();

    expect($script)->toContain('DETECTOR_WINDOW_SECS=120');
    // Distinct-account gate: 1 -> attribute, else NULL (the SAFE rule).
    expect($script)->toContain('unique');
    expect($script)->toContain('if length == 1');
    // ts_tokens arm resolves to the detector source, not a guess.
    expect(strpos($script, 'DETECTOR_WINDOW_SECS'))
        ->toBeGreaterThan(strpos($script, 'detector_scan()'));
});

it('reserves the exclude-check hook point between attribution and the POST', function () {
    $script = $this->get('/install')->getContent();

    expect($script)->toContain('exclude-check hook point (Phase 3)');

    $marker = strpos($script, 'exclude-check hook point (Phase 3)');
    expect($marker)->toBeGreaterThan(strpos($script, 'resolve_account'));
    expect($marker)->toBeLessThan(strpos($script, 'curl -sf --max-time 3 -X POST'));
});

it('sets up a python venv and installs slayer-cli, with a shim that execs the venv module', function () {
    $script = $this->get(route('install-script'))->content();

    expect($script)
        ->toContain('-m venv')
        ->toContain('/venv/bin/pip')
        ->toContain('-m slayer_cli')
        ->toContain('exec env SLAYER_NS')
        ->toContain('SLAYER_NS=token_slayer')
        ->toContain('SLAYER_INSTALL_URL=');
});

it('registers the current Claude login as a base account slot after installing the CLI', function () {
    $script = $this->get(route('install-script'))->content();

    // Best-effort, namespaced, never blocks the install.
    expect($script)
        ->toContain('-m slayer_cli detect-base')
        ->toContain('SLAYER_NS=token_slayer');

    // It must run AFTER the shim is written (needs the venv/package present).
    $shimPos = strpos($script, 'chmod +x "$HOME/.local/bin/token-slayer"');
    $detectPos = strpos($script, '-m slayer_cli detect-base');
    expect($shimPos)->not->toBeFalse()
        ->and($detectPos)->not->toBeFalse()
        ->and($shimPos)->toBeLessThan($detectPos);
});

it('registers an always-on Stop hook that warms the local usage cache, independent of auto-switch', function () {
    $script = $this->get(route('install-script'))->content();

    // Invokes the venv directly with an explicit namespace, like detect-base
    // -- NOT the shared `$HOME/.local/bin/token-slayer` shim, whose baked-in
    // namespace is whichever install ran last (would silently refresh the
    // wrong namespace on a machine with more than one installed).
    expect($script)
        ->toContain('-m slayer_cli hook usage-refresh')
        ->toContain('SLAYER_NS=token_slayer "$HOME/.config/token_slayer/venv/bin/python"')
        ->toContain('HOOK_FINGERPRINT="token_slayer/venv/bin/python"')
        ->toContain('events = ["Stop"]');

    // Appended alongside send-hook.sh's own Stop entry, never replacing it.
    expect($script)->not->toContain('data["hooks"][event] = [{');

    // The dedup filter is a plain substring match (`fingerprint not in
    // json.dumps(e)`) -- a fingerprint that isn't literally contained in
    // the command it's meant to identify silently fails to replace a stale
    // entry on re-install, leaving duplicates forever.
    $fingerprintPos = strpos($script, 'HOOK_FINGERPRINT="token_slayer/venv/bin/python"');
    $fingerprint = 'token_slayer/venv/bin/python';
    expect($fingerprintPos)->not->toBeFalse()
        ->and(str_contains($script, 'SLAYER_NS=token_slayer "$HOME/.config/'.$fingerprint.'"'))->toBeTrue();

    // Must be registered AFTER the shim exists (this section runs after it).
    $shimPos = strpos($script, 'chmod +x "$HOME/.local/bin/token-slayer"');
    $refreshPos = strpos($script, 'hook usage-refresh');
    expect($shimPos)->not->toBeFalse()
        ->and($refreshPos)->not->toBeFalse()
        ->and($shimPos)->toBeLessThan($refreshPos);
});

it('registers an always-on SessionStart hook that tracks the session for the Sessions TUI page, independent of auto-switch', function () {
    $script = $this->get(route('install-script'))->content();

    // Same shape as the usage-refresh Stop hook above: invokes the venv
    // directly with an explicit namespace (never the shared shim), so a
    // machine with more than one namespace installed never tracks the
    // wrong one.
    expect($script)
        ->toContain('-m slayer_cli hook session-track-start')
        ->toContain('SLAYER_NS=token_slayer')
        ->toContain('HOOK_FINGERPRINT="hook session-track-start"')
        ->toContain('events = ["SessionStart"]');

    // Appended alongside send-hook.sh's own SessionStart entry, never
    // replacing it -- must not be a full-replace assignment.
    expect($script)->not->toContain('data["hooks"][event] = [{');

    // The dedup filter is a plain substring match against `json.dumps(e)`
    // of the WHOLE settings entry -- the fingerprint must be a literal,
    // contiguous substring of the actual command text, or re-install
    // silently leaves duplicates forever instead of replacing the stale
    // entry (caught live: an earlier fingerprint here concatenated the
    // namespace with "/hook session-track-start", which is never actually
    // adjacent in the real command and re-running the merge twice produced
    // 2 entries instead of 1 -- verified by extracting this exact snippet
    // and executing it against fixture settings.json files).
    $cmdPos = strpos($script, 'SESSION_TRACK_CMD="SLAYER_NS=token_slayer');
    $fingerprintDeclPos = strpos($script, 'HOOK_FINGERPRINT="hook session-track-start"');
    expect($cmdPos)->not->toBeFalse()->and($fingerprintDeclPos)->not->toBeFalse();
    $cmdLine = substr($script, $cmdPos, $fingerprintDeclPos - $cmdPos);
    expect($cmdLine)->toContain('hook session-track-start');

    // Must be registered AFTER the shim exists (this section runs after it).
    $shimPos = strpos($script, 'chmod +x "$HOME/.local/bin/token-slayer"');
    $trackPos = strpos($script, 'hook session-track-start');
    expect($shimPos)->not->toBeFalse()
        ->and($trackPos)->not->toBeFalse()
        ->and($shimPos)->toBeLessThan($trackPos);
});

it('downloads the wheel to a PEP 427-valid temp name before pip-installing (pip rejects slayer_cli-latest.whl)', function () {
    $script = $this->get(route('install-script'))->content();

    // pip refuses `pip install <url ending in slayer_cli-latest.whl>` because
    // `latest` is not a valid version segment; the script must download first
    // to a spec-valid filename, then install that local file.
    expect($script)
        ->toContain('slayer_cli-0.0.0-py3-none-any.whl')
        ->toContain('install --quiet "$SLAYER_WHL"');

    // The served wheel version may be unchanged between builds, so a plain
    // --upgrade would ship stale code; the package code is force-reinstalled.
    expect($script)->toContain('install --quiet --force-reinstall --no-deps "$SLAYER_WHL"');

    // It must NOT pip-install straight from the wheel URL/route anymore.
    expect($script)->not->toContain('pip" install --quiet --upgrade "'.route('slayer-wheel').'"');
});

it('does not pass --break-system-packages as a pip CLI flag to the wheel install (older pip lacks the option entirely)', function () {
    $script = $this->get(route('install-script'))->content();

    // pip < 23.0.1 has no --break-system-packages option at all and hard-fails
    // with "no such option" if it's passed on the command line -- unlike an
    // unrecognized env var, which old pip simply ignores. The exported
    // PIP_BREAK_SYSTEM_PACKAGES=1 env var already covers the PEP 668 bypass
    // for pip versions that understand it, so the wheel install commands must
    // rely on that alone, not repeat the flag explicitly.
    expect($script)->toContain('export PIP_BREAK_SYSTEM_PACKAGES=1');

    $startPos = strpos($script, 'SLAYER_WHL_DIR="$(mktemp');
    $endPos = strpos($script, 'rm -f "$SLAYER_WHL"');
    expect($startPos)->not->toBeFalse()->and($endPos)->not->toBeFalse();
    $installBlock = substr($script, $startPos, $endPos - $startPos);
    expect($installBlock)->not->toContain('--break-system-packages');
});

it('does not let a malformed existing settings.json abort the whole installer', function () {
    $script = $this->get(route('install-script'))->content();

    // The settings.json merge runs under `set -e`; a bare json.load() on a
    // corrupt file would crash the entire install. It must catch that, back the
    // bad file up, and continue.
    expect($script)
        ->toContain('except (ValueError, OSError):')
        ->toContain('.corrupt-bak')
        ->toContain('was invalid JSON');
});

it('stops the install immediately if the venv or wheel bootstrap fails, surfacing the real error', function () {
    $script = $this->get(route('install-script'))->content();

    expect($script)
        ->toContain('already up to date')          // token-slayer CLI's own update/status subcommand, unrelated to install-time failure handling
        ->toContain('usage: token-slayer {update|status}')
        ->not->toContain('hook tracking is still installed')
        ->not->toContain('CLI unavailable');

    // Every venv/get-pip/wheel failure branch now exits instead of just logging.
    foreach ([
        "'python -m venv --without-pip' failed",
        'could not download get-pip.py',
        'get-pip bootstrap failed',
        'wheel install failed',
        'your token is missing or no longer valid',
    ] as $failureMessage) {
        $msgPos = strpos($script, $failureMessage);
        expect($msgPos)->not->toBeFalse("expected to find failure message: $failureMessage");
        $exitPos = strpos($script, 'exit 1', $msgPos);
        expect($exitPos)->not->toBeFalse()
            ->and($exitPos - $msgPos)->toBeLessThan(160);
    }
});

it('captures and prints the real curl/pip error instead of discarding it on failure', function () {
    $script = $this->get(route('install-script'))->content();

    expect($script)
        ->toContain('2>"$SLAYER_CURL_ERR"')
        ->toContain('cat "$SLAYER_CURL_ERR" >&2')
        ->toContain('SLAYER_PIP_ERR=$("$SLAYER_PIP" install')
        ->toContain('printf \'%s\n\' "$SLAYER_PIP_ERR" >&2');
});

it('checks curl\'s own exit status for the wheel download instead of pattern-matching a printed "000"', function () {
    // `curl -w '%{http_code}' ... || echo "000"` is broken: curl writes SOMETHING
    // to stdout via -w even on a hard transport failure (DNS/TLS/timeout), so the
    // clean string "000" the old `elif` branch looked for never actually appears
    // -- the real curl stderr was captured but never printed. Checking curl's own
    // exit status directly (`if !`) is the only way to reliably detect that case.
    $script = $this->get(route('install-script'))->content();

    expect($script)
        ->toContain('if ! SLAYER_HTTP=$(curl -sSL -w \'%{http_code}\'')
        ->not->toContain('|| echo "000"')
        ->toContain('could not reach the wheel download URL (see error above)');

    // The failure branch that prints the captured curl stderr must be the `if !`
    // guard around the curl call itself, not a separate "000" status check.
    $ifNotPos = strpos($script, 'if ! SLAYER_HTTP=$(curl -sSL');
    $catErrPos = strpos($script, 'cat "$SLAYER_CURL_ERR" >&2');
    expect($ifNotPos)->not->toBeFalse()
        ->and($catErrPos)->not->toBeFalse()
        ->and($catErrPos)->toBeGreaterThan($ifNotPos)
        ->and($catErrPos - $ifNotPos)->toBeLessThan(300);

    // The http-status dispatch is a plain if/elif/else over 200/401/else --
    // no more explicit "000" branch (that case is now handled above, by `if !`).
    expect($script)
        ->toContain('if [ "$SLAYER_HTTP" = "200" ]; then')
        ->toContain('elif [ "$SLAYER_HTTP" = "401" ]; then')
        ->not->toContain('elif [ "$SLAYER_HTTP" = "000" ]; then')
        ->not->toContain('$SLAYER_HTTP" = "000"');
});

it('symlinks slayer to the token-slayer shim', function () {
    $script = $this->get(route('install-script'))->content();

    expect($script)->toContain('ln -sf "$HOME/.local/bin/token-slayer" "$HOME/.local/bin/slayer"');
});

// The slayer-cli wheel route no longer redirects to a public asset URL; it
// relays the wheel behind hook.token. That behavior is covered end-to-end in
// tests/Feature/SlayerWheelTest.php.

it('bootstraps a pinned jq binary instead of relying on the system jq', function () {
    $script = $this->get(route('install-script'))->content();

    expect($script)
        ->toContain('JQ_VERSION="1.8.2"')
        ->toContain('JQ_DIR="$HOME/.config/token_slayer/bin"')
        ->toContain('JQ_BIN="$JQ_DIR/jq"')
        ->toContain('https://github.com/jqlang/jq/releases/download/jq-$JQ_VERSION/$JQ_ASSET')
        ->toContain('jq-linux-amd64 b1c22172dd303f3be49e935aa56aa48a8b7a46e0bc838b4997d3bb451495870f')
        ->toContain('jq-linux-arm64 8b85c817833814ddca00a144c33705546355afccf0cf39b188f3cdb48b852309')
        ->toContain('jq-macos-amd64 e94b266e3c26690550006abe63152b782280f4e14374accdf04cbde844f00bc0')
        ->toContain('jq-macos-arm64 2d75340ba57a4b4b4c8708a21c2dc8e958a48aaa8bba13b27f77f6e4c0eca07e');

    // Must run before the HOOK_SH heredoc is written, so a jq failure stops
    // the install before any jq-dependent file is even created.
    $jqBootstrapPos = strpos($script, 'JQ_VERSION="1.8.2"');
    $hookWritePos = strpos($script, "cat > \"\$HELPER.tmp\" <<'HOOK_SH'");
    expect($jqBootstrapPos)->not->toBeFalse()
        ->and($hookWritePos)->not->toBeFalse()
        ->and($jqBootstrapPos)->toBeLessThan($hookWritePos);
});

it('exits with a clear error when no pinned jq build exists for this platform/arch', function () {
    $script = $this->get(route('install-script'))->content();

    expect($script)->toContain('no pinned jq build for');

    $noBuildPos = strpos($script, 'no pinned jq build for');
    $exitPos = strpos($script, 'exit 1', $noBuildPos);
    expect($exitPos)->not->toBeFalse()
        ->and($exitPos - $noBuildPos)->toBeLessThan(200);
});

it('verifies the downloaded jq checksum before trusting it, and self-heals a mismatched existing binary', function () {
    $script = $this->get(route('install-script'))->content();

    expect($script)
        ->toContain('CURRENT_SHA=$(sha256 < "$JQ_BIN")')
        ->toContain('DOWNLOADED_SHA=$(sha256 < "$JQ_TMP")')
        ->toContain('checksum mismatch')
        ->toContain('chmod +x "$JQ_BIN"');
});

it('never falls back to a system jq inside the hook -- every jq call resolves to the bundled binary', function () {
    $script = $this->get(route('install-script'))->content();

    expect($script)
        ->not->toContain('command -v jq')
        ->toContain('JQ="$HOME/.config/token_slayer/bin/jq"');

    // The resolver is declared before BODY is read and before the first jq
    // call site (transcript token enrichment).
    $resolverPos = strpos($script, 'JQ="$HOME/.config/token_slayer/bin/jq"');
    $firstJqCallPos = strpos($script, '"$JQ" -r \'.transcript_path');
    expect($resolverPos)->not->toBeFalse()
        ->and($firstJqCallPos)->not->toBeFalse()
        ->and($resolverPos)->toBeLessThan($firstJqCallPos);
});

it('uses the resolved $JQ variable in every call site inside the hook, including account resolution', function () {
    $script = $this->get(route('install-script'))->content();

    // Spot-check a representative call from each function that used to guard
    // on `command -v jq` -- resolve_account, detector_scan, provider_account,
    // and the final body-merge/minimal-payload filters.
    expect($script)
        ->toContain('"$JQ" -r \'.claudeAiOauth.accessToken')
        ->toContain('"$JQ" -r \'.account_id // .org_uuid')
        ->toContain('"$JQ" -r \'.email // ""\' "$NS_DIR/account.json"')
        ->toContain('"$JQ" -r \'keys[]\'')
        ->toContain('[ -x "$JQ" ]; then')
        ->toContain('"$JQ" -c \'{');
});

it('guards jq calls with -x (executable check), not the always-true -n', function () {
    // $JQ is always assigned a literal path string, so `[ -n "$JQ" ]` (non-empty
    // string) is always true regardless of whether that file actually exists or
    // is executable -- it provided none of the "defensive against a manually
    // deleted binary" protection it was meant to. `-x` actually tests existence
    // + executability. Three guards: transcript-enrichment, post-resolve_account
    // body-merge, and the now-unconditional payload filter.
    $script = $this->get(route('install-script'))->content();

    expect($script)
        ->not->toContain('[ -n "$JQ" ]')
        ->toContain('[ -x "$JQ" ]; then');

    expect(substr_count($script, '-x "$JQ"'))->toBe(3);
});

it('a Codex-provider event can resolve identity via a provider-scoped active file, not just Claude events', function () {
    $script = $this->get(route('install-script'))->content();

    // The full call chain, in order, that makes this true: resolve_account
    // tries provider_account first (Task 1); provider_account's file lookup
    // is provider-scoped so it finds a Codex-written file even though
    // PROVIDER=codex is set (Task 2) -- the OLD code's guard would have
    // returned empty identity before ever reaching this lookup.
    $resolveDefinitionPosition = strpos($script, 'resolve_account() {');
    $providerCallPosition = strpos($script, 'provider_account && return');
    $providerScopedLookup = strpos($script, 'account-provider/active-${PROVIDER:-claude}.json');

    expect($providerCallPosition)->toBeGreaterThan($resolveDefinitionPosition)
        ->and($providerScopedLookup)->not->toBeFalse()
        ->and($script)->toContain('CODEX_CMD="PROVIDER=codex bash $HELPER"');
});

test('both installers accumulate a per-model token breakdown', function (string $url) {
    $script = $this->get($url)->assertOk()->getContent();

    expect($script)->toContain('.m[$k] += $tok')
        ->and($script)->toContain('$e.message.model // $e.model');
})->with([
    'sh' => ['/install'],
    'ps1' => ['/install.ps1'],
]);

test('both installers merge the usage object without nesting tokens', function (string $url) {
    $script = $this->get($url)->assertOk()->getContent();

    // The jq now returns an object. Left with the old scalar merge, the body
    // would become {"tokens":{"tokens":478,"models":{...}}}, and the server's
    // (int) cast on an array yields 1 with no warning -- every claude-code Stop
    // would deal 1 token of damage into the append-only ledger.
    expect($script)->not->toContain('{tokens:$t}')
        ->and($script)->toContain('--argjson u "$USAGE"');
})->with([
    'sh' => ['/install'],
    'ps1' => ['/install.ps1'],
]);

test('both installers retry the transcript read once instead of trusting a zero-token first read', function (string $url) {
    $script = $this->get($url)->assertOk()->getContent();

    // Claude Code fires Stop before the final assistant message is
    // guaranteed flushed to disk. A first read of tokens=0 must not be
    // trusted outright -- it must be re-attempted a bounded number of times
    // before the body is sent.
    expect($script)->toContain('extract_usage')
        ->and($script)->toContain('sleep 0.3');
})->with([
    'sh' => ['/install'],
    'ps1' => ['/install.ps1'],
]);

test('both installers only accept a retried read once two consecutive reads agree', function (string $url) {
    $script = $this->get($url)->assertOk()->getContent();

    // Two consecutive identical non-zero reads mean nothing was appended to
    // the transcript between them -- the write has settled. Comparing the
    // full USAGE string (not just the token count) means a boundary shift
    // into a different turn's models is caught too, since it would change
    // the models object even if the token count coincided.
    expect($script)->toContain('"$USAGE" = "$PREV"');
})->with([
    'sh' => ['/install'],
    'ps1' => ['/install.ps1'],
]);

test('both installers do not retry when the first transcript read already sees tokens', function (string $url) {
    $script = $this->get($url)->assertOk()->getContent();

    // A first read that already sees tokens>0 must be trusted immediately --
    // no added latency for the case that already works today. Only a
    // zero-token first read enters the retry branch.
    expect($script)->toContain('if [ "${TOK:-0}" = "0" ]; then');
})->with([
    'sh' => ['/install'],
    'ps1' => ['/install.ps1'],
]);

test('both installers cap the transcript re-read retry so a stuck flush cannot hang the hook', function (string $url) {
    $script = $this->get($url)->assertOk()->getContent();

    expect($script)->toContain('"$ATTEMPT" -lt 5');
})->with([
    'sh' => ['/install'],
    'ps1' => ['/install.ps1'],
]);

test('both installers dedupe a single API message split across multiple content-block rows before summing tokens', function (string $url) {
    $script = $this->get($url)->assertOk()->getContent();

    // Claude Code writes one JSONL row per content-block type (thinking,
    // tool_use, text) for a single API message, and every row repeats that
    // message's FULL output_tokens -- verified live via message.id: two
    // rows sharing the same id are the same underlying call. Summing every
    // row without deduping double-counts (or worse) any turn that used
    // extended thinking, which is common.
    expect($script)->toContain('$mid')
        ->and($script)->toContain('.seen[$mid]');
})->with([
    'sh' => ['/install'],
    'ps1' => ['/install.ps1'],
]);

test('both installers dispatch a Codex-shaped walk for codex', function (string $url) {
    $script = $this->get($url)->assertOk()->getContent();

    expect($script)->toContain('token_count')
        ->and($script)->toContain('last_token_usage.output_tokens')
        ->and($script)->toContain('turn_context')
        ->and($script)->toContain('task_started');
})->with([
    'sh' => ['/install'],
    'ps1' => ['/install.ps1'],
]);

test('the Codex walk never reads cumulative or total token fields', function (string $url) {
    $script = $this->get($url)->assertOk()->getContent();

    // total_token_usage is cumulative for the whole session, so a turn would
    // deal the session total. total_tokens includes input plus cached input,
    // which for Codex is ~30x output. reasoning_output_tokens is a SUBSET of
    // output_tokens (verified: input 13463 + output 715 = total 14178, with
    // reasoning 503 inside the 715), so adding it double-counts.
    expect($script)->not->toContain('total_token_usage.output_tokens')
        ->and($script)->not->toContain('last_token_usage.total_tokens')
        ->and($script)->not->toContain('reasoning_output_tokens');
})->with([
    'sh' => ['/install'],
    'ps1' => ['/install.ps1'],
]);

test('the rendered hook helper is syntactically valid shell', function (string $url, string $pattern) {
    // A misplaced `fi` in the provider-dispatched extractor would break the
    // hook for everyone with no error anywhere -- the hook is fire-and-forget
    // and its output is discarded, so the only symptom is missing events.
    // String assertions cannot catch that; parsing it can.
    $script = $this->get($url)->assertOk()->getContent();

    expect(preg_match($pattern, $script, $matches))->toBe(1);

    $path = tempnam(sys_get_temp_dir(), 'hook-syntax-');
    file_put_contents($path, $matches[1]);
    exec('sh -n '.escapeshellarg($path).' 2>&1', $output, $exitCode);
    @unlink($path);

    expect($exitCode)->toBe(0, implode("\n", $output));
})->with([
    'sh' => ['/install', "/cat > \"\\\$HELPER\\.tmp\" <<'HOOK_SH'\n(.*?)\nHOOK_SH/s"],
    'ps1' => ['/install.ps1', "/\\\$hookShTemplate = @'\n(.*?)\n'@/s"],
]);

test('the payload filter is unconditional, not opt-in', function (string $url) {
    $script = $this->get($url)->assertOk()->getContent();

    expect($script)->not->toContain('SLAYER_MINIMAL_PAYLOAD');
})->with([
    'sh' => ['/install'],
    'ps1' => ['/install.ps1'],
]);

test('both installers whitelist exactly the eleven allowed fields', function (string $url) {
    $script = $this->get($url)->assertOk()->getContent();

    // Assert against the whitelist BLOCK, not the whole script: several of
    // these names also appear in the account-enrichment merge, so a
    // whole-script toContain would pass even with the block missing entirely.
    // That matters most for ps1, which has no such block today and must gain
    // one -- otherwise Windows clients ship unfiltered with a green suite.
    $start = strpos($script, 'FILTERED=$(');
    expect($start)->not->toBeFalse();
    $block = substr($script, $start, 400);

    foreach ([
        'hook_event_name', 'session_id', 'tokens', 'models', 'tool_name',
        'custom_activity', 'client_version', 'account_email', 'account_uuid',
        'account_source', 'account_org_id',
    ] as $field) {
        expect($block)->toContain($field);
    }
})->with([
    'sh' => ['/install'],
    'ps1' => ['/install.ps1'],
]);

test('only the four handled hook events are registered', function (string $url) {
    // EventController handles session-start, user-prompt-submit/pre-invocation,
    // pre-tool-use and stop. PostToolUse, SubagentStop, SessionEnd and
    // Notification fell through to a bare 201 -- and PostToolUse is both the
    // highest-frequency event and the one carrying tool_response, so not
    // registering it stops that content at the source rather than filtering it.
    $script = $this->get($url)->assertOk()->getContent();

    // Assert on the registration list itself. A bare not->toContain of a name
    // would also match the line that REMOVES a stale registration.
    expect($script)->toContain('events = ["SessionStart", "UserPromptSubmit", "PreToolUse", "Stop"]')
        ->and($script)->not->toContain('"SessionStart", "UserPromptSubmit", "PreToolUse", "PostToolUse"')
        ->and($script)->not->toContain('"Stop", "SubagentStop", "SessionEnd", "Notification"')
        ->and($script)->not->toContain('for event in ["PreToolUse", "PostToolUse"]:');
})->with(['sh' => ['/install'], 'ps1' => ['/install.ps1']]);

test('stale registrations are stripped from every event, not just the kept ones', function (string $url) {
    // The loop only ever cleaned events still in its own list. Shrinking the
    // list would therefore leave the old PostToolUse entry in settings.json,
    // still firing the old hook -- the change would appear to work while doing
    // nothing, with no error anywhere.
    $script = $this->get($url)->assertOk()->getContent();

    expect($script)->toContain('for event in list(data["hooks"].keys()):');
})->with(['sh' => ['/install'], 'ps1' => ['/install.ps1']]);

test('the Antigravity registration drops PostToolUse too', function (string $url) {
    // Otherwise Antigravity clients keep firing the hook on PostToolUse and
    // keep shipping tool_response, making the payload claim false for them.
    $script = $this->get($url)->assertOk()->getContent();

    expect($script)->toContain('ns_data.pop("PostToolUse", None)')
        ->and($script)->toContain('for event in ["PreToolUse"]:');
})->with(['sh' => ['/install'], 'ps1' => ['/install.ps1']]);

test('the POSIX install script stamps the repo-owned hook version', function () {
    config(['token_slayer.hook_version' => '7']);

    // NOT assertSee('7'): the rendered script already contains that digit in
    // the pinned jq sha256 lines, so such a test would be green before any code
    // is written and would prove nothing about the route wiring.
    $script = $this->get('/install')->assertOk()->getContent();

    expect($script)->toContain("HOOK_VERSION='7'")
        ->and($script)->toContain('/hook-version');
});

test('the Windows install script stamps the hook version through its placeholder chain', function () {
    // The ps1 hook lives in a single-quoted PowerShell here-string, so nothing
    // interpolates: the value reaches it through .Replace() at install time.
    // Asserting the rendered literal would be wrong here, and forgetting the
    // Replace link would ship Windows hooks reporting "__TS_HOOK_VERSION__".
    config(['token_slayer.hook_version' => '7']);

    $script = $this->get('/install.ps1')->assertOk()->getContent();

    expect($script)->toContain("\$HookVersion = '7'")
        ->and($script)->toContain(".Replace('__TS_HOOK_VERSION__', \$HookVersion)")
        ->and($script)->toContain("HOOK_VERSION='__TS_HOOK_VERSION__'")
        ->and($script)->toContain("'hook-version'");
});

test('the hook reports its own version alongside the CLI version', function (string $url) {
    // A dedicated field rather than an encoding inside client_version: the
    // payload filter exists to keep session CONTENT off the wire, and a version
    // number is not content. Parsing a composite string would also risk
    // rendering "1.0.4+hook5" wherever client_version is displayed.
    $script = $this->get($url)->assertOk()->getContent();

    $start = strpos($script, 'FILTERED=$(');
    expect(substr($script, $start, 420))->toContain('hook_version');
})->with(['sh' => ['/install'], 'ps1' => ['/install.ps1']]);

test('the POSIX installer writes the hook through a temp file and a rename', function () {
    // cat > truncates in place. bash reads a script lazily by byte offset, so a
    // Stop hook executing inside that window reads past the end of a truncated
    // file and the turn's event is lost -- silently, because the hook is
    // fire-and-forget with its output discarded.
    $script = $this->get('/install')->assertOk()->getContent();

    expect($script)->toContain('cat > "$HELPER.tmp" <<\'HOOK_SH\'')
        ->and($script)->toContain('mv -f "$HELPER.tmp" "$HELPER"')
        ->and($script)->not->toContain('cat > "$HELPER" <<\'HOOK_SH\'');
});

test('the PowerShell installer replaces its hook atomically', function () {
    // The ps1 has no cat > and no $HELPER: it writes via WriteAllText, so the
    // POSIX assertions above would be vacuous on one half and unsatisfiable on
    // the other.
    $script = $this->get('/install.ps1')->assertOk()->getContent();

    expect($script)->toContain('WriteAllText("$Helper.tmp"')
        ->and($script)->toContain('Move-Item -Force "$Helper.tmp" $Helper');
});

test('both installers replace merged JSON config atomically', function (string $url) {
    // settings.json / hooks.json are written by embedded Python, not by cat --
    // and they are the files Claude Code itself reads while a session is live.
    $script = $this->get($url)->assertOk()->getContent();

    expect($script)->toContain('os.replace(tmp, path)')
        ->and($script)->not->toContain('with open(path, "w") as f:');
})->with(['sh' => ['/install'], 'ps1' => ['/install.ps1']]);

test('the hook stores the update signal from the response it already receives', function (string $url) {
    // The POST was fire-and-forget into /dev/null; capturing the body it
    // already gets is what removes the need for a second endpoint entirely.
    $script = $this->get($url)->assertOk()->getContent();

    expect($script)->toContain('update-state')
        ->and($script)->toContain('curl -sf --max-time 3');
})->with(['sh' => ['/install'], 'ps1' => ['/install.ps1']]);

test('a failed request never truncates the stored update signal', function (string $url) {
    // A plain redirect into update-state opens and truncates it the instant
    // the subshell starts -- before curl has even connected. A DNS failure or
    // a 3s timeout would then leave an empty file gating unattended execution.
    $script = $this->get($url)->assertOk()->getContent();

    expect($script)->toContain('.update-state.$$.tmp')
        ->and($script)->toContain('mv -f "$NS_DIR/.update-state.$$.tmp"');
})->with(['sh' => ['/install'], 'ps1' => ['/install.ps1']]);

test('the hook delegates updating to the CLI rather than reimplementing it', function (string $url) {
    $script = $this->get($url)->assertOk()->getContent();

    expect($script)->toContain('update --if-newer')
        ->and($script)->toContain('SLAYER_NO_AUTO_UPDATE')
        // The Windows installer writes .cmd shims and the hook runs under Git
        // Bash there, so checking only the extension-less name would make
        // auto-update silently never fire on Windows.
        ->and($script)->toContain('token-slayer.cmd')
        // Locking lives in the CLI: flock(1) exists on neither macOS nor Git Bash.
        ->and($script)->not->toContain('flock');
})->with(['sh' => ['/install'], 'ps1' => ['/install.ps1']]);

test('the installer verifies the wheel before pip installs it', function (string $url) {
    // Verifying the script but not the wheel would leave code executing in the
    // developer's venv with no integrity check at all.
    $script = $this->get($url)->assertOk()->getContent();

    expect($script)->toContain('SLAYER_EXPECTED_WHEEL_SHA')
        ->and($script)->toContain('wheel checksum mismatch');
})->with(['sh' => ['/install'], 'ps1' => ['/install.ps1']]);
