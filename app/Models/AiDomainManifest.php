<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class AiDomainManifest extends Model
{
    use HasUuids;

    protected $fillable = [
        'schema_version',
        'uuid',
        'domain_id',
        'name',
        'status',
        'charter',
        'ontology',
        'departments',
        'flow_profiles',
        'tools_allowed',
        'policy_profile',
        'memory_scope',
        'evidence_schema',
        'quality_gates',
        'handoff_rules',
        'delivery_types',
        'metrics',
        'forbidden_actions',
        'maturity_stage',
        'owner',
        'manifest_hash',
    ];

    protected function casts(): array
    {
        return [
            'charter' => 'array',
            'ontology' => 'array',
            'departments' => 'array',
            'flow_profiles' => 'array',
            'tools_allowed' => 'array',
            'policy_profile' => 'array',
            'memory_scope' => 'array',
            'evidence_schema' => 'array',
            'quality_gates' => 'array',
            'handoff_rules' => 'array',
            'delivery_types' => 'array',
            'metrics' => 'array',
            'forbidden_actions' => 'array',
            'maturity_stage' => 'integer',
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
        ];
    }

    public function capabilities(): HasMany
    {
        return $this->hasMany(AiDomainCapability::class, 'domain_manifest_id');
    }

    public function runtimeRecords(): HasMany
    {
        return $this->hasMany(AiDomainRuntimeRecord::class, 'domain_manifest_id');
    }

    public function maturityAssessments(): HasMany
    {
        return $this->hasMany(AiDomainMaturityAssessment::class, 'domain_manifest_id');
    }
}
