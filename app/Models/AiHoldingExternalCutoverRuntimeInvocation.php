<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class AiHoldingExternalCutoverRuntimeInvocation extends Model
{
    use HasUuids;

    protected $fillable = [
        'invocation_id',
        'work_order_id',
        'company_id',
        'flow_id',
        'status',
        'mode',
        'source_work_order_hash',
        'source_final_authority_binding_hash',
        'runtime_invocation_packet_hash',
        'required_final_authorities_json',
        'final_authority_bindings_json',
        'operator_runtime_contract_json',
        'blocked_operations_json',
        'execution_receipts_json',
        'last_execution_receipt_hash',
        'execution_receipt_count',
        'external_execution_allowed',
        'external_side_effects_enabled',
        'registered_at',
        'last_status_at',
        'last_executed_at',
        'manual_handoff_packet_json',
        'manual_handoff_packet_hash',
        'manual_handoff_registered_at',
        'manual_closeout_receipts_json',
        'last_manual_closeout_receipt_hash',
        'manual_closeout_receipt_count',
        'manual_closeout_registered_at',
    ];

    protected function casts(): array
    {
        return [
            'required_final_authorities_json' => 'array',
            'final_authority_bindings_json' => 'array',
            'operator_runtime_contract_json' => 'array',
            'blocked_operations_json' => 'array',
            'execution_receipts_json' => 'array',
            'execution_receipt_count' => 'integer',
            'external_execution_allowed' => 'boolean',
            'external_side_effects_enabled' => 'boolean',
            'registered_at' => 'immutable_datetime',
            'last_status_at' => 'immutable_datetime',
            'last_executed_at' => 'immutable_datetime',
            'manual_handoff_packet_json' => 'array',
            'manual_handoff_registered_at' => 'immutable_datetime',
            'manual_closeout_receipts_json' => 'array',
            'manual_closeout_receipt_count' => 'integer',
            'manual_closeout_registered_at' => 'immutable_datetime',
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
        ];
    }
}
