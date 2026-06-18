<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/**
 * K2 — per-venture measured burn (the denominator). Append-only; written by
 * VentureCostAttributionLedger. cost_microusd is measured, never fabricated.
 */
class AiVentureCostAttribution extends Model
{
    use HasUuids;

    protected $table = 'ai_venture_cost_attributions';

    public const COST_MODES = ['token_cost', 'operational'];

    protected $fillable = [
        'schema_version',
        'uuid',
        'venture_id',
        'cost_mode',
        'cost_microusd',
        'category',
        'source_ref',
        'provider',
        'model',
        'occurred_at',
        'attribution_hash',
    ];

    protected function casts(): array
    {
        return [
            'cost_microusd' => 'integer',
            'occurred_at' => 'immutable_datetime',
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
        ];
    }
}
