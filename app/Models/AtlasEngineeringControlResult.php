<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AtlasEngineeringControlResult extends Model
{
    use HasUuids;

    protected $fillable = [
        'engineering_run_id',
        'attempt_id',
        'control_id',
        'control_slug',
        'control_definition_hash',
        'control_version',
        'status',
        'signal_summary',
        'output_excerpt',
        'duration_ms',
        'artifact_path',
        'metadata',
    ];

    protected function casts(): array
    {
        return [
            'control_version' => 'integer',
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

    public function control(): BelongsTo
    {
        return $this->belongsTo(AtlasEngineeringControl::class, 'control_id');
    }
}
