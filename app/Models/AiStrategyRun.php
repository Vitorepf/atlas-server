<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class AiStrategyRun extends Model
{
    use HasUuids;

    protected $table = 'ai_strategy_runs';

    protected $fillable = [
        'schema_version',
        'uuid',
        'mission_id',
        'work_order_id',
        'run_kind',
        'status',
        'summary',
        'inputs',
        'outputs',
        'blockers',
        'next_action',
        'receipt_hash',
        'completed_at',
    ];

    protected function casts(): array
    {
        return [
            'inputs' => 'array',
            'outputs' => 'array',
            'blockers' => 'array',
            'completed_at' => 'immutable_datetime',
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
        ];
    }
}
