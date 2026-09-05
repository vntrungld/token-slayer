<?php

namespace App\Filament\Resources\AiModels;

use App\Filament\Resources\AiModels\Pages\ListAiModels;
use App\Models\AiModel;
use App\Services\Analytics\TokensByModelQuery;
use App\Services\Analytics\UsageFilters;
use App\Services\Battlefield\AiModelSyncer;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\PageRegistration;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Columns\TextInputColumn;
use Filament\Tables\Columns\ToggleColumn;
use Filament\Tables\Table;
use UnitEnum;

/**
 * Admin registry of raw model ids: total tokens/events per model (read from
 * `events` via {@see TokensByModelQuery}), whether a model earns a battlefield
 * flair badge, and for how long — both edited inline in the table, with no
 * separate create/edit page. Rows are only ever added by the Sync header
 * action, never by hand, so a fresh deploy starts every model unreviewed.
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
     * Build the table: read-only totals from {@see TokensByModelQuery}, plus
     * the two inline-editable columns.
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
                TextInputColumn::make('flair_duration_ms')
                    ->label('Duration (ms)')
                    ->type('number'),
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
            ]);
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
