<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class AiMarketModel extends Model
{
    use HasUuids;

    protected $table = 'ai_market_models';

    protected $fillable = [
        'schema_version',
        'uuid',
        'opportunity_id',
        'strategy_run_id',
        'model_id',
        'title',
        'tam',
        'sam',
        'som',
        'currency',
        'assumptions',
        'sources',
        'confidence',
        'status',
        'model_hash',
    ];

    protected function casts(): array
    {
        return [
            'tam' => 'float',
            'sam' => 'float',
            'som' => 'float',
            'assumptions' => 'array',
            'sources' => 'array',
            'confidence' => 'float',
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
        ];
    }
}
