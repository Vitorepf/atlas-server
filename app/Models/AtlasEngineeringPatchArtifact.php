<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AtlasEngineeringPatchArtifact extends Model
{
    use HasUuids;

    protected $fillable = [
        'engineering_run_id',
        'attempt_id',
        'base_ref',
        'head_ref',
        'diff_hash',
        'diff_excerpt',
        'diff_path',
        'changed_files_json',
        'created_files_json',
        'deleted_files_json',
        'risk_flags_json',
        'metadata',
    ];

    protected function casts(): array
    {
        return [
            'changed_files_json' => 'array',
            'created_files_json' => 'array',
            'deleted_files_json' => 'array',
            'risk_flags_json' => 'array',
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
}
