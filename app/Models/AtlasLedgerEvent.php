<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AtlasLedgerEvent extends Model
{
    protected $primaryKey = 'event_id';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = [
        'event_id',
        'schema_version',
        'tenant_id',
        'operator_id',
        'envelope_id',
        'receipt_id',
        'trace_id',
        'correlation_id',
        'causation_id',
        'event_type',
        'emitter_stage',
        'emitter_version',
        'payload',
        'payload_hash',
        'occurred_at',
    ];

    protected function casts(): array
    {
        return [
            'payload' => 'array',
            'occurred_at' => 'immutable_datetime',
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
        ];
    }
}
