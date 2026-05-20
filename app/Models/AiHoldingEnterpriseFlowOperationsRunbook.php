<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class AiHoldingEnterpriseFlowOperationsRunbook extends Model
{
    use HasUuids;

    protected $fillable = [
        'company_id',
        'flow_id',
        'status',
        'source_queue_item_id',
        'runbook_hash',
        'operating_package_hash',
        'operating_package_json',
        'replay_contract_json',
        'operating_package_attestations_json',
        'slo_contract_json',
        'incident_route_json',
        'reconciliation_contract_json',
        'promotion_gates_json',
        'dashboard_bindings_json',
        'drill_receipts_json',
        'last_drill_receipt_hash',
        'slo_green',
        'incident_route_green',
        'reconciliation_green',
        'promotion_gate_green',
        'external_execution_allowed',
        'external_side_effects_enabled',
        'registered_at',
        'last_drilled_at',
        'activated_at',
    ];

    protected function casts(): array
    {
        return [
            'operating_package_json' => 'array',
            'replay_contract_json' => 'array',
            'operating_package_attestations_json' => 'array',
            'slo_contract_json' => 'array',
            'incident_route_json' => 'array',
            'reconciliation_contract_json' => 'array',
            'promotion_gates_json' => 'array',
            'dashboard_bindings_json' => 'array',
            'drill_receipts_json' => 'array',
            'slo_green' => 'boolean',
            'incident_route_green' => 'boolean',
            'reconciliation_green' => 'boolean',
            'promotion_gate_green' => 'boolean',
            'external_execution_allowed' => 'boolean',
            'external_side_effects_enabled' => 'boolean',
            'registered_at' => 'immutable_datetime',
            'last_drilled_at' => 'immutable_datetime',
            'activated_at' => 'immutable_datetime',
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
        ];
    }
}
