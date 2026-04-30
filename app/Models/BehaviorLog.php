<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class BehaviorLog extends Model
{
    use HasUuids;
    use SoftDeletes;

    protected $fillable = [
        'client_id',
        'behavior_id',
        'behavior_client_id',
        'log_date',
        'value',
        'numeric_value',
        'note',
        'occurred_at',
        'occurred_timezone',
        'quantity_numeric',
        'quantity_unit',
        'intensity',
        'context',
        'recorded_at',
        'recorded_timezone',
        'source',
        'source_capture_id',
        'auto_marked',
        'confirmed_by_operator',
        'confidence',
        'inferred_by',
        'consent_snapshot_id',
        'reverted_at',
        'metadata',
    ];

    protected function casts(): array
    {
        return [
            'log_date' => 'immutable_date',
            'numeric_value' => 'float',
            'occurred_at' => 'immutable_datetime',
            'quantity_numeric' => 'float',
            'intensity' => 'integer',
            'context' => 'array',
            'recorded_at' => 'immutable_datetime',
            'auto_marked' => 'boolean',
            'confirmed_by_operator' => 'boolean',
            'confidence' => 'float',
            'reverted_at' => 'immutable_datetime',
            'metadata' => 'array',
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
            'deleted_at' => 'immutable_datetime',
        ];
    }

    public function behavior(): BelongsTo
    {
        return $this->belongsTo(Behavior::class);
    }
}
