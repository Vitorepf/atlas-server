<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class AiHeuristicUpdate extends Model
{
    use HasUuids;

    protected $fillable = [
        'schema_version',
        'heuristic_key',
        'flow_id',
        'status',
        'before_state',
        'after_state',
        'evidence_refs',
        'rollback_plan',
        'test_refs',
        'receipt_hash',
        'applied_at',
        'rolled_back_at',
    ];

    protected function casts(): array
    {
        return [
            'before_state' => 'array',
            'after_state' => 'array',
            'evidence_refs' => 'array',
            'rollback_plan' => 'array',
            'test_refs' => 'array',
            'applied_at' => 'immutable_datetime',
            'rolled_back_at' => 'immutable_datetime',
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
        ];
    }
}
