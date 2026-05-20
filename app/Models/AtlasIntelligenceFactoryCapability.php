<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class AtlasIntelligenceFactoryCapability extends Model
{
    use HasUuids;

    protected $fillable = [
        'schema_version',
        'status',
        'capability_key',
        'name',
        'capability_type',
        'domain',
        'flow_id',
        'version',
        'description',
        'input_schema',
        'output_schema',
        'use_when',
        'do_not_use_when',
        'safety_policy',
        'evidence_refs',
        'certification_hash',
        'aemor_outcome_refs',
        'metadata',
        'capability_hash',
    ];

    protected function casts(): array
    {
        return [
            'input_schema' => 'array',
            'output_schema' => 'array',
            'use_when' => 'array',
            'do_not_use_when' => 'array',
            'safety_policy' => 'array',
            'evidence_refs' => 'array',
            'aemor_outcome_refs' => 'array',
            'metadata' => 'array',
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
        ];
    }

    public function certifications(): HasMany
    {
        return $this->hasMany(AtlasIntelligenceFactoryCertification::class, 'capability_id');
    }
}
