<?php

namespace App\Services\Analytics\Concerns;

use App\Models\Event;
use App\Services\Analytics\UsageFilters;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Shared event-scoping for the analytics query classes: builds the base
 * `events` query narrowed by a {@see UsageFilters}, and the driver-aware
 * time-bucket SQL expression. Columns are qualified with `events.` so the
 * scope composes safely with joins (users/accounts) without ambiguous-column
 * errors on PostgreSQL.
 */
trait ScopesEventsByFilters
{
    /**
     * Base events query narrowed by the filter's range and optional
     * account/provider/user selections. All columns are `events.`-qualified.
     *
     * @param  UsageFilters  $filters  the shared analytics filter
     * @return Builder<Event>
     */
    protected function scopeEvents(UsageFilters $filters): Builder
    {
        return Event::query()
            ->whereBetween('events.created_at', [$filters->from, $filters->to])
            ->when($filters->accountId !== null, fn (Builder $q): Builder => $q->where('events.account_id', $filters->accountId))
            ->when($filters->provider !== null, fn (Builder $q): Builder => $q->where('events.provider', $filters->provider))
            ->when($filters->userId !== null, fn (Builder $q): Builder => $q->where('events.user_id', $filters->userId))
            ->when($filters->model !== null, fn (Builder $q): Builder => $filters->model === UsageFilters::UNKNOWN_MODEL
                ? $q->whereNull('events.model')
                : $q->where('events.model', $filters->model));
    }

    /**
     * Driver-aware SQL expression truncating a timestamp column to an hourly,
     * daily, weekly, or monthly bucket string, portable across PostgreSQL
     * (production) and SQLite (tests).
     *
     * @param  string  $bucket  `'hour'`, `'day'`, `'week'`, or `'month'`
     * @param  string  $column  the timestamp column to bucket (e.g. `events.created_at`)
     * @return string the raw SQL expression
     */
    protected function bucketExpression(string $bucket, string $column): string
    {
        $isSqlite = DB::connection()->getDriverName() === 'sqlite';

        return match ($bucket) {
            'hour' => $isSqlite
                ? "strftime('%Y-%m-%d %H:00', {$column})"
                : "to_char({$column}, 'YYYY-MM-DD HH24:00')",
            'week' => $isSqlite
                ? "strftime('%Y-%W', {$column})"
                : "to_char({$column}, 'IYYY-IW')",
            'month' => $isSqlite
                ? "strftime('%Y-%m', {$column})"
                : "to_char({$column}, 'YYYY-MM')",
            default => $isSqlite
                ? "strftime('%Y-%m-%d', {$column})"
                : "to_char({$column}, 'YYYY-MM-DD')",
        };
    }
}
