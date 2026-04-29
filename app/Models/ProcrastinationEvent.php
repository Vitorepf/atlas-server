<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class ProcrastinationEvent extends Model
{
    use HasUuids;
    use SoftDeletes;

    protected $fillable = [
        'client_id',
        'detected_at',
        'detected_timezone',
        'duration_min',
        'primary_category_class',
        'primary_category_label',
        'mission_active',
        'mission_context',
        'physiological_state',
        'subjective_state',
        'digital_context',
        'rule_version',
        'confidence',
        'confronted',
        'operator_response',
        'metadata',
    ];

    protected function casts(): array
    {
        return [
            'detected_at' => 'immutable_datetime',
            'duration_min' => 'integer',
            'primary_category_class' => 'integer',
            'mission_active' => 'boolean',
            'mission_context' => 'array',
            'physiological_state' => 'array',
            'subjective_state' => 'array',
            'digital_context' => 'array',
            'confidence' => 'float',
            'confronted' => 'boolean',
            'metadata' => 'array',
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
            'deleted_at' => 'immutable_datetime',
        ];
    }
}
