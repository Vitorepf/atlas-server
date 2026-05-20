<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class AtlasIntelligenceFactoryGap extends Model
{
    use HasUuids;

    protected $fillable = [
        'schema_version',
        'status',
        'objective_hash',
        'objective',
        'domain',
        'flow_id',
        'scope_type',
        'scope_id',
        'gap_type',
        'severity',
        'missing_capabilities',
        'existing_candidates',
        'evidence_refs',
        'metadata',
        'gap_hash',
    ];

    protected function casts(): array
    {
        return [
            'missing_capabilities' => 'array',
            'existing_candidates' => 'array',
            'evidence_refs' => 'array',
            'metadata' => 'array',
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
        ];
    }

    public function decisions(): HasMany
    {
        return $this->hasMany(AtlasIntelligenceFactoryDecision::class, 'gap_id');
    }
}
