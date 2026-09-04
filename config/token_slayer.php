<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Hook version
    |--------------------------------------------------------------------------
    |
    | The version of the hook shipped by THIS repo. Deliberately separate from
    | the slayer-cli release tag: that tag lives in a repo this project does not
    | publish, so a hook-only change would otherwise ship with an unchanged
    | version and every update prompt would silently no-op. Bump this whenever
    | the hook template changes in a way clients must pick up.
    |
    */

    'hook_version' => (string) env('TOKEN_SLAYER_HOOK_VERSION', '5'),

    /*
    |--------------------------------------------------------------------------
    | Anthropic OAuth (server-side quota probing)
    |--------------------------------------------------------------------------
    |
    | Constants for the per-account PKCE OAuth grant the server holds to call
    | the free usage/profile APIs. The client id is Anthropic's public OAuth
    | client for Claude subscriptions — not a secret.
    |
    | `user_agent` is load-bearing: platform.claude.com's WAF returns a bare
    | 429 (no Retry-After) for the default Guzzle/curl/browser User-Agent, so
    | every request must send a claude-cli-style UA instead. `redirect_uri`
    | is the manual/paste PKCE callback used by the connect flow.
    |
    */

    'anthropic' => [
        'oauth_client_id' => env('ANTHROPIC_OAUTH_CLIENT_ID', '9d1c250a-e61b-44d9-88ed-5944d1962f5e'),
        'token_endpoint' => 'https://platform.claude.com/v1/oauth/token',
        'usage_endpoint' => 'https://api.anthropic.com/api/oauth/usage',
        'profile_endpoint' => 'https://api.anthropic.com/api/oauth/profile',
        'messages_endpoint' => 'https://api.anthropic.com/v1/messages',
        'version_header' => '2023-06-01',
        'beta_header' => 'oauth-2025-04-20',
        'user_agent' => env('ANTHROPIC_USER_AGENT', 'claude-cli/2.1.206 (external, cli)'),
        'redirect_uri' => env('ANTHROPIC_OAUTH_REDIRECT_URI', 'https://platform.claude.com/oauth/code/callback'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Session anchoring
    |--------------------------------------------------------------------------
    |
    | A minimal real inference (max_tokens 1) is sent to each account at fixed
    | clock times so Anthropic's rolling 5-hour usage window starts then. A
    | 0-token usage/beacon call does NOT start a session, so this must be a
    | genuine (tiny) message. `model` is the cheapest valid model to bill the
    | one token against.
    |
    */

    'session_anchor' => [
        'model' => env('TOKEN_SLAYER_SESSION_ANCHOR_MODEL', 'claude-haiku-4-5-20251001'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Quota probing cadence
    |--------------------------------------------------------------------------
    */

    'probe' => [
        'refresh_headroom_hours' => (int) env('TOKEN_SLAYER_PROBE_HEADROOM_HOURS', 4),
    ],

    /*
    |--------------------------------------------------------------------------
    | Usage snapshot retention
    |--------------------------------------------------------------------------
    */

    'snapshots' => [
        'retention_days' => (int) env('TOKEN_SLAYER_SNAPSHOT_RETENTION_DAYS', 30),
    ],

];
