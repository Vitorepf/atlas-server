<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class AiMarketingEconomicsLedgerEntry extends Model
{
    use HasUuids;

    protected $table = 'ai_marketing_economics_ledger';

    protected $fillable = [
        'schema_version',
        'campaign_ref',
        'niche',
        'offer_type',
        'payout',
        'cvr_actual',
        'refund_rate_actual',
        'max_cpa_set',
        'max_cpa_achieved',
        'roas_actual',
        'margin_actual',
        'epc',
        'revenue',
        'cost',
        'recorded_at',
    ];

    protected function casts(): array
    {
        return [
            'payout' => 'float',
            'cvr_actual' => 'float',
            'refund_rate_actual' => 'float',
            'max_cpa_set' => 'float',
            'max_cpa_achieved' => 'float',
            'roas_actual' => 'float',
            'margin_actual' => 'float',
            'epc' => 'float',
            'revenue' => 'float',
            'cost' => 'float',
            'recorded_at' => 'immutable_datetime',
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
        ];
    }
}
