<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class AiEvidencePack extends Model
{
    use HasUuids;

    protected $table = 'ai_evidence_packs';

    protected $fillable = [
        'schema_version',
        'uuid',
        'target_type',
        'target_id',
        'mission_id',
        'work_order_id',
        'domain_id',
        'artifact_refs',
        'source_refs',
        'command_refs',
        'test_refs',
        'receipt_refs',
        'blocker_refs',
        'evidence_hash',
        'status',
    ];

    protected function casts(): array
    {
        return [
            'artifact_refs' => 'array',
            'source_refs' => 'array',
            'command_refs' => 'array',
            'test_refs' => 'array',
            'receipt_refs' => 'array',
            'blocker_refs' => 'array',
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
        ];
    }
}
