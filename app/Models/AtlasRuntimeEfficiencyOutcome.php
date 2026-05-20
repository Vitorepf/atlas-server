<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AtlasRuntimeEfficiencyOutcome extends Model
{
    use HasUuids;

    protected $fillable = [
        'decision_id',
        'schema_version',
        'status',
        'outcome_type',
        'quality_score',
        'context_roi_score',
        'signals',
        'learning_candidates',
        'evidence_refs',
        'outcome_hash',
    ];

    protected function casts(): array
    {
        return [
            'quality_score' => 'float',
            'context_roi_score' => 'float',
            'signals' => 'array',
            'learning_candidates' => 'array',
            'evidence_refs' => 'array',
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
        ];
    }

    public function decision(): BelongsTo
    {
        return $this->belongsTo(AtlasRuntimeEfficiencyDecision::class, 'decision_id');
    }
}
