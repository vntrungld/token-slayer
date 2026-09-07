<?php

namespace App\Filament\Widgets;

use App\Enums\ModelFamily;
use App\Services\Analytics\ModelContributorsQuery;
use App\Services\Analytics\TokensByModelQuery;
use App\Services\Analytics\UsageFilters;
use Filament\Support\RawJs;
use Filament\Widgets\ChartWidget;
use Filament\Widgets\Concerns\InteractsWithPageFilters;
use Illuminate\Support\Js;

/**
 * Bar chart of token spend per model, driven by the shared analytics page
 * filter. One bar per model family, keeping the family's own colour, with the
 * people behind it in the hover card.
 */
class TokensByModelChart extends ChartWidget
{
    use InteractsWithPageFilters;

    /**
     * How many contributors each model's hover card lists. Small on purpose:
     * the card is read at a glance while hovering, and the bar itself already
     * carries the untrimmed total.
     *
     * @var int
     */
    private const int CONTRIBUTORS = 5;

    /**
     * Colour used for a model id the family enum does not recognise. Fixed
     * rather than assigned by position, so a new model appearing does not
     * reshuffle every other bar's colour.
     *
     * @var string
     */
    private const string UNMAPPED_COLOR = '#6b7280';

    /**
     * Colour used for a bar with no family: events carrying no model at all,
     * and ids whose line the enum does not recognise yet.
     *
     * @var string
     */
    private const string UNKNOWN_COLOR = '#9ca3af';

    /**
     * Model family to bar colour. Keyed on the family, not on the bar's own
     * label, so every version of one line shares a colour — Opus 4.8 and
     * Opus 5 are two bars the eye still reads as one line.
     *
     * @var array<string, string>
     */
    private const array FAMILY_COLORS = [
        ModelFamily::Fable->value => '#d97706',
        ModelFamily::Opus->value => '#7c3aed',
        ModelFamily::Sonnet->value => '#059669',
        ModelFamily::Haiku->value => '#6b7280',
        ModelFamily::Gpt->value => '#2563eb',
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
     * Subtitle carrying the one thing the bars cannot say: that a token of
     * Opus is not a token of Sonnet in quota or money. Without the caveat a
     * tall cheap-model bar reads as "this is fine" when the opposite may be
     * true, and the project has no price table to weight with.
     *
     * The unknown share is included because it is the only feedback signal
     * that the client rollout is progressing. The per-model totals that used
     * to sit here are gone: the bars now carry them, and this line reported
     * them per raw model id, so a family split across two ids appeared twice
     * here and once on the axis.
     *
     * @return string|null
     */
    public function getDescription(): ?string
    {
        $filters = UsageFilters::fromPageFilters($this->filters ?? []);
        $unknown = app(TokensByModelQuery::class)->unknownShare($filters);

        return 'raw output tokens, not cost- or quota-equivalent across models'
            .($unknown > 0 ? ' · '.rtrim(rtrim(number_format($unknown, 1), '0'), '.').'% unknown' : '');
    }

    /**
     * Build the Chart.js data: one bar per model family, coloured per bar
     * rather than per dataset because there is only the one dataset.
     *
     * @return array<string, mixed>
     */
    public function getChartData(): array
    {
        return $this->getData();
    }

    /**
     * The hover-card lines for each bar, positionally aligned with the labels
     * `getChartData()` returns — one list of `handle — tokens` strings per
     * model, biggest contributor first.
     *
     * Formatted here rather than in the browser so the numbers match the rest
     * of the panel, and exposed so the alignment can be tested without going
     * through the rendered chart.
     *
     * @return array<int, array<int, string>>
     */
    public function getContributorLines(): array
    {
        return array_map(
            fn (array $row): array => array_map(
                fn (array $user): string => $user['handle'].' — '.number_format($user['tokens']),
                $row['top_users'],
            ),
            $this->contributors(),
        );
    }

    /**
     * The Chart.js options, exposed for testing: {@see getOptions()} is
     * protected, and the escaping this builds is the part worth asserting on.
     *
     * @return RawJs
     */
    public function getChartOptions(): RawJs
    {
        return $this->buildOptions();
    }

    /**
     * @return array<string, mixed>
     */
    protected function getData(): array
    {
        $rows = $this->contributors();
        $colors = array_map(fn (array $row): string => self::colorFor($row['family']), $rows);

        return [
            'datasets' => [[
                'label' => 'Tokens',
                'data' => array_column($rows, 'tokens'),
                'backgroundColor' => $colors,
                'borderColor' => $colors,
            ]],
            'labels' => array_column($rows, 'label'),
        ];
    }

    /**
     * @return array<string, mixed>|RawJs|null
     */
    protected function getOptions(): array|RawJs|null
    {
        return $this->buildOptions();
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

    /**
     * Options as raw JS, because the hover card needs a `afterBody` callback
     * and a callback cannot survive a PHP array.
     *
     * Two constraints shape how this string is written. Filament drops it
     * verbatim into `x-data="chart({...})"`, a double-quoted HTML attribute,
     * so a single literal `"` anywhere in it — a Slack handle's included —
     * would close that attribute early and spill the rest of the options onto
     * the page as text. And the contributor lines are user-supplied strings
     * landing in an executable context. Both are handled by encoding the lines
     * through {@see Js}, whose `JSON.parse('…')` output escapes every quote,
     * apostrophe, angle bracket and ampersand to a `\uXXXX` sequence; the JS
     * around it is then written with single quotes only.
     *
     * The parse runs once in an IIFE rather than inside the callback, which
     * would re-parse the whole table on every mouse move.
     *
     * @return RawJs
     */
    private function buildOptions(): RawJs
    {
        $lines = Js::from($this->getContributorLines());

        return RawJs::make(<<<JS
            (() => {
                const contributors = {$lines};
                return {
                    plugins: {
                        legend: { display: false },
                        tooltip: {
                            callbacks: {
                                label: (item) => item.formattedValue + ' tokens',
                                afterBody: (items) => contributors[items[0].dataIndex] ?? [],
                            },
                        },
                    },
                    scales: {
                        y: { beginAtZero: true },
                    },
                };
            })()
            JS);
    }

    /**
     * The model rows behind both the bars and the hover cards, fetched once
     * per render so the two can never disagree about order or totals.
     *
     * @return array<int, array{label:string, tokens:int, top_users:array<int, array{handle:string, tokens:int}>}>
     */
    private function contributors(): array
    {
        return app(ModelContributorsQuery::class)
            ->get(UsageFilters::fromPageFilters($this->filters ?? []), self::CONTRIBUTORS);
    }

    /**
     * Bar colour for a model, taken from its family.
     *
     * @param  ?ModelFamily  $family  the bar's family, or null when the id matched none
     * @return string
     */
    private static function colorFor(?ModelFamily $family): string
    {
        if ($family === null) {
            return self::UNKNOWN_COLOR;
        }

        return self::FAMILY_COLORS[$family->value] ?? self::UNMAPPED_COLOR;
    }
}
