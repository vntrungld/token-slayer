<?php

namespace App\Filament\Resources\AiModels;

use App\Enums\ModelFamily;
use App\Filament\Resources\AiModels\Pages\ListAiModels;
use App\Models\AiModel;
use App\Services\Analytics\TokensByModelQuery;
use App\Services\Analytics\UsageFilters;
use App\Services\Battlefield\AiModelSyncer;
use App\Services\Battlefield\ModelFlairResolver;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\ColorPicker;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\PageRegistration;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Columns\ToggleColumn;
use Filament\Tables\Table;
use UnitEnum;

/**
 * Admin registry of raw model ids: total tokens/events per model (read from
 * `events` via {@see TokensByModelQuery}), whether a model earns a battlefield
 * flair badge, and its animation (duration + color) — the latter two edited
 * via the "Edit animation" popup, with no separate create/edit page. Rows are
 * only ever added by the Sync header action, never by hand, so a fresh
 * deploy starts every model unreviewed.
 */
class AiModelResource extends Resource
{
    /**
     * The Eloquent model this resource manages.
     *
     * @var class-string<AiModel>|null
     */
    protected static ?string $model = AiModel::class;

    /**
     * Sidebar navigation icon.
     *
     * @var string|BackedEnum|null
     */
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedCpuChip;

    /**
     * Navigation group this resource belongs to.
     *
     * @var string|UnitEnum|null
     */
    protected static string|UnitEnum|null $navigationGroup = 'Analytics';

    /**
     * Sidebar label — "Models" per the owner's naming, distinct from the
     * per-org "Accounts" resource.
     *
     * @return string
     */
    public static function getNavigationLabel(): string
    {
        return 'Models';
    }

    /**
     * Build the table: read-only totals from {@see TokensByModelQuery}, the
     * inline "Badge" toggle, and the "Edit animation" row action, which is
     * the only place duration/color are shown or edited — no separate
     * columns for them, so the table stays uncluttered.
     *
     * @param  Table  $table  the table being configured
     * @return Table
     */
    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('model')
                    ->label('Model')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('tokens')
                    ->label('Tokens')
                    ->state(fn (AiModel $record): string => number_format(
                        self::tokensByModel()[$record->model]['tokens'] ?? 0,
                    )),
                TextColumn::make('events')
                    ->label('Events')
                    ->state(fn (AiModel $record): int => self::tokensByModel()[$record->model]['events'] ?? 0),
                ToggleColumn::make('flair_enabled')
                    ->label('Badge'),
            ])
            ->defaultSort('model')
            ->headerActions([
                Action::make('sync')
                    ->label('Sync')
                    ->icon(Heroicon::OutlinedArrowPath)
                    ->authorize('update')
                    ->action(function (): void {
                        $found = app(AiModelSyncer::class)->sync();

                        Notification::make()
                            ->title($found > 0 ? "Synced: {$found} new model(s) found" : 'Synced: no new models')
                            ->success()
                            ->send();
                    }),
            ])
            ->recordActions([
                self::editAnimationAction(),
            ]);
    }

    /**
     * Builds the "Edit animation" row action: a popup with the flair color
     * (Filament's native color picker) and duration, plus a live preview of
     * the orbiting-halo effect (see resources/views/filament/flair-preview.
     * blade.php) that updates instantly as the admin adjusts either field —
     * no save/reload needed to see the result.
     *
     * @return Action
     */
    private static function editAnimationAction(): Action
    {
        return Action::make('edit-animation')
            ->label('Edit animation')
            ->icon(Heroicon::OutlinedSparkles)
            ->authorize('update')
            ->fillForm(fn (AiModel $record): array => [
                'flair_duration_ms' => $record->flair_duration_ms,
                'flair_color' => $record->flair_color,
            ])
            ->modalContent(fn (AiModel $record) => view('filament.flair-preview', [
                'label' => strtoupper(ModelFamily::fromModelId($record->model)?->value ?? $record->model),
                'color' => $record->flair_color ?? ModelFlairResolver::DEFAULT_COLOR,
                'durationMs' => $record->flair_duration_ms,
            ]))
            ->schema([
                // Stable ids the preview polls every animation frame (see
                // flair-preview.blade.php) rather than listening for an
                // 'input' DOM event: the color picker's drag panel is a
                // separate web component that updates Filament's own Alpine
                // `state` var directly, without firing a native 'input'
                // event on this text input -- only typing a hex value by
                // hand would. Polling `.value` catches both, since Alpine's
                // x-model keeps that DOM property in sync regardless of
                // which interaction changed the underlying state.
                ColorPicker::make('flair_color')
                    ->label('Color')
                    ->hex()
                    ->extraInputAttributes(['id' => 'flair-preview-color-input']),
                TextInput::make('flair_duration_ms')
                    ->label('Duration (ms)')
                    ->numeric()
                    ->minValue(500)
                    ->required()
                    ->extraInputAttributes(['id' => 'flair-preview-duration-input']),
            ])
            ->action(function (AiModel $record, array $data): void {
                $record->update($data);

                Notification::make()->success()->title('Animation updated')->send();
            });
    }

    /**
     * All-time tokens/events per raw model id, memoized for the request so
     * every row's column state closure shares one query instead of N+1-ing.
     *
     * @return array<string, array{model:string, label:string, tokens:int, events:int}>
     */
    private static function tokensByModel(): array
    {
        static $cache = null;

        if ($cache === null) {
            $cache = collect(app(TokensByModelQuery::class)->get(UsageFilters::fromPageFilters(['range' => 'all'])))
                ->keyBy('model')
                ->all();
        }

        return $cache;
    }

    /**
     * The pages this resource registers — list only, since editing happens
     * inline in the table and rows are created only by Sync.
     *
     * @return array<string, PageRegistration>
     */
    public static function getPages(): array
    {
        return [
            'index' => ListAiModels::route('/'),
        ];
    }
}
