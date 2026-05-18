<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AiAtlasRuntimeDispatch extends Model
{
    use HasUuids;

    protected $table = 'ai_atlas_runtime_dispatches';

    protected $fillable = [
        'schema_version',
        'uuid',
        'router_decision_id',
        'flow_route_id',
        'mission_id',
        'work_order_id',
        'dispatch_target',
        'dispatch_status',
        'dispatch_payload',
        'evidence_refs',
        'blockers',
        'receipt_hash',
    ];

    protected function casts(): array
    {
        return [
            'dispatch_payload' => 'array',
            'evidence_refs' => 'array',
            'blockers' => 'array',
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
        ];
    }

    public function routerDecision(): BelongsTo
    {
        return $this->belongsTo(AiAtlasRouterDecision::class, 'router_decision_id');
    }

    public function flowRoute(): BelongsTo
    {
        return $this->belongsTo(AiAtlasFlowRoute::class, 'flow_route_id');
    }
}
