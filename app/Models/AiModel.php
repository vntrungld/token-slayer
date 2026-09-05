<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Admin-curated registry of raw model ids (`ai_models.model` matches
 * `events.model` exactly), each carrying whether it earns a battlefield flair
 * badge and for how long. Rows are created by the "Sync" action, never by a
 * migration seed, so a fresh deploy starts with everything off until reviewed.
 */
class AiModel extends Model
{
    protected $guarded = [];

    /**
     * The attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'flair_enabled' => 'boolean',
            'flair_duration_ms' => 'integer',
        ];
    }
}
