<?php

namespace App\Support\TemporalTruth;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;

/**
 * Adds 4 canonical scopes to any model that carries TEOS Temporal Truth Fields
 * (see {@see TemporalTruthCanon}):
 *
 *   - `current($at = now)` — record is in its validity window and not superseded.
 *   - `stale($at = now)`   — `stale_after` is in the past at the reference instant.
 *   - `superseded()`       — record has a non-null `superseded_by` (or, for
 *                            `atlas_memory_entries`, the legacy
 *                            `superseded_by_id` column already in production).
 *   - `authorityLevel($level)` — exact match on `authority_level`.
 *
 * Every scope tolerates legacy rows: a record whose temporal columns are all
 * NULL is treated as `current` (no expiry) and never `stale`/`superseded`.
 */
trait HasTemporalTruth
{
    public function scopeCurrent(Builder $query, ?CarbonImmutable $at = null): Builder
    {
        $reference = $at ?? CarbonImmutable::now();
        $supersededColumns = $this->supersededColumns();

        return $query
            ->where(function (Builder $inner) use ($reference): void {
                $inner->whereNull('valid_from')
                    ->orWhere('valid_from', '<=', $reference);
            })
            ->where(function (Builder $inner) use ($reference): void {
                $inner->whereNull('valid_until')
                    ->orWhere('valid_until', '>', $reference);
            })
            ->where(function (Builder $inner) use ($supersededColumns): void {
                foreach ($supersededColumns as $column) {
                    $inner->whereNull($column);
                }
            });
    }

    public function scopeStale(Builder $query, ?CarbonImmutable $at = null): Builder
    {
        $reference = $at ?? CarbonImmutable::now();

        return $query
            ->whereNotNull('stale_after')
            ->where('stale_after', '<', $reference);
    }

    public function scopeSuperseded(Builder $query): Builder
    {
        $columns = $this->supersededColumns();

        return $query->where(function (Builder $inner) use ($columns): void {
            foreach ($columns as $column) {
                $inner->orWhereNotNull($column);
            }
        });
    }

    public function scopeAuthorityLevel(Builder $query, string $level): Builder
    {
        return $query->where('authority_level', $level);
    }

    /**
     * Column names that, when non-null, indicate this row has been superseded.
     * Default is the canonical TEOS column `superseded_by`. Models that predate
     * TEOS may override (e.g. `AtlasMemoryEntry` returns `['superseded_by_id']`)
     * so the scopes hit the right column without forcing a duplicate.
     *
     * @return array<int,string>
     */
    protected function supersededColumns(): array
    {
        return ['superseded_by'];
    }
}
