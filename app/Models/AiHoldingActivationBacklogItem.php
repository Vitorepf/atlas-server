<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class AiHoldingActivationBacklogItem extends Model
{
    use HasUuids;

    protected $fillable = [
        'company_id',
        'flow_id',
        'work_package_id',
        'status',
        'activation_stage',
        'priority_score',
        'mandate_packet_hash',
        'owner',
        'deliverable',
        'gap_counts_json',
        'connector_activation_gaps_json',
        'governance_gaps_json',
        'operationalization_gaps_json',
        'evidence_required_json',
        'evidence_attached_json',
        'implementation_receipts_json',
        'source_flow_backlog_json',
        'implementation_attempt_count',
        'last_implementation_receipt_hash',
        'blocked_reason',
        'manual_handoff_ready',
        'external_execution_allowed',
        'external_side_effects_enabled',
        'queued_at',
        'last_run_at',
        'completed_at',
    ];

    protected function casts(): array
    {
        return [
            'priority_score' => 'integer',
            'gap_counts_json' => 'array',
            'connector_activation_gaps_json' => 'array',
            'governance_gaps_json' => 'array',
            'operationalization_gaps_json' => 'array',
            'evidence_required_json' => 'array',
            'evidence_attached_json' => 'array',
            'implementation_receipts_json' => 'array',
            'source_flow_backlog_json' => 'array',
            'implementation_attempt_count' => 'integer',
            'manual_handoff_ready' => 'boolean',
            'external_execution_allowed' => 'boolean',
            'external_side_effects_enabled' => 'boolean',
            'queued_at' => 'immutable_datetime',
            'last_run_at' => 'immutable_datetime',
            'completed_at' => 'immutable_datetime',
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
        ];
    }
}
