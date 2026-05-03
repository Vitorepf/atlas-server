<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class AtlasOpenBrainAccessLog extends Model
{
    use HasUuids;

    protected $fillable = [
        'surface',
        'requester',
        'action',
        'status',
        'workspace_hash',
        'workspace_label',
        'context_pack_hash',
        'context_refs_count',
        'memory_refs_count',
        'provider_safe',
        'query_json',
        'result_summary_json',
        'metadata',
        'accessed_at',
    ];

    protected function casts(): array
    {
        return [
            'context_refs_count' => 'integer',
            'memory_refs_count' => 'integer',
            'provider_safe' => 'boolean',
            'query_json' => 'array',
            'result_summary_json' => 'array',
            'metadata' => 'array',
            'accessed_at' => 'immutable_datetime',
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
        ];
    }
}
