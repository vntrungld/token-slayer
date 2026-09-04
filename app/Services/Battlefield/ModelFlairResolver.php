<?php

namespace App\Services\Battlefield;

use App\Enums\ModelFamily;

/**
 * Decides whether the model that produced a turn earns a battlefield flair
 * badge. The decision lives server-side on purpose: the JS never learns which
 * model ids are special, so marking a new model is a config edit with no
 * frontend rebuild.
 */
class ModelFlairResolver
{
    /**
     * The flair key for a model id, or null when it earns none. The key is the
     * model FAMILY rather than the raw id, so a point release
     * (`claude-fable-5-2`) keeps rendering the badge the JS already knows.
     *
     * @param  ?string  $model  the raw id stored in `events.model`
     * @return ?string
     */
    public function resolve(?string $model): ?string
    {
        if ($model === null) {
            return null;
        }

        foreach ((array) config('game.flair_models', []) as $prefix) {
            if (str_starts_with($model, (string) $prefix)) {
                return ModelFamily::fromModelId($model)?->value ?? $model;
            }
        }

        return null;
    }
}
