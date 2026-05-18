<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class AiOpportunity extends Model
{
    use HasUuids;

    protected $table = 'ai_opportunities';

    protected $fillable = [
        'schema_version',
        'uuid',
        'strategy_run_id',
        'mission_id',
        'opportunity_id',
        'title',
        'problem',
        'icp',
        'pain',
        'urgency',
        'market',
        'competitors',
        'risks',
        'confidence',
        'status',
        'opportunity_hash',
    ];

    protected function casts(): array
    {
        return [
            'market' => 'array',
            'competitors' => 'array',
            'risks' => 'array',
            'confidence' => 'float',
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
        ];
    }
}
