<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AtlasEngineeringEvidence extends Model
{
    use HasUuids;

    protected $table = 'atlas_engineering_evidence';

    protected $fillable = [
        'task_id',
        'project_id',
        'project_step_id',
        'trace_id',
        'evidence_type',
        'target_id',
        'status',
        'confidence',
        'summary',
        'command',
        'artifact_url',
        'output_excerpt',
        'files',
        'metadata',
        'source',
        'recorded_at',
    ];

    protected function casts(): array
    {
        return [
            'confidence' => 'float',
            'files' => 'array',
            'metadata' => 'array',
            'recorded_at' => 'immutable_datetime',
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
        ];
    }

    public function task(): BelongsTo
    {
        return $this->belongsTo(AtlasTask::class, 'task_id');
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(AtlasProject::class, 'project_id');
    }

    public function projectStep(): BelongsTo
    {
        return $this->belongsTo(AtlasProjectStep::class, 'project_step_id');
    }
}
