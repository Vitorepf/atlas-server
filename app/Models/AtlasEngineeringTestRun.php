<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AtlasEngineeringTestRun extends Model
{
    use HasUuids;

    protected $fillable = [
        'engineering_run_id',
        'attempt_id',
        'test_case_id',
        'command',
        'exit_code',
        'status',
        'duration_ms',
        'stdout_excerpt',
        'stderr_excerpt',
        'artifact_path',
        'metadata',
    ];

    protected function casts(): array
    {
        return [
            'exit_code' => 'integer',
            'duration_ms' => 'integer',
            'metadata' => 'array',
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
        ];
    }

    public function run(): BelongsTo
    {
        return $this->belongsTo(AtlasEngineeringRun::class, 'engineering_run_id');
    }

    public function attempt(): BelongsTo
    {
        return $this->belongsTo(AtlasEngineeringRunAttempt::class, 'attempt_id');
    }

    public function testCase(): BelongsTo
    {
        return $this->belongsTo(AtlasEngineeringTestCase::class, 'test_case_id');
    }
}
