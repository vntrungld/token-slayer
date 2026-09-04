<?php

namespace App\Filament\Resources\Users\RelationManagers;

use App\Enums\ModelFamily;
use App\Filament\Resources\Users\Pages\ViewUser;
use App\Models\Event;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * Read-only stream of the events logged by a `User` (`events.user_id`),
 * newest first, across every org account they've used. No create/edit/delete.
 */
class EventsRelationManager extends RelationManager
{
    /**
     * The relationship on the owner `User` this manager reads.
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
     * Render this relation manager only on the View page (keeping Edit focused
     * on role assignment) AND only for users granted the `view_events`
     * permission. super_admin passes via Shield's Gate::before bypass.
     *
     * @param  Model  $ownerRecord  the owning User record
     * @param  string  $pageClass  the page the manager is about to render on
     * @return bool
     */
    public static function canViewForRecord(Model $ownerRecord, string $pageClass): bool
    {
        return $pageClass === ViewUser::class
            && (auth()->user()?->can('view_events') ?? false);
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

        return ModelFamily::fromModelId($record->model)?->getLabel() ?? $record->model;
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
            ->modifyQueryUsing(fn (Builder $query): Builder => $query->with('account'))
            ->defaultSort('created_at', 'desc')
            ->columns([
                TextColumn::make('created_at')
                    ->label('When')
                    ->dateTime()
                    ->sortable(),
                TextColumn::make('account')
                    ->label('Account')
                    ->state(fn (Event $record): string => $record->account?->email ?? $record->account_email ?? '—'),
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
