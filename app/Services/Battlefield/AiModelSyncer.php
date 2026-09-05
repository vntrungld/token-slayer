<?php

namespace App\Services\Battlefield;

use App\Models\AiModel;
use App\Models\Event;

/**
 * Discovers raw model ids from `events` that have no row in the admin-curated
 * `ai_models` registry yet, and creates them with flair off by default — an
 * unreviewed model must not start blinking on the battlefield unattended.
 */
class AiModelSyncer
{
    /**
     * Create a row for every distinct `events.model` not already registered.
     * Existing rows, including any an admin has manually enabled, are left
     * untouched.
     *
     * @return int how many new models were discovered
     */
    public function sync(): int
    {
        $known = AiModel::pluck('model')->all();

        $discovered = Event::query()
            ->whereNotNull('model')
            ->whereNotIn('model', $known)
            ->distinct()
            ->pluck('model');

        foreach ($discovered as $model) {
            AiModel::create(['model' => $model]);
        }

        return $discovered->count();
    }
}
