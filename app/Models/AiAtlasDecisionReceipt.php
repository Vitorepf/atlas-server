<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AiAtlasDecisionReceipt extends Model
{
    use HasUuids;

    protected $table = 'ai_atlas_decision_receipts';

    protected $fillable = [
        'schema_version',
        'uuid',
        'router_decision_id',
        'runtime_dispatch_id',
        'receipt_type',
        'decision_summary',
        'evidence_refs',
        'policy_refs',
        'receipt_hash',
    ];

    protected function casts(): array
    {
        return [
            'decision_summary' => 'array',
            'evidence_refs' => 'array',
            'policy_refs' => 'array',
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
        ];
    }

    public function routerDecision(): BelongsTo
    {
        return $this->belongsTo(AiAtlasRouterDecision::class, 'router_decision_id');
    }

    public function runtimeDispatch(): BelongsTo
    {
        return $this->belongsTo(AiAtlasRuntimeDispatch::class, 'runtime_dispatch_id');
    }
}
