<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AtlasEngineeringBenchmarkResult extends Model
{
    use HasUuids;

    protected $fillable = [
        'benchmark_run_id',
        'suite_id',
        'case_id',
        'engineering_run_id',
        'task_id',
        'status',
        'decision',
        'score',
        'passed',
        'duration_ms',
        'expectation_json',
        'observed_json',
        'failure_summary',
        'metadata',
    ];

    protected function casts(): array
    {
        return [
            'score' => 'integer',
            'passed' => 'boolean',
            'duration_ms' => 'integer',
            'expectation_json' => 'array',
            'observed_json' => 'array',
            'metadata' => 'array',
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
        ];
    }

    public function benchmarkRun(): BelongsTo
    {
        return $this->belongsTo(AtlasEngineeringBenchmarkRun::class, 'benchmark_run_id');
    }

    public function suite(): BelongsTo
    {
        return $this->belongsTo(AtlasEngineeringBenchmarkSuite::class, 'suite_id');
    }

    public function benchmarkCase(): BelongsTo
    {
        return $this->belongsTo(AtlasEngineeringBenchmarkCase::class, 'case_id');
    }

    public function engineeringRun(): BelongsTo
    {
        return $this->belongsTo(AtlasEngineeringRun::class, 'engineering_run_id');
    }

    public function task(): BelongsTo
    {
        return $this->belongsTo(AtlasTask::class, 'task_id');
    }
}
