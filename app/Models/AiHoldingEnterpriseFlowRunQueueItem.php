<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class AiHoldingEnterpriseFlowRunQueueItem extends Model
{
    use HasUuids;

    protected $fillable = [
        'company_id',
        'flow_id',
        'status',
        'priority_score',
        'activation_stage',
        'mandate_packet_hash',
        'operating_package_hash',
        'operating_package_json',
        'replay_contract_json',
        'operating_package_attestations_json',
        'connector_activation_count',
        'connector_probe_green_count',
        'work_package_count',
        'completed_work_package_count',
        'blocked_work_package_count',
        'attempt_count',
        'queue_receipts_json',
        'execution_receipts_json',
        'last_execution_receipt_hash',
        'dlq_reason',
        'external_execution_allowed',
        'external_side_effects_enabled',
        'queued_at',
        'leased_at',
        'last_executed_at',
        'completed_at',
        'dlq_at',
    ];

    protected function casts(): array
    {
        return [
            'priority_score' => 'integer',
            'operating_package_json' => 'array',
            'replay_contract_json' => 'array',
            'operating_package_attestations_json' => 'array',
            'connector_activation_count' => 'integer',
            'connector_probe_green_count' => 'integer',
            'work_package_count' => 'integer',
            'completed_work_package_count' => 'integer',
            'blocked_work_package_count' => 'integer',
            'attempt_count' => 'integer',
            'queue_receipts_json' => 'array',
            'execution_receipts_json' => 'array',
            'external_execution_allowed' => 'boolean',
            'external_side_effects_enabled' => 'boolean',
            'queued_at' => 'immutable_datetime',
            'leased_at' => 'immutable_datetime',
            'last_executed_at' => 'immutable_datetime',
            'completed_at' => 'immutable_datetime',
            'dlq_at' => 'immutable_datetime',
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
        ];
    }
}
