<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class AtlasAaelAuditReport extends Model
{
    use HasUuids;

    protected $fillable = [
        'cycle_id',
        'schema_version',
        'status',
        'audit_court',
        'self_evolution_memory',
        'dormant_capability_activation',
        'quality_score',
        'claim_policy',
        'evidence_refs',
        'audit_hash',
    ];

    protected function casts(): array
    {
        return [
            'audit_court' => 'array',
            'self_evolution_memory' => 'array',
            'dormant_capability_activation' => 'array',
            'quality_score' => 'array',
            'claim_policy' => 'array',
            'evidence_refs' => 'array',
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
        ];
    }
}
