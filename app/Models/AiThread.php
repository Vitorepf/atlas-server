<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class AiThread extends Model
{
    use HasUuids;

    protected $fillable = [
        'title',
        'summary',
        'status',
        'surface',
        'workspace',
        'source_type',
        'source_id',
        'last_trace_id',
        'last_provider',
        'message_count',
        'last_message_at',
        'metadata',
    ];

    protected function casts(): array
    {
        return [
            'source_id' => 'string',
            'last_trace_id' => 'string',
            'message_count' => 'integer',
            'last_message_at' => 'immutable_datetime',
            'metadata' => 'array',
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
        ];
    }

    public function messages(): HasMany
    {
        return $this->hasMany(AiMessage::class, 'thread_id')->orderBy('position');
    }

    public function traces(): HasMany
    {
        return $this->hasMany(AiTrace::class, 'thread_id')->orderBy('created_at');
    }

    public function sessions(): HasMany
    {
        return $this->hasMany(AiSession::class, 'thread_id')->orderByDesc('started_at');
    }

    public function activeSession(): HasOne
    {
        return $this->hasOne(AiSession::class, 'thread_id')->where('status', 'active')->latest('started_at');
    }

    public function activeState(): HasOne
    {
        return $this->hasOne(AiSessionState::class, 'thread_id')->where('active', true)->latest('updated_at');
    }

    public function compactions(): HasMany
    {
        return $this->hasMany(AiCompaction::class, 'thread_id')->latest('created_at');
    }

    public function latestCompaction(): HasOne
    {
        return $this->hasOne(AiCompaction::class, 'thread_id')->latestOfMany('created_at');
    }

    public function providerHandoffs(): HasMany
    {
        return $this->hasMany(AiProviderHandoff::class, 'thread_id')->latest('created_at');
    }

    public function latestProviderHandoff(): HasOne
    {
        return $this->hasOne(AiProviderHandoff::class, 'thread_id')->latestOfMany('created_at');
    }

    public function lastTrace(): BelongsTo
    {
        return $this->belongsTo(AiTrace::class, 'last_trace_id');
    }
}
