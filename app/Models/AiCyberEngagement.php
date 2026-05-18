<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class AiCyberEngagement extends Model
{
    use HasUuids;

    protected $table = 'ai_cyber_engagements';

    protected $fillable = [
        'schema_version',
        'uuid',
        'mission_id',
        'work_order_id',
        'engagement_id',
        'engagement_kind',
        'title',
        'summary',
        'requester',
        'targets',
        'authorization_present',
        'authorization',
        'legal_review',
        'privacy_review',
        'status',
        'blockers',
        'next_action',
        'engagement_hash',
    ];

    protected function casts(): array
    {
        return [
            'targets' => 'array',
            'authorization_present' => 'boolean',
            'authorization' => 'array',
            'legal_review' => 'array',
            'privacy_review' => 'array',
            'blockers' => 'array',
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
        ];
    }
}
