<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class AtlasAarsCertification extends Model
{
    use HasUuids;

    protected $fillable = [
        'simulation_id',
        'schema_version',
        'status',
        'checks',
        'promotion_gate',
        'claim_policy',
        'evidence_refs',
        'certification_hash',
    ];

    protected function casts(): array
    {
        return [
            'checks' => 'array',
            'promotion_gate' => 'array',
            'claim_policy' => 'array',
            'evidence_refs' => 'array',
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
        ];
    }
}
