<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class AiBlocker extends Model
{
    use HasUuids;

    protected $table = 'ai_blockers';

    protected $fillable = [
        'schema_version',
        'uuid',
        'target_type',
        'target_id',
        'blocker_type',
        'severity',
        'reason',
        'evidence_refs',
        'status',
        'resolved_at',
        'mission_id',
    ];

    protected function casts(): array
    {
        return [
            'evidence_refs' => 'array',
            'resolved_at' => 'immutable_datetime',
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
        ];
    }
}
