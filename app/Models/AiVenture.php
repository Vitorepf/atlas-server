<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class AiVenture extends Model
{
    use HasUuids;

    protected $table = 'ai_ventures';

    protected $fillable = [
        'schema_version',
        'uuid',
        'venture_id',
        'name',
        'thesis',
        'status',
        'stage',
        'stage_key',
        'idea_id',
        'opportunity_id',
        'venture_blueprint_id',
        'north_star',
        'founding_inputs',
        'target_arr_usd',
        'venture_hash',
    ];

    protected function casts(): array
    {
        return [
            'north_star' => 'array',
            'founding_inputs' => 'array',
            'target_arr_usd' => 'float',
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
        ];
    }
}
