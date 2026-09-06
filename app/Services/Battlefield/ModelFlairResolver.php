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
     * Cache key for the enabled-models snapshot. Versioned (:v2) because the
     * cached shape changed from `array<string,int>` (bare duration) to
     * `array<string, array{duration_ms:int, color:?string}>` — reusing the
     * same key would let a stale pre-deploy entry survive past a deploy that
     * skips `optimize:clear` (e.g. a direct-SSH hotfix), and `resolve()`
     * indexing `['duration_ms']` into what is actually a bare int throws a
     * TypeError that fails the whole `/api/events` request until the entry
     * expires. Bump this suffix again the next time the cached shape changes.
     *
     * @var string
     */
    private const string CACHE_KEY = 'battlefield:flair-models:v2';

    /**
     * Flair color for a row that has none configured yet — every row created
     * by Sync starts with a null color, so this keeps the badge rendering
     * (in the one color the effect always used before this was configurable)
     * until an admin picks one.
     *
     * @var string
     */
    public const string DEFAULT_COLOR = '#fbbf24';

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

        $row = $this->enabledModels()[$model] ?? null;

        if ($row === null) {
            return null;
        }

        return new FlairDecision(
            ModelFamily::fromModelId($model)?->value ?? $model,
            $row['duration_ms'],
            $row['color'] ?? self::DEFAULT_COLOR,
        );
    }

    /**
     * The currently flair-enabled models, keyed by raw id, mapped to their
     * configured duration and color.
     *
     * @return array<string, array{duration_ms: int, color: ?string}>
     */
    private function enabledModels(): array
    {
        return Cache::remember(
            self::CACHE_KEY,
            self::CACHE_TTL_SECONDS,
            fn (): array => AiModel::where('flair_enabled', true)
                ->get(['model', 'flair_duration_ms', 'flair_color'])
                ->mapWithKeys(fn (AiModel $row): array => [
                    $row->model => ['duration_ms' => $row->flair_duration_ms, 'color' => $row->flair_color],
                ])
                ->all(),
        );
    }
}
