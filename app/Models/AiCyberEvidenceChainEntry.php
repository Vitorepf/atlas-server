<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class AiCyberEvidenceChainEntry extends Model
{
    use HasUuids;

    protected $table = 'ai_cyber_evidence_chain';

    protected $fillable = [
        'schema_version',
        'uuid',
        'engagement_id',
        'parent_entry_id',
        'entry_kind',
        'actor',
        'payload',
        'artifact_refs',
        'previous_hash',
        'entry_hash',
    ];

    protected function casts(): array
    {
        return [
            'payload' => 'array',
            'artifact_refs' => 'array',
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
        ];
    }
}
