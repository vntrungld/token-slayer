<?php

namespace App\Services\Battlefield;

use App\Enums\ModelFamily;
use App\Models\AiModel;
use Illuminate\Support\Facades\Cache;

/**
 * Decides whether the model that produced a turn earns a battlefield flair
 * badge, and for how long. The decision lives server-side and reads the
 * admin-curated `ai_models` registry — the JS never learns which model ids
 * are special, so enabling a new one is an admin toggle with no rebuild.
 */
class ModelFlairResolver
{
    /**
     * How long the enabled-models snapshot is cached. `/api/events` is the
     * single append-only write path and reads this on every Stop, so a raw
     * query per event is avoided; short enough that an admin's toggle reaches
     * the battlefield within a minute.
     *
     * @var int
     */
    private const int CACHE_TTL_SECONDS = 60;

    /**
     * Cache key for the enabled-models snapshot.
     *
     * @var string
     */
    private const string CACHE_KEY = 'battlefield:flair-models';

    /**
     * The flair decision for a model id, or null when it earns none — because
     * it has no registry row at all, or its row exists but is disabled.
     * Matching is by the exact raw id (an admin decision): a sibling point
     * release is a separate, unreviewed row until enabled on its own.
     *
     * @param  ?string  $model  the raw id stored in `events.model`
     * @return ?FlairDecision
     */
    public function resolve(?string $model): ?FlairDecision
    {
        if ($model === null) {
            return null;
        }

        $durationMs = $this->enabledModels()[$model] ?? null;

        if ($durationMs === null) {
            return null;
        }

        return new FlairDecision(
            ModelFamily::fromModelId($model)?->value ?? $model,
            $durationMs,
        );
    }

    /**
     * The currently flair-enabled models, keyed by raw id, mapped to their
     * configured duration.
     *
     * @return array<string, int>
     */
    private function enabledModels(): array
    {
        return Cache::remember(
            self::CACHE_KEY,
            self::CACHE_TTL_SECONDS,
            fn (): array => AiModel::where('flair_enabled', true)->pluck('flair_duration_ms', 'model')->all(),
        );
    }
}
