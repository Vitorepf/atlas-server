<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class AtlasAarsCounterfactual extends Model
{
    use HasUuids;

    protected $fillable = [
        'simulation_id',
        'schema_version',
        'status',
        'baseline',
        'alternatives',
        'delta_analysis',
        'decision_effects',
        'evidence_refs',
        'counterfactual_hash',
    ];

    protected function casts(): array
    {
        return [
            'baseline' => 'array',
            'alternatives' => 'array',
            'delta_analysis' => 'array',
            'decision_effects' => 'array',
            'evidence_refs' => 'array',
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
        ];
    }
}
