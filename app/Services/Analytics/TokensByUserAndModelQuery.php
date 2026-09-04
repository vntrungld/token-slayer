<?php

namespace App\Services\Analytics;

use App\Enums\ModelFamily;
use App\Services\Analytics\Concerns\ScopesEventsByFilters;

/**
 * Per-user, per-model token totals for the analytics page's stacked bar chart.
 * Deliberately separate from {@see TokensByModelQuery}: a stacked chart needs a
 * dense user × model matrix, which a single `group by model` cannot produce.
 */
final class TokensByUserAndModelQuery
{
    use ScopesEventsByFilters;

    /**
     * The top `$limit` users by total tokens in the range, with each one's spend
     * split by model family. Every model row is dense — a user with no events
     * for a model reports 0 rather than being absent — because the chart indexes
     * datasets positionally against the user labels, so a sparse row would shift
     * bars onto the wrong people.
     *
     * @param  UsageFilters  $filters  the shared analytics filter
     * @param  int  $limit  how many users to include on the axis
     * @return array{users: array<int, array{user_id:int, handle:string}>, matrix: array<string, array<int, int>>}
     */
    public function get(UsageFilters $filters, int $limit): array
    {
        $top = (new TopUsersQuery)->get($filters, $limit);
        $userIds = array_column($top, 'user_id');

        if ($userIds === []) {
            return ['users' => [], 'matrix' => []];
        }

        $rows = $this->scopeEvents($filters)
            ->whereIn('events.user_id', $userIds)
            ->groupBy('events.user_id', 'events.model')
            ->selectRaw('events.user_id as user_id, events.model as model, SUM(events.tokens) as tokens')
            ->get();

        $positions = array_flip($userIds);
        $matrix = [];

        foreach ($rows as $row) {
            $label = $row->model === null
                ? 'Unknown'
                : (ModelFamily::fromModelId($row->model)?->getLabel() ?? $row->model);

            $matrix[$label] ??= array_fill(0, count($userIds), 0);

            // += rather than =: two raw ids in the same family (claude-opus-4-8
            // and claude-opus-5) share a label and must sum, not overwrite.
            $matrix[$label][$positions[(int) $row->user_id]] += (int) $row->tokens;
        }

        ksort($matrix);

        return [
            'users' => array_map(
                fn (array $user): array => ['user_id' => $user['user_id'], 'handle' => $user['handle']],
                $top,
            ),
            'matrix' => $matrix,
        ];
    }
}
