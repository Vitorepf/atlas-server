<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class AtlasAweosExecution extends Model
{
    use HasUuids;

    protected $table = 'atlas_aweos_executions';

    protected $fillable = [
        'schema_version',
        'status',
        'maturity_level',
        'surface_id',
        'domain',
        'flow_id',
        'objective_hash',
        'objective',
        'mission_id',
        'persistent_context_pack_id',
        'persistent_context_hash',
        'runtime_efficiency_decision_id',
        'workcell_id',
        'aemor_episode_id',
        'persistent_context',
        'runtime_efficiency',
        'agentic_workcell',
        'aemor_episode',
        'execution_plan',
        'tool_orchestration',
        'repair_recovery_loop',
        'operator_decision_economy',
        'continuation_engine',
        'strategic_next_action',
        'mission_control',
        'evidence_refs',
        'claim_policy',
        'execution_hash',
    ];

    protected function casts(): array
    {
        return [
            'persistent_context' => 'array',
            'runtime_efficiency' => 'array',
            'agentic_workcell' => 'array',
            'aemor_episode' => 'array',
            'execution_plan' => 'array',
            'tool_orchestration' => 'array',
            'repair_recovery_loop' => 'array',
            'operator_decision_economy' => 'array',
            'continuation_engine' => 'array',
            'strategic_next_action' => 'array',
            'mission_control' => 'array',
            'evidence_refs' => 'array',
            'claim_policy' => 'array',
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
        ];
    }

    public function events(): HasMany
    {
        return $this->hasMany(AtlasAweosEvent::class, 'execution_id');
    }

    public function certifiedOutcome(): HasOne
    {
        return $this->hasOne(AtlasAweosCertifiedOutcome::class, 'execution_id');
    }
}
