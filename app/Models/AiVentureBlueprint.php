<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class AiVentureBlueprint extends Model
{
    use HasUuids;

    protected $table = 'ai_venture_blueprints';

    protected $fillable = [
        'schema_version',
        'uuid',
        'opportunity_id',
        'strategy_run_id',
        'blueprint_id',
        'title',
        'product',
        'gtm',
        'unit_economics',
        'hiring_plan',
        'operations',
        'milestones',
        'risks',
        'status',
        'blueprint_hash',
    ];

    protected function casts(): array
    {
        return [
            'product' => 'array',
            'gtm' => 'array',
            'unit_economics' => 'array',
            'hiring_plan' => 'array',
            'operations' => 'array',
            'milestones' => 'array',
            'risks' => 'array',
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
        ];
    }
}
