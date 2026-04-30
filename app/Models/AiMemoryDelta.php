<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class AiMemoryDelta extends Model
{
    use HasUuids;

    protected $fillable = [
        'source_trace_id',
        'source_session_id',
        'source_workspace',
        'type',
        'claim',
        'evidence',
        'scope',
        'confidence',
        'valid_from',
        'valid_until',
        'use_when',
        'do_not_use_when',
        'requires_confirmation',
        'status',
        'superseded_by',
    ];

    protected function casts(): array
    {
        return [
            'source_trace_id' => 'string',
            'source_session_id' => 'string',
            'evidence' => 'array',
            'confidence' => 'float',
            'valid_from' => 'immutable_datetime',
            'valid_until' => 'immutable_datetime',
            'use_when' => 'array',
            'do_not_use_when' => 'array',
            'requires_confirmation' => 'boolean',
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
        ];
    }
}
