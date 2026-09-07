<?php

namespace App\Filament\Resources\Accounts;

use App\Enums\AccountPlan;
use App\Enums\AccountStatus;
use App\Enums\CodexPlan;
use App\Enums\Provider;
use App\Filament\Actions\ClaudeReconnectModal;
use App\Filament\Resources\Accounts\Pages\CreateAccount;
use App\Filament\Resources\Accounts\Pages\EditAccount;
use App\Filament\Resources\Accounts\Pages\ListAccounts;
use App\Filament\Resources\Accounts\Pages\ViewAccount;
use App\Filament\Resources\Accounts\RelationManagers\EventsRelationManager;
use App\Filament\Resources\Accounts\RelationManagers\MembersRelationManager;
use App\Filament\Resources\Accounts\RelationManagers\ProvisionsRelationManager;
use App\Models\Account;
use App\Models\ClaudeCredential;
use App\Services\AccountConnectService;
use App\Services\Accounts\PlanBadgeResolver;
use App\Services\Accounts\PlanResolver;
use App\Services\ProviderServiceFactory;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Actions\ViewAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Infolists\Components\TextEntry;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\PageRegistration;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Validation\Rules\Unique;

/**
 * Admin CRUD for org `Account` records: connection details, plan, status,
 * and (via `MembersRelationManager`) which `User`s are members of the account.
 * Attribution on already-ingested `events` rows is unaffected by edits or
 * deletes here — events keep the raw `account_email` they were stamped with.
 */
class AccountResource extends Resource
{
    /**
     * The Eloquent model this resource manages.
     *
     * @var class-string<Account>|null
     */
    protected static ?string $model = Account::class;

    /**
     * Sidebar navigation icon for this resource.
     *
     * @var string|BackedEnum|null
     */
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedRectangleStack;

    /**
     * Build the create/edit form: email, organization UUID (auto-learned
     * from events, or pasted manually for immediate attribution), display
     * name, plan, and (edit-only) the connection status.
     *
     * @param  Schema  $schema  The schema being configured by Filament.
     * @return Schema
     */
    public static function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                TextInput::make('email')
                    ->email()
                    ->required()
                    ->unique(
                        ignoreRecord: true,
                        modifyRuleUsing: fn (Unique $rule): Unique => $rule->where('provider', Provider::Claude),
                    )
                    ->maxLength(255)
                    ->disabledOn('edit'),
                TextInput::make('organization_uuid')
                    ->label('Organization UUID')
                    ->helperText('Auto-learned from events; paste manually to attribute switcher users immediately.')
                    ->unique(
                        table: 'claude_credentials',
                        column: 'organization_uuid',
                        modifyRuleUsing: fn (Unique $rule, ?Account $record): Unique => $record?->claudeCredential !== null
                            ? $rule->ignore($record->claudeCredential->id)
                            : $rule,
                    )
                    ->maxLength(64)
                    ->disabledOn('edit'),
                TextInput::make('name')
                    ->maxLength(255),
                Select::make('plan')
                    ->options(AccountPlan::class)
                    ->required()
                    ->default(AccountPlan::Max20x)
                    ->helperText('From Claude (organization type × rate limit tier).')
                    ->disabledOn('edit'),
                Select::make('status')
                    ->options(AccountStatus::class)
                    ->required()
                    ->hiddenOn('create'),
            ]);
    }

    /**
     * Build the index table: identity columns, member count, status badge,
     * and last-probed recency.
     *
     * @param  Table  $table  The table being configured by Filament.
     * @return Table
     */
    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('provider')
                    ->badge()
                    ->sortable(),
                TextColumn::make('email')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('name')
                    ->searchable()
                    ->toggleable(),
                TextColumn::make('plan')
                    ->badge()
                    ->state(fn (Account $record): AccountPlan|CodexPlan|null => app(PlanBadgeResolver::class)->for($record))
                    ->placeholder('Unknown'),
                TextColumn::make('tracked_users_count')
                    ->counts('trackedUsers')
                    ->label('Members')
                    ->sortable(),
                TextColumn::make('status')
                    ->badge(),
                TextColumn::make('latestUsageSnapshot.util_5h')
                    ->label('5h')
                    ->badge()
                    ->formatStateUsing(fn (?int $state): string => $state === null ? '—' : "{$state}%")
                    ->color(fn (?int $state): string => static::utilizationColor($state)),
                TextColumn::make('latestUsageSnapshot.util_7d')
                    ->label('7d')
                    ->badge()
                    ->formatStateUsing(fn (?int $state): string => $state === null ? '—' : "{$state}%")
                    ->color(fn (?int $state): string => static::utilizationColor($state)),
                TextColumn::make('last_probed_at')
                    ->since()
                    ->placeholder('Never')
                    ->sortable(query: fn (Builder $query, string $direction): Builder => $query->orderBy(
                        ClaudeCredential::select('last_probed_at')->whereColumn('claude_credentials.account_id', 'accounts.id'),
                        $direction,
                    )),
                TextColumn::make('organization_uuid')
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('organization_type')
                    ->label('Org type')
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('rate_limit_tier')
                    ->label('Rate limit tier')
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                //
            ])
            ->recordActions([
                ActionGroup::make([
                    static::connectAction(),
                    static::refreshNowAction(),
                    static::disconnectAction(),
                    ViewAction::make(),
                    EditAction::make(),
                    DeleteAction::make()
                        ->modalDescription('Deleting this account does not rewrite historical events — already-ingested events keep the raw account_email they were stamped with.'),
                ]),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make(),
                ]),
            ]);
    }

    /**
     * Build the read-only detail schema shown on {@see ViewAccount}: identity,
     * the resolved plan badge alongside the raw `organization_type` ×
     * `rate_limit_tier` pair it was derived from (useful when
     * {@see PlanResolver} can't narrow past
     * {@see AccountPlan::Unknown}), connection status, and probe
     * recency. Filament v5's `ViewRecord` picks this up automatically.
     *
     * @param  Schema  $schema  The schema being configured by Filament.
     * @return Schema
     */
    public static function infolist(Schema $schema): Schema
    {
        return $schema
            ->components([
                TextEntry::make('email'),
                TextEntry::make('name')
                    ->placeholder('—'),
                TextEntry::make('plan')
                    ->badge()
                    ->visible(fn (Account $record): bool => $record->provider === Provider::Claude),
                TextEntry::make('organization_type')
                    ->label('Org type')
                    ->placeholder('—')
                    ->visible(fn (Account $record): bool => $record->provider === Provider::Claude),
                TextEntry::make('rate_limit_tier')
                    ->label('Rate limit tier')
                    ->placeholder('—')
                    ->visible(fn (Account $record): bool => $record->provider === Provider::Claude),
                TextEntry::make('codexCredential.plan_type')
                    ->label('Codex plan')
                    ->placeholder('—')
                    ->visible(fn (Account $record): bool => $record->provider === Provider::Codex),
                TextEntry::make('status')
                    ->badge(),
                TextEntry::make('last_probed_at')
                    ->since()
                    ->placeholder('Never'),
            ]);
    }

    /**
     * Build the per-row "Connect" record action, used to re-auth a specific
     * account. Only shown when the account is not already Active. Mounting
     * starts a fresh {@see AccountConnectService} attempt and shows the
     * authorize URL; submitting resolves the pasted code against THIS record
     * ({@see AccountConnectService::resolve()} with the record as the expected
     * account), so authorizing a different Claude account is rejected and
     * writes nothing.
     *
     * @return Action
     */
    private static function connectAction(): Action
    {
        return Action::make('connect')
            ->label('Connect')
            ->icon(Heroicon::OutlinedLink)
            ->visible(fn (Account $record): bool => $record->provider === Provider::Claude && $record->status !== AccountStatus::Active)
            ->modalHeading('Re-connect Claude account')
            ->modalDescription('Open the authorize URL, approve access, then paste the code back here. You must authorize the same account this row represents.')
            ->modalSubmitActionLabel('Complete connect')
            ->fillForm(fn (): array => ClaudeReconnectModal::start())
            ->schema(ClaudeReconnectModal::schema())
            ->action(fn (array $data, Account $record) => ClaudeReconnectModal::complete($record, $data, 'Connect'));
    }

    /**
     * Build the "Refresh now" record action: runs the usage prober against the
     * account on demand and reports the fresh 5h/7d utilization, or the recorded
     * probe error. Delegates to the provider's prober via
     * {@see ProviderServiceFactory::proberFor()}.
     *
     * @return Action
     */
    private static function refreshNowAction(): Action
    {
        return Action::make('refreshNow')
            ->label('Refresh now')
            ->icon(Heroicon::OutlinedArrowPath)
            ->action(function (Account $record): void {
                $snapshot = app(ProviderServiceFactory::class)->proberFor($record)->probe($record);

                if ($snapshot === null) {
                    Notification::make()
                        ->warning()
                        ->title('Probe did not complete')
                        ->body($record->refresh()->probe_error ?? 'The account is not probeable right now.')
                        ->send();

                    return;
                }

                Notification::make()
                    ->success()
                    ->title('Usage refreshed')
                    ->body("5h: {$snapshot->util_5h}% · 7d: {$snapshot->util_7d}%")
                    ->send();
            });
    }

    /**
     * Build the "Disconnect" record action: the compromised-token response.
     * Wipes the stored OAuth grant via {@see AccountConnectService::disconnect()}.
     * Its confirm modal doubles as the leak runbook, because Anthropic has no
     * token-revocation endpoint — the real kill switch is owner-side.
     *
     * @return Action
     */
    private static function disconnectAction(): Action
    {
        return Action::make('disconnect')
            ->label('Disconnect')
            ->icon(Heroicon::OutlinedNoSymbol)
            ->color('danger')
            ->requiresConfirmation()
            ->modalHeading('Disconnect Claude account')
            ->modalDescription('Wipes the stored access and refresh tokens immediately and marks the account as needing re-auth. If the token may be compromised, this alone is NOT enough — also sign into this Claude account at claude.ai and revoke app access / sign out of all sessions, then Connect again for a fresh grant.')
            ->modalSubmitActionLabel('Disconnect')
            ->action(function (Account $record): void {
                app(ProviderServiceFactory::class)->disconnecterFor($record)->disconnect($record);

                Notification::make()
                    ->success()
                    ->title('Account disconnected')
                    ->body('Stored tokens wiped. Revoke app access on claude.ai too if the token may be compromised.')
                    ->send();
            });
    }

    /**
     * Eager-load the latest usage snapshot so the 5h/7d quota columns don't
     * trigger a query per row.
     *
     * @return Builder<Account>
     */
    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->with('latestUsageSnapshot');
    }

    /**
     * Map a utilization percent to a Filament badge color band: healthy
     * (&lt;70) success, warming (&lt;90) warning, hot (&ge;90) danger. Unknown
     * (null) reads as neutral gray.
     *
     * @param  ?int  $percent  the utilization percent, or null when unprobed
     * @return string the Filament color name
     */
    private static function utilizationColor(?int $percent): string
    {
        return match (true) {
            $percent === null => 'gray',
            $percent >= 90 => 'danger',
            $percent >= 70 => 'warning',
            default => 'success',
        };
    }

    /**
     * Relation managers embedded on the edit page.
     *
     * @return array<class-string>
     */
    public static function getRelations(): array
    {
        return [
            MembersRelationManager::class,
            ProvisionsRelationManager::class,
            EventsRelationManager::class,
        ];
    }

    /**
     * CRUD pages registered for this resource.
     *
     * @return array<string, PageRegistration>
     */
    public static function getPages(): array
    {
        return [
            'index' => ListAccounts::route('/'),
            'create' => CreateAccount::route('/create'),
            'edit' => EditAccount::route('/{record}/edit'),
            'view' => ViewAccount::route('/{record}'),
        ];
    }
}
