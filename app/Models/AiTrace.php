<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class AiTrace extends Model
{
    use HasUuids;

    protected $fillable = [
        'trace_key',
        'source_type',
        'source_id',
        'status',
        'operator_input',
        'intent',
        'agent_slug',
        'provider',
        'model',
        'skill_versions',
        'context_refs',
        'prompt_hash',
        'response_hash',
        'response_text',
        'latency_ms',
        'feedback_score',
        'feedback_action',
        'feedback_comment',
        'completed_at',
        'metadata',
    ];

    protected function casts(): array
    {
        return [
            'source_id' => 'string',
            'skill_versions' => 'array',
            'context_refs' => 'array',
            'latency_ms' => 'integer',
            'feedback_score' => 'integer',
            'completed_at' => 'immutable_datetime',
            'metadata' => 'array',
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
        ];
    }

    public function job(): HasOne
    {
        return $this->hasOne(AiJob::class, 'trace_id');
    }

    public function jobs(): HasMany
    {
        return $this->hasMany(AiJob::class, 'trace_id');
    }
}
