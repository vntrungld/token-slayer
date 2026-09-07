<?php

namespace App\Models;

use App\Enums\AccountPlan;
use App\Enums\AccountStatus;
use App\Enums\MembershipStatus;
use App\Enums\Provider;
use App\Models\Contracts\CredentialsProvider;
use App\Services\CodexUsageProber;
use App\Support\CacheKeys;
use Database\Factories\AccountFactory;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Carbon;

#[Hidden(['oauth_access_token', 'oauth_refresh_token'])]
class Account extends Model
{
    /** @use HasFactory<AccountFactory> */
    use HasFactory;

    protected $guarded = [];

    /**
     * Mirrors the migration's DB-level default so a freshly-created
     * in-memory instance reads 'claude' immediately — Eloquent does not
     * refresh DB-computed defaults into memory after an INSERT that omits
     * the column.
     *
     * @var array<string, mixed>
     */
    protected $attributes = [
        'provider' => 'claude',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'provider' => Provider::class,
        ];
    }

    /**
     * Keep the resolver's email and organization-uuid maps, and this
     * account's membership aggregate + ingest pair caches, in sync with
     * account mutations. Also persists a dirty, in-memory `claudeCredential`
     * relation after the parent account saves — Eloquent does not cascade-save
     * `HasOne` relations on its own, so the accessor proxies below rely on
     * this hook to actually reach the database.
     *
     * @return void
     */
    protected static function booted(): void
    {
        $flush = function (Account $account): void {
            CacheKeys::forgetAccountMaps();
            CacheKeys::forgetAccountMembership($account->id);
            CacheKeys::forgetMembershipPairs($account->id);
        };
        static::saved($flush);
        static::deleted($flush);

        static::saved(function (Account $account): void {
            if ($account->relationLoaded('claudeCredential') && $account->claudeCredential?->isDirty()) {
                $account->claudeCredential->account_id = $account->id;
                $account->claudeCredential->save();
            }
        });
    }

    /**
     * Every developer linked to this org account via the `account_user`
     * pivot, of any membership status. Use {@see trackedUsers()} for the
     * "members" subset.
     *
     * @return BelongsToMany<User, $this>
     */
    public function users(): BelongsToMany
    {
        return $this->belongsToMany(User::class)
            ->using(AccountUser::class)
            ->withPivot('status')
            ->withTimestamps();
    }

    /**
     * The tracked members of this account (`account_user.status = tracked`).
     *
     * @return BelongsToMany<User, $this>
     */
    public function trackedUsers(): BelongsToMany
    {
        return $this->users()->wherePivot('status', MembershipStatus::Tracked->value);
    }

    /**
     * The untracked contributors of this account (`account_user.status =
     * untracked`) — developers with events here who have not been promoted.
     *
     * @return BelongsToMany<User, $this>
     */
    public function untrackedUsers(): BelongsToMany
    {
        return $this->users()->wherePivot('status', MembershipStatus::Untracked->value);
    }

    /**
     * Every provisioned grant issued for this account, across all users'
     * devices and statuses.
     *
     * @return HasMany<AccountProvisionedGrant, $this>
     */
    public function provisionedGrants(): HasMany
    {
        return $this->hasMany(AccountProvisionedGrant::class);
    }

    /**
     * Every quota-utilization snapshot recorded for this account by the
     * 5-minute prober, in natural (insertion) order. Callers that need
     * newest-first should order the query explicitly.
     *
     * @return HasMany<AccountUsageSnapshot, $this>
     */
    public function usageSnapshots(): HasMany
    {
        return $this->hasMany(AccountUsageSnapshot::class);
    }

    /**
     * The most recently recorded quota-utilization snapshot for this
     * account, resolved via `latestOfMany` on `created_at`.
     *
     * @return HasOne<AccountUsageSnapshot, $this>
     */
    public function latestUsageSnapshot(): HasOne
    {
        return $this->hasOne(AccountUsageSnapshot::class)->latestOfMany('created_at');
    }

    /**
     * This account's Claude-specific OAuth credential and probe-health
     * state, split into its own table in the envelope/credential split.
     *
     * @return HasOne<ClaudeCredential, $this>
     */
    public function claudeCredential(): HasOne
    {
        return $this->hasOne(ClaudeCredential::class);
    }

    /**
     * This account's Codex-specific persistent credential (Step A of the
     * admin-provisioning flow), when this account's provider is 'codex'.
     *
     * @return HasOne<CodexCredential, $this>
     */
    public function codexCredential(): HasOne
    {
        return $this->hasOne(CodexCredential::class);
    }

    /**
     * The `CredentialsProvider` this account's provider-agnostic accessors
     * (`status`, `lastProbedAt`, `probeError`) read from: `codexCredential`
     * for a Codex account, `claudeCredential` for everything else. The
     * single branch point every one of those accessors goes through.
     *
     * @return Attribute<?CredentialsProvider, never>
     */
    protected function credential(): Attribute
    {
        return Attribute::make(
            get: fn (): ?CredentialsProvider => $this->provider === Provider::Codex
                ? $this->codexCredential
                : $this->claudeCredential,
        );
    }

    /**
     * Every usage event attributed to this org account via
     * `events.account_id`, in natural order. Callers that need newest-first
     * order the query explicitly.
     *
     * @return HasMany<Event, $this>
     */
    public function events(): HasMany
    {
        return $this->hasMany(Event::class);
    }

    /**
     * Scope to accounts the usage prober should attempt this cycle: not
     * soft-disabled, not already known to have a dead refresh token
     * (`NeedsReauth` accounts are skipped until reconnected), and holding
     * a refresh token to exchange in the first place. Queries through
     * `claudeCredential` since `status`/`oauth_refresh_token` moved off
     * `accounts` in the envelope/credential split.
     *
     * @param  Builder<Account>  $query  the query being scoped
     * @return Builder<Account> the scoped query
     */
    public function scopeProbeable(Builder $query): Builder
    {
        return $query->whereHas('claudeCredential', function (Builder $credentials): void {
            $credentials
                ->where('status', '!=', AccountStatus::Disabled->value)
                ->where('status', '!=', AccountStatus::NeedsReauth->value)
                ->whereNotNull('oauth_refresh_token');
        });
    }

    /**
     * Scope to Codex accounts the usage prober should attempt this cycle —
     * the Codex counterpart to {@see scopeProbeable()}: not soft-disabled,
     * not already known to need re-auth, and holding an access token to
     * probe with in the first place (Codex has no separate refresh-token
     * exchange for a usage probe, unlike Claude — see
     * {@see CodexUsageProber}).
     *
     * @param  Builder<Account>  $query  the query being scoped
     * @return Builder<Account> the scoped query
     */
    public function scopeCodexProbeable(Builder $query): Builder
    {
        return $query->whereHas('codexCredential', function (Builder $credentials): void {
            $credentials
                ->where('status', '!=', AccountStatus::Disabled->value)
                ->where('status', '!=', AccountStatus::NeedsReauth->value)
                ->whereNotNull('codex_access_token');
        });
    }

    protected static function newFactory(): AccountFactory
    {
        return AccountFactory::new();
    }

    /**
     * The credential row this account's Claude-column accessors read from
     * and write to, materializing (and caching on the relation) an unsaved
     * instance the first time a write touches a credential-less account,
     * so several accessor writes in the same request land on one in-memory
     * row instead of each silently creating and discarding its own.
     *
     * @return ClaudeCredential
     */
    private function claudeCredentialForWrite(): ClaudeCredential
    {
        if ($this->claudeCredential === null) {
            $this->setRelation('claudeCredential', $this->claudeCredential()->make());
        }

        return $this->claudeCredential;
    }

    /**
     * Proxies to `claudeCredential.organization_uuid` — moved off `accounts`
     * in the envelope/credential split; see `claudeCredentialForWrite()`.
     *
     * @return Attribute<?string, ?string>
     */
    protected function organizationUuid(): Attribute
    {
        return Attribute::make(
            get: fn (): ?string => $this->claudeCredential?->organization_uuid,
            set: function (?string $value): array {
                $this->claudeCredentialForWrite()->organization_uuid = $value;

                return [];
            },
        );
    }

    /**
     * Proxies to `claudeCredential.organization_type`.
     *
     * @return Attribute<?string, ?string>
     */
    protected function organizationType(): Attribute
    {
        return Attribute::make(
            get: fn (): ?string => $this->claudeCredential?->organization_type,
            set: function (?string $value): array {
                $this->claudeCredentialForWrite()->organization_type = $value;

                return [];
            },
        );
    }

    /**
     * Proxies to `claudeCredential.rate_limit_tier`.
     *
     * @return Attribute<?string, ?string>
     */
    protected function rateLimitTier(): Attribute
    {
        return Attribute::make(
            get: fn (): ?string => $this->claudeCredential?->rate_limit_tier,
            set: function (?string $value): array {
                $this->claudeCredentialForWrite()->rate_limit_tier = $value;

                return [];
            },
        );
    }

    /**
     * Proxies to `claudeCredential.plan`, defaulting to Max20x (mirroring
     * the DB-level default the raw column used to carry) when no credential
     * row exists yet.
     *
     * @return Attribute<AccountPlan, AccountPlan>
     */
    protected function plan(): Attribute
    {
        return Attribute::make(
            get: fn (): AccountPlan => $this->claudeCredential?->plan ?? AccountPlan::Max20x,
            set: function (AccountPlan $value): array {
                $this->claudeCredentialForWrite()->plan = $value;

                return [];
            },
        );
    }

    /**
     * Proxies to `claudeCredential.oauth_access_token`.
     *
     * @return Attribute<?string, ?string>
     */
    protected function oauthAccessToken(): Attribute
    {
        return Attribute::make(
            get: fn (): ?string => $this->claudeCredential?->oauth_access_token,
            set: function (?string $value): array {
                $this->claudeCredentialForWrite()->oauth_access_token = $value;

                return [];
            },
        );
    }

    /**
     * Proxies to `claudeCredential.oauth_refresh_token`.
     *
     * @return Attribute<?string, ?string>
     */
    protected function oauthRefreshToken(): Attribute
    {
        return Attribute::make(
            get: fn (): ?string => $this->claudeCredential?->oauth_refresh_token,
            set: function (?string $value): array {
                $this->claudeCredentialForWrite()->oauth_refresh_token = $value;

                return [];
            },
        );
    }

    /**
     * Proxies to `claudeCredential.oauth_expires_at`.
     *
     * @return Attribute<?Carbon, mixed>
     */
    protected function oauthExpiresAt(): Attribute
    {
        return Attribute::make(
            get: fn (): ?Carbon => $this->claudeCredential?->oauth_expires_at,
            set: function (mixed $value): array {
                $this->claudeCredentialForWrite()->oauth_expires_at = $value;

                return [];
            },
        );
    }

    /**
     * Proxies to `claudeCredential.last_refreshed_at` — when this Claude
     * grant last rotated successfully.
     *
     * Distinct from `lastProbedAt`, which every probe cycle stamps whether or
     * not a token was exchanged. Only a successful refresh moves this one, so
     * it is the signal that separates a healthy grant from one whose refresh
     * has quietly started failing without flipping the status.
     *
     * @return Attribute<?Carbon, mixed>
     */
    protected function lastRefreshedAt(): Attribute
    {
        return Attribute::make(
            get: fn (): ?Carbon => $this->claudeCredential?->last_refreshed_at,
            set: function (mixed $value): array {
                $this->claudeCredentialForWrite()->last_refreshed_at = $value;

                return [];
            },
        );
    }

    /**
     * Proxies to `claudeCredential.oauth_refresh_expires_at`, populated from
     * the `refresh_token_expires_in` every successful token exchange returns.
     *
     * @return Attribute<?Carbon, mixed>
     */
    protected function oauthRefreshExpiresAt(): Attribute
    {
        return Attribute::make(
            get: fn (): ?Carbon => $this->claudeCredential?->oauth_refresh_expires_at,
            set: function (mixed $value): array {
                $this->claudeCredentialForWrite()->oauth_refresh_expires_at = $value;

                return [];
            },
        );
    }

    /**
     * Proxies to `credential.status` (Claude or Codex, per provider),
     * defaulting to Active for a credential-less Claude account (mirroring
     * the DB-level default the raw column used to carry) or NeedsReauth for
     * a credential-less Codex account (which genuinely isn't usable yet —
     * unlike Claude, a Codex account is never created without its
     * credential in the same request, so this default only matters for a
     * still-mid-connect row).
     *
     * @return Attribute<AccountStatus, AccountStatus>
     */
    protected function status(): Attribute
    {
        return Attribute::make(
            get: fn (): AccountStatus => $this->credential?->credentialStatus()
                ?? ($this->provider === Provider::Codex ? AccountStatus::NeedsReauth : AccountStatus::Active),
            set: function (AccountStatus $value): array {
                $this->claudeCredentialForWrite()->status = $value;

                return [];
            },
        );
    }

    /**
     * Proxies to `credential.last_probed_at` (Claude or Codex, per provider).
     *
     * @return Attribute<?Carbon, mixed>
     */
    protected function lastProbedAt(): Attribute
    {
        return Attribute::make(
            get: fn (): ?Carbon => $this->credential?->credentialLastProbedAt(),
            set: function (mixed $value): array {
                $this->claudeCredentialForWrite()->last_probed_at = $value;

                return [];
            },
        );
    }

    /**
     * Proxies to `credential.probe_error` (Claude or Codex, per provider).
     *
     * @return Attribute<?string, ?string>
     */
    protected function probeError(): Attribute
    {
        return Attribute::make(
            get: fn (): ?string => $this->credential?->credentialProbeError(),
            set: function (?string $value): array {
                $this->claudeCredentialForWrite()->probe_error = $value;

                return [];
            },
        );
    }

    /**
     * Proxies to `claudeCredential.account_uuid`.
     *
     * @return Attribute<?string, ?string>
     */
    protected function accountUuid(): Attribute
    {
        return Attribute::make(
            get: fn (): ?string => $this->claudeCredential?->account_uuid,
            set: function (?string $value): array {
                $this->claudeCredentialForWrite()->account_uuid = $value;

                return [];
            },
        );
    }
}
