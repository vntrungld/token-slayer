@php
    $envVar = strtoupper($namespace).'_TOKEN';
@endphp
# {{ $namespace }} hook installer (native Windows / PowerShell)
# Installs Claude Code, Codex, and Antigravity CLI hooks that POST to {{ $baseUrl }}.
# Hooks read the token at runtime from $HOME\.config\{{ $namespace }}\token.
#
# Set $env:{{ $envVar }} = "xxx" before running this script to save the token
# in the same run. Otherwise the token must be written separately.
#
# Re-running this script is safe -- existing {{ $namespace }} hook blocks are
# replaced, other settings in your config files are preserved.
#
# Mirrors resources/views/install-script.blade.php (the POSIX installer) --
# same steps, same order, same hook footprint. See that file for the
# canonical macOS/Linux behavior this script keeps parity with.

$Ns            = '{{ $namespace }}'
$WheelUrl      = '{{ $slayerWheelUrl }}'
$InstallUrl    = '{{ $installUrl }}'
$ClientVersion = '{{ $clientVersion }}'
$BaseUrl       = '{{ $baseUrl }}'
$EnvVarName    = '{{ $envVar }}'

@verbatim
#Requires -Version 5.1
$ErrorActionPreference = 'Stop'
$allowed = @('Unrestricted','RemoteSigned','Bypass')
if ((Get-ExecutionPolicy).ToString() -notin $allowed) {
  throw "PowerShell needs an execution policy in [$($allowed -join ', ')].`nRun: Set-ExecutionPolicy RemoteSigned -Scope CurrentUser  (or use the -ExecutionPolicy ByPass one-liner)"
}
$Home_ = $HOME                       # = USERPROFILE
$Cfg  = Join-Path $Home_ ".config\$Ns"
$Venv = Join-Path $Cfg "venv"
$Bin  = Join-Path $Home_ ".local\bin"
New-Item -ItemType Directory -Force -Path $Cfg, $Bin | Out-Null

# --- Python detection -------------------------------------------------------
# Pick a Python that ACTUALLY RUNS and has a WORKING `venv` and `pyexpat`. A
# bare `python`/`python3` on PATH can be the Microsoft Store's App Execution
# Alias stub, which does nothing but pop the Store -- rejecting anything
# resolved from WindowsApps and probing `import venv` catches both that and a
# too-old interpreter. `pyexpat` is probed too: pip imports `xml.parsers.expat`
# -> `pyexpat`, and an interpreter whose pyexpat fails to load breaks EVERY pip
# operation while `import venv` still succeeds -- a real, field-diagnosed
# broken-stdlib build. Versioned `py -3.x` candidates are probed so a healthy
# interpreter is found even when the default is broken or too old.
function Find-Python {
  $cands = @()
  if ($env:SLAYER_PYTHON) { $cands += $env:SLAYER_PYTHON }
  $cands += @('py -3', 'py -3.13', 'py -3.12', 'py -3.11', 'py -3.10', 'python', 'python3')
  # Two passes. %LOCALAPPDATA%\Microsoft\WindowsApps holds BOTH the Store stub
  # that only offers to install Python AND the working shims of the Python
  # Install Manager (real interpreter under \AppData\Local\Python\pythoncore-*),
  # so the path cannot tell them apart -- only the probe below can. A real
  # install elsewhere is still preferred, hence store aliases come second.
  # The stub only opens the Store when run with NO arguments; the probe always
  # passes -c, so it just exits non-zero and falls through.
  foreach ($allowStoreAlias in @($false, $true)) {
  foreach ($c in $cands) {
    $exe, $arg = ($c -split ' ',2)
    $resolved = (Get-Command $exe -ErrorAction SilentlyContinue)
    if (-not $resolved) { continue }
    $path = $resolved.Source
    $isStoreAlias = [bool]($path -and $path -match 'WindowsApps')
    if ($isStoreAlias -ne $allowStoreAlias) { continue }
    # Build the probe's argument list explicitly rather than passing $arg
    # directly -- $arg is $null for the bare `python`/`python3` candidates,
    # and passing $null as a native-command argument is version-dependent in
    # PowerShell (see $PyPrefix below for the same fix applied after
    # resolution).
    $probeArgs = @()
    if ($arg) { $probeArgs += $arg }
    $probeArgs += @('-c', "import sys,venv,pyexpat; print('%d.%d' % sys.version_info[:2])")
    $probe = & $exe @probeArgs 2>$null
    if ($LASTEXITCODE -eq 0 -and $probe) {
      $maj,$min = $probe.Trim() -split '\.'
      if ([int]$maj -gt 3 -or ([int]$maj -eq 3 -and [int]$min -ge 10)) {
        return ,@($exe, $arg)
      }
    }
  }
  }
  throw "No usable Python >= 3.10 with a working venv and pyexpat was found. Install from https://www.python.org/downloads/ or 'winget install Python.Python.3.12', then re-run -- or set SLAYER_PYTHON to a specific interpreter.`n(If 'python' opens the Microsoft Store, disable the App Execution Alias or install real Python.)"
}
# Index the returned pair rather than destructuring it. Find-Python returns
# `,@($exe, $arg)`: the leading comma deliberately keeps the pair intact as a
# SINGLE output object, so `$a, $b = Find-Python` would bind the whole array
# to $a and leave $b $null -- and `& $a` then stringifies the array into one
# literal command name ("py -3"), which does not exist.
$Py    = Find-Python
$PyExe = $Py[0]
$PyArg = $Py[1]
# $PyArg is the optional version selector for the resolved interpreter (e.g.
# "-3.12" for a versioned `py` launch), $null for a bare `python`/`python3`
# resolution. Splat $PyPrefix wherever $PyArg would otherwise be passed
# directly as a native-command argument -- passing $null itself is
# version-dependent in PowerShell and can surface as an empty string (""),
# turning `python -m venv` into `python "" -m venv`, which fails. This makes
# an empty argument impossible by construction at every call site.
$PyPrefix = @()
if ($PyArg) { $PyPrefix = @($PyArg) }

# --- jq bootstrap ------------------------------------------------------------
# Every machine uses this exact pinned jq.exe -- NEVER a system jq -- so
# cross-platform/cross-version differences in jq's own behavior can never
# again produce silently wrong token/account attribution. Mirrors the POSIX
# installer's jq bootstrap; see that file for the full rationale.
$JqVersion = '1.8.2'
$JqDir = Join-Path $Cfg 'bin'
$JqBin = Join-Path $JqDir 'jq.exe'
New-Item -ItemType Directory -Force -Path $JqDir | Out-Null

$jqAssets = @{
  'AMD64' = @{ Name = 'jq-windows-amd64.exe'; Sha256 = 'a6fc67fedaf9128a3309a1e2ebb8b986aeccf70122ee46d2cb4849e423f0c627' }
  'ARM64' = @{ Name = 'jq-windows-arm64.exe'; Sha256 = '083b5377392bc57cf27052b6d20a2d927770683bca844632901ff38b4b7b0ac7' }
}
$jqArch = $env:PROCESSOR_ARCHITECTURE
# Under WOW64 (32-bit PowerShell running on a 64-bit machine -- some
# corporate images default to "Windows PowerShell (x86)"), PROCESSOR_ARCHITECTURE
# reports the process architecture (x86), not the real hardware; the true
# architecture is in PROCESSOR_ARCHITEW6432 in that case.
if ($jqArch -eq 'x86' -and $env:PROCESSOR_ARCHITEW6432) { $jqArch = $env:PROCESSOR_ARCHITEW6432 }
if (-not $jqAssets.ContainsKey($jqArch)) {
  throw "No pinned jq build for Windows/$jqArch -- token-slayer cannot install without a known-good jq for this platform. Open an issue with this OS/arch."
}
$jqAsset = $jqAssets[$jqArch]

function Get-FileSha256Hex($path) {
  (Get-FileHash -LiteralPath $path -Algorithm SHA256).Hash.ToLowerInvariant()
}

# Self-heal: an existing binary that doesn't match the pinned checksum (a
# partial download from a prior failed run, or a version bump) is rebuilt,
# not trusted. A binary that already matches is left untouched.
if (Test-Path $JqBin) {
  if ((Get-FileSha256Hex $JqBin) -ne $jqAsset.Sha256) { Remove-Item -Force $JqBin }
}

if (-not (Test-Path $JqBin)) {
  $jqUrl = "https://github.com/jqlang/jq/releases/download/jq-$JqVersion/$($jqAsset.Name)"
  $jqTmp = Join-Path $JqDir '.jq.download'
  try {
    Invoke-WebRequest -UseBasicParsing -Uri $jqUrl -OutFile $jqTmp
  } catch {
    throw "Could not download jq from $jqUrl -- check your network connection and re-run. ($($_.Exception.Message))"
  }
  $downloadedSha = Get-FileSha256Hex $jqTmp
  if ($downloadedSha -ne $jqAsset.Sha256) {
    Remove-Item -Force $jqTmp -ErrorAction SilentlyContinue
    throw "Downloaded jq checksum mismatch -- expected $($jqAsset.Sha256), got $downloadedSha. Refusing to install a binary that doesn't match. Re-run to retry the download."
  }
  Move-Item -Force $jqTmp $JqBin
}

# --- venv + PEP668 + get-pip fallback (Windows `Scripts\` layout) -----------
# PIP_BREAK_SYSTEM_PACKAGES=1: pip's official override for the
# EXTERNALLY-MANAGED marker some python.org/winget builds ship; safe here
# because every pip action below targets our OWN dedicated venv, never the
# system Python.
$env:PIP_BREAK_SYSTEM_PACKAGES = '1'
$VenvPy = Join-Path $Venv 'Scripts\python.exe'

# Self-heal: a venv left over from a failed run or a since-removed/broken
# interpreter can have a working-looking directory but no importable
# slayer_cli. Rebuild it clean instead of reusing it, so simply re-running
# the installer (or `token-slayer update`) repairs a broken install. A
# healthy venv where slayer_cli already imports is left untouched, keeping
# updates fast. Guarded so a missing $VenvPy (a never-created or
# half-deleted venv) can never throw here.
if (Test-Path $Venv) {
  $venvHealthy = $false
  if (Test-Path $VenvPy) {
    try {
      & $VenvPy -c 'import slayer_cli' 2>$null
      $venvHealthy = ($LASTEXITCODE -eq 0)
    } catch { $venvHealthy = $false }
  }
  if (-not $venvHealthy) { Remove-Item -Recurse -Force $Venv -ErrorAction SilentlyContinue }
}

# A failure anywhere in this chain now stops the whole install: a broken
# slayer-cli venv used to be tolerated so a Python problem never bricked hook
# tracking, but every install step is now required to actually work.
& $PyExe @PyPrefix -m venv $Venv
if (-not (Test-Path $VenvPy)) {
  Write-Warning 'slayer-cli: bundled pip bootstrap failed; retrying with get-pip...'
  Remove-Item -Recurse -Force $Venv -ErrorAction SilentlyContinue
  & $PyExe @PyPrefix -m venv --without-pip $Venv
  if (-not (Test-Path $VenvPy)) {
    throw "slayer-cli: 'python -m venv --without-pip' failed -- see the error above."
  }
  (Invoke-WebRequest -UseBasicParsing https://bootstrap.pypa.io/get-pip.py).Content | & $VenvPy -
}
if (-not (Test-Path (Join-Path $Venv 'Scripts\pip.exe'))) {
  throw 'slayer-cli: venv/pip setup failed -- see the error above.'
}

# The wheel route requires a valid hook token. Resolve it: the env var
# passed on the install one-liner, else the token saved by a previous
# install, so `token-slayer update` (which re-runs this script with no env
# var) still works. Never echoed.
$slayerToken = [Environment]::GetEnvironmentVariable($EnvVarName)
if (-not $slayerToken) {
  $wheelTokenFile = Join-Path $Cfg 'token'
  if ((Test-Path $wheelTokenFile) -and (Get-Item $wheelTokenFile).Length -gt 0) {
    $slayerToken = (Get-Content -Raw -LiteralPath $wheelTokenFile).Trim()
  }
}

$whl = Join-Path $env:TEMP 'slayer_cli-0.0.0-py3-none-any.whl'
# Invoke-WebRequest throws on a non-2xx status, and -SkipHttpErrorCheck
# does not exist on PowerShell 5.1 (this script requires 5.1), so the
# status is read from the exception in the catch. A request that never
# got a response at all (DNS/TLS failure, no connectivity) has no
# .Response and is treated as 0; the exception message is kept so a
# transport failure is diagnosable instead of just "0".
$slayerHttp = 0
$slayerErr = $null
try {
  Invoke-WebRequest -UseBasicParsing -Uri $WheelUrl -OutFile $whl `
    -Headers @{ Authorization = "Bearer $slayerToken" } | Out-Null
  $slayerHttp = 200
} catch {
  $slayerErr = $_.Exception.Message
  if ($_.Exception.Response) { $slayerHttp = [int]$_.Exception.Response.StatusCode } else { $slayerHttp = 0 }
}

if ($slayerHttp -eq 200) {
  # Two steps on purpose: the served wheel is always "latest" and its version
  # may be UNCHANGED between builds, so a plain install is a no-op and ships
  # stale code. First install pulls deps; force-reinstall --no-deps refreshes
  # only the package code every time, cheaply (deps untouched). pip's own
  # exit code is checked explicitly -- a native exe's non-zero exit is NOT a
  # terminating error under $ErrorActionPreference='Stop' the way a cmdlet's
  # is, so a failed install would otherwise be silently ignored.
  & $VenvPy -m pip install --quiet $whl
  if ($LASTEXITCODE -ne 0) { throw 'slayer-cli: wheel install failed -- see the error above.' }
  & $VenvPy -m pip install --quiet --force-reinstall --no-deps $whl
  if ($LASTEXITCODE -ne 0) { throw 'slayer-cli: wheel force-reinstall failed -- see the error above.' }
} elseif ($slayerHttp -eq 401) {
  throw 'slayer-cli: your token is missing or no longer valid. Open your token-slayer profile page, click Regenerate token, and re-run the install command it shows.'
} else {
  throw "slayer-cli: could not download the CLI (server said $slayerHttp): $slayerErr"
}
Remove-Item $whl -ErrorAction SilentlyContinue

# --- Shims (3 .cmd, absolute venv path) -------------------------------------
# Absolute $VenvPy avoids %~dp0 layout coupling.
# The two env vars mirror what the POSIX shim execs through: without
# SLAYER_INSTALL_URL, `token-slayer update` aborts before re-running the
# installer, so the hook can never be refreshed on Windows. `setlocal` keeps
# both inside this cmd process instead of leaking into the machine.
# CRLF throughout: cmd.exe is not reliable on LF-only batch files.
$shim = (@(
  '@echo off',
  'setlocal',
  "set `"SLAYER_NS=$Ns`"",
  "set `"SLAYER_INSTALL_URL=$InstallUrl`"",
  "`"$VenvPy`" -m slayer_cli %*"
) -join "`r`n") + "`r`n"
foreach ($n in 'tok','slayer','token-slayer') {
  Set-Content -Path (Join-Path $Bin "$n.cmd") -Value $shim -Encoding Ascii -NoNewline
}

# --- PATH -- uv registry pattern + broadcast --------------------------------
function Add-Path($dir) {
  if ($env:SLAYER_NO_MODIFY_PATH) { return }
  $reg = 'registry::HKEY_CURRENT_USER\Environment'
  $cur = (Get-Item -LiteralPath $reg).GetValue('Path','','DoNotExpandEnvironmentNames') -split ';' -ne ''
  if ($dir -in $cur) { return }
  Set-ItemProperty -Type ExpandString -LiteralPath $reg Path ((,$dir + $cur) -join ';')
  $d = 'slayer-' + [guid]::NewGuid().ToString()
  [Environment]::SetEnvironmentVariable($d,'x','User'); [Environment]::SetEnvironmentVariable($d,[NullString]::value,'User')
}
Add-Path $Bin
$env:Path = "$Bin;$env:Path"     # current shell sees it now

# --- Token, hooks (reuse the sh installer's Python merge logic), Git-Bash ---
# warning, detect-base, final tip.
#
# The hooks all shell out to send-hook.sh via bash (Git for Windows / Git
# Bash), same as the POSIX installer -- "shell":"bash" tells Claude Code to
# run the command through bash rather than cmd.exe. bash on Windows (MSYS)
# is happiest with forward slashes even for a native drive-letter path, so
# the bash-facing command strings use a slash-converted copy of $Helper;
# every other path in this script stays native Windows (backslash) form.
$Helper = Join-Path $Cfg 'send-hook.sh'
$ChecksumFile = Join-Path $Cfg '.hook-checksum'
$HelperBash = $Helper -replace '\\','/'

# `Get-Command bash` is unusable for finding Git Bash: on Windows it resolves
# C:\Windows\System32\bash.exe -- the WSL launcher -- long before Git Bash, and
# on a machine with no or broken WSL distro that binary fails outright. Resolve
# the real thing by known install locations and from git.exe's own path, and
# reject anything under System32. Returns $null when Git Bash is absent.
function Find-GitBash {
  $cands = @()
  if ($env:CLAUDE_CODE_GIT_BASH_PATH) { $cands += $env:CLAUDE_CODE_GIT_BASH_PATH }
  $git = Get-Command git -ErrorAction SilentlyContinue
  if ($git -and $git.Source) {
    # ...\Git\cmd\git.exe -> ...\Git\bin\bash.exe
    $cands += (Join-Path (Split-Path (Split-Path $git.Source -Parent) -Parent) 'bin\bash.exe')
  }
  foreach ($base in @($env:ProgramFiles, ${env:ProgramFiles(x86)})) {
    if ($base) { $cands += (Join-Path $base 'Git\bin\bash.exe') }
  }
  if ($env:LOCALAPPDATA) { $cands += (Join-Path $env:LOCALAPPDATA 'Programs\Git\bin\bash.exe') }
  foreach ($c in $cands) {
    if (-not $c) { continue }
    if ($c -match '\\System32\\') { continue }
    if (Test-Path -LiteralPath $c) { return $c }
  }
  return $null
}
$gitBash = Find-GitBash

# The hook commands invoke bash by ABSOLUTE path when Git Bash was found: a
# bare `bash` resolves through PATH, where the System32 WSL launcher shadows
# Git Bash. (Claude Code's own outer shell lookup is separate -- point it at
# the same binary with CLAUDE_CODE_GIT_BASH_PATH if it picks the wrong one.)
$BashExe = if ($gitBash) { ($gitBash -replace '\\','/') } else { 'bash' }

function Get-Sha256Hex($text) {
  $sha = [System.Security.Cryptography.SHA256]::Create()
  try {
    $bytes = [System.Text.Encoding]::UTF8.GetBytes($text)
    ($sha.ComputeHash($bytes) | ForEach-Object { $_.ToString('x2') }) -join ''
  } finally { $sha.Dispose() }
}

# Bundled detector config (data, not code): tells the hook where a
# locally-run proxy already logs session/account, so token-slayer can
# attribute without modifying the proxy. Overwritten on every install so new
# entries ship centrally.
$detectorConfig = @'
{
  "teamclaude": { "join": "session", "logs": ["~/.config/teamclaude/requests/*.log"], "account_pattern": "account: ([^[:space:]]+)" },
  "claudehub":  { "join": "ts_tokens", "logs": ["~/.config/claudehub/stats.jsonl"], "account_field": "account_name", "ts_field": "ts" },
  "auth2api":   { "join": "ts_tokens", "logs": ["~/.config/auth2api/stats.jsonl"], "account_field": "accountEmail", "ts_field": "ts" }
}
'@
Set-Content -Path (Join-Path $Cfg 'detector-config.json') -Value $detectorConfig -Encoding Ascii

# If an existing send-hook.sh no longer matches the checksum of the last
# stock install (or predates checksum tracking entirely), assume the user
# hand-edited it and back it up before we overwrite it below.
$hookBackup = $null
if (Test-Path $Helper) {
  $oldSha = Get-Sha256Hex (Get-Content -Raw $Helper)
  $storedSha = if (Test-Path $ChecksumFile) { (Get-Content -Raw $ChecksumFile).Trim() } else { '' }
  if (-not $storedSha -or $oldSha -ne $storedSha) {
    $hookBackup = "$Helper.bak.$(Get-Date -Format yyyyMMddHHmmss)"
    Copy-Item $Helper $hookBackup
  }
}

# Same send-hook.sh content as the POSIX installer, verbatim -- it runs
# under bash (Git Bash) regardless of host OS. __TS_*__ placeholders are
# substituted below via plain .NET string replace (no shell interpolation,
# so bash's own $(...) / $VAR syntax inside this template is left alone).
$hookShTemplate = @'
#!/usr/bin/env bash
set -u

API_URL='__TS_BASE_URL__'
TOKEN_FILE="$HOME/.config/__TS_NAMESPACE__/token"

BODY=$(cat)
[ -r "$TOKEN_FILE" ] || exit 0

# Always the pinned binary installed by the bootstrap above -- never a
# system jq -- so hook behavior can never drift between machines.
JQ="$HOME/.config/__TS_NAMESPACE__/bin/jq.exe"

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
    if [ "${PROVIDER:-}" = "codex" ]; then
      USAGE=$("$JQ" -sr '
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
      ' "$TRANSCRIPT" 2>/dev/null)
    else
    USAGE=$("$JQ" -sr '
      . as $a
      | (length - 1) as $end
      | reduce range($end; -1; -1) as $i ({t:0, m:{}, stop:false};
          if .stop then . else
            ($a[$i]) as $e
            | if $e.type == "assistant" or $e.type == "PLANNER_RESPONSE" or $e.source == "MODEL" then
                (($e.message.usage.output_tokens // $e.usage.output_tokens // $e.usage.outputTokens // 0)) as $tok
                | (($e.message.model // $e.model) // null) as $k
                | .t += $tok
                | (if $tok > 0 and $k != null then .m[$k] += $tok else . end)
              elif ($e.type == "USER_INPUT" or $e.source == "USER_EXPLICIT") then
                .stop = true
              elif $e.type == "user"
                   and ((try $e.message.content[0].type catch null) != "tool_result") then
                .stop = true
              else . end
          end)
      | {tokens: .t, models: .m}
    ' "$TRANSCRIPT" 2>/dev/null)
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

CLIENT_VERSION='__TS_CLIENT_VERSION__'
HOOK_UA='token-slayer-hook/__TS_CLIENT_VERSION__ (external, cli)'
NS_DIR="$HOME/.config/__TS_NAMESPACE__"

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
  _pv=""
  if [ -n "${CLAUDE_ACCOUNT_PROVIDER:-}" ] && [ -r "${CLAUDE_ACCOUNT_PROVIDER}" ]; then
    _pv="${CLAUDE_ACCOUNT_PROVIDER}"
  elif [ -n "${SESSION_ID:-}" ] && [ -r "$NS_DIR/account-provider/sessions/$SESSION_ID.json" ]; then
    _pv="$NS_DIR/account-provider/sessions/$SESSION_ID.json"
  elif [ -r "$NS_DIR/account-provider/active.json" ]; then
    _pv="$NS_DIR/account-provider/active.json"
  fi
  [ -n "$_pv" ] || return 1

  _org=$("$JQ" -r '.org_uuid // ""' "$_pv" 2>/dev/null)
  [ -n "$_org" ] || return 1
  ACC_ORG_ID="$_org"
  ACC_EMAIL=$("$JQ" -r '.email // ""' "$_pv" 2>/dev/null)
  ACC_UUID=$("$JQ" -r '.uuid // ""' "$_pv" 2>/dev/null)
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

  # Pre-check: non-Claude providers (codex/antigravity) never carry Claude account claims.
  [ -n "${PROVIDER:-}" ] && return

  # 0. Account Identity Provider (proxy/switcher declares identity) -- highest signal.
  provider_account && return

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
    '. + {client_version: $v} + (if $s != "" then {account_source: $s} else {} end)
       + (if $e != "" then {account_email: $e, account_uuid: $u} else {} end)
       + (if $o != "" then {account_org_id: $o} else {} end)' \
    2>/dev/null || printf '%s' "$BODY")
fi

CUSTOM_SH="$HOME/.config/__TS_NAMESPACE__/custom.sh"
[ -r "$CUSTOM_SH" ] && . "$CUSTOM_SH"

# --- exclude-check hook point (Phase 3) ---
# Reserved: a future dev-owned exclude-accounts.json will let a developer drop
# their own private accounts here (exit 0 before POST) so those events never
# leave the machine. Not active yet -- default is track everything.

# The body goes over stdin, never as an argv argument: Git Bash hands argv to
# the native curl.exe through a Win32 codepage conversion that mangles every
# non-ASCII byte, and the server then drops the event with "Malformed UTF-8".
# A single argument is also capped near 32 KB, well under a long assistant
# message.
printf '%s' "$BODY" | curl -s --max-time 3 -X POST "$URL" \
  -H "Authorization: Bearer $(cat "$TOKEN_FILE")" \
  -H 'Content-Type: application/json' \
  --data-binary @- >/dev/null 2>&1 &
'@
$hookSh = $hookShTemplate.Replace('__TS_BASE_URL__', $BaseUrl).Replace('__TS_NAMESPACE__', $Ns).Replace('__TS_CLIENT_VERSION__', $ClientVersion)
# LF line endings (not CRLF) -- this file is executed by bash.
$hookSh = $hookSh -replace "`r`n", "`n"
[System.IO.File]::WriteAllText($Helper, $hookSh)

Get-Sha256Hex $hookSh | Set-Content -Path $ChecksumFile -Encoding Ascii -NoNewline

# Keep only the 3 most recent backups so a long-lived install doesn't
# accumulate one file per update.
Get-ChildItem -Path $Cfg -Filter 'send-hook.sh.bak.*' -ErrorAction SilentlyContinue |
  Sort-Object LastWriteTime -Descending | Select-Object -Skip 3 |
  Remove-Item -Force -ErrorAction SilentlyContinue

if ($hookBackup) {
  Write-Host ""
  Write-Host "=========================================================="
  Write-Host "WARNING: your existing send-hook.sh had local modifications"
  Write-Host "and has been overwritten by this install."
  Write-Host ""
  Write-Host "  backup saved to: $hookBackup"
  Write-Host ""
  Write-Host "Move your customizations into:"
  Write-Host "  `$HOME\.config\$Ns\custom.sh"
  Write-Host "That file is sourced automatically on every hook run and"
  Write-Host "survives every update -- edits to send-hook.sh itself do not."
  Write-Host "=========================================================="
  Write-Host ""
}

$ClaudeCmd = "`"$BashExe`" `"$HelperBash`""
$CodexCmd  = "PROVIDER=codex `"$BashExe`" `"$HelperBash`""
$AgyCmd    = "PROVIDER=antigravity `"$BashExe`" `"$HelperBash`""

# Save the token now if the caller pre-set it, e.g.
#   $env:TOKEN_SLAYER_TOKEN = "xxx"; iex (irm $InstallUrl)
# NEVER echo the value itself.
$tokenValue = [Environment]::GetEnvironmentVariable($EnvVarName)
$tokenFile = Join-Path $Cfg 'token'
if ($tokenValue) {
  Set-Content -Path $tokenFile -Value $tokenValue -Encoding Ascii -NoNewline
  Write-Host "saved token -> $tokenFile"
}

# Helper: run a Python merge script (from a here-string, written to a temp
# file) through the base interpreter -- NOT the venv -- so hook installation
# still succeeds even when the venv/pip step above failed. Mirrors the sh
# installer's use of "$PY" (never the venv) for these same merges.
function Invoke-PyMerge($scriptBody, [string[]]$scriptArgs) {
  $tmp = [System.IO.Path]::GetTempFileName()
  try {
    [System.IO.File]::WriteAllText($tmp, $scriptBody)
    Get-Content -Raw -LiteralPath $tmp | & $PyExe @PyPrefix - @scriptArgs
  } finally {
    Remove-Item -LiteralPath $tmp -ErrorAction SilentlyContinue
  }
}

# --- Claude Code: merge into ~/.claude/settings.json ------------------------
$ClaudeDir = Join-Path $Home_ '.claude'
New-Item -ItemType Directory -Force -Path $ClaudeDir | Out-Null
$Settings = Join-Path $ClaudeDir 'settings.json'
if (-not (Test-Path $Settings) -or (Get-Item $Settings).Length -eq 0) {
  Set-Content -Path $Settings -Value '{}' -Encoding Ascii
}

$env:CLAUDE_CMD = $ClaudeCmd
$env:HOOK_FINGERPRINT = 'send-hook.sh'
Invoke-PyMerge @'
import json, os, sys

path = sys.argv[1]
cmd = os.environ["CLAUDE_CMD"]
events = [
    "SessionStart", "UserPromptSubmit", "PreToolUse", "PostToolUse",
    "Stop", "SubagentStop", "SessionEnd", "Notification",
]

try:
    with open(path) as f:
        data = json.load(f)
    if not isinstance(data, dict):
        raise ValueError("settings.json is not a JSON object")
except (ValueError, OSError):
    # A pre-existing malformed settings.json would otherwise abort the whole
    # installer. Preserve the bad file for inspection and start from an
    # empty object so hook installation still succeeds.
    try:
        os.replace(path, path + ".corrupt-bak")
        sys.stderr.write("warning: %s was invalid JSON; backed up to %s.corrupt-bak and reset\n" % (path, path))
    except OSError:
        pass
    data = {}

data.setdefault("hooks", {})
fingerprint = os.environ["HOOK_FINGERPRINT"]  # substring match filters out our own stale entries
for event in events:
    entries = [e for e in data["hooks"].get(event, [])
               if fingerprint not in json.dumps(e)]
    entries.append({"hooks": [{"type": "command", "command": cmd, "shell": "bash"}]})
    data["hooks"][event] = entries

with open(path, "w") as f:
    json.dump(data, f, indent=2)
    f.write("\n")
'@ @($Settings)

Write-Host "installed Claude Code hooks -> $Settings"

# Register a second, always-on Stop hook that warms the local usage cache
# (independent of auto-switch, which stays opt-in via `token-slayer run`) so
# `token-slayer tui` shows near-real-time quota without waiting on its
# ticker. Appended alongside send-hook.sh's own Stop entry, not replacing it.
#
# Invokes the venv directly with an explicit SLAYER_NS, like `detect-base`
# below -- NOT the .cmd shims. Those are shared per-machine files, so a
# machine with more than one namespace installed would have this hook
# silently refresh the wrong one.
$UsageRefreshCmd = "SLAYER_NS=$Ns `"$VenvPy`" -m slayer_cli hook usage-refresh"
$env:CLAUDE_CMD = $UsageRefreshCmd
$env:HOOK_FINGERPRINT = 'hook usage-refresh'
Invoke-PyMerge @'
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

with open(path, "w") as f:
    json.dump(data, f, indent=2)
    f.write("\n")
'@ @($Settings)

Write-Host "installed Claude Code usage-refresh hook -> $Settings"

# Register a third, always-on SessionStart hook that tracks this session for
# the `token-slayer tui` Sessions page (independent of auto-switch, same as
# the usage-refresh Stop hook above) -- so a user sees their session there
# without needing the hidden install-hooks auto-switch command. Appended
# alongside send-hook.sh's own SessionStart entry, not replacing it.
$SessionTrackCmd = "SLAYER_NS=$Ns `"$VenvPy`" -m slayer_cli hook session-track-start"
$env:CLAUDE_CMD = $SessionTrackCmd
$env:HOOK_FINGERPRINT = 'hook session-track-start'
Invoke-PyMerge @'
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

with open(path, "w") as f:
    json.dump(data, f, indent=2)
    f.write("\n")
'@ @($Settings)

Write-Host "installed Claude Code session-tracking hook -> $Settings"

# --- Codex CLI: rewrite the namespace block in ~/.codex/config.toml --------
$CodexDir = Join-Path $Home_ '.codex'
New-Item -ItemType Directory -Force -Path $CodexDir | Out-Null
$CodexConfig = Join-Path $CodexDir 'config.toml'
if (-not (Test-Path $CodexConfig)) { New-Item -ItemType File -Path $CodexConfig | Out-Null }

# Remove any previous namespace block (between markers) so we can append a fresh one.
$env:NAMESPACE = $Ns
Invoke-PyMerge @'
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

with open(path, "w") as f:
    f.write(text)
'@ @($CodexConfig)

$codexBlock = @"
# >>> $Ns hooks
[[hooks]]
event = "session_start"
command = "$CodexCmd"

[[hooks]]
event = "stop"
command = "$CodexCmd"
# <<< $Ns hooks
"@
Add-Content -Path $CodexConfig -Value $codexBlock -Encoding Ascii

Write-Host "installed Codex CLI hooks -> $CodexConfig"

# --- Antigravity CLI: merge into ~/.gemini/config/hooks.json ---------------
$GeminiDir = Join-Path $Home_ '.gemini\config'
New-Item -ItemType Directory -Force -Path $GeminiDir | Out-Null
$AgyHooks = Join-Path $GeminiDir 'hooks.json'
if (-not (Test-Path $AgyHooks) -or (Get-Item $AgyHooks).Length -eq 0) {
  Set-Content -Path $AgyHooks -Value '{}' -Encoding Ascii
}

$env:AGY_CMD = $AgyCmd
$env:NAMESPACE = $Ns
Invoke-PyMerge @'
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

# Events with matchers (tool hooks)
for event in ["PreToolUse", "PostToolUse"]:
    ns_data[event] = [{
        "matcher": "*",
        "hooks": [{"type": "command", "command": cmd}]
    }]

with open(path, "w") as f:
    json.dump(data, f, indent=2)
    f.write("\n")
'@ @($AgyHooks)

Write-Host "installed Antigravity CLI hooks -> $AgyHooks"

# --- Git for Windows check ---------------------------------------------------
# Every hook command shells out via bash (Git Bash); without it nothing this
# installer just set up can ever run, so this stops the install rather than
# just warning. Deliberately keyed on Find-GitBash (see $gitBash above), not
# `Get-Command bash`: the WSL launcher in System32 answers to `bash` and would
# silence this check on a machine that cannot actually run the hooks.
if (-not $gitBash) {
  throw 'Attribution hooks need Git for Windows (Git Bash) to run -- a WSL bash does not count. Install Git for Windows (winget install Git.Git), then re-run this installer.'
}

# --- Register the machine's current Claude login as a base account slot ----
# (best-effort, never blocks the install)
if (Test-Path $VenvPy) {
  $env:SLAYER_NS = $Ns
  try { & $VenvPy -m slayer_cli detect-base 2>$null | Out-Null } catch { }
}

Set-Content -Path (Join-Path $Cfg 'version') -Value $ClientVersion -Encoding Ascii -NoNewline

if (-not $tokenValue -and (-not (Test-Path $tokenFile) -or (Get-Item $tokenFile).Length -eq 0)) {
  Write-Host ""
  Write-Host "Next: save your token from the profile page into `$HOME\.config\$Ns\token."
}

Write-Host ""
Write-Host "Tip: create `$HOME\.config\$Ns\custom.sh to customize what your fighter shows -- it survives every install and update."
Write-Host ""
Write-Host "Open a NEW terminal so the updated PATH takes effect, then run: tok"
@endverbatim
