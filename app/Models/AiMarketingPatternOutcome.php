<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/**
 * One row per (page snapshot + measured outcome). The audit fingerprint at the moment + the
 * real conversion that came back from the postback. Drives the LearnedWeightLedger.
 */
class AiMarketingPatternOutcome extends Model
{
    use HasUuids;

    protected $fillable = [
        'vsl_asset_id', 'niche', 'page_kind', 'present_patterns', 'audit_snapshot',
        'hollowness', 'conversion_rate', 'clicks', 'conversions', 'revenue_per_visitor', 'recorded_at',
    ];

    protected function casts(): array
    {
        return [
            'present_patterns' => 'array',
            'audit_snapshot' => 'array',
            'hollowness' => 'int',
            'conversion_rate' => 'float',
            'clicks' => 'int',
            'conversions' => 'int',
            'revenue_per_visitor' => 'float',
            'recorded_at' => 'datetime',
        ];
    }
}
