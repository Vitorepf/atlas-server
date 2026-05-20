<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class AiHoldingExternalCutoverWorkItem extends Model
{
    use HasUuids;

    protected $fillable = [
        'work_item_id',
        'work_order_id',
        'company_id',
        'flow_id',
        'action_id',
        'workstream',
        'owner_role',
        'status',
        'required_receipt_ids_json',
        'completion_requires_json',
        'blocked_operations_json',
        'source_cutover_dossier_hash',
        'work_item_hash',
        'bound_receipt_hash',
        'receipt_binding_hash',
        'receipt_binding_json',
        'executable',
        'external_execution_allowed',
        'external_side_effects_enabled',
        'registered_at',
        'completed_at',
    ];

    protected function casts(): array
    {
        return [
            'required_receipt_ids_json' => 'array',
            'completion_requires_json' => 'array',
            'blocked_operations_json' => 'array',
            'receipt_binding_json' => 'array',
            'executable' => 'boolean',
            'external_execution_allowed' => 'boolean',
            'external_side_effects_enabled' => 'boolean',
            'registered_at' => 'immutable_datetime',
            'completed_at' => 'immutable_datetime',
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
        ];
    }
}
