<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AtlasStrategyRivalsReview extends Model
{
    use HasUuids;

    protected $fillable = [
        'case_id',
        'horizon_days',
        'review_due_at',
        'reviewed_at',
        'status',
        'regret_score',
        'alignment_score',
        'agency_score',
        'outcome_summary',
        'metadata',
    ];

    protected function casts(): array
    {
        return [
            'horizon_days' => 'integer',
            'review_due_at' => 'immutable_datetime',
            'reviewed_at' => 'immutable_datetime',
            'regret_score' => 'integer',
            'alignment_score' => 'integer',
            'agency_score' => 'integer',
            'metadata' => 'array',
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
        ];
    }

    public function case(): BelongsTo
    {
        return $this->belongsTo(AtlasStrategyRivalsCase::class, 'case_id');
    }
}
