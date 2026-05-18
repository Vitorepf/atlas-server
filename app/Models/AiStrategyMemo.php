<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class AiStrategyMemo extends Model
{
    use HasUuids;

    protected $table = 'ai_strategy_memos';

    protected $fillable = [
        'schema_version',
        'uuid',
        'strategy_run_id',
        'opportunity_id',
        'venture_blueprint_id',
        'memo_kind',
        'title',
        'decision',
        'rationale',
        'assumptions',
        'experiment_refs',
        'evidence_refs',
        'next_actions',
        'status',
        'memo_hash',
    ];

    protected function casts(): array
    {
        return [
            'rationale' => 'array',
            'assumptions' => 'array',
            'experiment_refs' => 'array',
            'evidence_refs' => 'array',
            'next_actions' => 'array',
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
        ];
    }
}
