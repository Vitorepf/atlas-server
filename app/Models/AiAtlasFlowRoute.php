<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AiAtlasFlowRoute extends Model
{
    use HasUuids;

    protected $table = 'ai_atlas_flow_routes';

    protected $fillable = [
        'schema_version',
        'uuid',
        'router_decision_id',
        'flow_id',
        'flow_profile',
        'runtime_mode',
        'expected_capabilities',
        'required_gates',
        'fallback_flows',
        'status',
    ];

    protected function casts(): array
    {
        return [
            'expected_capabilities' => 'array',
            'required_gates' => 'array',
            'fallback_flows' => 'array',
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
        ];
    }

    public function routerDecision(): BelongsTo
    {
        return $this->belongsTo(AiAtlasRouterDecision::class, 'router_decision_id');
    }
}
