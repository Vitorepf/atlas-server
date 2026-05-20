<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class AtlasRiskSignal extends Model
{
    use HasUuids;

    protected $fillable = [
        'schema_version',
        'status',
        'risk_type',
        'severity',
        'summary',
        'mitigations',
        'evidence_refs',
        'metadata',
        'risk_hash',
    ];

    protected function casts(): array
    {
        return [
            'mitigations' => 'array',
            'evidence_refs' => 'array',
            'metadata' => 'array',
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
        ];
    }
}
