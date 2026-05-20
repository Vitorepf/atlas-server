<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class AtlasPersistentContextPack extends Model
{
    use HasUuids;

    protected $fillable = [
        'uuid',
        'schema_version',
        'status',
        'scope_type',
        'scope_id',
        'workspace',
        'surface_id',
        'domain',
        'flow_id',
        'provider',
        'prompt_hash',
        'context_pack_hash',
        'must_know_ledger_hash',
        'sufficiency_status',
        'retrieval_report',
        'must_know_ledger',
        'context_pack',
        'provider_handoff',
        'post_execution_update',
        'memory_delta_id',
        'evidence_refs',
        'metadata',
    ];

    protected function casts(): array
    {
        return [
            'retrieval_report' => 'array',
            'must_know_ledger' => 'array',
            'context_pack' => 'array',
            'provider_handoff' => 'array',
            'post_execution_update' => 'array',
            'evidence_refs' => 'array',
            'metadata' => 'array',
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
        ];
    }
}
