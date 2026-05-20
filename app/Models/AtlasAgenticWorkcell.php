<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class AtlasAgenticWorkcell extends Model
{
    use HasUuids;

    protected $fillable = [
        'schema_version',
        'status',
        'surface_id',
        'domain',
        'flow_id',
        'topology',
        'maturity_level',
        'objective_hash',
        'objective',
        'areg_decision',
        'org_design',
        'role_roster',
        'task_graph',
        'context_packs',
        'execution_schedule',
        'verification_plan',
        'evidence_ledger',
        'memory_packet',
        'counterfactual_replay',
        'learning_policy',
        'control_plane_summary',
        'claim_policy',
        'workcell_hash',
    ];

    protected function casts(): array
    {
        return [
            'areg_decision' => 'array',
            'org_design' => 'array',
            'role_roster' => 'array',
            'task_graph' => 'array',
            'context_packs' => 'array',
            'execution_schedule' => 'array',
            'verification_plan' => 'array',
            'evidence_ledger' => 'array',
            'memory_packet' => 'array',
            'counterfactual_replay' => 'array',
            'learning_policy' => 'array',
            'control_plane_summary' => 'array',
            'claim_policy' => 'array',
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
        ];
    }

    public function events(): HasMany
    {
        return $this->hasMany(AtlasAgenticWorkcellEvent::class, 'workcell_id');
    }

    public function outcomes(): HasMany
    {
        return $this->hasMany(AtlasAgenticWorkcellOutcome::class, 'workcell_id');
    }
}
