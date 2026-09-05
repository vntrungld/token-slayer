<?php

use Illuminate\Support\Facades\Http;

beforeEach(fn () => config(['app.hook_namespace' => 'token_slayer']));

test('install.ps1 is publicly accessible as plain text', function () {
    $response = $this->get(route('install-script-ps1'));

    $response->assertOk();
    expect($response->headers->get('Content-Type'))->toStartWith('text/plain');
});

test('install.ps1 renders every blade directive away', function () {
    // The body is wrapped in @verbatim so PowerShell's own $vars survive; a
    // mistake there ships raw blade to the user instead of a runnable script.
    $script = $this->get(route('install-script-ps1'))->content();

    expect($script)
        ->not->toContain('@php')
        ->not->toContain('@endphp')
        ->not->toContain('@verbatim')
        ->not->toContain('@endverbatim')
        ->not->toContain('{{')
        ->not->toContain('{!!');
});

test('install.ps1 stamps the namespace into the header variables', function () {
    $script = $this->get(route('install-script-ps1'))->content();

    expect($script)
        ->toContain("\$Ns            = 'token_slayer'")
        ->toContain("\$EnvVarName    = 'TOKEN_SLAYER_TOKEN'")
        ->toContain(url('/api/events'));
});

test('install.ps1 points its own update URL back at itself, not at the POSIX installer', function () {
    // `token-slayer update` re-fetches $InstallUrl. On Windows that must be the
    // PowerShell script — pointing at /install would hand a Windows box a sh
    // script it cannot run.
    $script = $this->get(route('install-script-ps1'))->content();

    expect($script)->toContain("\$InstallUrl    = '".route('install-script-ps1')."'");
});

test('install.ps1 requires a Python that has a working venv and pyexpat', function () {
    $script = $this->get(route('install-script-ps1'))->content();

    expect($script)
        ->toContain('import sys,venv,pyexpat')
        ->toContain('py -3.12')
        ->toContain('py -3.10');
});

test('install.ps1 skips the Microsoft Store python stub', function () {
    // A bare `python` that resolves into WindowsApps is the Store alias: it
    // opens the Store instead of running, so it must never be selected.
    $script = $this->get(route('install-script-ps1'))->content();

    expect($script)->toContain('WindowsApps');
});

test('install.ps1 reads the Find-Python pair by index rather than destructuring it', function () {
    // Regression guard. Find-Python returns `,@($exe, $arg)` — one object, on
    // purpose. `$PyExe, $PyArg = Find-Python` therefore bound the whole array
    // to $PyExe, and `& $PyExe` stringified it into the literal command name
    // "py -3", which broke the install on every host with the py launcher.
    $script = $this->get(route('install-script-ps1'))->content();

    expect($script)
        ->toContain('$Py    = Find-Python')
        ->toContain('$PyExe = $Py[0]')
        ->toContain('$PyArg = $Py[1]')
        ->not->toContain('$PyExe, $PyArg = Find-Python');
});

test('install.ps1 installs all three command shims', function () {
    $script = $this->get(route('install-script-ps1'))->content();

    expect($script)->toContain("foreach (\$n in 'tok','slayer','token-slayer')");
});

test('install.ps1 authenticates the wheel download with a bearer token and never embeds one', function () {
    $script = $this->get(route('install-script-ps1'))->content();

    expect($script)
        ->toContain('Authorization = "Bearer $slayerToken"')
        ->toContain(route('slayer-wheel'));

    // The token is supplied at run time (env var or token file) — a served
    // script that carried one would leak it to everyone who fetches the URL.
    expect($script)->not->toMatch('/sk-ant-[A-Za-z0-9_-]+/');
});

test('install.ps1 routes the hooks through bash for Git-for-Windows users', function () {
    $script = $this->get(route('install-script-ps1'))->content();

    expect($script)
        ->toContain('"shell": "bash"')
        ->toContain('Git for Windows');
});

it('stops the install with a throw when Git for Windows is missing, not just a warning', function () {
    // Regression guard for the Write-Warning -> throw conversion: asserting only
    // 'Git for Windows' text would still pass even if the code reverted to
    // Write-Warning (which logs but lets the install continue despite every
    // hook being unable to run without bash).
    $script = $this->get(route('install-script-ps1'))->content();

    expect($script)
        ->toContain("throw 'Attribution hooks need Git for Windows")
        ->not->toContain("Write-Warning 'Attribution hooks need Git for Windows")
        ->not->toContain('Write-Warning "Attribution hooks need Git for Windows');
});

it('resolves Git Bash by known install path instead of Get-Command bash', function () {
    // `Get-Command bash` finds C:\Windows\System32\bash.exe (the WSL launcher)
    // first, which is unusable for running the hooks and can fail outright on
    // a box with no or broken WSL distro.
    $script = $this->get(route('install-script-ps1'))->content();

    expect($script)
        ->toContain('function Find-GitBash')
        ->toContain("if (\$c -match '\\\\System32\\\\') { continue }")
        ->toContain('$gitBash = Find-GitBash');
});

it('writes hook commands that invoke Git Bash by absolute path, not a bare bash', function () {
    // A bare `bash` in the hook command resolves through PATH, where the WSL
    // launcher in System32 shadows Git Bash.
    $script = $this->get(route('install-script-ps1'))->content();

    expect($script)
        ->toContain('$BashExe = if ($gitBash) { ($gitBash -replace \'\\\\\',\'/\') } else { \'bash\' }')
        ->toContain('$ClaudeCmd = "`"$BashExe`" `"$HelperBash`""')
        ->not->toContain('$ClaudeCmd = "bash `"$HelperBash`""');
});

it('stamps the resolver-derived client version into install.ps1', function () {
    config(['github.token' => 'ghp_test', 'github.cli_repo' => 'acme/slayer-cli']);
    Http::fake(['api.github.com/*' => Http::response([
        'tag_name' => 'v1.2.3',
        'assets' => [['id' => 1, 'name' => 'slayer_cli-latest.whl']],
    ], 200)]);

    $script = $this->get(route('install-script-ps1'))->content();

    expect($script)->toContain("\$ClientVersion = '1.2.3'");
});

it('stamps an empty client version into install.ps1 when the release cannot be resolved', function () {
    Http::fake(['api.github.com/*' => Http::response(['message' => 'down'], 500)]);

    $script = $this->get(route('install-script-ps1'))->content();

    // Fail-soft: an empty stamp still yields a runnable script.
    expect($script)->toContain("\$ClientVersion = ''");
});

it('pipes the event body into curl over stdin in the bundled hook, not as an argv argument', function () {
    // The hook this installer writes is the one Windows actually runs, so it
    // is the file where the argv codepage conversion corrupts every non-ASCII
    // byte of the payload — see the matching test for the POSIX installer.
    $script = $this->get(route('install-script-ps1'))->content();

    expect($script)
        ->toContain('printf \'%s\' "$BODY" | curl -sf --max-time 3 -X POST "$URL"')
        ->toContain('--data-binary @-')
        ->not->toContain('-d "$BODY"');
});

it('never falls back to a system jq inside the Windows hook -- every jq call resolves to the bundled jq.exe', function () {
    $script = $this->get(route('install-script-ps1'))->content();

    expect($script)
        ->not->toContain('command -v jq')
        ->toContain('JQ="$HOME/.config/__TS_NAMESPACE__/bin/jq.exe"');

    $resolverPos = strpos($script, 'JQ="$HOME/.config/__TS_NAMESPACE__/bin/jq.exe"');
    $firstJqCallPos = strpos($script, '"$JQ" -r \'.transcript_path');
    expect($resolverPos)->not->toBeFalse()
        ->and($firstJqCallPos)->not->toBeFalse()
        ->and($resolverPos)->toBeLessThan($firstJqCallPos);
});

it('guards jq calls in the Windows hook template with -x (executable check), not the always-true -n', function () {
    // $JQ is always assigned a literal path string, so `[ -n "$JQ" ]` (non-empty
    // string) is always true regardless of whether that file actually exists or
    // is executable -- it provided none of the "defensive against a manually
    // deleted binary" protection it was meant to. `-x` actually tests existence
    // + executability.
    $script = $this->get(route('install-script-ps1'))->content();

    expect($script)
        ->not->toContain('[ -n "$JQ" ]')
        ->toContain('[ -x "$JQ" ]; then');

    // Four now: the SubagentStop session_id fold-in, transcript enrichment,
    // the post-resolve_account body merge, and the payload filter the
    // Windows hook previously did not have at all.
    expect(substr_count($script, '[ -x "$JQ" ]'))->toBe(4);
});

it('bakes SLAYER_INSTALL_URL and SLAYER_NS into the Windows .cmd shims', function () {
    // The POSIX shim execs through `env SLAYER_NS=… SLAYER_INSTALL_URL=…`;
    // without the same on Windows `token-slayer update` aborts with
    // "SLAYER_INSTALL_URL is not set" and the hook is never refreshed.
    $script = $this->get(route('install-script-ps1'))->content();

    expect($script)
        ->toContain('SLAYER_NS=$Ns')
        ->toContain('SLAYER_INSTALL_URL=$InstallUrl')
        ->toContain('setlocal')
        ->toContain('-m slayer_cli %*');
});

it('falls back to WindowsApps interpreters instead of rejecting them by path', function () {
    // %LOCALAPPDATA%\Microsoft\WindowsApps holds BOTH the Store stub and the
    // working shims of the Python Install Manager, so path alone cannot tell
    // them apart — only the probe can. Real installs are still preferred.
    $script = $this->get(route('install-script-ps1'))->content();

    expect($script)
        ->toContain('foreach ($allowStoreAlias in @($false, $true))')
        ->toContain('WindowsApps')
        ->not->toContain("if (\$path -and \$path -match 'WindowsApps') { continue }");
});

it('bootstraps a pinned jq.exe instead of relying on a system jq', function () {
    $script = $this->get(route('install-script-ps1'))->content();

    expect($script)
        ->toContain("\$JqVersion = '1.8.2'")
        ->toContain("\$JqBin = Join-Path \$JqDir 'jq.exe'")
        ->toContain('jq-windows-amd64.exe')
        ->toContain('a6fc67fedaf9128a3309a1e2ebb8b986aeccf70122ee46d2cb4849e423f0c627')
        ->toContain('jq-windows-arm64.exe')
        ->toContain('083b5377392bc57cf27052b6d20a2d927770683bca844632901ff38b4b7b0ac7')
        ->toContain('https://github.com/jqlang/jq/releases/download/jq-$JqVersion/');

    // Must run before the hook helper (HOOK_SH) template is written.
    $jqBootstrapPos = strpos($script, "\$JqVersion = '1.8.2'");
    $hookWritePos = strpos($script, '$hookShTemplate = @');
    expect($jqBootstrapPos)->not->toBeFalse()
        ->and($hookWritePos)->not->toBeFalse()
        ->and($jqBootstrapPos)->toBeLessThan($hookWritePos);
});

it('throws with a clear message when no pinned jq build exists for this Windows architecture', function () {
    $script = $this->get(route('install-script-ps1'))->content();

    expect($script)
        ->toContain('No pinned jq build for Windows/')
        ->toContain('Open an issue with this OS/arch.');
});

it('falls back to PROCESSOR_ARCHITEW6432 under WOW64 (32-bit PowerShell on a 64-bit machine)', function () {
    // $env:PROCESSOR_ARCHITECTURE reports the PROCESS architecture (x86) under
    // WOW64, not the real hardware -- the true architecture is in
    // PROCESSOR_ARCHITEW6432 in that case. Without this fallback, a user
    // running "Windows PowerShell (x86)" on a 64-bit machine gets a hard
    // "No pinned jq build for Windows/x86" abort despite the machine being
    // perfectly capable.
    $script = $this->get(route('install-script-ps1'))->content();

    expect($script)->toContain("if (\$jqArch -eq 'x86' -and \$env:PROCESSOR_ARCHITEW6432) { \$jqArch = \$env:PROCESSOR_ARCHITEW6432 }");

    $fallbackPos = strpos($script, 'PROCESSOR_ARCHITEW6432');
    $checkPos = strpos($script, '$jqAssets.ContainsKey($jqArch)');
    expect($fallbackPos)->not->toBeFalse()
        ->and($checkPos)->not->toBeFalse()
        ->and($fallbackPos)->toBeLessThan($checkPos);
});

it('verifies the downloaded jq.exe checksum via Get-FileHash before trusting it', function () {
    $script = $this->get(route('install-script-ps1'))->content();

    expect($script)
        ->toContain('Get-FileHash -LiteralPath $path -Algorithm SHA256')
        ->toContain('checksum mismatch');
});

it('throws immediately if the venv or wheel bootstrap fails on Windows, surfacing the real error', function () {
    $script = $this->get(route('install-script-ps1'))->content();

    expect($script)
        ->not->toContain('hook tracking still installed')
        ->not->toContain('CLI unavailable')
        ->toContain('throw "slayer-cli: \'python -m venv --without-pip\' failed -- see the error above."')
        ->toContain("if (-not (Test-Path (Join-Path \$Venv 'Scripts\\pip.exe'))) {\n  throw")
        ->toContain('if ($LASTEXITCODE -ne 0) { throw')
        ->toContain('$slayerErr = $_.Exception.Message');
});
