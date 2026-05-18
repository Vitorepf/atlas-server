<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AiMissionCertification extends Model
{
    use HasUuids;

    protected $fillable = [
        'schema_version',
        'uuid',
        'mission_id',
        'status',
        'checked_requirements',
        'missing_requirements',
        'evidence_refs',
        'certification_hash',
        'certified_at',
    ];

    protected function casts(): array
    {
        return [
            'checked_requirements' => 'array',
            'missing_requirements' => 'array',
            'evidence_refs' => 'array',
            'certified_at' => 'immutable_datetime',
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
        ];
    }

    public function mission(): BelongsTo
    {
        return $this->belongsTo(AiMission::class, 'mission_id');
    }
}
