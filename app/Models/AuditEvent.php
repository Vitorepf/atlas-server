<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class AuditEvent extends Model
{
    use HasUuids;

    protected $fillable = [
        'event_type',
        'subject_type',
        'subject_id',
        'actor_type',
        'actor_id',
        'severity',
        'summary',
        'evidence',
        'privacy',
        'refs',
        'metadata',
        'occurred_at',
    ];

    protected function casts(): array
    {
        return [
            'subject_id' => 'string',
            'evidence' => 'array',
            'privacy' => 'array',
            'refs' => 'array',
            'metadata' => 'array',
            'occurred_at' => 'immutable_datetime',
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
        ];
    }
}
