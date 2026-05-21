<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class AtlasAverExecution extends Model
{
    use HasUuids;

    protected $fillable = [
        'schema_version',
        'status',
        'maturity_level',
        'aweos_execution_id',
        'surface_id',
        'domain',
        'flow_id',
        'workspace_hash',
        'objective_hash',
        'objective',
        'execution_contract',
        'patch_plan',
        'safety_gate',
        'verification_plan',
        'rollback_plan',
        'claim_policy',
        'evidence_refs',
        'execution_hash',
    ];

    protected function casts(): array
    {
        return [
            'execution_contract' => 'array',
            'patch_plan' => 'array',
            'safety_gate' => 'array',
            'verification_plan' => 'array',
            'rollback_plan' => 'array',
            'claim_policy' => 'array',
            'evidence_refs' => 'array',
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
        ];
    }

    public function commandLedgers(): HasMany
    {
        return $this->hasMany(AtlasAverCommandLedger::class, 'execution_id');
    }

    public function diffLedgers(): HasMany
    {
        return $this->hasMany(AtlasAverDiffLedger::class, 'execution_id');
    }

    public function testLedgers(): HasMany
    {
        return $this->hasMany(AtlasAverTestLedger::class, 'execution_id');
    }

    public function repairCycles(): HasMany
    {
        return $this->hasMany(AtlasAverRepairCycle::class, 'execution_id');
    }

    public function certifiedExecution(): HasOne
    {
        return $this->hasOne(AtlasAverCertifiedExecution::class, 'execution_id');
    }
}
