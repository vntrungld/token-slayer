<?php

namespace App\Services\Analytics;

use App\Enums\ModelFamily;
use App\Services\Analytics\Concerns\ScopesEventsByFilters;
use App\Support\ModelName;

/**
 * Per-model token totals with the people behind each one, for the analytics
 * page's model chart: one bar per model, and the contributor list its hover
 * card reads from.
 *
 * Deliberately separate from {@see TokensByModelQuery}, which groups on the
 * raw stored id and knows nothing about who spent it, and from
 * {@see TokensByUserAndModelQuery}, which ranks users globally first. Ranking
 * globally is exactly what this must not do: a developer who runs one model
 * and nothing else never reaches an overall top-N, yet can be that model's
 * entire story.
 */
final class ModelContributorsQuery
{
    use ScopesEventsByFilters;

    /**
     * Models in the range, biggest total first, each with its own top
     * contributors.
     *
     * Grouped by DISPLAY NAME, not by raw id and not by family. Not by raw id,
     * because two dated builds of one model (`claude-haiku-4-5-20251001` and a
     * later stamp) render identically and two bars with the same label are
     * unreadable. Not by family, because Opus 4.8 and Opus 5 are different
     * models at different prices — and because "GPT" is a whole vendor rather
     * than one line, so a family bar would hide every OpenAI model a developer
     * can choose between. The family rides along only to colour the bar, which
     * keeps one line's versions in one colour.
     *
     * @param  UsageFilters  $filters  the shared analytics filter
     * @param  int  $topUsers  how many contributors to keep per model
     * @return array<int, array{label:string, family:?ModelFamily, tokens:int, top_users:array<int, array{handle:string, tokens:int}>}>
     */
    public function get(UsageFilters $filters, int $topUsers): array
    {
        $rows = $this->scopeEvents($filters)
            ->join('users', 'users.id', '=', 'events.user_id')
            ->groupBy('events.model', 'users.id', 'users.slack_handle', 'users.display_name', 'users.name')
            ->selectRaw('events.model as model')
            ->selectRaw('users.id as user_id, users.slack_handle, users.display_name, users.name')
            ->selectRaw('SUM(events.tokens) as tokens')
            ->get();

        // label => ['family' => …, 'tokens' => total, 'users' => user_id => [handle, tokens]].
        // Folding in PHP rather than SQL because the display name comes from a
        // PHP formatter, not a column: the database cannot group by it.
        $models = [];

        foreach ($rows as $row) {
            $label = ModelName::for($row->model);
            $userId = (int) $row->user_id;
            $tokens = (int) $row->tokens;

            $models[$label]['family'] = ModelName::familyOf($row->model);
            $models[$label]['tokens'] = ($models[$label]['tokens'] ?? 0) + $tokens;
            $models[$label]['users'][$userId]['handle'] = $this->displayHandle($row, $userId);
            $models[$label]['users'][$userId]['tokens'] = ($models[$label]['users'][$userId]['tokens'] ?? 0) + $tokens;
        }

        uasort($models, fn (array $a, array $b): int => $b['tokens'] <=> $a['tokens']);

        return array_map(
            fn (string $label): array => [
                'label' => $label,
                'family' => $models[$label]['family'],
                'tokens' => $models[$label]['tokens'],
                'top_users' => $this->rankContributors($models[$label]['users'], $topUsers),
            ],
            array_keys($models),
        );
    }

    /**
     * Resolve a user's display handle from the available identity columns,
     * falling back to a `#id` label.
     *
     * @param  object  $row  a row carrying slack_handle/display_name/name columns
     * @param  int  $userId  the user's id, for the fallback label
     * @return string
     */
    private function displayHandle(object $row, int $userId): string
    {
        return $row->slack_handle ?: ($row->display_name ?: ($row->name ?: ('#'.$userId)));
    }

    /**
     * One model's contributors, biggest first and trimmed to `$limit`. The
     * trimmed remainder is intentionally not folded into an "others" entry:
     * the bar already carries the untrimmed total, so the hover card is a
     * "who is behind this" list, not a second accounting of the same number.
     *
     * @param  array<int, array{handle:string, tokens:int}>  $users  keyed by user id
     * @param  int  $limit  how many to keep
     * @return array<int, array{handle:string, tokens:int}>
     */
    private function rankContributors(array $users, int $limit): array
    {
        $ranked = array_values($users);

        usort($ranked, fn (array $a, array $b): int => $b['tokens'] <=> $a['tokens']);

        return array_slice($ranked, 0, $limit);
    }
}
