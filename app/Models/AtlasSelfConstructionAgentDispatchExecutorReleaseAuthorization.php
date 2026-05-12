<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class AtlasSelfConstructionAgentDispatchExecutorReleaseAuthorization extends Model
{
    use HasUuids;

    protected $fillable = [
        'authorization_key',
        'receipt_key',
        'authorization_id',
        'packet_id',
        'provider',
        'provider_role',
        'decision',
        'status',
        'signed_by',
        'signed_at',
        'expires_at',
        'signed_receipt_template_hash',
        'signed_receipt_preflight_hash',
        'persistence_template_hash',
        'persistence_preflight_hash',
        'external_signature_validation_report_hash',
        'signed_receipt_hash',
        'payload',
        'persisted_at',
    ];

    protected function casts(): array
    {
        return [
            'signed_at' => 'immutable_datetime',
            'expires_at' => 'immutable_datetime',
            'payload' => 'array',
            'persisted_at' => 'immutable_datetime',
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
        ];
    }
}
