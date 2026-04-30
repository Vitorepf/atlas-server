<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AiSessionState extends Model
{
    use HasUuids;

    protected $fillable = [
        'thread_id',
        'session_id',
        'version',
        'active',
        'objective',
        'current_phase',
        'current_topic',
        'user_position',
        'decisions',
        'open_loops',
        'next_steps',
        'relevant_artifacts',
        'constraints',
        'pending_steer',
        'provider_context',
        'quality_notes',
        'metadata',
    ];

    protected function casts(): array
    {
        return [
            'thread_id' => 'string',
            'session_id' => 'string',
            'version' => 'integer',
            'active' => 'boolean',
            'decisions' => 'array',
            'open_loops' => 'array',
            'next_steps' => 'array',
            'relevant_artifacts' => 'array',
            'constraints' => 'array',
            'provider_context' => 'array',
            'quality_notes' => 'array',
            'metadata' => 'array',
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
        ];
    }

    public function thread(): BelongsTo
    {
        return $this->belongsTo(AiThread::class, 'thread_id');
    }

    public function session(): BelongsTo
    {
        return $this->belongsTo(AiSession::class, 'session_id');
    }
}
