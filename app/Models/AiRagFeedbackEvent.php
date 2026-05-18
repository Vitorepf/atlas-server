<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class AiRagFeedbackEvent extends Model
{
    use HasUuids;

    protected $fillable = [
        'schema_version',
        'retrieval_receipt_id',
        'flow_id',
        'query_plan_hash',
        'included_sources',
        'used_sources',
        'noise_sources',
        'missed_required_sources',
        'context_sufficiency',
        'post_execution_utility',
        'source_utility',
        'outcome_status',
        'failure_reason',
        'next_retrieval_hint',
        'memory_candidate_id',
        'learning_proposal_id',
        'run_outcome_id',
        'payload',
        'feedback_hash',
    ];

    protected function casts(): array
    {
        return [
            'included_sources' => 'integer',
            'used_sources' => 'integer',
            'noise_sources' => 'integer',
            'missed_required_sources' => 'array',
            'context_sufficiency' => 'integer',
            'post_execution_utility' => 'integer',
            'source_utility' => 'array',
            'next_retrieval_hint' => 'array',
            'payload' => 'array',
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
        ];
    }
}
