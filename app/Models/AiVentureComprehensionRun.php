<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class AiVentureComprehensionRun extends Model
{
    use HasUuids;

    protected $table = 'ai_venture_comprehension_runs';

    protected $fillable = [
        'schema_version',
        'uuid',
        'venture_id',
        'workspace_path',
        'repo_roots',
        'status',
        'capabilities_run',
        'summary',
        'files_scanned',
        'findings_total',
        'analyzed',
        'started_at',
        'completed_at',
        'run_hash',
    ];

    protected function casts(): array
    {
        return [
            'repo_roots' => 'array',
            'capabilities_run' => 'array',
            'summary' => 'array',
            'files_scanned' => 'integer',
            'findings_total' => 'integer',
            'analyzed' => 'boolean',
            'started_at' => 'immutable_datetime',
            'completed_at' => 'immutable_datetime',
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
        ];
    }

    public function findings(): HasMany
    {
        return $this->hasMany(AiVentureComprehensionFinding::class, 'run_id');
    }

    public function documentation(): HasMany
    {
        return $this->hasMany(AiVentureDocumentationArtifact::class, 'run_id');
    }
}
