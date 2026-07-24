<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use LogicException;

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
        'scope_type',
        'scope_id',
        'event_hash',
        'prev_event_hash',
        'chain_basis',
        'chain_key_hash',
        'chain_position',
        'occurred_at',
    ];

    protected function casts(): array
    {
        return [
            'payload' => 'array',
            'chain_position' => 'integer',
            'occurred_at' => 'immutable_datetime',
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
        ];
    }

    /**
     * @param  array<string,mixed>  $options
     */
    public function save(array $options = []): bool
    {
        if ($this->exists && $this->isDirty()) {
            throw new LogicException('Atlas ledger events are append-only; update is not allowed.');
        }

        return parent::save($options);
    }

    public function delete()
    {
        throw new LogicException('Atlas ledger events are append-only; delete is not allowed.');
    }
}
