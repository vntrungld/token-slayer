<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Track the version of the hook a developer is running, separately from
     * `client_version`. The two move independently: `client_version` is the
     * release tag of the CLI wheel, published from a repo this project cannot
     * tag, so a hook-only change would otherwise ship with an unchanged version
     * and nobody would ever be told to update.
     *
     * @return void
     */
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('hook_version', 32)->nullable();
        });
    }

    /**
     * Drop the column.
     *
     * @return void
     */
    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('hook_version');
        });
    }
};
