<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

final class AtlasWorkspaceArtifactGraphSnapshot extends Model
{
    use HasUuids;

    protected $table = 'atlas_workspace_artifact_graph_snapshots';

    protected $fillable = [
        'workspace_id',
        'runtime_hash',
        'artifact_intelligence_hash',
        'status',
        'lake_hash',
        'graph_hash',
        'artifact_count',
        'node_count',
        'edge_count',
        'replay_ready',
        'simulation_decision',
        'nodes',
        'edges',
        'payload',
        'captured_at',
    ];

    protected function casts(): array
    {
        return [
            'artifact_count' => 'integer',
            'node_count' => 'integer',
            'edge_count' => 'integer',
            'replay_ready' => 'boolean',
            'nodes' => 'array',
            'edges' => 'array',
            'payload' => 'array',
            'captured_at' => 'immutable_datetime',
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
        ];
    }
}
