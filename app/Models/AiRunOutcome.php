<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class AiRunOutcome extends Model
{
    use HasUuids;

    protected $fillable = [
        'schema_version',
        'run_id',
        'trace_id',
        'flow_id',
        'outcome_status',
        'flow_quality',
        'retrieval_quality',
        'execution_quality',
        'evidence_quality',
        'human_override',
        'learning_required',
        'missed_signals',
        'evidence_refs',
        'payload',
        'outcome_hash',
        'evaluated_at',
    ];

    protected function casts(): array
    {
        return [
            'flow_quality' => 'integer',
            'retrieval_quality' => 'integer',
            'execution_quality' => 'integer',
            'evidence_quality' => 'integer',
            'human_override' => 'boolean',
            'learning_required' => 'boolean',
            'missed_signals' => 'array',
            'evidence_refs' => 'array',
            'payload' => 'array',
            'evaluated_at' => 'immutable_datetime',
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
        ];
    }

    public function learningCandidates(): HasMany
    {
        return $this->hasMany(AiLearningCandidate::class, 'run_outcome_id');
    }
}
