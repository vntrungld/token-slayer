<?php

namespace App\Services;

use App\Enums\AccountPlan;
use App\Enums\AccountStatus;
use App\Exceptions\AccountConnectException;
use App\Models\Account;
use App\Services\Accounts\PlanResolver;
use App\Services\Connect\ConnectDraft;
use App\Services\Connect\ConnectResolution;
use App\Services\Contracts\AccountDisconnecterContract;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use Throwable;

/**
 * Drives the admin-facing PKCE "Connect" flow that grants token-slayer a
 * server-side OAuth token for an org `Account`: {@see start()} builds the
 * authorize URL the admin opens in their browser, and {@see resolve()}
 * exchanges the code they paste back for tokens, matching the authorized
 * identity against an expected account (per-row re-auth) or against all
 * existing accounts by email/organization uuid (open connect), so an admin
 * cannot accidentally attach a stranger's Claude account to the wrong org
 * account row.
 *
 * Per token-hygiene requirements, raw token material is never logged,
 * exposed in exception messages, or written to `probe_error`.
 */
class AccountConnectService implements AccountDisconnecterContract
{
    /**
     * Cache key prefix for a pending connect attempt, keyed by `state`. The
     * full key is `{PREFIX}{state}`.
     *
     * @var string
     */
    private const string CACHE_KEY_PREFIX = 'account-connect:';

    /**
     * Cache key prefix for the stashed token material of a not-yet-created
     * account, keyed by a random handoff key. The full key is
     * `{PENDING_KEY_PREFIX}{handoffKey}`; the entry is single-use, forgotten
     * as soon as {@see createFromPending()} reads it.
     *
     * @var string
     */
    private const string PENDING_KEY_PREFIX = 'account-connect-pending:';

    /**
     * How long a pending connect attempt's PKCE verifier stays cached
     * before it is considered expired. The entry is also single-use: it is
     * forgotten as soon as {@see resolve()} reads it, whether or not the
     * exchange that follows succeeds.
     *
     * @var int
     */
    private const int CACHE_TTL_MINUTES = 10;

    /**
     * Base URL of Anthropic's PKCE authorize endpoint, verified live from
     * the Claude Code binary (2026-07-10). Do not substitute the older
     * claude.ai host.
     *
     * @var string
     */
    private const string AUTHORIZE_URL = 'https://claude.com/cai/oauth/authorize';

    /**
     * OAuth scopes requested by the connect flow's authorize URL, verified
     * live from the Claude Code binary (2026-07-10).
     *
     * @var array<int, string>
     */
    private const array SCOPES = [
        'org:create_api_key',
        'user:profile',
        'user:inference',
        'user:sessions:claude_code',
        'user:mcp_servers',
        'user:file_upload',
    ];

    /**
     * Build the service with the OAuth client and prober it delegates to.
     *
     * @param  AnthropicOAuthClient  $client  the token/usage/profile API client
     * @param  UsageProber  $prober  the quota prober invoked best-effort after a successful connect
     * @return void
     */
    public function __construct(
        private readonly AnthropicOAuthClient $client,
        private readonly UsageProber $prober,
    ) {}

    /**
     * Start a PKCE connect attempt: generate a verifier, S256 challenge, and
     * random state; cache the verifier under the state key for
     * CACHE_TTL_MINUTES (single-use — invalidated the moment {@see resolve()}
     * reads it); and build the authorize URL the admin opens in their browser.
     * No account is bound here — identity is derived from the authorized
     * profile in {@see resolve()}.
     *
     * @return array{url: string, state: string} the authorize URL and the state to echo back on completion
     */
    public function start(): array
    {
        $verifier = $this->generateVerifier();
        $state = $this->generateState();

        Cache::put(
            self::CACHE_KEY_PREFIX.$state,
            ['verifier' => $verifier],
            now()->addMinutes(self::CACHE_TTL_MINUTES),
        );

        $url = self::AUTHORIZE_URL.'?'.http_build_query([
            'code' => 'true',
            'client_id' => config('token_slayer.anthropic.oauth_client_id'),
            'response_type' => 'code',
            'redirect_uri' => config('token_slayer.anthropic.redirect_uri'),
            'scope' => implode(' ', self::SCOPES),
            'code_challenge' => $this->challengeFor($verifier),
            'code_challenge_method' => 'S256',
            'state' => $state,
        ], '', '&', PHP_QUERY_RFC3986);

        return ['url' => $url, 'state' => $state];
    }

    /**
     * Pull and single-use invalidate the cached PKCE verifier for `$state`,
     * then exchange `$pastedCode` for a raw token array (token.json shape).
     *
     * @param  string  $state  the state returned by {@see start()}
     * @param  string  $pastedCode  the `code#state` (or bare code) the admin pasted
     * @return array<string, mixed> the decoded token response
     *
     * @throws AccountConnectException when the verifier is missing/expired
     */
    public function exchangeForToken(string $state, string $pastedCode): array
    {
        $cacheKey = self::CACHE_KEY_PREFIX.$state;
        $pending = Cache::get($cacheKey);
        Cache::forget($cacheKey);

        if ($pending === null) {
            throw new AccountConnectException('connect_state_expired', 'This connect link expired or was already used. Start again.');
        }

        $code = Str::before($pastedCode, '#');

        return $this->client->exchangeCode($code, $pending['verifier'], $state);
    }

    /**
     * Resolve a pasted PKCE code into a connect outcome. Pulls and single-use
     * invalidates the cached verifier, exchanges the code, and reads the
     * authorized profile. When `$expected` is given (per-row re-auth), the
     * authorized identity MUST match that account or nothing is written; when
     * it is null (open connect), the identity is upserted: an existing account
     * matched by email then organization uuid has its token updated, otherwise
     * a pending draft is returned for the confirm-and-create step.
     *
     * @param  string  $state  the state value returned by {@see start()}
     * @param  string  $pastedCode  the code the admin pasted, either `CODE` or `CODE#STATE`
     * @param  ?Account  $expected  the account this attempt must match (per-row re-auth), or null
     * @return ConnectResolution existing (token updated) or pending (new draft)
     *
     * @throws AccountConnectException 'connect_state_expired' | 'connect_no_identity' | 'connect_identity_mismatch'
     */
    public function resolve(string $state, string $pastedCode, ?Account $expected = null): ConnectResolution
    {
        [$token, $profile, $email, $orgUuid] = $this->exchangeAndIdentify($state, $pastedCode);

        if ($expected !== null) {
            if (! $this->identityMatches($expected, $email, $orgUuid)) {
                throw new AccountConnectException('connect_identity_mismatch', "The Claude account you authorized ({$email}) does not match this account.");
            }

            $this->applyToken($expected, $token, $profile);

            return ConnectResolution::existing($expected);
        }

        $match = $this->findByIdentity($email, $orgUuid);
        if ($match !== null) {
            $this->applyToken($match, $token, $profile);

            return ConnectResolution::existing($match);
        }

        return ConnectResolution::pending($this->stashPending($token, $profile, $email, $orgUuid));
    }

    /**
     * Exchange a pasted PKCE code and verify the authorized identity matches
     * `$account`, WITHOUT writing anything to the account — unlike
     * {@see resolve()}, this never calls {@see applyToken()}, so it never
     * touches the account's own stored probe grant. For callers (e.g. the
     * per-user provisioning flow) that need a verified-identity token but
     * own their own storage of where the grant goes.
     *
     * @param  string  $state  the state value returned by {@see start()}
     * @param  string  $pastedCode  the code the admin pasted, either `CODE` or `CODE#STATE`
     * @param  Account  $account  the account the authorized identity must match
     * @return array<string, mixed> the exchanged token response
     *
     * @throws AccountConnectException 'connect_state_expired' | 'connect_no_identity' | 'connect_identity_mismatch'
     */
    public function exchangeVerifiedToken(string $state, string $pastedCode, Account $account): array
    {
        [$token, , $email, $orgUuid] = $this->exchangeAndIdentify($state, $pastedCode);

        if (! $this->identityMatches($account, $email, $orgUuid)) {
            throw new AccountConnectException('connect_identity_mismatch', "The Claude account you authorized ({$email}) does not match this account.");
        }

        return $token;
    }

    /**
     * Exchange a pasted PKCE code and read the authorized profile's identity.
     * Shared by {@see resolve()} and {@see exchangeVerifiedToken()}.
     *
     * @param  string  $state  the state value returned by {@see start()}
     * @param  string  $pastedCode  the code the admin pasted, either `CODE` or `CODE#STATE`
     * @return array{0: array<string, mixed>, 1: array<string, mixed>, 2: string, 3: ?string} token, profile, email, organization uuid
     *
     * @throws AccountConnectException 'connect_state_expired' | 'connect_no_identity'
     */
    private function exchangeAndIdentify(string $state, string $pastedCode): array
    {
        $token = $this->exchangeForToken($state, $pastedCode);
        $profile = $this->client->profile($token['access_token']);

        $email = $profile['account']['email'] ?? null;
        if ($email === null) {
            throw new AccountConnectException('connect_no_identity', 'Could not read an email from the authorized Claude account.');
        }

        return [$token, $profile, $email, $profile['organization']['uuid'] ?? null];
    }

    /**
     * Create-and-connect a new account from a stashed pending draft, or —
     * if the same identity was created between {@see resolve()} and now
     * (a race) — update that existing row instead of duplicating. Pulls and
     * single-use invalidates the pending stash, writes the grant with the
     * admin-confirmed plan and name plus the stashed raw plan fields (never
     * from admin input), flips the account Active, learns its organization
     * uuid, and best-effort probes it.
     *
     * @param  string  $handoffKey  the draft's handoff key from {@see resolve()}
     * @param  AccountPlan|string  $plan  the admin-confirmed plan: an {@see AccountPlan} instance (Filament's Select auto-casts `options(AccountPlan::class)` state) or its raw value string (direct callers/tests)
     * @param  ?string  $name  the admin-confirmed display name
     * @return Account the created or updated, freshly persisted account
     *
     * @throws AccountConnectException 'connect_state_expired' when the stash is missing/expired
     */
    public function createFromPending(string $handoffKey, AccountPlan|string $plan, ?string $name): Account
    {
        $cacheKey = self::PENDING_KEY_PREFIX.$handoffKey;
        $pending = Cache::get($cacheKey);
        Cache::forget($cacheKey);

        if ($pending === null) {
            throw new AccountConnectException('connect_state_expired', 'This connect session expired. Start the connect again.');
        }

        $orgUuid = $pending['organization_uuid'] ?? null;

        $account = $this->findByIdentity($pending['email'], $orgUuid) ?? new Account([
            'email' => $pending['email'],
            'organization_uuid' => $orgUuid,
        ]);

        $this->reconcileIdentity($account, $pending['email'], $name);

        $account->name = $name;
        $account->plan = $plan instanceof AccountPlan ? $plan : (AccountPlan::tryFrom($plan) ?? AccountPlan::Unknown);
        $account->organization_type = $pending['organization_type'] ?? $account->organization_type;
        $account->rate_limit_tier = $pending['rate_limit_tier'] ?? $account->rate_limit_tier;
        $account->account_uuid = $pending['account_uuid'] ?? $account->account_uuid;
        $this->writeGrant($account, $pending['access_token'], $pending['refresh_token'], $pending['expires_in'], $pending['refresh_token_expires_in'] ?? null);
        $account->save();

        $this->learnOrganizationUuid($account, $orgUuid);

        $this->probeBestEffort($account);

        return $account->refresh();
    }

    /**
     * Whether an account's identity matches an authorized profile, by
     * case-insensitive email or by organization uuid.
     *
     * @param  Account  $account  the candidate account
     * @param  string  $email  the authorized email
     * @param  ?string  $orgUuid  the authorized organization uuid, if any
     * @return bool
     */
    private function identityMatches(Account $account, string $email, ?string $orgUuid): bool
    {
        if (mb_strtolower((string) $account->email) === mb_strtolower($email)) {
            return true;
        }

        return $orgUuid !== null && $account->organization_uuid === $orgUuid;
    }

    /**
     * Find an existing account by the identity match rule: email
     * (case-insensitive) first, then organization uuid.
     *
     * @param  string  $email  the authorized email
     * @param  ?string  $orgUuid  the authorized organization uuid, if any
     * @return ?Account the matched account, or null when none matches
     */
    private function findByIdentity(string $email, ?string $orgUuid): ?Account
    {
        $byEmail = Account::query()->whereRaw('lower(email) = ?', [mb_strtolower($email)])->first();
        if ($byEmail !== null) {
            return $byEmail;
        }

        if ($orgUuid === null) {
            return null;
        }

        return Account::query()->whereRelation('claudeCredential', 'organization_uuid', $orgUuid)->first();
    }

    /**
     * Stash the exchanged token material for a not-yet-created account under a
     * random single-use handoff key and return the profile-derived draft the
     * confirm-and-create step fills in. The raw `organization_type`/
     * `rate_limit_tier` pair is stashed alongside the token material so
     * {@see createFromPending()} can persist it verbatim, never from admin
     * input.
     *
     * @param  array<string, mixed>  $token  the token response (access/refresh/expires_in)
     * @param  array<string, mixed>  $profile  the authorized profile response
     * @param  string  $email  the authorized email
     * @param  ?string  $orgUuid  the authorized organization uuid, if any
     * @return ConnectDraft the draft carried to the create step
     */
    private function stashPending(array $token, array $profile, string $email, ?string $orgUuid): ConnectDraft
    {
        $key = $this->generateState();
        $organizationType = $profile['organization']['organization_type'] ?? null;
        $rateLimitTier = $profile['organization']['rate_limit_tier'] ?? null;
        $plan = (new PlanResolver)->resolve($organizationType, $rateLimitTier);

        Cache::put(
            self::PENDING_KEY_PREFIX.$key,
            [
                'access_token' => $token['access_token'],
                'refresh_token' => $token['refresh_token'],
                'expires_in' => $token['expires_in'],
                'refresh_token_expires_in' => $token['refresh_token_expires_in'] ?? null,
                'account_uuid' => $profile['account']['uuid'] ?? null,
                'organization_uuid' => $orgUuid,
                'organization_type' => $organizationType,
                'rate_limit_tier' => $rateLimitTier,
                'email' => $email,
            ],
            now()->addMinutes(self::CACHE_TTL_MINUTES),
        );

        return new ConnectDraft(
            email: $email,
            orgUuid: $orgUuid,
            plan: $plan,
            organizationType: $organizationType,
            rateLimitTier: $rateLimitTier,
            name: $profile['organization']['name'] ?? ($profile['account']['full_name'] ?? null),
            handoffKey: $key,
        );
    }

    /**
     * Write an exchanged grant and profile identity onto an existing account,
     * flip it Active, learn its organization uuid, and best-effort probe it.
     * The raw `organization_type`/`rate_limit_tier` pair is stored verbatim
     * and the plan is re-resolved from it via {@see PlanResolver}.
     *
     * @param  Account  $account  the account to update
     * @param  array<string, mixed>  $token  the token response (access/refresh/expires_in)
     * @param  array<string, mixed>  $profile  the authorized profile response
     * @return void
     */
    private function applyToken(Account $account, array $token, array $profile): void
    {
        $this->writeGrant($account, $token['access_token'], $token['refresh_token'], $token['expires_in'], $token['refresh_token_expires_in'] ?? null);
        $account->account_uuid = $profile['account']['uuid'] ?? ($token['account']['uuid'] ?? $account->account_uuid);
        $organizationType = $profile['organization']['organization_type'] ?? null;
        $rateLimitTier = $profile['organization']['rate_limit_tier'] ?? null;
        $account->organization_type = $organizationType ?? $account->organization_type;
        $account->rate_limit_tier = $rateLimitTier ?? $account->rate_limit_tier;
        $account->plan = (new PlanResolver)->resolve($organizationType, $rateLimitTier);
        $this->reconcileIdentity(
            $account,
            $profile['account']['email'] ?? null,
            $profile['organization']['name'] ?? ($profile['account']['full_name'] ?? null),
        );
        $account->save();

        $this->learnOrganizationUuid($account, $profile['organization']['uuid'] ?? null);

        $this->probeBestEffort($account);
    }

    /**
     * Reconcile an existing account's human identity fields to the freshly
     * authorized Claude profile: adopt the profile email when it differs and
     * no other account already holds it, and fill a blank name from the
     * profile. Claude is the source of truth for these fields.
     *
     * @param  Account  $account  the account being connected/updated
     * @param  ?string  $email  the authorized profile email
     * @param  ?string  $name  a display name candidate from the profile
     * @return void
     */
    private function reconcileIdentity(Account $account, ?string $email, ?string $name): void
    {
        if ($email !== null && mb_strtolower($email) !== mb_strtolower((string) $account->email)) {
            $takenByOther = Account::query()
                ->whereRaw('lower(email) = ?', [mb_strtolower($email)])
                ->whereKeyNot($account->getKey())
                ->exists();

            if (! $takenByOther) {
                $account->email = $email;
            }
        }

        if (blank($account->name) && filled($name)) {
            $account->name = $name;
        }
    }

    /**
     * Set the OAuth grant fields and Active status on an account in memory
     * (does not save). Shared by {@see applyToken()} and
     * {@see createFromPending()}.
     *
     * @param  Account  $account  the account to mutate
     * @param  string  $accessToken  the new access token
     * @param  string  $refreshToken  the new (rotated) refresh token
     * @param  int  $expiresIn  seconds until the access token expires
     * @param  ?int  $refreshExpiresIn  seconds until the refresh token expires, or null when the response omitted it (keeps the account's existing value, if any)
     * @return void
     */
    private function writeGrant(Account $account, string $accessToken, string $refreshToken, int $expiresIn, ?int $refreshExpiresIn = null): void
    {
        $account->oauth_access_token = $accessToken;
        $account->oauth_refresh_token = $refreshToken;
        $account->oauth_expires_at = now()->addSeconds($expiresIn);
        $account->oauth_refresh_expires_at = $refreshExpiresIn !== null ? now()->addSeconds($refreshExpiresIn) : $account->oauth_refresh_expires_at;
        // A fresh grant is a rotation like any other, so the staleness clock
        // starts here too — otherwise a just-reconnected account would read as
        // never having refreshed.
        $account->last_refreshed_at = now();
        $account->status = AccountStatus::Active;
        $account->probe_error = null;
    }

    /**
     * Sever the stored OAuth grant for an account — the compromised-token
     * response. Anthropic exposes NO token revocation endpoint (verified:
     * `POST /v1/oauth/revoke` → 404), so there is no server-side revoke to
     * attempt; this wipes the locally stored access/refresh tokens and expiry
     * and marks the account `NeedsReauth`. The real kill switch remains with
     * the account owner (claude.ai → revoke app access / sign out all
     * sessions), surfaced to the admin in the action's confirm runbook.
     *
     * @param  Account  $account  the account to disconnect
     * @return void
     */
    public function disconnect(Account $account): void
    {
        $account->oauth_access_token = null;
        $account->oauth_refresh_token = null;
        $account->oauth_expires_at = null;
        $account->status = AccountStatus::NeedsReauth;
        $account->probe_error = 'disconnected by admin';
        $account->save();
    }

    /**
     * Generate a PKCE code verifier: base64url(random 32 bytes), no padding.
     *
     * @return string the code verifier
     */
    private function generateVerifier(): string
    {
        return $this->base64UrlEncode(random_bytes(32));
    }

    /**
     * Derive the S256 code challenge for a verifier: base64url(sha256(verifier)), no padding.
     *
     * @param  string  $verifier  the PKCE code verifier
     * @return string the code challenge
     */
    private function challengeFor(string $verifier): string
    {
        return $this->base64UrlEncode(hash('sha256', $verifier, binary: true));
    }

    /**
     * Generate a random opaque state value for a connect attempt.
     *
     * @return string the state value
     */
    private function generateState(): string
    {
        return $this->base64UrlEncode(random_bytes(24));
    }

    /**
     * Base64url-encode (unpadded) a raw byte string.
     *
     * @param  string  $bytes  the raw bytes to encode
     * @return string the base64url-encoded, unpadded string
     */
    private function base64UrlEncode(string $bytes): string
    {
        return rtrim(strtr(base64_encode($bytes), '+/', '-_'), '=');
    }

    /**
     * Attempt to record the newly-connected account's organization uuid,
     * respecting the `organization_uuid` unique constraint. Mirrors
     * {@see AccountResolver}'s learn-uuid pattern: on a collision (another
     * account already claims this uuid), the write is skipped rather than
     * failing the whole connect.
     *
     * @param  Account  $account  the just-connected account
     * @param  ?string  $organizationUuid  the organization uuid from the profile response, if any
     * @return void
     */
    private function learnOrganizationUuid(Account $account, ?string $organizationUuid): void
    {
        if ($organizationUuid === null || $account->organization_uuid === $organizationUuid) {
            return;
        }

        // getOriginal() would not see this — organization_uuid is a virtual
        // accessor proxying to claudeCredential, not a real Account column —
        // so capture the pre-write value explicitly to restore on failure.
        $previousOrganizationUuid = $account->organization_uuid;
        $account->organization_uuid = $organizationUuid;

        try {
            $account->save();
        } catch (QueryException) {
            // Another account row already claims this organization uuid
            // (unique constraint). Skip the write rather than failing the
            // whole connect; the admin can reconcile the duplicate later.
            $account->organization_uuid = $previousOrganizationUuid;
        }
    }

    /**
     * Probe the just-connected account's quota immediately so the admin
     * sees a fresh utilization snapshot without waiting for the next
     * scheduled cycle. A probe failure must never fail the connect.
     *
     * @param  Account  $account  the just-connected account
     * @return void
     */
    private function probeBestEffort(Account $account): void
    {
        try {
            $this->prober->probe($account);
        } catch (Throwable) {
            // Best-effort only: the connect already succeeded, and the
            // prober's own 5-minute cycle will retry.
        }
    }
}
