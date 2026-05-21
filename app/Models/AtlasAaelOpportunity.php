<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class AtlasAaelOpportunity extends Model
{
    use HasUuids;

    protected $fillable = [
        'schema_version',
        'status',
        'source_type',
        'domain',
        'flow_id',
        'opportunity_type',
        'risk_level',
        'priority_score',
        'strategic_alignment_score',
        'objective_hash',
        'objective',
        'signals',
        'roi_model',
        'dependencies',
        'evidence_refs',
        'opportunity_hash',
    ];

    protected function casts(): array
    {
        return [
            'priority_score' => 'float',
            'strategic_alignment_score' => 'float',
            'signals' => 'array',
            'roi_model' => 'array',
            'dependencies' => 'array',
            'evidence_refs' => 'array',
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
        ];
    }
}
