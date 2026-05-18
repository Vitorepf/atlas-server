<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AiMarketingApprovalGate extends Model
{
    use HasUuids;

    protected $table = 'ai_marketing_approval_gates';

    protected $fillable = [
        'schema_version',
        'uuid',
        'marketing_run_id',
        'artifact_id',
        'experiment_id',
        'gate_type',
        'requested_action',
        'proposed_budget',
        'currency',
        'status',
        'approver',
        'reason',
        'policy_approval_request_id',
        'receipt_hash',
    ];

    protected function casts(): array
    {
        return [
            'proposed_budget' => 'decimal:6',
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
        ];
    }

    public function marketingRun(): BelongsTo
    {
        return $this->belongsTo(AiMarketingRun::class, 'marketing_run_id');
    }

    public function artifact(): BelongsTo
    {
        return $this->belongsTo(AiMarketingArtifact::class, 'artifact_id');
    }

    public function experiment(): BelongsTo
    {
        return $this->belongsTo(AiMarketingExperiment::class, 'experiment_id');
    }
}
