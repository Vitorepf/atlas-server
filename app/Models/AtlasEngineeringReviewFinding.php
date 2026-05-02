<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AtlasEngineeringReviewFinding extends Model
{
    use HasUuids;

    protected $fillable = [
        'engineering_run_id',
        'attempt_id',
        'task_id',
        'source',
        'severity',
        'status',
        'title',
        'body',
        'file_path',
        'start_line',
        'end_line',
        'evidence_json',
        'resolution_json',
        'detected_at',
        'resolved_at',
        'metadata',
    ];

    protected function casts(): array
    {
        return [
            'start_line' => 'integer',
            'end_line' => 'integer',
            'evidence_json' => 'array',
            'resolution_json' => 'array',
            'detected_at' => 'immutable_datetime',
            'resolved_at' => 'immutable_datetime',
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

    public function task(): BelongsTo
    {
        return $this->belongsTo(AtlasTask::class, 'task_id');
    }
}
