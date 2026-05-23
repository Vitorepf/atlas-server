<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

final class AtlasWorkspaceArtifactLakeEntry extends Model
{
    use HasUuids;

    protected $table = 'atlas_workspace_artifact_lake_entries';

    protected $fillable = [
        'workspace_id',
        'runtime_hash',
        'artifact_hash',
        'artifact_type',
        'status',
        'consumer',
        'source_hashes',
        'body',
        'quality_score',
        'captured_at',
    ];

    protected function casts(): array
    {
        return [
            'source_hashes' => 'array',
            'body' => 'array',
            'quality_score' => 'float',
            'captured_at' => 'immutable_datetime',
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
        ];
    }
}
