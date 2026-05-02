<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class AtlasToolRun extends Model
{
    use HasUuids;

    protected $fillable = [
        'tool_definition_id',
        'tool_slug',
        'surface',
        'workspace_hash',
        'workspace',
        'run_context_type',
        'run_context_id',
        'status',
        'required',
        'failure_policy',
        'policy_decision',
        'command_hash',
        'exit_code',
        'started_at',
        'finished_at',
        'duration_ms',
        'stdout_artifact_id',
        'stderr_artifact_id',
        'summary_json',
        'normalized_result_json',
        'policy_decision_json',
        'metadata_json',
    ];

    protected function casts(): array
    {
        return [
            'required' => 'boolean',
            'exit_code' => 'integer',
            'started_at' => 'immutable_datetime',
            'finished_at' => 'immutable_datetime',
            'duration_ms' => 'integer',
            'summary_json' => 'array',
            'normalized_result_json' => 'array',
            'policy_decision_json' => 'array',
            'metadata_json' => 'array',
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
        ];
    }

    public function tool(): BelongsTo
    {
        return $this->belongsTo(AtlasToolDefinition::class, 'tool_definition_id');
    }

    public function artifacts(): HasMany
    {
        return $this->hasMany(AtlasToolArtifact::class, 'tool_run_id');
    }

    public function findings(): HasMany
    {
        return $this->hasMany(AtlasToolFinding::class, 'tool_run_id');
    }
}
