<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class AtlasStrategyRivalsCase extends Model
{
    use HasUuids;

    protected $fillable = [
        'title',
        'decision_domain',
        'status',
        'decision_made_at',
        'baseline_choice',
        'atlas_assisted_choice',
        'context_summary',
        'horizon_days',
        'source_hash',
        'tags_json',
        'metrics_json',
        'metadata',
    ];

    protected function casts(): array
    {
        return [
            'decision_made_at' => 'immutable_datetime',
            'horizon_days' => 'integer',
            'tags_json' => 'array',
            'metrics_json' => 'array',
            'metadata' => 'array',
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
        ];
    }

    public function reviews(): HasMany
    {
        return $this->hasMany(AtlasStrategyRivalsReview::class, 'case_id');
    }
}
