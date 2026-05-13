<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class AtlasProgrammingContextPack extends Model
{
    use HasUuids;

    protected $fillable = [
        'plan_id',
        'parent_plan_id',
        'context_pack_hash',
        'schema_version',
        'status',
        'provider_safe',
        'retrieval_strategy',
        'ranked_refs_json',
        'excluded_refs_json',
        'source_counts_json',
        'metrics_json',
        'budget_json',
        'payload_json',
    ];

    protected function casts(): array
    {
        return [
            'provider_safe' => 'boolean',
            'ranked_refs_json' => 'array',
            'excluded_refs_json' => 'array',
            'source_counts_json' => 'array',
            'metrics_json' => 'array',
            'budget_json' => 'array',
            'payload_json' => 'array',
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
        ];
    }
}
