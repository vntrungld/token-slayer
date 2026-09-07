<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Record when a Claude grant last rotated successfully.
     *
     * `oauth_refresh_expires_at` alone cannot tell a healthy account from a
     * stalled one: a healthy grant rotates every few hours and pushes that
     * deadline ~29 days out each time, so a grant whose refresh has quietly
     * started failing looks identical until the frozen deadline finally drifts
     * into the warning window weeks later. This is the Claude counterpart to
     * `codex_credentials.last_refreshed_at`, which already carries the same
     * signal for the other provider.
     *
     * Nullable with no backfill: there is no honest value for a row that
     * predates the column, and inventing one would either flag the whole fleet
     * as stale or silence it. Existing rows start unknown and become
     * meaningful at their next successful refresh.
     *
     * @return void
     */
    public function up(): void
    {
        Schema::table('claude_credentials', function (Blueprint $table): void {
            $table->timestampTz('last_refreshed_at')->nullable()->after('oauth_refresh_expires_at');
        });
    }

    /**
     * Drop the column.
     *
     * @return void
     */
    public function down(): void
    {
        Schema::table('claude_credentials', function (Blueprint $table): void {
            $table->dropColumn('last_refreshed_at');
        });
    }
};
