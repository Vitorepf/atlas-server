<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class AiMarketingWinningPattern extends Model
{
    use HasUuids;

    protected $fillable = [
        'schema_version',
        'niche',
        'source',
        'real_cvr',
        'cvr_stats',
        'converting_keywords',
        'winning_pages',
        'winning_funnels',
        'campaigns_sample',
        'campaigns_count',
        'sales_total',
        'clicks_total',
        'computed_at',
        'economics_real',
        'keyword_performance',
        'device_split',
        'network_split',
        'match_type_split',
        'geo',
        'timing',
        'funnel_profile',
        'bidding_of_winners',
        'winner_commonalities',
    ];

    protected function casts(): array
    {
        return [
            'real_cvr' => 'float',
            'cvr_stats' => 'array',
            'converting_keywords' => 'array',
            'winning_pages' => 'array',
            'winning_funnels' => 'array',
            'campaigns_sample' => 'array',
            'economics_real' => 'array',
            'keyword_performance' => 'array',
            'device_split' => 'array',
            'network_split' => 'array',
            'match_type_split' => 'array',
            'geo' => 'array',
            'timing' => 'array',
            'funnel_profile' => 'array',
            'bidding_of_winners' => 'array',
            'winner_commonalities' => 'array',
            'campaigns_count' => 'integer',
            'sales_total' => 'integer',
            'clicks_total' => 'integer',
            'computed_at' => 'immutable_datetime',
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
        ];
    }
}
