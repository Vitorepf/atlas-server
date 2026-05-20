<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class AiHoldingExternalActionMandate extends Model
{
    use HasUuids;

    protected $fillable = [
        'company_id',
        'flow_id',
        'status',
        'mandate_packet_hash',
        'source_runtime_receipt_hash',
        'source_connector_certification_hash',
        'source_flow_usage_attestation_hash',
        'connector_scope_json',
        'blocked_operations_json',
        'preflight_checks_json',
        'risk_controls_json',
        'rollback_or_compensation_json',
        'incident_route_json',
        'cost_budget_envelope_json',
        'operator_signature_required',
        'second_reviewer_required',
        'auto_execute_allowed',
        'external_side_effects_enabled',
        'packet_json',
        'queued_at',
        'preflighted_at',
    ];

    protected function casts(): array
    {
        return [
            'connector_scope_json' => 'array',
            'blocked_operations_json' => 'array',
            'preflight_checks_json' => 'array',
            'risk_controls_json' => 'array',
            'rollback_or_compensation_json' => 'array',
            'incident_route_json' => 'array',
            'cost_budget_envelope_json' => 'array',
            'operator_signature_required' => 'boolean',
            'second_reviewer_required' => 'boolean',
            'auto_execute_allowed' => 'boolean',
            'external_side_effects_enabled' => 'boolean',
            'packet_json' => 'array',
            'queued_at' => 'immutable_datetime',
            'preflighted_at' => 'immutable_datetime',
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
        ];
    }
}
