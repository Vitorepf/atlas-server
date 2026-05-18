<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class AiAtlasRouterDecision extends Model
{
    use HasUuids;

    protected $table = 'ai_atlas_router_decisions';

    protected $fillable = [
        'schema_version',
        'uuid',
        'mission_id',
        'work_order_id',
        'intent_classification_id',
        'primary_domain',
        'secondary_domains',
        'routing_mode',
        'decision_reason',
        'policy_required',
        'evidence_required',
        'tool_plan_required',
        'status',
        'receipt_hash',
    ];

    protected function casts(): array
    {
        return [
            'secondary_domains' => 'array',
            'decision_reason' => 'array',
            'policy_required' => 'boolean',
            'evidence_required' => 'boolean',
            'tool_plan_required' => 'boolean',
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
        ];
    }

    public function intentClassification(): BelongsTo
    {
        return $this->belongsTo(AiAtlasIntentClassification::class, 'intent_classification_id');
    }

    public function flowRoutes(): HasMany
    {
        return $this->hasMany(AiAtlasFlowRoute::class, 'router_decision_id');
    }

    public function runtimeDispatches(): HasMany
    {
        return $this->hasMany(AiAtlasRuntimeDispatch::class, 'router_decision_id');
    }

    public function decisionReceipts(): HasMany
    {
        return $this->hasMany(AiAtlasDecisionReceipt::class, 'router_decision_id');
    }
}
