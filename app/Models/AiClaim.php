<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class AiClaim extends Model
{
    use HasUuids;

    protected $table = 'ai_claims';

    protected $fillable = [
        'schema_version',
        'uuid',
        'claim_text',
        'claim_type',
        'confidence',
        'evidence_refs',
        'verification_status',
        'risk_level',
        'mission_id',
        'domain_id',
        'claim_hash',
    ];

    protected function casts(): array
    {
        return [
            'confidence' => 'float',
            'evidence_refs' => 'array',
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
        ];
    }
}
