<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class AiTrace extends Model
{
    use HasUuids;

    protected $fillable = [
        'trace_key',
        'thread_id',
        'session_id',
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
            'thread_id' => 'string',
            'session_id' => 'string',
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

    public function qualityEvaluation(): HasOne
    {
        return $this->hasOne(AiQualityEvaluation::class, 'trace_id');
    }

    public function metricSummary(): HasOne
    {
        return $this->hasOne(AiTraceMetricSummary::class, 'trace_id');
    }

    public function qualityActions(): HasMany
    {
        return $this->hasMany(AiQualityAction::class, 'trace_id')->latest('created_at');
    }

    public function routerDecision(): HasOne
    {
        return $this->hasOne(AiRouterDecision::class, 'trace_id');
    }

    public function atlasDecision(): HasOne
    {
        return $this->hasOne(AiDecision::class, 'trace_id');
    }

    public function remediationSourceActions(): HasMany
    {
        return $this->hasMany(AiQualityAction::class, 'remediation_trace_id')->latest('created_at');
    }

    public function jobs(): HasMany
    {
        return $this->hasMany(AiJob::class, 'trace_id');
    }

    public function streamEvents(): HasMany
    {
        return $this->hasMany(AiStreamEvent::class, 'trace_id')->orderBy('sequence');
    }

    public function toolEvents(): HasMany
    {
        return $this->hasMany(AiToolEvent::class, 'trace_id')->orderBy('created_at');
    }

    public function thread(): BelongsTo
    {
        return $this->belongsTo(AiThread::class, 'thread_id');
    }

    public function session(): BelongsTo
    {
        return $this->belongsTo(AiSession::class, 'session_id');
    }

    public function messages(): HasMany
    {
        return $this->hasMany(AiMessage::class, 'trace_id');
    }

    public function memoryUsages(): HasMany
    {
        return $this->hasMany(AtlasMemoryEntryUsage::class, 'trace_id')
            ->orderBy('position');
    }
}
