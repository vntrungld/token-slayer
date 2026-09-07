<?php

namespace App\Services\Attribution;

use App\Enums\AccountStatus;
use App\Enums\Provider;
use App\Models\Account;
use App\Models\ClaudeCredential;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * Accounts an admin should act on. The page built on this doubles as the
 * reminder — its rows do not clear until someone repairs them, and its
 * navigation badge carries the count — so anything an admin must fix belongs
 * here, not only what is about to break.
 *
 * A Claude account qualifies three ways, checked in that order of certainty:
 * its grant was already rejected (`needs_reauth`); its refresh token expires
 * within 3 days (a real, precise deadline); or it has not rotated
 * successfully in over 2 days. The last of those is what catches a grant
 * whose refresh is failing transiently — a rate limit or a network error,
 * both of which deliberately leave the status Active so the next cycle
 * retries. Waiting for the deadline instead would catch it too, but roughly
 * 26 days later, since a frozen deadline only drifts into the 3-day window
 * near the end of the refresh token's ~29-day life.
 *
 * A Codex account qualifies when `earliest_refresh_at` has passed, or (when
 * that field is absent, which is the common case under the current
 * provisioning design — see the Phase 2 spec's 2026-09-02 correction)
 * `last_refreshed_at` is over 8 days old, mirroring the `codex` CLI's own
 * internal proactive-refresh heuristic.
 */
final class ExpiringAccountsQuery
{
    /**
     * @var int
     */
    private const int CLAUDE_WARNING_DAYS = 3;

    /**
     * How long a Claude grant may go without a successful rotation before it
     * is treated as stalled. A healthy grant rotates every few hours — the
     * refresher exchanges a token once the 8-hour access token is within 4
     * hours of expiry — so two days is well clear of any ordinary gap while
     * still being weeks earlier than the deadline would notice.
     *
     * @var int
     */
    private const int CLAUDE_STALENESS_DAYS = 2;

    /**
     * @var int
     */
    private const int CODEX_STALENESS_DAYS = 8;

    /**
     * @return array<int, array{account_id:int, email:?string, name:?string, provider:Provider, label:string, deadline:?Carbon}>
     */
    public function get(): array
    {
        return $this->claudeRows()->concat($this->codexRows())->values()->all();
    }

    /**
     * @return Collection<int, array{account_id:int, email:?string, name:?string, provider:Provider, label:string, deadline:?Carbon}>
     */
    private function claudeRows(): Collection
    {
        return Account::query()
            ->where('provider', Provider::Claude)
            ->whereHas('claudeCredential', function ($credentials): void {
                // Disabled is a deliberate admin choice, not a fault: it must
                // never sit in the badge count nagging to be repaired.
                $credentials
                    ->where('status', '!=', AccountStatus::Disabled->value)
                    ->where(function ($faulty): void {
                        $faulty
                            ->where('status', AccountStatus::NeedsReauth->value)
                            ->orWhere(fn ($q) => $q
                                ->whereNotNull('oauth_refresh_expires_at')
                                ->where('oauth_refresh_expires_at', '<=', now()->addDays(self::CLAUDE_WARNING_DAYS)))
                            // Null is "never recorded", not "never refreshed":
                            // every row predating the column reads null, and
                            // treating that as stalled would flag the whole
                            // fleet the day this ships.
                            ->orWhere(fn ($q) => $q
                                ->whereNotNull('last_refreshed_at')
                                ->where('last_refreshed_at', '<', now()->subDays(self::CLAUDE_STALENESS_DAYS)));
                    });
            })
            ->with('claudeCredential')
            ->get()
            ->map(fn (Account $account): array => [
                'account_id' => $account->id,
                'email' => $account->email,
                'name' => $account->name,
                'provider' => Provider::Claude,
                'label' => self::claudeLabel($account->claudeCredential),
                // Only a real expiry deadline is a deadline. A rejected or
                // stalled grant has no date to count down to, and inventing
                // one would rank it against accounts that do have one.
                'deadline' => $account->claudeCredential->status === AccountStatus::NeedsReauth
                    ? null
                    : $account->claudeCredential->oauth_refresh_expires_at,
            ]);
    }

    /**
     * What a Claude row says is wrong with it, most certain reason first: a
     * rejected grant is a fact, an approaching deadline is a forecast, and a
     * stalled rotation is an inference.
     *
     * @param  ClaudeCredential  $credential  the account's Claude credential
     * @return string
     */
    private static function claudeLabel(ClaudeCredential $credential): string
    {
        if ($credential->status === AccountStatus::NeedsReauth) {
            return 'needs re-auth — the grant was rejected';
        }

        $deadline = $credential->oauth_refresh_expires_at;

        if ($deadline !== null && $deadline->lte(now()->addDays(self::CLAUDE_WARNING_DAYS))) {
            return 'expires '.$deadline->diffForHumans();
        }

        return "hasn't refreshed since ".$credential->last_refreshed_at->diffForHumans();
    }

    /**
     * @return Collection<int, array{account_id:int, email:?string, name:?string, provider:Provider, label:string, deadline:?Carbon}>
     */
    private function codexRows(): Collection
    {
        return Account::query()
            ->where('provider', Provider::Codex)
            ->whereHas('codexCredential', function ($query): void {
                $query->where(function ($q): void {
                    $q->whereNotNull('earliest_refresh_at')->where('earliest_refresh_at', '<=', now());
                })->orWhere(function ($q): void {
                    $q->whereNull('earliest_refresh_at')->where('last_refreshed_at', '<', now()->subDays(self::CODEX_STALENESS_DAYS));
                });
            })
            ->with('codexCredential')
            ->get()
            ->map(fn (Account $account): array => [
                'account_id' => $account->id,
                'email' => $account->email,
                'name' => $account->name,
                'provider' => Provider::Codex,
                'label' => "hasn't refreshed recently — may need attention",
                'deadline' => null,
            ]);
    }
}
