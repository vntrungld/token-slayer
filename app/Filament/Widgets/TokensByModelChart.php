<?php

namespace App\Filament\Widgets;

use App\Services\Analytics\TokensByModelQuery;
use App\Services\Analytics\TokensByUserAndModelQuery;
use App\Services\Analytics\UsageFilters;
use Filament\Widgets\ChartWidget;
use Filament\Widgets\Concerns\InteractsWithPageFilters;

/**
 * Stacked bar chart of token spend per developer, split by the model that
 * produced it, driven by the shared analytics page filter.
 */
class TokensByModelChart extends ChartWidget
{
    use InteractsWithPageFilters;

    /**
     * Users on the axis. Matches {@see TopUsersLeaderboard::LIMIT} so the two
     * widgets on the same page agree on who "the top users" are.
     *
     * @var int
     */
    private const int LIMIT = 10;

    /**
     * Colour used for a model id the family enum does not recognise. Fixed
     * rather than assigned by position, so a new model appearing does not
     * reshuffle every other bar's colour.
     *
     * @var string
     */
    private const string UNMAPPED_COLOR = '#6b7280';

    /**
     * Colour used for events carrying no model at all.
     *
     * @var string
     */
    private const string UNKNOWN_COLOR = '#9ca3af';

    /**
     * Family label to bar colour. Derived from the enum so the chart, the admin
     * badges and any future surface agree.
     *
     * @var array<string, string>
     */
    private const array FAMILY_COLORS = [
        'Fable' => '#d97706',
        'Opus' => '#7c3aed',
        'Sonnet' => '#059669',
        'Haiku' => '#6b7280',
        'GPT' => '#2563eb',
    ];

    /**
     * The heading shown above the chart.
     *
     * @var string|null
     */
    protected ?string $heading = 'Tokens by model';

    /**
     * How many of the page's columns this widget spans.
     *
     * @var int|string|array<string, int|string|null>
     */
    protected int|string|array $columnSpan = 1;

    /**
     * Maximum canvas height so the full-width chart stays compact.
     *
     * @var string|null
     */
    protected ?string $maxHeight = '300px';

    /**
     * Only users granted this widget's own View permission see it, so the role
     * editor's Widgets tab toggles each chart independently. super_admin passes
     * via the Gate::before bypass.
     *
     * @return bool
     */
    public static function canView(): bool
    {
        return auth()->user()?->can('View:TokensByModelChart') ?? false;
    }

    /**
     * Subtitle carrying the two things the bars alone cannot say: the
     * range-wide totals per model, and the fact that a token of Opus is not a
     * token of Sonnet in quota or money. Without the caveat a tall cheap-model
     * bar reads as "this person is fine" when the opposite may be true, and the
     * project has no price table to weight with.
     *
     * The unknown share is included because it is the only feedback signal that
     * the client rollout is progressing.
     *
     * @return string|null
     */
    public function getDescription(): ?string
    {
        $filters = UsageFilters::fromPageFilters($this->filters ?? []);
        $query = app(TokensByModelQuery::class);

        $totals = collect($query->get($filters))
            ->map(fn (array $row): string => $row['label'].' '.number_format($row['tokens']))
            ->implode(' · ');

        $unknown = $query->unknownShare($filters);

        return trim(($totals !== '' ? $totals.' — ' : '')
            .'raw output tokens, not cost- or quota-equivalent across models'
            .($unknown > 0 ? ' · '.rtrim(rtrim(number_format($unknown, 1), '0'), '.').'% unknown' : ''));
    }

    /**
     * Build the Chart.js datasets: one per model family, each dense across the
     * top users so the stacks line up positionally with the labels.
     *
     * @return array<string, mixed>
     */
    public function getChartData(): array
    {
        return $this->getData();
    }

    /**
     * @return array<string, mixed>
     */
    protected function getData(): array
    {
        $result = app(TokensByUserAndModelQuery::class)
            ->get(UsageFilters::fromPageFilters($this->filters ?? []), self::LIMIT);

        $datasets = [];

        foreach ($result['matrix'] as $label => $values) {
            $color = self::colorFor($label);

            $datasets[] = [
                'label' => $label,
                'data' => $values,
                'backgroundColor' => $color,
                'borderColor' => $color,
            ];
        }

        return [
            'datasets' => $datasets,
            'labels' => array_column($result['users'], 'handle'),
        ];
    }

    /**
     * Bar colour for a dataset label.
     *
     * @param  string  $label  a family label, a raw model id, or `Unknown`
     * @return string
     */
    private static function colorFor(string $label): string
    {
        if ($label === 'Unknown') {
            return self::UNKNOWN_COLOR;
        }

        return self::FAMILY_COLORS[$label] ?? self::UNMAPPED_COLOR;
    }

    /**
     * Stack both axes so each bar reads as one developer's total split by model.
     *
     * @return array<string, mixed>
     */
    protected function getOptions(): array
    {
        return [
            'scales' => [
                'x' => ['stacked' => true],
                'y' => ['stacked' => true, 'beginAtZero' => true],
            ],
        ];
    }

    /**
     * The Chart.js chart type.
     *
     * @return string
     */
    protected function getType(): string
    {
        return 'bar';
    }
}
