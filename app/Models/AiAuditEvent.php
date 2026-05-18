<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class AiAuditEvent extends Model
{
    use HasUuids;

    protected $table = 'ai_audit_events';

    protected $fillable = [
        'schema_version',
        'uuid',
        'event_type',
        'target_type',
        'target_id',
        'actor_type',
        'payload',
        'event_hash',
        'mission_id',
        'scope_type',
        'scope_id',
        'correlation_id',
        'causation_id',
    ];

    protected function casts(): array
    {
        return [
            'payload' => 'array',
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
        ];
    }
}
