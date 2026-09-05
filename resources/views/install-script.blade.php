@php
    $envVar = strtoupper($namespace).'_TOKEN';
    $envCheck = '${'.$envVar.':-}';
    $envRead = '"$'.$envVar.'"';
@endphp
{!! '#!/bin/sh' !!}
# {{ $namespace }} hook installer
# Installs Claude Code, Codex, and Antigravity CLI hooks that POST to {{ $baseUrl }}.
# Hooks read the token at runtime from ~/.config/{{ $namespace }}/token.
#
# Pass {{ $envVar }}=<token> in the environment to save the token in the
# same run, e.g. `curl -fsSL ... | {{ $envVar }}=xxx sh`. Otherwise the
# token must be written separately.
#
# Re-running this script is safe — existing {{ $namespace }} hook blocks are
# replaced, other settings in your config files are preserved.

set -e

# Pick a Python that ACTUALLY RUNS and has `venv`. `command -v python3` is not
# enough on macOS: /usr/bin/python3 is an Xcode Command-Line-Tools stub that
# exists on PATH but fails ("invalid active developer path") until the tools are
# installed — which silently broke both the venv below and the settings.json
# merges further down. Probing `import venv` rejects the stub and any Python
# missing the module (e.g. Debian without python3-venv).
PY=""
# Probe each candidate for a Python that is >= 3.10 AND has a WORKING venv and
# pyexpat. The pyexpat check rejects the broken Homebrew python@3.14 3.14.6
# bottle (its pyexpat links against a libexpat symbol the resolved lib lacks,
# which breaks ALL pip use); the version gate rejects the macOS system
# python3.9 (too old for slayer and for get-pip). Versioned names are probed so
# a healthy brew python3.12/3.13 is found even when the default `python3` is the
# broken or too-old one.
for _c in "${SLAYER_PYTHON:-}" python3 python3.13 python3.12 python3.11 python3.10 \
          /opt/homebrew/bin/python3 /usr/local/bin/python3 python; do
    [ -n "$_c" ] || continue
    _p=$(command -v "$_c" 2>/dev/null) || continue
    if "$_p" -c 'import sys, venv, pyexpat; sys.exit(0 if sys.version_info[:2] >= (3, 10) else 1)' >/dev/null 2>&1; then
        PY="$_p"; break
    fi
done
if [ -z "$PY" ]; then
    echo "error: no usable Python (>= 3.10 with a working venv + pyexpat) was found." >&2
    case "$(uname -s)" in
        Darwin) echo "  macOS: install a working Python, e.g. 'brew install python@3.12' (the Homebrew python@3.14 3.14.6 bottle ships a broken pyexpat) or from python.org, then re-run -- or set SLAYER_PYTHON to it." >&2 ;;
        Linux)  echo "  Linux: install Python 3.10+ with venv, e.g. 'sudo apt install python3-venv'." >&2 ;;
    esac
    exit 1
fi

# Ensure token directory exists.
mkdir -p "$HOME/.config/{{ $namespace }}"

sha256() { if command -v sha256sum >/dev/null 2>&1; then sha256sum | cut -d' ' -f1; else shasum -a 256 | cut -d' ' -f1; fi; }

# --- jq bootstrap ----
# Every machine uses this exact pinned jq binary -- NEVER the system jq --
# so cross-platform/cross-version differences in jq's own behavior (try/catch
# semantics, sort/type-coercion edge cases) can never again produce silently
# wrong token/account attribution the way "whatever jq happens to be on this
# machine" did on prod. A missing/unverifiable jq now stops the install
# entirely rather than shipping a hook that will silently record nothing.
JQ_VERSION="1.8.2"
JQ_DIR="$HOME/.config/{{ $namespace }}/bin"
JQ_BIN="$JQ_DIR/jq"
mkdir -p "$JQ_DIR"

jq_asset_and_sha() {
    # $1 = uname -s, $2 = uname -m. One `echo "asset sha256"` line per
    # supported platform; pinned to the jq 1.8.2 release's own sha256sum.txt.
    case "$1:$2" in
        Linux:x86_64)  echo "jq-linux-amd64 b1c22172dd303f3be49e935aa56aa48a8b7a46e0bc838b4997d3bb451495870f" ;;
        Linux:aarch64) echo "jq-linux-arm64 8b85c817833814ddca00a144c33705546355afccf0cf39b188f3cdb48b852309" ;;
        Darwin:x86_64) echo "jq-macos-amd64 e94b266e3c26690550006abe63152b782280f4e14374accdf04cbde844f00bc0" ;;
        Darwin:arm64)  echo "jq-macos-arm64 2d75340ba57a4b4b4c8708a21c2dc8e958a48aaa8bba13b27f77f6e4c0eca07e" ;;
        *) echo "" ;;
    esac
}

JQ_ASSET_SHA=$(jq_asset_and_sha "$(uname -s)" "$(uname -m)")
if [ -z "$JQ_ASSET_SHA" ]; then
    echo "error: no pinned jq build for $(uname -s)/$(uname -m) -- token-slayer cannot install without a known-good jq for this platform. Open an issue with this OS/arch." >&2
    exit 1
fi
JQ_ASSET=$(printf '%s' "$JQ_ASSET_SHA" | cut -d' ' -f1)
JQ_SHA=$(printf '%s' "$JQ_ASSET_SHA" | cut -d' ' -f2)

# Self-heal: an existing binary that doesn't match the pinned checksum (a
# partial download from a prior failed run, or a version bump) is rebuilt,
# not trusted. A binary that already matches is left untouched.
if [ -x "$JQ_BIN" ]; then
    CURRENT_SHA=$(sha256 < "$JQ_BIN")
    [ "$CURRENT_SHA" = "$JQ_SHA" ] || rm -f "$JQ_BIN"
fi

if [ ! -x "$JQ_BIN" ]; then
    JQ_URL="https://github.com/jqlang/jq/releases/download/jq-$JQ_VERSION/$JQ_ASSET"
    JQ_TMP="$JQ_DIR/.jq.download"
    if ! curl -fsSL "$JQ_URL" -o "$JQ_TMP" 2>"$JQ_DIR/.jq.curl-err"; then
        cat "$JQ_DIR/.jq.curl-err" >&2
        rm -f "$JQ_TMP" "$JQ_DIR/.jq.curl-err"
        echo "error: could not download jq from $JQ_URL (see error above) -- check your network connection and re-run." >&2
        exit 1
    fi
    rm -f "$JQ_DIR/.jq.curl-err"
    DOWNLOADED_SHA=$(sha256 < "$JQ_TMP")
    if [ "$DOWNLOADED_SHA" != "$JQ_SHA" ]; then
        rm -f "$JQ_TMP"
        echo "error: downloaded jq checksum mismatch -- expected $JQ_SHA, got $DOWNLOADED_SHA. Refusing to install a binary that doesn't match. Re-run to retry the download." >&2
        exit 1
    fi
    mv "$JQ_TMP" "$JQ_BIN"
    chmod +x "$JQ_BIN"
fi

# Bundled detector config (data, not code): tells the hook where a locally-run
# proxy already logs session/account, so token-slayer can attribute without
# modifying the proxy. Overwritten on every install so new entries ship centrally.
cat > "$HOME/.config/{{ $namespace }}/detector-config.json" <<'DETECTOR_JSON'
{
  "teamclaude": { "join": "session", "logs": ["~/.config/teamclaude/requests/*.log"], "account_pattern": "account: ([^[:space:]]+)" },
  "claudehub":  { "join": "ts_tokens", "logs": ["~/.config/claudehub/stats.jsonl"], "account_field": "account_name", "ts_field": "ts" },
  "auth2api":   { "join": "ts_tokens", "logs": ["~/.config/auth2api/stats.jsonl"], "account_field": "accountEmail", "ts_field": "ts" }
}
DETECTOR_JSON

# Drop the hook helper script. Stop events are enriched with a tokens count
# parsed from the local Claude transcript (requires jq when available),
# because the server cannot read the user's filesystem.
HELPER="$HOME/.config/{{ $namespace }}/send-hook.sh"
CHECKSUM_FILE="$HOME/.config/{{ $namespace }}/.hook-checksum"

# If an existing send-hook.sh no longer matches the checksum of the last
# stock install (or predates checksum tracking entirely), assume the user
# hand-edited it and back it up before we overwrite it below.
HOOK_BACKUP=""
if [ -f "$HELPER" ]; then
    OLD_SHA=$(sha256 < "$HELPER")
    STORED_SHA=""
    [ -r "$CHECKSUM_FILE" ] && STORED_SHA=$(cat "$CHECKSUM_FILE")
    if [ -z "$STORED_SHA" ] || [ "$OLD_SHA" != "$STORED_SHA" ]; then
        HOOK_BACKUP="$HELPER.bak.$(date +%Y%m%d%H%M%S)"
        cp "$HELPER" "$HOOK_BACKUP"
    fi
fi

# Written to a temp file in the same directory and renamed into place: the
# hook may be EXECUTING right now, and bash reads a script lazily by byte
# offset, so truncating it mid-run makes the running copy read past the end
# and lose that turn's event with no error anywhere.
cat > "$HELPER.tmp" <<'HOOK_SH'
#!/usr/bin/env bash
set -u

API_URL='{{ $baseUrl }}'
TOKEN_FILE="$HOME/.config/{{ $namespace }}/token"

BODY=$(cat)
[ -r "$TOKEN_FILE" ] || exit 0

# Always the pinned binary installed by the bootstrap above -- never a
# system jq -- so hook behavior can never drift between machines.
JQ="$HOME/.config/{{ $namespace }}/bin/jq"

# A SubagentStop's session_id is the PARENT session's id, not unique per
# subagent (per the official hook payload docs) -- fold in agent_id so this
# agent's Event can never be conflated with the parent session's own Stop
# events, which share that same session_id.
if [ -x "$JQ" ]; then
  BODY=$(printf '%s' "$BODY" | "$JQ" -c '
    if .hook_event_name == "SubagentStop" and ((.agent_id // "") != "") then
      .session_id = ((.session_id // "") + ":" + .agent_id)
    else . end
  ' 2>/dev/null || printf '%s' "$BODY")
fi

if [ -x "$JQ" ]; then
  TRANSCRIPT=$(printf '%s' "$BODY" | "$JQ" -r '.transcript_path // .transcriptPath // ""' 2>/dev/null)
  if [ -n "$TRANSCRIPT" ] && [ -r "$TRANSCRIPT" ]; then
    # Emits {tokens, models}. The capture and the merge below MUST change
    # together: with the old scalar merge this object would be nested under
    # `tokens`, and the server's (int) cast on an array yields 1 with no
    # warning -- every Stop would deal 1 token of damage.
    # Codex rollout JSONL shares no shape with a Claude transcript: no
    # type:"assistant" entries, usage under event_msg.payload.type ==
    # "token_count", model only in turn_context. Running the Claude walk over
    # it returns 0, which is why Codex ingestion was silently dead from
    # 2026-06-28. antigravity deliberately keeps the Claude walk: its shape is
    # unverified and must not change here.
    #
    # The Claude walk dedupes by message.id ($mid): when a single API
    # response mixes content-block types (e.g. thinking + tool_use, or
    # thinking + text), Claude Code writes ONE JSONL row per block type but
    # repeats that message's full output_tokens on every row -- verified via
    # two rows sharing the same message.id. Summing every assistant row
    # blindly double-counts (or worse, with 3+ block types) any turn that
    # used extended thinking, which is common. A row with no id (an
    # unverified shape from PLANNER_RESPONSE/MODEL sources) is never
    # deduped, matching the pre-dedup behavior for those.
    extract_usage() {
      if [ "${PROVIDER:-}" = "codex" ]; then
        "$JQ" -sr '
          . as $a
          | (length - 1) as $end
          | reduce range($end; -1; -1) as $i ({t:0, k:null, stop:false};
              if .stop then . else
                ($a[$i]) as $e
                | if $e.type == "event_msg" and $e.payload.type == "token_count" then
                    .t += ($e.payload.info.last_token_usage.output_tokens // 0)
                  elif $e.type == "turn_context" then
                    .k = (.k // $e.payload.model)
                  elif $e.type == "event_msg" and $e.payload.type == "task_started" then
                    .stop = true
                  else . end
              end)
          | {tokens: .t, models: (if (.k != null and .t > 0) then {(.k): .t} else {} end)}
        ' "$TRANSCRIPT" 2>/dev/null
      else
        "$JQ" -sr '
          . as $a
          | (length - 1) as $end
          | reduce range($end; -1; -1) as $i ({t:0, m:{}, seen:{}, stop:false};
              if .stop then . else
                ($a[$i]) as $e
                | if $e.type == "assistant" or $e.type == "PLANNER_RESPONSE" or $e.source == "MODEL" then
                    ($e.message.id // $e.id // null) as $mid
                    | if ($mid != null and (.seen[$mid] // false)) then .
                      else
                        (($e.message.usage.output_tokens // $e.usage.output_tokens // $e.usage.outputTokens // 0)) as $tok
                        | (($e.message.model // $e.model) // null) as $k
                        | .t += $tok
                        | (if $tok > 0 and $k != null then .m[$k] += $tok else . end)
                        | (if $mid != null then .seen[$mid] = true else . end)
                      end
                  elif ($e.type == "USER_INPUT" or $e.source == "USER_EXPLICIT") then
                    .stop = true
                  elif $e.type == "user"
                       and ((try $e.message.content[0].type catch null) != "tool_result") then
                    .stop = true
                  else . end
              end)
          | {tokens: .t, models: .m}
        ' "$TRANSCRIPT" 2>/dev/null
      fi
    }

    # Claude Code fires Stop before the final assistant message is
    # guaranteed flushed to disk; reading right away can see a truncated
    # file and compute tokens=0, silently dropping the whole turn (the
    # server only creates an Event when tokens>0 -- a zero read is never
    # retried server-side). A first read that already sees tokens>0 is
    # trusted immediately with no added latency, unchanged from before.
    # Only a zero first read is retried: reread the same file every 300ms,
    # up to 5 extra times (~1.5s), until two CONSECUTIVE reads agree on a
    # non-zero result. The transcript is append-only, so identical output
    # twice in a row means nothing landed between the two reads -- it has
    # settled. Two consecutive zeros do NOT count as agreement: a
    # still-flushing file reads zero every time until the write lands, so
    # zero must exhaust the full retry budget rather than being accepted
    # early. Comparing the whole USAGE string (not just the token count)
    # also catches a boundary shift into a different turn's models, since
    # that would change the models object even if the count coincided --
    # and reading a genuinely later turn would require a full
    # prompt-to-response round trip inside this same short window, which
    # does not happen in practice.
    USAGE=$(extract_usage)
    TOK=$(printf '%s' "$USAGE" | "$JQ" -r '.tokens // 0' 2>/dev/null)
    if [ "${TOK:-0}" = "0" ]; then
      PREV="$USAGE"
      ATTEMPT=0
      while [ "$ATTEMPT" -lt 5 ]; do
        sleep 0.3
        USAGE=$(extract_usage)
        TOK=$(printf '%s' "$USAGE" | "$JQ" -r '.tokens // 0' 2>/dev/null)
        ATTEMPT=$((ATTEMPT + 1))
        if [ "${TOK:-0}" != "0" ] && [ "$USAGE" = "$PREV" ]; then
          break
        fi
        PREV="$USAGE"
      done
    fi
    case "$USAGE" in
      '{'*) BODY=$(printf '%s' "$BODY" | "$JQ" -c --argjson u "$USAGE" '. + $u' 2>/dev/null || printf '%s' "$BODY") ;;
    esac
  fi
fi

URL="$API_URL"
if [ "${PROVIDER:-}" = "codex" ]; then
  URL="${URL}?provider=codex"
elif [ "${PROVIDER:-}" = "antigravity" ]; then
  URL="${URL}?provider=antigravity"
fi

CLIENT_VERSION='{{ $clientVersion }}'
HOOK_VERSION='{{ $hookVersion }}'
HOOK_UA='token-slayer-hook/{{ $clientVersion }} (external, cli)'
NS_DIR="$HOME/.config/{{ $namespace }}"

sha256() { if command -v sha256sum >/dev/null 2>&1; then sha256sum | cut -d' ' -f1; else shasum -a 256 | cut -d' ' -f1; fi; }

current_access_token() {
  # Same lookup order Claude Code uses; hooks inherit CLAUDE_CONFIG_DIR.
  # CLAUDE_CODE_OAUTH_TOKEN (CI/automation) takes priority over on-disk credentials.
  if [ -n "${CLAUDE_CODE_OAUTH_TOKEN:-}" ]; then
    printf '%s' "$CLAUDE_CODE_OAUTH_TOKEN"
    return
  fi
  for f in "${CLAUDE_CONFIG_DIR:-}/.credentials.json" "$HOME/.claude/.credentials.json"; do
    [ -r "$f" ] || continue
    "$JQ" -r '.claudeAiOauth.accessToken // ""' "$f" 2>/dev/null && return
  done
  if [ "$(uname)" = "Darwin" ]; then
    security find-generic-password -s "Claude Code-credentials" -w 2>/dev/null \
      | "$JQ" -r '.claudeAiOauth.accessToken // ""' 2>/dev/null
  fi
}

beacon_org_id() {
  # $1 = full auth header value, e.g. "Authorization: Bearer xxx" or "x-api-key: xxx".
  # Deliberately-invalid inference request: max_tokens=0 and empty messages -> HTTP
  # 400, zero token cost, touches no quota, and works with bare inference scope
  # (including setup-tokens, which get a permanent 403 from /api/oauth/profile). The
  # response headers still carry the org UUID that owns the token.
  curl -si --max-time 5 -A "$HOOK_UA" "https://api.anthropic.com/v1/messages" \
    -H "$1" \
    -H "anthropic-version: 2023-06-01" -H "content-type: application/json" \
    -d '{"model":"claude-haiku-4-5-20251001","max_tokens":0,"messages":[]}' 2>/dev/null \
    | grep -i '^anthropic-organization-id:' | awk '{print $2}' | tr -d '\r'
}

provider_account() {
  # Account Identity Provider intake. Whichever layer holds the real credential
  # (a proxy or switcher) may declare identity via a portable transport;
  # token-slayer ships no code into that layer -- it only reads. Env var + file
  # only (socket / URL / executable transports are out of scope for now).
  #
  # File precedence: an explicit CLAUDE_ACCOUNT_PROVIDER override, then a
  # session-scoped file, then the provider-scoped active file (what an
  # updated switcher writes for every provider, including non-Claude ones),
  # then -- ONLY when PROVIDER is unset -- the legacy shared active.json a
  # not-yet-updated Claude-only client may still be writing.
  _pv=""
  if [ -n "${CLAUDE_ACCOUNT_PROVIDER:-}" ] && [ -r "${CLAUDE_ACCOUNT_PROVIDER}" ]; then
    _pv="${CLAUDE_ACCOUNT_PROVIDER}"
  elif [ -n "${SESSION_ID:-}" ] && [ -r "$NS_DIR/account-provider/sessions/$SESSION_ID.json" ]; then
    _pv="$NS_DIR/account-provider/sessions/$SESSION_ID.json"
  elif [ -r "$NS_DIR/account-provider/active-${PROVIDER:-claude}.json" ]; then
    _pv="$NS_DIR/account-provider/active-${PROVIDER:-claude}.json"
  elif [ -z "${PROVIDER:-}" ] && [ -r "$NS_DIR/account-provider/active.json" ]; then
    _pv="$NS_DIR/account-provider/active.json"
  fi
  [ -n "$_pv" ] || return 1

  _org=$("$JQ" -r '.account_id // .org_uuid // ""' "$_pv" 2>/dev/null)
  [ -n "$_org" ] || return 1
  ACC_ORG_ID="$_org"
  ACC_EMAIL=$("$JQ" -r '.email // ""' "$_pv" 2>/dev/null)
  ACC_UUID=$("$JQ" -r '.user_id // .uuid // ""' "$_pv" 2>/dev/null)
  ACC_SOURCE="provider"
  return 0
}

detector_scan() {
  # Generic, data-driven fallback for local proxies. Reads ONLY existing local
  # logs named by detector-config.json; never writes/rotates/deletes them.
  # Two join strategies per config entry: exact `session` (match the current
  # session id) and best-effort `ts_tokens` (SAFE single-account time window).
  _cfg="$NS_DIR/detector-config.json"
  [ -r "$_cfg" ] || return 1

  for _mgr in $("$JQ" -r 'keys[]' "$_cfg" 2>/dev/null); do
    _join=$("$JQ" -r --arg k "$_mgr" '.[$k].join // ""' "$_cfg" 2>/dev/null)
    case "$_join" in
      session)
        [ -n "${SESSION_ID:-}" ] || continue
        _pat=$("$JQ" -r --arg k "$_mgr" '.[$k].account_pattern // ""' "$_cfg" 2>/dev/null)
        [ -n "$_pat" ] || continue
        for _glob in $("$JQ" -r --arg k "$_mgr" '.[$k].logs[]' "$_cfg" 2>/dev/null); do
          _glob=$(printf '%s' "$_glob" | sed "s#^~#$HOME#")
          for _f in $_glob; do
            [ -r "$_f" ] || continue
            grep -qF -- "$SESSION_ID" "$_f" 2>/dev/null || continue
            # This file carries the current session (teamclaude logs one file per
            # request), so we trust its first "account:" match. account_pattern must
            # hold exactly one capture group and no "/" (the sed delimiter). Unlike
            # the ts_tokens arm this has no distinct-account guard: it rests on the
            # one-request-per-file assumption -- verify on staging with a real log.
            _acct=$(sed -nE "s/.*$_pat.*/\1/p" "$_f" 2>/dev/null | head -1)
            if [ -n "$_acct" ]; then
              ACC_EMAIL="$_acct"; ACC_UUID=""; ACC_ORG_ID=""; ACC_SOURCE="detector"
              return 0
            fi
          done
        done
        ;;
      ts_tokens)
        DETECTOR_WINDOW_SECS=120
        _af=$("$JQ" -r --arg k "$_mgr" '.[$k].account_field // ""' "$_cfg" 2>/dev/null)
        _tf=$("$JQ" -r --arg k "$_mgr" '.[$k].ts_field // "ts"' "$_cfg" 2>/dev/null)
        [ -n "$_af" ] || continue
        _now=$(date +%s)
        _lo=$((_now - DETECTOR_WINDOW_SECS))
        for _glob in $("$JQ" -r --arg k "$_mgr" '.[$k].logs[]' "$_cfg" 2>/dev/null); do
          _glob=$(printf '%s' "$_glob" | sed "s#^~#$HOME#")
          for _f in $_glob; do
            [ -r "$_f" ] || continue
            # SAFE rule: one distinct account in the window -> attribute; more -> NULL.
            _acct=$("$JQ" -rs --arg af "$_af" --arg tf "$_tf" \
              --argjson lo "$_lo" --argjson hi "$_now" '
                [ .[] | select((.[$tf] // 0) >= $lo and (.[$tf] // 0) <= $hi) | .[$af] ]
                | map(select(. != null and . != "")) | unique
                | if length == 1 then .[0] else "" end' "$_f" 2>/dev/null)
            if [ -n "$_acct" ]; then
              ACC_EMAIL="$_acct"; ACC_UUID=""; ACC_ORG_ID=""; ACC_SOURCE="detector"
              return 0
            fi
          done
        done
        ;;
    esac
  done
  return 1
}

resolve_account() {
  ACC_EMAIL="" ACC_UUID="" ACC_SOURCE="" ACC_ORG_ID=""

  # 0. Account Identity Provider (proxy/switcher declares identity) -- highest
  # signal, tried for every provider (it is already provider-agnostic).
  provider_account && return

  # Pre-check: non-Claude providers (codex/antigravity) never carry the
  # Claude-specific claims below (manual override / credential beacon /
  # .claude.json fallback).
  [ -n "${PROVIDER:-}" ] && return

  # 1. Manual override: wins over credential/proxy/auto (a provider still precedes it).
  if [ -r "$NS_DIR/account.json" ]; then
    ACC_EMAIL=$("$JQ" -r '.email // ""' "$NS_DIR/account.json" 2>/dev/null)
    ACC_UUID=$("$JQ" -r '.uuid // ""' "$NS_DIR/account.json" 2>/dev/null)
    [ -n "$ACC_EMAIL" ] && { ACC_SOURCE="manual"; return; }
  fi

  # 2. Proxy detect: base URL rerouted -> client cannot know the account. Don't guess,
  #    and don't beacon a URL that isn't api.anthropic.com.
  case "${ANTHROPIC_BASE_URL:-}" in
    ""|*api.anthropic.com*) ;;
    *) detector_scan && return
       ACC_SOURCE="proxy"; ACC_EMAIL=""; ACC_UUID=""; return ;;
  esac

  # 3. Credential identity: resolve the org UUID via a zero-cost beacon call, cached
  #    per token fingerprint so repeat events do zero network work.
  OAUTH_TOKEN=$(current_access_token)
  if [ -n "$OAUTH_TOKEN" ]; then
    TOKEN="$OAUTH_TOKEN"
    AUTH_HEADER="Authorization: Bearer $OAUTH_TOKEN"
  elif [ -n "${ANTHROPIC_API_KEY:-}" ]; then
    TOKEN="$ANTHROPIC_API_KEY"
    AUTH_HEADER="x-api-key: $ANTHROPIC_API_KEY"
  else
    TOKEN=""
  fi

  if [ -n "$TOKEN" ]; then
    FP=$(printf '%s' "$TOKEN" | sha256)
    CACHE="$NS_DIR/identity-cache.json"
    NOW=$(date +%s)
    BEACON_ERROR_RETRY_SECS=300

    CACHED_STATUS="" CACHED_CHECKED_AT=0
    if [ -r "$CACHE" ]; then
      CACHED_STATUS=$("$JQ" -r --arg fp "$FP" '.[$fp].status // ""' "$CACHE" 2>/dev/null)
      CACHED_CHECKED_AT=$("$JQ" -r --arg fp "$FP" '.[$fp].checked_at // 0' "$CACHE" 2>/dev/null)
    fi
    : "${CACHED_CHECKED_AT:=0}"

    SHOULD_LOOKUP=1
    case "$CACHED_STATUS" in
      ok)
        ACC_ORG_ID=$("$JQ" -r --arg fp "$FP" '.[$fp].org_id // ""' "$CACHE" 2>/dev/null)
        ACC_EMAIL=$("$JQ" -r --arg fp "$FP" '.[$fp].email // ""' "$CACHE" 2>/dev/null)
        ACC_UUID=$("$JQ" -r --arg fp "$FP" '.[$fp].uuid // ""' "$CACHE" 2>/dev/null)
        SHOULD_LOOKUP=0
        ;;
      restricted)
        # Permanent negative for this fp: never beacon it again.
        SHOULD_LOOKUP=0
        ;;
      error)
        # Transient failure: retry only after the short self-heal window.
        [ $((NOW - CACHED_CHECKED_AT)) -le "$BEACON_ERROR_RETRY_SECS" ] && SHOULD_LOOKUP=0
        ;;
    esac

    if [ "$SHOULD_LOOKUP" = "1" ]; then
      ACC_ORG_ID=$(beacon_org_id "$AUTH_HEADER")

      if [ -n "$ACC_ORG_ID" ]; then
        STATUS="ok"
        if [ -n "$OAUTH_TOKEN" ]; then
          # Best-effort profile lookup for email/uuid (enables server auto-learn); a
          # 403 here is fine and just leaves email/uuid blank -- the beacon already
          # proved identity via the org id.
          PROFILE=$(curl -sf --max-time 5 -A "$HOOK_UA" "https://api.anthropic.com/api/oauth/profile" \
            -H "Authorization: Bearer $OAUTH_TOKEN" -H "anthropic-beta: oauth-2025-04-20" 2>/dev/null)
          ACC_EMAIL=$(printf '%s' "$PROFILE" | "$JQ" -r '.account.email // .account.email_address // .email // ""' 2>/dev/null)
          ACC_UUID=$(printf '%s' "$PROFILE" | "$JQ" -r '.account.uuid // .account_uuid // ""' 2>/dev/null)
        fi
      else
        STATUS="error"
      fi

      TMP=$(mktemp) && "$JQ" --arg fp "$FP" --arg o "$ACC_ORG_ID" --arg e "$ACC_EMAIL" \
        --arg u "$ACC_UUID" --arg st "$STATUS" --argjson t "$NOW" \
        '. + {($fp): {org_id: $o, email: $e, uuid: $u, status: $st, checked_at: $t}}' \
        "$CACHE" 2>/dev/null > "$TMP" \
        || printf '{"%s":{"org_id":"%s","email":"%s","uuid":"%s","status":"%s","checked_at":%s}}' \
             "$FP" "$ACC_ORG_ID" "$ACC_EMAIL" "$ACC_UUID" "$STATUS" "$NOW" > "$TMP"
      mv "$TMP" "$CACHE"
    fi

    [ -n "$ACC_ORG_ID" ] && { ACC_SOURCE="credential"; return; }
  fi

  # 4. Fallback: oauthAccount (may be stale under external switchers).
  CJ="${CLAUDE_CONFIG_DIR:-$HOME}/.claude.json"
  [ -r "$CJ" ] || CJ="$HOME/.claude.json"
  if [ -r "$CJ" ]; then
    ACC_EMAIL=$("$JQ" -r '.oauthAccount.emailAddress // ""' "$CJ" 2>/dev/null)
    ACC_UUID=$("$JQ" -r '.oauthAccount.accountUuid // ""' "$CJ" 2>/dev/null)
    [ -n "$ACC_EMAIL" ] && ACC_SOURCE="auto"
  fi
}

if [ -x "$JQ" ]; then
  SESSION_ID=$(printf '%s' "$BODY" | "$JQ" -r '.session_id // .sessionId // ""' 2>/dev/null)
  resolve_account
  BODY=$(printf '%s' "$BODY" | "$JQ" -c --arg e "$ACC_EMAIL" --arg u "$ACC_UUID" \
    --arg s "$ACC_SOURCE" --arg v "$CLIENT_VERSION" --arg o "$ACC_ORG_ID" \
    --arg hv "$HOOK_VERSION" \
    '. + {client_version: $v, hook_version: $hv} + (if $s != "" then {account_source: $s} else {} end)
       + (if $e != "" then {account_email: $e, account_uuid: $u} else {} end)
       + (if $o != "" then {account_org_id: $o} else {} end)' \
    2>/dev/null || printf '%s' "$BODY")
fi

CUSTOM_SH="$HOME/.config/{{ $namespace }}/custom.sh"
[ -r "$CUSTOM_SH" ] && . "$CUSTOM_SH"

# --- exclude-check hook point (Phase 3) ---
# Reserved: a future dev-owned exclude-accounts.json will let a developer drop
# their own private accounts here (exit 0 before POST) so those events never
# leave the machine. Not active yet -- default is track everything.

# The server reads exactly these eleven fields. Everything else the hook
# receives on stdin -- the prompt, tool_input, tool_response, the last
# assistant message, cwd, permission_mode, transcript_path -- would cross the
# network and be discarded unread, so it is not sent at all. This is
# unconditional, not opt-in: content leaving the machine should not depend on
# a developer knowing to set an env var.
#
# It runs AFTER custom.sh, which shares this shell and therefore still sees the
# full body: the documented custom_activity recipes that read tool_input keep
# working, and only the resulting label leaves the machine.
if [ -x "$JQ" ]; then
  FILTERED=$(printf '%s' "$BODY" | "$JQ" -c '{
    hook_event_name, session_id, tokens, models, tool_name, custom_activity,
    client_version, hook_version, account_email, account_uuid, account_source,
    account_org_id
  } | with_entries(select(.value != null))' 2>/dev/null)
  case "$FILTERED" in '{'*) BODY="$FILTERED" ;; esac
fi

# The body goes over stdin, never as an argv argument: under Git Bash the
# native curl.exe receives argv through a Win32 codepage conversion that
# mangles every non-ASCII byte, and the server then drops the event with
# "Malformed UTF-8". A single argument is also length-capped (~32 KB on
# Windows, 128 KB per arg on Linux) while a long assistant message is not.
# The response used to be discarded. It carries the version the server wants
# clients on, the sha256 of the artifacts, and a fleet-wide pause flag -- so it
# is captured here instead, which is why no second endpoint is needed.
#
# -f so a non-2xx body is never stored; a PID-unique temp name so concurrent
# hooks cannot interleave; mv so a DNS failure or a 3s timeout leaves the
# PREVIOUS signal intact rather than truncating it to nothing.
( printf '%s' "$BODY" | curl -sf --max-time 3 -X POST "$URL" \
    -H "Authorization: Bearer $(cat "$TOKEN_FILE")" \
    -H 'Content-Type: application/json' \
    --data-binary @- -o "$NS_DIR/.update-state.$$.tmp" \
  && chmod 600 "$NS_DIR/.update-state.$$.tmp" \
  && mv -f "$NS_DIR/.update-state.$$.tmp" "$NS_DIR/update-state" \
  || rm -f "$NS_DIR/.update-state.$$.tmp" ) >/dev/null 2>&1 &

# Bring the client up to date, but only ever from SessionStart and only when
# the developer has not opted out. Everything that decides WHETHER to update --
# reading the signal above, honouring `paused`, verifying the sha256, locking --
# lives in the CLI, in a language with real primitives for it. The hook stays a
# thin, fast bash script that cannot block a session.
if [ "$(printf '%s' "$BODY" | "$JQ" -r '.hook_event_name // ""' 2>/dev/null)" = "SessionStart" ] \
   && [ -z "${SLAYER_NO_AUTO_UPDATE:-}" ]; then
  # The Windows installer writes .cmd shims into the same directory, and this
  # hook runs under Git Bash there -- checking only the extension-less name
  # would make auto-update silently never fire on Windows.
  for _tsl in "$HOME/.local/bin/token-slayer" "$HOME/.local/bin/token-slayer.cmd"; do
    if [ -x "$_tsl" ] || [ -f "$_tsl" ]; then
      ( "$_tsl" update --if-newer ) >/dev/null 2>&1 &
      break
    fi
  done
fi
HOOK_SH
chmod +x "$HELPER.tmp"
mv -f "$HELPER.tmp" "$HELPER"

sha256 < "$HELPER" > "$CHECKSUM_FILE"

# Keep only the 3 most recent backups so a long-lived install doesn't
# accumulate one file per update.
ls -1t "$HOME/.config/{{ $namespace }}"/send-hook.sh.bak.* 2>/dev/null | tail -n +4 | xargs rm -f --

if [ -n "$HOOK_BACKUP" ]; then
    echo ""
    echo "=========================================================="
    echo "WARNING: your existing send-hook.sh had local modifications"
    echo "and has been overwritten by this install."
    echo ""
    echo "  backup saved to: $HOOK_BACKUP"
    echo ""
    echo "Move your customizations into:"
    echo "  ~/.config/{{ $namespace }}/custom.sh"
    echo "That file is sourced automatically on every hook run and"
    echo "survives every update -- edits to send-hook.sh itself do not."
    echo "=========================================================="
    echo ""
fi

printf '%s' "{{ $clientVersion }}" > "$HOME/.config/{{ $namespace }}/version"
# Separate from `version`: the CLI wheel and the hook are released by different
# repos, so a hook-only change must be visible without a CLI release.
printf '%s' "{{ $hookVersion }}" > "$HOME/.config/{{ $namespace }}/hook-version"

mkdir -p "$HOME/.local/bin"

# slayer-cli: an isolated venv keeps its click/textual/pydantic/keyring/httpx
# deps off the system Python. Every step is tolerant (|| echo "...skipped")
# so a broken/missing Python venv NEVER blocks hook tracking below.
# stderr is NOT swallowed here on purpose: a hidden venv failure used to leave
# the user with no CLI and no idea why.
#
# PEP 668: python.org / Homebrew / uv Pythons ship an EXTERNALLY-MANAGED marker.
# pip normally SKIPS that check inside a venv, but some macOS python.org 3.14
# framework builds fail to detect the venv, so ensurepip's internal
# `pip install --upgrade pip` is refused ("externally-managed-environment") and
# `python -m venv` exits non-zero. PIP_BREAK_SYSTEM_PACKAGES=1 is pip's official
# override; ensurepip's pip subprocess inherits it (fixing the primary path),
# and it is safe because every pip action here targets our OWN dedicated venv,
# never the system Python.
export PIP_BREAK_SYSTEM_PACKAGES=1
SLAYER_VENV_DIR="$HOME/.config/{{ $namespace }}/venv"
# Self-heal: a venv left over from a failed run or a since-removed/broken
# interpreter can have a working-looking directory but no importable
# slayer_cli. Rebuild it clean instead of reusing it, so simply re-running the
# installer (or `token-slayer update`) repairs a broken install. A healthy venv
# where slayer_cli already imports is left untouched, keeping updates fast.
if [ -d "$SLAYER_VENV_DIR" ] && ! "$SLAYER_VENV_DIR/bin/python" -c 'import slayer_cli' >/dev/null 2>&1; then
    rm -rf "$SLAYER_VENV_DIR"
fi
if ! "$PY" -m venv "$SLAYER_VENV_DIR"; then
    # `python -m venv` runs `ensurepip` to bootstrap pip; that subprocess can
    # fail on a base interpreter marked PEP-668 externally-managed even though
    # the `venv` module itself works. Retry WITHOUT the bundled bootstrap, then
    # fetch pip via the official PyPA get-pip.py with an explicit
    # --break-system-packages (so PEP 668 can't block it even when pip fails to
    # detect the venv). Each step is separate and errors are NOT suppressed, so
    # a real failure (network/SSL/unsupported version) is visible in the output.
    # A failure anywhere in this chain now stops the whole install: a broken
    # slayer-cli venv used to be tolerated so a Python problem never bricked
    # hook tracking, but every install step is now required to actually work.
    echo "slayer-cli: bundled pip bootstrap failed; retrying without it..." >&2
    rm -rf "$SLAYER_VENV_DIR"
    if ! "$PY" -m venv --without-pip "$SLAYER_VENV_DIR"; then
        echo "slayer-cli: 'python -m venv --without-pip' failed (see error above)." >&2
        exit 1
    elif ! curl -fsSL https://bootstrap.pypa.io/get-pip.py -o /tmp/slayer-get-pip.py; then
        echo "slayer-cli: could not download get-pip.py (see error above)." >&2
        exit 1
    elif _slayer_gp=$("$SLAYER_VENV_DIR/bin/python" /tmp/slayer-get-pip.py --break-system-packages 2>&1); then
        rm -f /tmp/slayer-get-pip.py
        echo "slayer-cli: pip bootstrapped via get-pip." >&2
    else
        printf '%s\n' "$_slayer_gp" >&2
        rm -f /tmp/slayer-get-pip.py
        echo "slayer-cli: get-pip bootstrap failed (see error above)." >&2
        exit 1
    fi
fi
# pip refuses to install straight from {{ $slayerWheelUrl }}: its basename
# (slayer_cli-latest.whl) is not a PEP 427 wheel filename (`latest` is not a
# valid version), so `pip install <url>` fails with "not a valid wheel
# filename". Download to a spec-valid temp name first, then install that file
# (the real version comes from the wheel's own METADATA).
SLAYER_WHL_DIR="$(mktemp -d 2>/dev/null || echo /tmp)"
SLAYER_WHL="$SLAYER_WHL_DIR/slayer_cli-0.0.0-py3-none-any.whl"
SLAYER_PIP="$HOME/.config/{{ $namespace }}/venv/bin/pip"

# The wheel route now requires a valid hook token. Resolve it: the env var
# passed on the install one-liner, else the token saved by a previous install
# so `token-slayer update` (which re-runs this script with no env var) works.
SLAYER_TOKEN="{!! $envCheck !!}"
if [ -z "$SLAYER_TOKEN" ] && [ -s "$HOME/.config/{{ $namespace }}/token" ]; then
  SLAYER_TOKEN="$(cat "$HOME/.config/{{ $namespace }}/token")"
fi

# No -f: we must read the status code, not fail silently. curl's own -w
# status output is unreliable as a hard-failure signal: on a hard transport
# failure (DNS/TLS/timeout) curl still writes SOMETHING to stdout via -w,
# just not a clean "000" -- so curl's own exit status is checked directly
# via `if !` instead of pattern-matching the printed body. curl's own
# stderr (DNS/TLS/timeout detail) is captured rather than discarded, so a
# transport failure is diagnosable.
SLAYER_CURL_ERR="$SLAYER_WHL_DIR/curl-stderr"
if ! SLAYER_HTTP=$(curl -sSL -w '%{http_code}' \
    -H "Authorization: Bearer $SLAYER_TOKEN" \
    "{{ $slayerWheelUrl }}" -o "$SLAYER_WHL" 2>"$SLAYER_CURL_ERR"); then
  cat "$SLAYER_CURL_ERR" >&2
  echo "slayer-cli: could not reach the wheel download URL (see error above)." >&2
  exit 1
fi

if [ "$SLAYER_HTTP" = "200" ]; then
  # Two steps on purpose: the served wheel is always "latest" and its version
  # may be UNCHANGED between builds, so a plain `--upgrade` is a no-op and ships
  # stale code. First install pulls deps (first run) / no-ops; then
  # force-reinstall --no-deps refreshes ONLY the package code every time,
  # cheaply (deps untouched). --quiet keeps successful-run output short; on
  # failure stderr is captured explicitly and printed in full below rather
  # than trusting --quiet to have shown enough on its own.
  # Deliberately no PEP-668-bypass CLI flag here: pip older than 23.0.1 has no
  # such option at all and hard-fails with "no such option" when passed one,
  # unlike an unrecognized env var, which old pip just ignores.
  # PIP_BREAK_SYSTEM_PACKAGES=1 (exported above) already covers the bypass for
  # pip versions that understand it, so it alone is enough here.
  # Verify the wheel before installing it, when the caller supplied the digest
  # the server published. Without this the wheel would be the one artifact in
  # the chain executing with no integrity check at all -- the install script
  # itself is already verified by `token-slayer update`, and jq has been
  # checksum-pinned for the same reason since long before this.
  if [ -n "${SLAYER_EXPECTED_WHEEL_SHA:-}" ]; then
    SLAYER_WHL_SHA=$(sha256 < "$SLAYER_WHL")
    if [ "$SLAYER_WHL_SHA" != "$SLAYER_EXPECTED_WHEEL_SHA" ]; then
      echo "error: wheel checksum mismatch -- expected $SLAYER_EXPECTED_WHEEL_SHA, got $SLAYER_WHL_SHA. Refusing to install it." >&2
      exit 1
    fi
  fi

  if SLAYER_PIP_ERR=$("$SLAYER_PIP" install --quiet "$SLAYER_WHL" 2>&1) \
      && SLAYER_PIP_ERR=$("$SLAYER_PIP" install --quiet --force-reinstall --no-deps "$SLAYER_WHL" 2>&1); then
    :
  else
    printf '%s\n' "$SLAYER_PIP_ERR" >&2
    echo "slayer-cli: wheel install failed (see the error above)." >&2
    exit 1
  fi
elif [ "$SLAYER_HTTP" = "401" ]; then
  echo "slayer-cli: your token is missing or no longer valid. Open your token-slayer profile page, click Regenerate token, and re-run the install command it shows." >&2
  exit 1
else
  echo "slayer-cli: could not download the CLI (server said $SLAYER_HTTP)." >&2
  exit 1
fi
rm -f "$SLAYER_WHL" "$SLAYER_CURL_ERR"

cat > "$HOME/.local/bin/token-slayer" <<'CLI_SH'
#!/usr/bin/env bash
set -u
NS_DIR="$HOME/.config/{{ $namespace }}"
SLAYER_VENV="$NS_DIR/venv"
INSTALL_URL='{{ $installUrl }}'
LATEST='{{ $clientVersion }}'

sha256() { if command -v sha256sum >/dev/null 2>&1; then sha256sum | cut -d' ' -f1; else shasum -a 256 | cut -d' ' -f1; fi; }

# Prefer the installed slayer-cli package; fall back to the old minimal
# update/status behavior when the venv is missing so a failed venv/pip step
# never bricks the token-slayer command.
if [ -x "$SLAYER_VENV/bin/python" ]; then
  exec env SLAYER_NS={{ $namespace }} SLAYER_INSTALL_URL={{ $installUrl }} "$SLAYER_VENV/bin/python" -m slayer_cli "$@"
fi

case "${1:-}" in
  update)
    CURRENT=$(cat "$NS_DIR/version" 2>/dev/null || echo "?")
    if [ "$CURRENT" = "$LATEST" ]; then echo "token-slayer: already up to date (v$CURRENT)"; exit 0; fi
    echo "token-slayer: v$CURRENT -> v$LATEST, re-running installer..."
    curl -fsSL "$INSTALL_URL" | sh
    ;;
  status)
    echo "client version: $(cat "$NS_DIR/version" 2>/dev/null || echo none) (latest known at install: $LATEST)"
    echo "hook version: $(cat "$NS_DIR/hook-version" 2>/dev/null || echo none) (served: {{ $hookVersion }})"
    [ -s "$NS_DIR/token" ] && echo "hook token: present" || echo "hook token: MISSING"
    if [ -r "$NS_DIR/account.json" ]; then
      echo "account: $("$HOME/.config/{{ $namespace }}/bin/jq" -r '.email' "$NS_DIR/account.json" 2>/dev/null) (manual)"
    else
      echo "account: resolved automatically per event (credential/auto)"
    fi
    if [ -r "$NS_DIR/custom.sh" ]; then
      echo "custom.sh: active"
    else
      echo "custom.sh: none"
    fi
    if [ -r "$NS_DIR/.hook-checksum" ]; then
      CURRENT_SHA=$(sha256 < "$NS_DIR/send-hook.sh" 2>/dev/null)
      STORED_SHA=$(cat "$NS_DIR/.hook-checksum")
      if [ "$CURRENT_SHA" = "$STORED_SHA" ]; then
        echo "send-hook.sh: stock"
      else
        echo "send-hook.sh: modified"
      fi
    else
      echo "send-hook.sh: unknown"
    fi
    ;;
  *) echo "usage: token-slayer {update|status}"; exit 1 ;;
esac
CLI_SH
chmod +x "$HOME/.local/bin/token-slayer"
# Every alias the CLI answers to gets a shim on PATH. These point at the shim
# script above (which runs `python -m slayer_cli`), NOT at the wheel's console
# scripts -- those live inside the venv, which is not on the user's PATH, so a
# `[project.scripts]` entry alone never reaches the user.
ln -sf "$HOME/.local/bin/token-slayer" "$HOME/.local/bin/slayer"
ln -sf "$HOME/.local/bin/token-slayer" "$HOME/.local/bin/tok"

# Register the machine's current Claude login as a base account slot, so a user
# who already uses Claude Code sees their existing account in token-slayer right
# away. Idempotent + identity-deduplicated (skips when absent or already
# tracked) and best-effort -- never blocks the install.
if [ -x "$HOME/.config/{{ $namespace }}/venv/bin/python" ]; then
  SLAYER_NS={{ $namespace }} "$HOME/.config/{{ $namespace }}/venv/bin/python" -m slayer_cli detect-base >/dev/null 2>&1 || true
fi

case ":$PATH:" in
  *":$HOME/.local/bin:"*) ;;
  *) for rc in "$HOME/.zshrc" "$HOME/.bashrc"; do
       [ -f "$rc" ] && ! grep -q '# token-slayer PATH' "$rc" \
         && printf '\n# token-slayer PATH\nexport PATH="$HOME/.local/bin:$PATH"\n' >> "$rc"
     done ;;
esac

CLAUDE_CMD="bash $HELPER"
CODEX_CMD="PROVIDER=codex bash $HELPER"
AGY_CMD="PROVIDER=antigravity bash $HELPER"

# If {{ $envVar }} was passed, save it now so a single command does both
# hook setup and token install.
if [ -n "{!! $envCheck !!}" ]; then
    TOKEN_FILE="$HOME/.config/{{ $namespace }}/token"
    printf '%s' {!! $envRead !!} > "$TOKEN_FILE"
    chmod 600 "$TOKEN_FILE"
    echo "saved token -> $TOKEN_FILE"
fi

# --- Claude Code: merge into ~/.claude/settings.json ---
mkdir -p "$HOME/.claude"
SETTINGS="$HOME/.claude/settings.json"
[ -s "$SETTINGS" ] || echo '{}' > "$SETTINGS"

CLAUDE_CMD="$CLAUDE_CMD" HOOK_FINGERPRINT="{{ $namespace }}/send-hook.sh" "$PY" - "$SETTINGS" <<'PY'
import json, os, sys

path = sys.argv[1]
cmd = os.environ["CLAUDE_CMD"]
# Only the events EventController actually handles. PostToolUse, SessionEnd
# and Notification fell through to a bare 201 -- and PostToolUse is both the
# highest-frequency event (one per tool call) and the one carrying
# tool_response, so not registering it stops that content at the source.
# Liveness is unaffected: PreToolUse fires immediately before every PostToolUse,
# with UserPromptSubmit and Stop bracketing the turn. SubagentStop fires once
# per completed subagent with its OWN transcript_path (never the parent
# session's file) -- without it, every Task-dispatched subagent's real token
# usage is invisible: it never shares an assistant-type entry with the parent
# transcript, so no amount of retrying or dedup on the parent's own Stop walk
# can recover it.
events = ["SessionStart", "UserPromptSubmit", "PreToolUse", "Stop", "SubagentStop"]

try:
    with open(path) as f:
        data = json.load(f)
    if not isinstance(data, dict):
        raise ValueError("settings.json is not a JSON object")
except (ValueError, OSError):
    # A pre-existing malformed settings.json would otherwise crash the whole
    # installer (the script runs under `set -e`). Preserve the bad file for
    # inspection and start from an empty object so hook installation still
    # succeeds rather than aborting the entire install.
    try:
        os.replace(path, path + ".corrupt-bak")
        sys.stderr.write("warning: %s was invalid JSON; backed up to %s.corrupt-bak and reset\n" % (path, path))
    except OSError:
        pass
    data = {}

data.setdefault("hooks", {})
fingerprint = os.environ["HOOK_FINGERPRINT"]  # e.g. "{{ $namespace }}/send-hook.sh" not in json.dumps(e) filters out our own stale entries

# Strip our own entries from EVERY event, not just the ones about to be
# re-added. Shrinking the event list otherwise leaves stale registrations (e.g.
# PostToolUse) in place, still firing the old hook, with no error anywhere.
for event in list(data["hooks"].keys()):
    kept = [e for e in data["hooks"].get(event, [])
            if fingerprint not in json.dumps(e)]
    if kept:
        data["hooks"][event] = kept
    else:
        del data["hooks"][event]

for event in events:
    data["hooks"].setdefault(event, []).append(
        {"hooks": [{"type": "command", "command": cmd, "shell": "bash"}]}
    )

# Write-then-rename: these files are read by a live Claude Code / Codex
# session, and truncate-in-place leaves a window where a reader sees a
# partial or empty config.
tmp = path + ".tmp"
with open(tmp, "w") as f:
    json.dump(data, f, indent=2)
    f.write("\n")
    f.flush()
    os.fsync(f.fileno())
os.replace(tmp, path)
PY

echo "installed Claude Code hooks -> $SETTINGS"

# Register a second, always-on Stop hook that warms the local usage cache
# (independent of auto-switch, which stays opt-in via `token-slayer run`) so
# `token-slayer tui` shows near-real-time quota without waiting on its
# ticker. Appended alongside send-hook.sh's own Stop entry, not replacing it.
#
# Invokes the venv directly with an explicit SLAYER_NS, like `detect-base`
# above -- NOT the `$HOME/.local/bin/token-slayer` shim. That shim is a
# single shared file rewritten by every namespace's install (its NS_DIR is
# whichever namespace installed last), so a machine with more than one
# namespace installed (e.g. prod + staging) would have this hook silently
# refresh the wrong one.
USAGE_REFRESH_CMD="SLAYER_NS={{ $namespace }} \"\$HOME/.config/{{ $namespace }}/venv/bin/python\" -m slayer_cli hook usage-refresh"
# The fingerprint must be a literal substring of the command above (the
# dedup filter is a plain substring match, same as send-hook.sh's) --
# picking anything else silently fails to replace a stale prior entry.
CLAUDE_CMD="$USAGE_REFRESH_CMD" HOOK_FINGERPRINT="{{ $namespace }}/venv/bin/python" "$PY" - "$SETTINGS" <<'PY'
import json, os, sys

path = sys.argv[1]
cmd = os.environ["CLAUDE_CMD"]
events = ["Stop"]

with open(path) as f:
    data = json.load(f)

data.setdefault("hooks", {})
fingerprint = os.environ["HOOK_FINGERPRINT"]
for event in events:
    entries = [e for e in data["hooks"].get(event, [])
               if fingerprint not in json.dumps(e)]
    entries.append({"hooks": [{"type": "command", "command": cmd, "shell": "bash"}]})
    data["hooks"][event] = entries

# Write-then-rename: these files are read by a live Claude Code / Codex
# session, and truncate-in-place leaves a window where a reader sees a
# partial or empty config.
tmp = path + ".tmp"
with open(tmp, "w") as f:
    json.dump(data, f, indent=2)
    f.write("\n")
    f.flush()
    os.fsync(f.fileno())
os.replace(tmp, path)
PY

echo "installed Claude Code usage-refresh hook -> $SETTINGS"

# Register a third, always-on SessionStart hook that tracks this session for
# the `token-slayer tui` Sessions page (independent of auto-switch, same as
# the usage-refresh Stop hook above) -- so a user sees their session there
# without needing the hidden `install-hooks` auto-switch command. Appended
# alongside send-hook.sh's own SessionStart entry, not replacing it.
#
# Invokes the venv directly with an explicit SLAYER_NS, like `detect-base`
# and the usage-refresh hook above -- NOT the shared `token-slayer` shim
# (see that hook's comment for why).
SESSION_TRACK_CMD="SLAYER_NS={{ $namespace }} \"\$HOME/.config/{{ $namespace }}/venv/bin/python\" -m slayer_cli hook session-track-start"
# The fingerprint must be a literal, contiguous substring of the command
# above (the dedup filter is a plain substring match) -- picking anything
# else silently fails to replace a stale prior entry, leaving duplicates on
# every re-run. "hook session-track-start" is the command's own trailing
# text, verified present verbatim.
CLAUDE_CMD="$SESSION_TRACK_CMD" HOOK_FINGERPRINT="hook session-track-start" "$PY" - "$SETTINGS" <<'PY'
import json, os, sys

path = sys.argv[1]
cmd = os.environ["CLAUDE_CMD"]
events = ["SessionStart"]

with open(path) as f:
    data = json.load(f)

data.setdefault("hooks", {})
fingerprint = os.environ["HOOK_FINGERPRINT"]
for event in events:
    entries = [e for e in data["hooks"].get(event, [])
               if fingerprint not in json.dumps(e)]
    entries.append({"hooks": [{"type": "command", "command": cmd, "shell": "bash"}]})
    data["hooks"][event] = entries

# Write-then-rename: these files are read by a live Claude Code / Codex
# session, and truncate-in-place leaves a window where a reader sees a
# partial or empty config.
tmp = path + ".tmp"
with open(tmp, "w") as f:
    json.dump(data, f, indent=2)
    f.write("\n")
    f.flush()
    os.fsync(f.fileno())
os.replace(tmp, path)
PY

echo "installed Claude Code session-tracking hook -> $SETTINGS"

# --- Codex CLI: heal legacy config.toml hooks, then merge into ~/.codex/hooks.json ---
mkdir -p "$HOME/.codex"
CODEX_CONFIG="$HOME/.codex/config.toml"

# Modern Codex writes its own hook-trust state as `[hooks.state."<key>"]` -- a
# TABLE named "hooks" -- into config.toml. A legacy hooks array-of-tables
# block under that same key collides with it (TOML forbids one key being both
# a table and an array-of-tables), making the whole config.toml unparseable
# and breaking Codex entirely. Only heal a file that already exists -- never
# create one, since config.toml is Codex's own and may hold unrelated config.
if [ -f "$CODEX_CONFIG" ]; then
    NAMESPACE="{{ $namespace }}" "$PY" - "$CODEX_CONFIG" <<'PY'
import os, sys, re

path = sys.argv[1]
ns = re.escape(os.environ["NAMESPACE"])
with open(path) as f:
    text = f.read()

text = re.sub(
    rf"(?ms)^# >>> {ns} hooks\n.*?^# <<< {ns} hooks\n?",
    "",
    text,
)

# Write-then-rename: config.toml is Codex's own file and may be read while a
# session is live; truncating it in place could hand Codex a partial config.
tmp = path + ".tmp"
with open(tmp, "w") as f:
    f.write(text)
    f.flush()
    os.fsync(f.fileno())
os.replace(tmp, path)
PY
fi

# Current Codex reads hooks from hooks.json, top-level "hooks" key, PascalCase
# event names -- the array-of-tables TOML shape healed above is obsolete.
CODEX_HOOKS="$HOME/.codex/hooks.json"
[ -s "$CODEX_HOOKS" ] || echo '{"hooks": {}}' > "$CODEX_HOOKS"

CODEX_CMD="$CODEX_CMD" HOOK_FINGERPRINT="{{ $namespace }}/send-hook.sh" "$PY" - "$CODEX_HOOKS" <<'PY'
import json, os, sys, time

path = sys.argv[1]
cmd = os.environ["CODEX_CMD"]
fingerprint = os.environ["HOOK_FINGERPRINT"]
events = ["SessionStart", "Stop"]

try:
    with open(path) as f:
        data = json.load(f)
    if not isinstance(data, dict):
        raise ValueError("hooks.json is not a JSON object")
except (ValueError, OSError):
    # A pre-existing malformed or missing hooks.json would otherwise crash the
    # whole installer (the script runs under `set -e`). Preserve a bad file
    # for inspection and start from an empty object so hook installation
    # still succeeds rather than aborting the entire install.
    try:
        os.replace(path, path + ".bak.%d" % int(time.time()))
    except OSError:
        pass
    data = {}

data.setdefault("hooks", {})
for event in events:
    groups = []
    for group in data["hooks"].get(event, []):
        # Drop only OUR handler objects (identified by the fingerprint
        # substring), keeping any other tool's entries in the same group.
        handlers = [h for h in group.get("hooks", [])
                    if fingerprint not in json.dumps(h)]
        if handlers:
            group = dict(group)
            group["hooks"] = handlers
            groups.append(group)
    groups.append({"hooks": [{"type": "command", "command": cmd}]})
    data["hooks"][event] = groups

# Write-then-rename: these files are read by a live Claude Code / Codex
# session, and truncate-in-place leaves a window where a reader sees a
# partial or empty config.
tmp = path + ".tmp"
with open(tmp, "w") as f:
    json.dump(data, f, indent=2)
    f.write("\n")
    f.flush()
    os.fsync(f.fileno())
os.replace(tmp, path)
PY

echo "installed Codex CLI hooks -> $CODEX_HOOKS"
echo "Codex: run /hooks inside Codex once to trust the token-slayer hooks (required before they fire)."

# --- Antigravity CLI: merge into ~/.gemini/config/hooks.json ---
mkdir -p "$HOME/.gemini/config"
AGY_HOOKS="$HOME/.gemini/config/hooks.json"
[ -s "$AGY_HOOKS" ] || echo '{}' > "$AGY_HOOKS"

AGY_CMD="$AGY_CMD" NAMESPACE="{{ $namespace }}" "$PY" - "$AGY_HOOKS" <<'PY'
import json, os, sys

path = sys.argv[1]
cmd = os.environ["AGY_CMD"]
ns = os.environ["NAMESPACE"]

with open(path) as f:
    try:
        data = json.load(f)
    except Exception:
        data = {}

# Ensure data is a dictionary
if not isinstance(data, dict):
    data = {}

# We want to set data[ns] = { ... }
ns_data = data.setdefault(ns, {})
if not isinstance(ns_data, dict):
    ns_data = {}
    data[ns] = ns_data

# Simple events without matchers
for event in ["SessionStart", "PreInvocation", "Stop"]:
    ns_data[event] = [{"type": "command", "command": cmd}]

# Events with matchers (tool hooks). PostToolUse is deliberately absent -- the
# server does nothing with it and it carries tool_response -- and any previously
# registered one is removed rather than left behind.
ns_data.pop("PostToolUse", None)
for event in ["PreToolUse"]:
    ns_data[event] = [{
        "matcher": "*",
        "hooks": [{"type": "command", "command": cmd}]
    }]

# Write-then-rename: these files are read by a live Claude Code / Codex
# session, and truncate-in-place leaves a window where a reader sees a
# partial or empty config.
tmp = path + ".tmp"
with open(tmp, "w") as f:
    json.dump(data, f, indent=2)
    f.write("\n")
    f.flush()
    os.fsync(f.fileno())
os.replace(tmp, path)
PY

echo "installed Antigravity CLI hooks -> $AGY_HOOKS"

if [ -z "{!! $envCheck !!}" ] && [ ! -s "$HOME/.config/{{ $namespace }}/token" ]; then
    echo ""
    echo "Next: save your token from the profile page into ~/.config/{{ $namespace }}/token."
fi

echo ""
echo "Tip: create ~/.config/{{ $namespace }}/custom.sh to customize what your fighter shows -- it survives every install and update."
