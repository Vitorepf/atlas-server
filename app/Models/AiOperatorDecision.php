<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class AiOperatorDecision extends Model
{
    use HasUuids;

    protected $table = 'ai_operator_decisions';

    protected $fillable = [
        'schema_version',
        'uuid',
        'decision_type',
        'target_type',
        'target_id',
        'decision',
        'reason',
        'payload',
        'decided_by',
        'decided_at',
        'receipt_hash',
        'mission_id',
    ];

    protected function casts(): array
    {
        return [
            'payload' => 'array',
            'decided_at' => 'immutable_datetime',
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
        ];
    }
}
