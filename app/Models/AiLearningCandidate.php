<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;

class AiLearningCandidate extends Model
{
    use HasUuids;

    protected $fillable = [
        'schema_version',
        'run_outcome_id',
        'candidate_hash',
        'status',
        'decision',
        'memory_type',
        'scope',
        'claim',
        'confidence',
        'promotion_allowed',
        'evidence_refs',
        'payload',
        'receipt_hash',
        'decided_at',
    ];

    protected function casts(): array
    {
        return [
            'confidence' => 'integer',
            'promotion_allowed' => 'boolean',
            'evidence_refs' => 'array',
            'payload' => 'array',
            'decided_at' => 'immutable_datetime',
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
        ];
    }

    public function outcome(): BelongsTo
    {
        return $this->belongsTo(AiRunOutcome::class, 'run_outcome_id');
    }

    public function memory(): HasOne
    {
        return $this->hasOne(AiCompoundingMemory::class, 'learning_candidate_id');
    }
}
