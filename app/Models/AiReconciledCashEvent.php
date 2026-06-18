<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/**
 * K1 — the only revenue truth that pays. Append-only; written exclusively by
 * ReconciledCashEventStore. `source` is DB-CHECK constrained to externally
 * reconciled origins, so a self-reported figure can never be credited.
 */
class AiReconciledCashEvent extends Model
{
    use HasUuids;

    protected $table = 'ai_reconciled_cash_events';

    public const SOURCES = ['payment_processor', 'bank', 'external_reconciled'];

    public const KINDS = ['credit', 'refund', 'chargeback', 'dispute', 'failed_renewal'];

    protected $fillable = [
        'schema_version',
        'uuid',
        'venture_id',
        'source',
        'event_kind',
        'external_ref',
        'amount_cents',
        'currency',
        'occurred_at',
        'settlement_horizon_days',
        'settled_at',
        'reverses_external_ref',
        'raw_payload',
        'event_hash',
    ];

    protected function casts(): array
    {
        return [
            'amount_cents' => 'integer',
            'settlement_horizon_days' => 'integer',
            'raw_payload' => 'array',
            'occurred_at' => 'immutable_datetime',
            'settled_at' => 'immutable_datetime',
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
        ];
    }
}
