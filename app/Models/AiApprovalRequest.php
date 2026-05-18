<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class AiApprovalRequest extends Model
{
    use HasUuids;

    protected $fillable = [
        'schema_version',
        'uuid',
        'mission_id',
        'work_order_id',
        'approval_type',
        'requested_action',
        'requester_type',
        'status',
        'approver',
        'reason',
        'decision_payload',
        'expires_at',
        'decided_at',
        'receipt_hash',
    ];

    protected function casts(): array
    {
        return [
            'decision_payload' => 'array',
            'expires_at' => 'immutable_datetime',
            'decided_at' => 'immutable_datetime',
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
        ];
    }
}
