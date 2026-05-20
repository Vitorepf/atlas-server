<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class AiHoldingConnectorActivationRecord extends Model
{
    use HasUuids;

    protected $fillable = [
        'company_id',
        'flow_id',
        'connector_id',
        'status',
        'certification_receipt_hash',
        'flow_usage_attestation_hash',
        'adapter_contract_hash',
        'auth_boundary_hash',
        'sandbox_probe_hash',
        'slo_hash',
        'lineage_hash',
        'replay_fixture_hash',
        'allowed_modes_json',
        'blocked_modes_json',
        'pre_run_requirements_json',
        'probe_receipts_json',
        'last_probe_receipt_hash',
        'last_probe_status',
        'vault_binding_required',
        'vault_binding_attested',
        'sandbox_probe_green',
        'slo_monitor_bound',
        'reconciliation_bound',
        'external_execution_allowed',
        'external_side_effects_enabled',
        'registered_at',
        'last_probed_at',
        'activated_at',
    ];

    protected function casts(): array
    {
        return [
            'allowed_modes_json' => 'array',
            'blocked_modes_json' => 'array',
            'pre_run_requirements_json' => 'array',
            'probe_receipts_json' => 'array',
            'vault_binding_required' => 'boolean',
            'vault_binding_attested' => 'boolean',
            'sandbox_probe_green' => 'boolean',
            'slo_monitor_bound' => 'boolean',
            'reconciliation_bound' => 'boolean',
            'external_execution_allowed' => 'boolean',
            'external_side_effects_enabled' => 'boolean',
            'registered_at' => 'immutable_datetime',
            'last_probed_at' => 'immutable_datetime',
            'activated_at' => 'immutable_datetime',
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
        ];
    }
}
