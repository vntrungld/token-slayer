<?php

namespace App\Filament\Resources\Accounts\RelationManagers;

use App\Enums\ModelFamily;
use App\Models\Event;
use App\Support\ModelName;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * Read-only stream of the events attributed to an `Account`
 * (`events.account_id`), newest first. Shows which developer logged each
 * event, its provider, token cost, and session — no create/edit/delete.
 */
class EventsRelationManager extends RelationManager
{
    /**
     * The relationship on the owner `Account` this manager reads.
     *
     * @var string
     */
    protected static string $relationship = 'events';

    /**
     * The navigation/tab title for this relation.
     *
     * @var string|null
     */
    protected static ?string $title = 'Events';

    /**
     * Render the events tab only for users granted the `view_events`
     * permission. super_admin passes via Shield's Gate::before bypass.
     *
     * @param  Model  $ownerRecord  the owning Account record
     * @param  string  $pageClass  the page the manager is about to render on
     * @return bool
     */
    public static function canViewForRecord(Model $ownerRecord, string $pageClass): bool
    {
        return auth()->user()?->can('view_events') ?? false;
    }

    /**
     * No form: the events stream is read-only.
     *
     * @param  Schema  $schema  The schema being configured by Filament.
     * @return Schema
     */
    public function form(Schema $schema): Schema
    {
        return $schema->components([]);
    }

    /**
     * Badge text for an event's model. A known family shows its label; a model
     * the enum does not know yet shows its raw id verbatim, so a newly released
     * model stays visible rather than collapsing into an "other" bucket; an
     * event from a client that predates model tracking shows a dash.
     *
     * @param  Event  $record  the event row being rendered
     * @return string
     */
    public static function modelLabel(Event $record): string
    {
        if ($record->model === null) {
            return '—';
        }

        return ModelName::for($record->model);
    }

    /**
     * Badge colour for an event's model, grey for anything with no known family.
     *
     * @param  Event  $record  the event row being rendered
     * @return string
     */
    public static function modelColor(Event $record): string
    {
        return ModelFamily::fromModelId($record->model)?->getColor() ?? 'gray';
    }

    /**
     * Build the read-only events table, newest first.
     *
     * @param  Table  $table  The table being configured by Filament.
     * @return Table
     */
    public function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query): Builder => $query->with('user'))
            ->defaultSort('created_at', 'desc')
            ->columns([
                TextColumn::make('created_at')
                    ->label('When')
                    ->dateTime()
                    ->sortable(),
                TextColumn::make('developer')
                    ->label('Developer')
                    ->state(fn (Event $record): string => $record->user?->displayHandle() ?? '#'.$record->user_id),
                TextColumn::make('provider')
                    ->badge()
                    ->color('gray'),
                TextColumn::make('tokens')
                    ->numeric()
                    ->sortable(),
                TextColumn::make('model')
                    ->label('Model')
                    ->badge()
                    ->state(fn (Event $record): string => self::modelLabel($record))
                    ->color(fn (Event $record): string => self::modelColor($record)),
                TextColumn::make('session_id')
                    ->label('Session')
                    ->limit(12)
                    ->toggleable(isToggledHiddenByDefault: true),
            ]);
    }
}
