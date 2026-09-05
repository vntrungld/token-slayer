<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Admin-curated registry of raw model ids seen in `events.model`, keyed by
     * exact id (not family): a point release earns its own row so an admin can
     * disable one release without affecting a sibling. Populated by the
     * "Sync" action, which discovers ids from `events` — never by a migration
     * seed — so a fresh deploy starts with every model's flair off until an
     * admin reviews it.
     *
     * @return void
     */
    public function up(): void
    {
        Schema::create('ai_models', function (Blueprint $table) {
            $table->id();
            $table->string('model', 64)->unique();
            $table->boolean('flair_enabled')->default(false);
            $table->unsignedInteger('flair_duration_ms')->default(6000);
            $table->timestamps();
        });
    }

    /**
     * Drop the table.
     *
     * @return void
     */
    public function down(): void
    {
        Schema::dropIfExists('ai_models');
    }
};
