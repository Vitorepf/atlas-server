<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class AiEngineeringCompanyCertification extends Model
{
    use HasUuids;

    protected $fillable = ['engagement_record_id', 'schema_version', 'certification_id', 'status', 'checks', 'blockers', 'claim_policy', 'evidence_refs', 'certification_hash', 'certified_at'];

    protected function casts(): array
    {
        return [
            'checks' => 'array',
            'blockers' => 'array',
            'claim_policy' => 'array',
            'evidence_refs' => 'array',
            'certified_at' => 'immutable_datetime',
        ];
    }
}
