<?php

namespace App\Models;

use App\Enums\AccountPlan;
use App\Enums\AccountStatus;
use App\Models\Contracts\CredentialsProvider;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * One account's Claude-specific OAuth credential and probe-health state —
 * split off `accounts` (Phase 1 of the Codex admin-provisioning
 * decomposition) so a sibling `codex_credentials` table can hold Codex's
 * structurally different OAuth state without bolting mismatched columns
 * onto a single shared table.
 */
#[Hidden(['oauth_access_token', 'oauth_refresh_token'])]
class ClaudeCredential extends Model implements CredentialsProvider
{
    protected $guarded = [];

    /**
     * Mirrors the DB-level defaults so a freshly-made-but-unsaved instance
     * (as `Account`'s accessor proxies create via `HasOne::make()`) reads
     * back the same default status a persisted row would have.
     *
     * @var array<string, mixed>
     */
    protected $attributes = [
        'status' => AccountStatus::Active->value,
    ];

    /**
     * The `Account` envelope this credential belongs to.
     *
     * @return BelongsTo<Account, $this>
     */
    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class);
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => AccountStatus::class,
            'plan' => AccountPlan::class,
            'oauth_access_token' => 'encrypted',
            'oauth_refresh_token' => 'encrypted',
            'oauth_expires_at' => 'datetime',
            'oauth_refresh_expires_at' => 'datetime',
            'last_refreshed_at' => 'datetime',
            'last_probed_at' => 'datetime',
        ];
    }

    /**
     * @inheritDoc
     */
    public function credentialStatus(): AccountStatus
    {
        return $this->status;
    }

    /**
     * @inheritDoc
     */
    public function credentialLastProbedAt(): ?Carbon
    {
        return $this->last_probed_at;
    }

    /**
     * @inheritDoc
     */
    public function credentialProbeError(): ?string
    {
        return $this->probe_error;
    }
}
