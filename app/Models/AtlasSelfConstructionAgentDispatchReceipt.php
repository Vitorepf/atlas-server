<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AtlasSelfConstructionAgentDispatchReceipt extends Model
{
    use HasUuids;

    protected $fillable = [
        'agent_run_id',
        'wakeup_item_id',
        'receipt_key',
        'packet_id',
        'provider',
        'provider_role',
        'decision',
        'status',
        'signed_by',
        'signed_at',
        'expires_at',
        'used_at',
        'dispatch_envelope_hash',
        'adapter_contract_hash',
        'receipt_hash',
        'payload',
    ];

    protected function casts(): array
    {
        return [
            'signed_at' => 'immutable_datetime',
            'expires_at' => 'immutable_datetime',
            'used_at' => 'immutable_datetime',
            'payload' => 'array',
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
        ];
    }

    public function run(): BelongsTo
    {
        return $this->belongsTo(AtlasSelfConstructionAgentRun::class, 'agent_run_id');
    }

    public function wakeupItem(): BelongsTo
    {
        return $this->belongsTo(AtlasSelfConstructionAgentWakeupItem::class, 'wakeup_item_id');
    }
}
