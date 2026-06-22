<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AiMarketingCampaignBlueprint extends Model
{
    use HasUuids;

    protected $fillable = [
        'schema_version',
        'vsl_asset_id',
        'campaign_ref',
        'channel',
        'geo',
        'language',
        'inputs',
        'economics',
        'bid_plan',
        'structure',
        'keywords_plan',
        'angle',
        'ad_plan',
        'first_test_plan',
        'status',
        'notes',
    ];

    protected function casts(): array
    {
        return [
            'inputs' => 'array',
            'economics' => 'array',
            'bid_plan' => 'array',
            'structure' => 'array',
            'keywords_plan' => 'array',
            'angle' => 'array',
            'ad_plan' => 'array',
            'first_test_plan' => 'array',
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
        ];
    }

    public function vslAsset(): BelongsTo
    {
        return $this->belongsTo(AiMarketingVslAsset::class, 'vsl_asset_id');
    }
}
