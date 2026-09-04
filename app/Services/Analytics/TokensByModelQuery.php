<?php

namespace App\Services\Analytics;

use App\Enums\ModelFamily;
use App\Services\Analytics\Concerns\ScopesEventsByFilters;

/**
 * Range-wide token totals broken down by the model that produced them.
 * Grouping happens on the raw stored id and the display family is attached
 * afterwards, so a model the enum does not know yet still reports its real
 * total rather than disappearing into an "other" bucket.
 */
final class TokensByModelQuery
{
    use ScopesEventsByFilters;

    /**
     * Tokens and event counts per model in the range, largest first. Events
     * with no recorded model — rows predating model tracking, and rows from
     * clients that have not updated — collapse into a single `unknown` bucket.
     *
     * @param  UsageFilters  $filters  the shared analytics filter
     * @return array<int, array{model:string, label:string, tokens:int, events:int}>
     */
    public function get(UsageFilters $filters): array
    {
        return $this->scopeEvents($filters)
            ->groupBy('events.model')
            ->selectRaw('events.model as model, SUM(events.tokens) as tokens, COUNT(*) as events')
            ->orderByRaw('SUM(events.tokens) DESC')
            ->get()
            ->map(fn ($row): array => [
                'model' => $row->model ?? UsageFilters::UNKNOWN_MODEL,
                'label' => $row->model === null
                    ? 'Unknown'
                    : (ModelFamily::fromModelId($row->model)?->getLabel() ?? $row->model),
                'tokens' => (int) $row->tokens,
                'events' => (int) $row->events,
            ])
            ->all();
    }

    /**
     * Percentage of tokens in the range that carry no model, rounded to one
     * decimal. This is the only feedback signal that the client rollout is
     * progressing: without it the `unknown` bucket can stay the largest bar
     * for months while everyone assumes the feature shipped.
     *
     * @param  UsageFilters  $filters  the shared analytics filter
     * @return float 0.0 when the range contains no tokens at all
     */
    public function unknownShare(UsageFilters $filters): float
    {
        $total = (int) $this->scopeEvents($filters)->sum('events.tokens');

        if ($total === 0) {
            return 0.0;
        }

        $unknown = (int) $this->scopeEvents($filters)->whereNull('events.model')->sum('events.tokens');

        return round($unknown / $total * 100, 1);
    }
}
