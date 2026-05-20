<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AtlasIntelligenceFactoryCertification extends Model
{
    use HasUuids;

    protected $fillable = [
        'capability_id',
        'schema_version',
        'status',
        'checks',
        'evidence_refs',
        'certification_hash',
        'expires_at',
    ];

    protected function casts(): array
    {
        return [
            'checks' => 'array',
            'evidence_refs' => 'array',
            'expires_at' => 'immutable_datetime',
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
        ];
    }

    public function capability(): BelongsTo
    {
        return $this->belongsTo(AtlasIntelligenceFactoryCapability::class, 'capability_id');
    }
}
