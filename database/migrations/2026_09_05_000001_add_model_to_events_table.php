<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Record which model produced each usage event. Nullable on purpose: rows
     * written before this shipped, and rows from clients that have not updated
     * yet, carry no model and are reported as an `unknown` bucket. No backfill
     * is possible — the model was never captured.
     *
     * `created_at` leads the index because every analytics query filters on the
     * date range first, and `model` has only a handful of distinct values, which
     * is the worst possible leading column for a composite. `->after()` is
     * deliberately omitted: neither PostgresGrammar nor SQLiteGrammar honors it,
     * so it would be a misleading no-op.
     *
     * @return void
     */
    public function up(): void
    {
        Schema::table('events', function (Blueprint $table) {
            $table->string('model', 64)->nullable();
            $table->index(['created_at', 'model']);
        });
    }

    /**
     * Drop the column and its index.
     *
     * @return void
     */
    public function down(): void
    {
        Schema::table('events', function (Blueprint $table) {
            $table->dropIndex(['created_at', 'model']);
            $table->dropColumn('model');
        });
    }
};
