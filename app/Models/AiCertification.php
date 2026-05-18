<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class AiCertification extends Model
{
    use HasUuids;

    protected $table = 'ai_certifications';

    protected $fillable = [
        'schema_version',
        'uuid',
        'target_type',
        'target_id',
        'mission_id',
        'status',
        'checked_requirements',
        'missing_requirements',
        'evidence_refs',
        'blocker_refs',
        'certification_hash',
        'certified_at',
    ];

    protected function casts(): array
    {
        return [
            'checked_requirements' => 'array',
            'missing_requirements' => 'array',
            'evidence_refs' => 'array',
            'blocker_refs' => 'array',
            'certified_at' => 'immutable_datetime',
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
        ];
    }
}
