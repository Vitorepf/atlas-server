<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class AtlasStrategicSimulation extends Model
{
    use HasUuids;

    protected $fillable = [
        'schema_version',
        'status',
        'mode',
        'options',
        'predicted_outcomes',
        'risks',
        'evidence_refs',
        'simulation_hash',
    ];

    protected function casts(): array
    {
        return [
            'options' => 'array',
            'predicted_outcomes' => 'array',
            'risks' => 'array',
            'evidence_refs' => 'array',
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
        ];
    }
}
