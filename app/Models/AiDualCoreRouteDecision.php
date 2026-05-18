<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AiDualCoreRouteDecision extends Model
{
    use HasUuids;

    protected $table = 'ai_dual_core_route_decisions';

    protected $fillable = [
        'schema_version',
        'uuid',
        'mission_id',
        'work_order_id',
        'router_decision_id',
        'intent_classification_id',
        'conversation_id',
        'route',
        'reason',
        'intent_summary',
        'ambiguity_level',
        'risk_level',
        'expected_duration',
        'modules_touched_estimate',
        'sdd_required',
        'evidence_required',
        'operator_visible',
        'rejected_routes',
        'routing_signals',
        'confidence',
        'evidence_refs',
        'policy_refs',
        'policy_snapshot',
        'actor_type',
        'decision_hash',
    ];

    protected function casts(): array
    {
        return [
            'modules_touched_estimate' => 'integer',
            'sdd_required' => 'boolean',
            'evidence_required' => 'array',
            'operator_visible' => 'boolean',
            'rejected_routes' => 'array',
            'routing_signals' => 'array',
            'confidence' => 'float',
            'evidence_refs' => 'array',
            'policy_refs' => 'array',
            'policy_snapshot' => 'array',
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
        ];
    }

    public function routerDecision(): BelongsTo
    {
        return $this->belongsTo(AiAtlasRouterDecision::class, 'router_decision_id');
    }

    public function intentClassification(): BelongsTo
    {
        return $this->belongsTo(AiAtlasIntentClassification::class, 'intent_classification_id');
    }

    public function mission(): BelongsTo
    {
        return $this->belongsTo(AiMission::class, 'mission_id');
    }

    public function workOrder(): BelongsTo
    {
        return $this->belongsTo(AiWorkOrder::class, 'work_order_id');
    }

    /**
     * Stable canonical projection matching `atlas.dual_core.route_decision.v1`
     * (`atlas-dual-core-engineering-system.md:247-258`). Field order is
     * fixed; consumers must not depend on associative iteration order.
     *
     * @return array<string,mixed>
     */
    public function toCanonicalArray(): array
    {
        return [
            'schema' => $this->schema_version,
            'decision_id' => $this->uuid,
            'route' => $this->route,
            'reason' => $this->reason,
            'intent_summary' => $this->intent_summary,
            'ambiguity_level' => $this->ambiguity_level,
            'risk_level' => $this->risk_level,
            'expected_duration' => $this->expected_duration,
            'modules_touched_estimate' => (int) $this->modules_touched_estimate,
            'sdd_required' => (bool) $this->sdd_required,
            'evidence_required' => array_values((array) $this->evidence_required),
            'operator_visible' => (bool) $this->operator_visible,
            'rejected_routes' => array_values((array) ($this->rejected_routes ?? [])),
            'routing_signals' => array_values((array) ($this->routing_signals ?? [])),
            'confidence' => $this->confidence !== null ? (float) $this->confidence : null,
            'mission_id' => $this->mission_id,
            'work_order_id' => $this->work_order_id,
            'router_decision_id' => $this->router_decision_id,
            'intent_classification_id' => $this->intent_classification_id,
            'conversation_id' => $this->conversation_id,
            'evidence_refs' => $this->evidence_refs,
            'policy_refs' => $this->policy_refs,
            'policy_snapshot' => $this->policy_snapshot,
            'actor_type' => $this->actor_type,
            'decision_hash' => $this->decision_hash,
            'created_at' => $this->created_at?->toISOString(),
        ];
    }
}
