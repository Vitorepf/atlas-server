<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class AiQualityEvaluation extends Model
{
    use HasUuids;

    protected $fillable = [
        'trace_id',
        'thread_id',
        'session_id',
        'provider',
        'model',
        'agent_slug',
        'evaluator_version',
        'score',
        'status',
        'dimensions',
        'flags',
        'suggested_actions',
        'metadata',
    ];

    protected function casts(): array
    {
        return [
            'trace_id' => 'string',
            'thread_id' => 'string',
            'session_id' => 'string',
            'score' => 'integer',
            'dimensions' => 'array',
            'flags' => 'array',
            'suggested_actions' => 'array',
            'metadata' => 'array',
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
        ];
    }

    public function trace(): BelongsTo
    {
        return $this->belongsTo(AiTrace::class, 'trace_id');
    }

    public function thread(): BelongsTo
    {
        return $this->belongsTo(AiThread::class, 'thread_id');
    }

    public function session(): BelongsTo
    {
        return $this->belongsTo(AiSession::class, 'session_id');
    }

    public function actions(): HasMany
    {
        return $this->hasMany(AiQualityAction::class, 'evaluation_id')->latest('created_at');
    }
}
