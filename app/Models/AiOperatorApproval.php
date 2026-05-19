<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class AiOperatorApproval extends Model
{
    use HasUuids;

    protected $fillable = [
        'schema_version',
        'uuid',
        'mission_id',
        'work_order_id',
        'trace_id',
        'job_id',
        'requested_action',
        'risk_level',
        'gate_mode',
        'approval_required',
        'reason',
        'options',
        'status',
        'operator_decision',
        'operator',
        'operator_note',
        'expires_at',
        'decided_at',
        'consumed_at',
        'evidence_refs',
        'receipt_hash',
        'hash',
    ];

    protected function casts(): array
    {
        return [
            'approval_required' => 'boolean',
            'options' => 'array',
            'evidence_refs' => 'array',
            'expires_at' => 'immutable_datetime',
            'decided_at' => 'immutable_datetime',
            'consumed_at' => 'immutable_datetime',
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
        ];
    }
}
