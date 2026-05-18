<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class AiPermissionGate extends Model
{
    use HasUuids;

    protected $fillable = [
        'schema_version',
        'uuid',
        'mission_id',
        'work_order_id',
        'domain_id',
        'tool_id',
        'gate_type',
        'requested_action',
        'risk_level',
        'decision',
        'reasons',
        'required_approvals',
        'evidence_refs',
        'receipt_hash',
    ];

    protected function casts(): array
    {
        return [
            'reasons' => 'array',
            'required_approvals' => 'array',
            'evidence_refs' => 'array',
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
        ];
    }
}
