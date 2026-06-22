<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class AiMarketingDecisionLedgerEntry extends Model
{
    use HasUuids;

    protected $table = 'ai_marketing_decision_ledger';

    protected $fillable = [
        'schema_version',
        'campaign_ref',
        'vsl_asset_id',
        'niche',
        'stage',
        'symptom',
        'action',
        'lever',
        'vsl_block',
        'numeric_rule',
        'predicted_effect',
        'offer_state',
        'decided_at',
        'outcome',
        'result_metrics',
        'result_note',
        'measured_at',
        'decision_hash',
    ];

    protected function casts(): array
    {
        return [
            'offer_state' => 'array',
            'result_metrics' => 'array',
            'decided_at' => 'immutable_datetime',
            'measured_at' => 'immutable_datetime',
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
        ];
    }
}
