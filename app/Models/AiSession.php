<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class AiSession extends Model
{
    use HasUuids;

    protected $fillable = [
        'thread_id',
        'status',
        'purpose',
        'provider_primary',
        'provider_last',
        'started_at',
        'ended_at',
        'message_count',
        'token_estimate',
        'metadata',
    ];

    protected function casts(): array
    {
        return [
            'thread_id' => 'string',
            'started_at' => 'immutable_datetime',
            'ended_at' => 'immutable_datetime',
            'message_count' => 'integer',
            'token_estimate' => 'integer',
            'metadata' => 'array',
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
        ];
    }

    public function thread(): BelongsTo
    {
        return $this->belongsTo(AiThread::class, 'thread_id');
    }

    public function activeState(): HasOne
    {
        return $this->hasOne(AiSessionState::class, 'session_id')->where('active', true)->latest('updated_at');
    }

    public function states(): HasMany
    {
        return $this->hasMany(AiSessionState::class, 'session_id');
    }

    public function compactions(): HasMany
    {
        return $this->hasMany(AiCompaction::class, 'session_id');
    }

    public function handoffs(): HasMany
    {
        return $this->hasMany(AiProviderHandoff::class, 'session_id');
    }
}
