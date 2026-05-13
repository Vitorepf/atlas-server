<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

/**
 * @see docs/engineering-knowledge-base/atlas-programming-governance-system-contracts.md
 */
class AtlasProgrammingGateRun extends Model
{
    use HasUuids;

    protected $fillable = [
        'work_item_id',
        'gate_name',
        'status',
        'blocking',
        'input_hash',
        'output_hash',
        'reason',
        'waiver_reason',
        'decided_by',
        'decided_at',
        'payload_json',
    ];

    protected function casts(): array
    {
        return [
            'blocking' => 'boolean',
            'payload_json' => 'array',
            'decided_at' => 'immutable_datetime',
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
        ];
    }

    public function workItem(): BelongsTo
    {
        return $this->belongsTo(AtlasProgrammingWorkItem::class, 'work_item_id');
    }

    public function scopeBlocking(Builder $query): Builder
    {
        return $query->where('blocking', true);
    }

    public function scopeFailed(Builder $query): Builder
    {
        return $query->where('status', 'failed');
    }

    /**
     * Use UUID v7 so primary keys stay sortable by insertion time.
     * `latestGate()` relies on this when SQLite collapses created_at to
     * second-precision and multiple runs land in the same second.
     */
    public function newUniqueId(): string
    {
        return (string) Str::uuid7();
    }
}
