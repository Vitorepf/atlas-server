<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class AiInboxItem extends Model
{
    use HasUuids;

    public const TYPES = [
        'approval',
        'alert',
        'completion',
        'capture',
        'thread_update',
        'job_status',
        'insight',
        'proposal',
        'job_result',
        'self_diagnostic',
    ];

    public const STATUSES = [
        'unread',
        'read',
        'actioned',
        'resolved',
        'dismissed',
        'expired',
        'snoozed',
    ];

    protected $fillable = [
        'user_id',
        'type',
        'category',
        'severity',
        'status',
        'title',
        'summary',
        'body',
        'source_type',
        'source_id',
        'initiator',
        'context_bundle_id',
        'dedupe_key',
        'available_actions',
        'response',
        'payload',
        'deep_link',
        'push_policy',
        'priority_score',
        'confidence_score',
        'expires_at',
        'snoozed_until',
        'read_at',
        'resolved_at',
        'dismissed_at',
    ];

    protected function casts(): array
    {
        return [
            'source_id' => 'string',
            'context_bundle_id' => 'string',
            'available_actions' => 'array',
            'response' => 'array',
            'payload' => 'array',
            'push_policy' => 'array',
            'priority_score' => 'integer',
            'confidence_score' => 'float',
            'expires_at' => 'immutable_datetime',
            'snoozed_until' => 'immutable_datetime',
            'read_at' => 'immutable_datetime',
            'resolved_at' => 'immutable_datetime',
            'dismissed_at' => 'immutable_datetime',
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
        ];
    }

    public function contextBundle(): BelongsTo
    {
        return $this->belongsTo(AiContextBundle::class, 'context_bundle_id');
    }

    public function pushDeliveries(): HasMany
    {
        return $this->hasMany(MobilePushDelivery::class, 'inbox_item_id');
    }
}
