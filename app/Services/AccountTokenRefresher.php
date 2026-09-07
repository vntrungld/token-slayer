<?php

namespace App\Services;

use App\Enums\AccountStatus;
use App\Events\AccountTokenRejected;
use App\Exceptions\UsageProbeException;
use App\Models\Account;

/**
 * Ensures an account holds a usable, non-imminently-expiring OAuth access
 * token before it is used for any Anthropic API call (usage probe, session
 * anchor). Refreshes near-expiry tokens and classifies refresh failures the
 * same way for every caller: a dead refresh token (invalid_grant/unauthorized)
 * flips the account to {@see AccountStatus::NeedsReauth} and dispatches
 * {@see AccountTokenRejected} exactly once; a transient failure records a safe
 * `probe_error` and leaves the status untouched so the next cycle retries.
 *
 * Per token-hygiene requirements, no raw token material is ever written to
 * `probe_error` or anywhere else.
 */
class AccountTokenRefresher
{
    /**
     * Build the refresher with the OAuth client it delegates token grants to.
     *
     * @param  AnthropicOAuthClient  $client  the token/refresh API client
     * @return void
     */
    public function __construct(private readonly AnthropicOAuthClient $client) {}

    /**
     * Ensure the account has a usable access token, refreshing it first when
     * it is missing or near expiry.
     *
     * @param  Account  $account  the account whose token is being ensured
     * @return bool true when the account holds a fresh, usable access token;
     *              false when it should be skipped (disabled, tokenless, or a
     *              refresh failure)
     */
    public function ensureFreshToken(Account $account): bool
    {
        if ($account->status === AccountStatus::Disabled || $account->oauth_refresh_token === null) {
            return false;
        }

        if ($this->needsRefresh($account) && ! $this->refresh($account)) {
            return false;
        }

        return true;
    }

    /**
     * Determine whether the account's access token is missing an expiry or
     * within the refresh headroom of expiring.
     *
     * @param  Account  $account  the account to inspect
     * @return bool true when the token should be refreshed before use
     */
    private function needsRefresh(Account $account): bool
    {
        return $account->oauth_expires_at === null
            || $account->oauth_expires_at->lt(now()->addHours((int) config('token_slayer.probe.refresh_headroom_hours', 4)));
    }

    /**
     * Exchange the account's refresh token for a new access/refresh token
     * pair and persist it. On a dead-grant failure, flips the account to
     * AccountStatus::NeedsReauth; on a transient failure, leaves status
     * untouched so the next cycle retries.
     *
     * @param  Account  $account  the account whose token is being refreshed
     * @return bool true when the refresh succeeded and the account is ready to use
     */
    private function refresh(Account $account): bool
    {
        try {
            $token = $this->client->refresh($account->oauth_refresh_token);
        } catch (UsageProbeException $exception) {
            // A rate-limit is transient and expected — skip silently and retry
            // next cycle, exactly as a 429 on the usage call is handled.
            if ($exception->reason === 'rate_limited') {
                return false;
            }

            if (in_array($exception->reason, ['invalid_grant', 'unauthorized'], true)) {
                // Capture the pre-mutation status so the alert fires only on the
                // true transition into NeedsReauth, never on a re-probe of an
                // already-flagged account (defensive — scopeProbeable already
                // excludes NeedsReauth accounts from the batch).
                $wasReauth = $account->getOriginal('status') === AccountStatus::NeedsReauth;

                $account->status = AccountStatus::NeedsReauth;
                $account->probe_error = "refresh failed: {$exception->reason}";
                $account->save();

                if (! $wasReauth) {
                    AccountTokenRejected::dispatch($account, $exception->reason);
                }

                return false;
            }

            // Transient failure: record the error, leave status untouched so the
            // next cycle retries.
            $account->probe_error = "refresh failed: {$exception->reason}";
            $account->save();

            return false;
        }

        $account->oauth_access_token = $token['access_token'];
        $account->oauth_refresh_token = $token['refresh_token'];
        $account->oauth_expires_at = now()->addSeconds($token['expires_in']);
        if (isset($token['refresh_token_expires_in'])) {
            $account->oauth_refresh_expires_at = now()->addSeconds($token['refresh_token_expires_in']);
        }
        // Stamped only on the success path: this is the "the grant still
        // rotates" signal, so a failed attempt must leave it where it was or
        // a permanently failing account would look permanently healthy.
        $account->last_refreshed_at = now();
        $account->save();

        return true;
    }
}
