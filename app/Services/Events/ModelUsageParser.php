<?php

namespace App\Services\Events;

use App\Enums\ModelFamily;
use Illuminate\Support\Facades\Log;

/**
 * Turns the hook's client-supplied `models` map into a single trustworthy
 * `events.model` value. The map is attacker-controllable — a hook token is all
 * that stands in front of it — so nothing from it reaches the database before
 * passing through {@see self::sanitize()}.
 */
class ModelUsageParser
{
    /**
     * Longest model id accepted, matching the `events.model` column width. A
     * longer key is dropped rather than truncated: truncation could merge two
     * distinct models into one bucket and silently corrupt the totals, while
     * dropping loses only that entry.
     *
     * @var int
     */
    private const int MAX_KEY_LENGTH = 64;

    /**
     * Most models kept from one turn. A real turn has one; the cap exists so a
     * malicious client cannot push an unbounded map through the warning below.
     * Excess entries are dropped lowest-token-first, degrading the payload
     * rather than discarding it wholesale.
     *
     * @var int
     */
    private const int MAX_MODELS = 12;

    /**
     * Reduce arbitrary client input to a map of raw model id => positive token
     * count, dropping anything that does not qualify.
     *
     * @param  mixed  $models  the raw `models` field from the hook payload
     * @return array<string, int>
     */
    public function sanitize(mixed $models): array
    {
        if (! is_array($models)) {
            return [];
        }

        $clean = [];

        foreach ($models as $model => $tokens) {
            if (! is_string($model)) {
                continue;
            }

            $model = trim($model);

            if ($model === '' || mb_strlen($model) > self::MAX_KEY_LENGTH) {
                continue;
            }

            if (! is_int($tokens) && ! (is_string($tokens) && ctype_digit($tokens))) {
                continue;
            }

            $tokens = (int) $tokens;

            if ($tokens <= 0) {
                continue;
            }

            $clean[$model] = $tokens;
        }

        arsort($clean);

        return array_slice($clean, 0, self::MAX_MODELS, preserve_keys: true);
    }

    /**
     * The single model a turn is recorded under: the most expensive family it
     * touched, then the highest token count within that family, then key order
     * for determinism. Ranking by cost rather than by token count is
     * deliberate — in a limit-fallback turn the cheap model produces more
     * tokens precisely because it finished the work the expensive one started,
     * so a token-count rule would hide the expensive spend in the cheap bucket.
     *
     * @param  array<string, int>  $models  a sanitized map
     * @return ?string
     */
    public function primaryModel(array $models): ?string
    {
        if ($models === []) {
            return null;
        }

        if (count($models) > 1) {
            Log::warning('Turn used multiple models', ['models' => $models]);
        }

        $ranked = [];

        foreach ($models as $model => $tokens) {
            $ranked[] = [
                'model' => $model,
                'rank' => ModelFamily::fromModelId($model)?->rank() ?? 0,
                'tokens' => $tokens,
            ];
        }

        usort($ranked, fn (array $a, array $b): int => [$b['rank'], $b['tokens'], $a['model']]
            <=> [$a['rank'], $a['tokens'], $b['model']]);

        return $ranked[0]['model'];
    }
}
