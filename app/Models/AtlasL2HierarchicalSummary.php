<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class AtlasL2HierarchicalSummary extends Model
{
    use HasUuids;

    protected $table = 'atlas_l2_hierarchical_summaries';

    protected $fillable = [
        'compaction_id',
        'thread_id',
        'version',
        'status',
        'local_runtime',
        'l1_summary_hash',
        'l2_summary_hash',
        'l2_summary',
        'coverage_score',
        'l1_context_retention_score',
        'l2_context_retention_score',
        'compression_ratio',
        'required_items',
        'scorer_report',
        'rejection_reasons',
        'metadata',
    ];

    protected function casts(): array
    {
        return [
            'compaction_id' => 'string',
            'thread_id' => 'string',
            'version' => 'integer',
            'coverage_score' => 'float',
            'l1_context_retention_score' => 'float',
            'l2_context_retention_score' => 'float',
            'compression_ratio' => 'float',
            'required_items' => 'array',
            'scorer_report' => 'array',
            'rejection_reasons' => 'array',
            'metadata' => 'array',
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
        ];
    }
}
