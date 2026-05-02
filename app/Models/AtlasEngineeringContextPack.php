<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AtlasEngineeringContextPack extends Model
{
    use HasUuids;

    protected $fillable = [
        'engineering_run_id',
        'task_id',
        'hash',
        'contract_json',
        'blueprint_json',
        'repo_profile_json',
        'selected_files_json',
        'prior_runs_json',
        'memory_refs_json',
        'prompt_sections_json',
        'token_budget_json',
        'metadata',
    ];

    protected function casts(): array
    {
        return [
            'contract_json' => 'array',
            'blueprint_json' => 'array',
            'repo_profile_json' => 'array',
            'selected_files_json' => 'array',
            'prior_runs_json' => 'array',
            'memory_refs_json' => 'array',
            'prompt_sections_json' => 'array',
            'token_budget_json' => 'array',
            'metadata' => 'array',
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
        ];
    }

    public function run(): BelongsTo
    {
        return $this->belongsTo(AtlasEngineeringRun::class, 'engineering_run_id');
    }

    public function task(): BelongsTo
    {
        return $this->belongsTo(AtlasTask::class, 'task_id');
    }
}
