<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class AiHoldingExternalCutoverWorkOrder extends Model
{
    use HasUuids;

    protected $fillable = [
        'work_order_id',
        'company_id',
        'flow_id',
        'status',
        'phase',
        'cutover_decision',
        'source_cutover_dossier_hash',
        'work_order_hash',
        'required_receipt_ids_json',
        'missing_receipt_ids_json',
        'operator_enablement_pack_json',
        'launch_blockers_json',
        'final_authority_bindings_json',
        'final_authority_binding_hash',
        'work_item_count',
        'pending_work_item_count',
        'bound_receipt_count',
        'final_authority_binding_count',
        'work_order_ready',
        'supervised_cutover_enabled',
        'external_execution_allowed',
        'external_side_effects_enabled',
        'registered_at',
        'last_status_at',
    ];

    protected function casts(): array
    {
        return [
            'required_receipt_ids_json' => 'array',
            'missing_receipt_ids_json' => 'array',
            'operator_enablement_pack_json' => 'array',
            'launch_blockers_json' => 'array',
            'final_authority_bindings_json' => 'array',
            'work_item_count' => 'integer',
            'pending_work_item_count' => 'integer',
            'bound_receipt_count' => 'integer',
            'final_authority_binding_count' => 'integer',
            'work_order_ready' => 'boolean',
            'supervised_cutover_enabled' => 'boolean',
            'external_execution_allowed' => 'boolean',
            'external_side_effects_enabled' => 'boolean',
            'registered_at' => 'immutable_datetime',
            'last_status_at' => 'immutable_datetime',
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
        ];
    }
}
