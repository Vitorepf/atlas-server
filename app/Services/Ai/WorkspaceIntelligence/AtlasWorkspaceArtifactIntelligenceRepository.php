<?php

declare(strict_types=1);

namespace App\Services\Ai\WorkspaceIntelligence;

use App\Models\AtlasWorkspaceArtifactGraphSnapshot;
use App\Models\AtlasWorkspaceArtifactLakeEntry;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Schema;

final class AtlasWorkspaceArtifactIntelligenceRepository
{
    public function persist(array $report): ?AtlasWorkspaceArtifactGraphSnapshot
    {
        if (
            ! Schema::hasTable('atlas_workspace_artifact_lake_entries')
            || ! Schema::hasTable('atlas_workspace_artifact_graph_snapshots')
        ) {
            return null;
        }

        $runtimeHash = (string) ($report['runtime_hash'] ?? '');
        if ($runtimeHash === '') {
            return null;
        }

        $awair = (array) ($report['awair'] ?? []);
        $workspaceId = (string) data_get($awair, 'workspace_id', data_get($report, 'workspace.workspace_id', 'unknown'));
        $capturedAt = Carbon::now();
        $nodesByHash = collect((array) data_get($awair, 'artifact_graph.nodes', []))
            ->filter(static fn (mixed $node): bool => is_array($node))
            ->keyBy(fn (array $node): string => (string) ($node['id'] ?? ''));
        $qualityByHash = collect((array) data_get($awair, 'artifact_quality_governor.quality', []))
            ->filter(static fn (mixed $quality): bool => is_array($quality))
            ->keyBy(fn (array $quality): string => (string) ($quality['artifact_hash'] ?? ''));

        AtlasWorkspaceArtifactLakeEntry::query()
            ->where('runtime_hash', $runtimeHash)
            ->delete();

        foreach ((array) data_get($report, 'awaf.artifacts', []) as $artifact) {
            if (! is_array($artifact)) {
                continue;
            }
            $artifactHash = (string) ($artifact['artifact_hash'] ?? '');
            if ($artifactHash === '') {
                continue;
            }

            $node = (array) ($nodesByHash->get($artifactHash) ?? []);
            $quality = (array) ($qualityByHash->get($artifactHash) ?? []);

            AtlasWorkspaceArtifactLakeEntry::query()->create([
                'workspace_id' => (string) ($artifact['workspace_id'] ?? $workspaceId),
                'runtime_hash' => $runtimeHash,
                'artifact_hash' => $artifactHash,
                'artifact_type' => (string) ($artifact['artifact_type'] ?? 'unknown'),
                'status' => (string) ($artifact['status'] ?? 'unknown'),
                'consumer' => ($node['consumer'] ?? null) !== null ? (string) $node['consumer'] : null,
                'source_hashes' => (array) ($artifact['source_hashes'] ?? []),
                'body' => (array) ($artifact['body'] ?? []),
                'quality_score' => (float) ($quality['quality_score'] ?? 0.0),
                'captured_at' => $capturedAt,
            ]);
        }

        return AtlasWorkspaceArtifactGraphSnapshot::query()->updateOrCreate(
            ['runtime_hash' => $runtimeHash],
            [
                'workspace_id' => $workspaceId,
                'artifact_intelligence_hash' => (string) ($awair['artifact_intelligence_hash'] ?? ''),
                'status' => (string) ($awair['status'] ?? 'blocked'),
                'lake_hash' => data_get($awair, 'artifact_lake.lake_hash'),
                'graph_hash' => data_get($awair, 'artifact_graph.graph_hash'),
                'artifact_count' => (int) data_get($awair, 'artifact_lake.artifact_count', 0),
                'node_count' => count((array) data_get($awair, 'artifact_graph.nodes', [])),
                'edge_count' => count((array) data_get($awair, 'artifact_graph.edges', [])),
                'replay_ready' => (bool) data_get($awair, 'artifact_replay.replay_ready', false),
                'simulation_decision' => (string) data_get($awair, 'artifact_simulation.decision', 'blocked'),
                'nodes' => (array) data_get($awair, 'artifact_graph.nodes', []),
                'edges' => (array) data_get($awair, 'artifact_graph.edges', []),
                'payload' => $awair,
                'captured_at' => $capturedAt,
            ],
        );
    }

    public function latest(string $workspaceId): ?AtlasWorkspaceArtifactGraphSnapshot
    {
        if (! Schema::hasTable('atlas_workspace_artifact_graph_snapshots')) {
            return null;
        }

        return AtlasWorkspaceArtifactGraphSnapshot::query()
            ->where('workspace_id', $workspaceId)
            ->latest('captured_at')
            ->first();
    }
}
