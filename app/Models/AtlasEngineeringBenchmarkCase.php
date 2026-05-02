<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class AtlasEngineeringBenchmarkCase extends Model
{
    use HasUuids;

    protected $fillable = [
        'suite_id',
        'task_id',
        'case_code',
        'title',
        'description',
        'workspace_path_hash',
        'task_contract_json',
        'runner_options_json',
        'expected_decision',
        'min_score',
        'corpus_tier',
        'domain_slug',
        'risk_profile',
        'curation_status',
        'curation_score',
        'corpus_fingerprint',
        'curated_at',
        'tags_json',
        'status',
        'metadata',
    ];

    protected function casts(): array
    {
        return [
            'task_contract_json' => 'array',
            'runner_options_json' => 'array',
            'min_score' => 'integer',
            'curation_score' => 'integer',
            'tags_json' => 'array',
            'metadata' => 'array',
            'curated_at' => 'immutable_datetime',
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
        ];
    }

    public function suite(): BelongsTo
    {
        return $this->belongsTo(AtlasEngineeringBenchmarkSuite::class, 'suite_id');
    }

    public function task(): BelongsTo
    {
        return $this->belongsTo(AtlasTask::class, 'task_id');
    }

    public function results(): HasMany
    {
        return $this->hasMany(AtlasEngineeringBenchmarkResult::class, 'case_id');
    }
}
