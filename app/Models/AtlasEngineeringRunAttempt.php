<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class AtlasEngineeringRunAttempt extends Model
{
    use HasUuids;

    protected $fillable = [
        'engineering_run_id',
        'attempt_number',
        'trace_id',
        'provider',
        'model',
        'phase',
        'prompt_hash',
        'input_summary_json',
        'patch_hash',
        'diff_stat_json',
        'changed_files_json',
        'status',
        'failure_summary',
        'started_at',
        'finished_at',
        'metadata',
    ];

    protected function casts(): array
    {
        return [
            'attempt_number' => 'integer',
            'input_summary_json' => 'array',
            'diff_stat_json' => 'array',
            'changed_files_json' => 'array',
            'started_at' => 'immutable_datetime',
            'finished_at' => 'immutable_datetime',
            'metadata' => 'array',
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
        ];
    }

    public function run(): BelongsTo
    {
        return $this->belongsTo(AtlasEngineeringRun::class, 'engineering_run_id');
    }

    public function patchArtifacts(): HasMany
    {
        return $this->hasMany(AtlasEngineeringPatchArtifact::class, 'attempt_id');
    }

    public function controlResults(): HasMany
    {
        return $this->hasMany(AtlasEngineeringControlResult::class, 'attempt_id');
    }

    public function testRuns(): HasMany
    {
        return $this->hasMany(AtlasEngineeringTestRun::class, 'attempt_id');
    }

    public function reviewFindings(): HasMany
    {
        return $this->hasMany(AtlasEngineeringReviewFinding::class, 'attempt_id');
    }
}
