<?php

declare(strict_types=1);

namespace App\Services\Engineering\UniversalRealityCartography;

use App\Services\Ai\WorkspaceIntelligence\AtlasWorkspaceArtifactIntelligenceRepository;
use App\Services\Ai\WorkspaceIntelligence\AtlasWorkspaceArtifactWorkroomService;
use App\Services\Ai\WorkspaceIntelligence\AtlasWorkspaceIntelligenceRuntimeService;
use App\Services\Ai\WorkspaceIntelligence\AtlasWorkspaceRuntimeProjectionRepository;
use Illuminate\Support\Arr;

/**
 * Workspace-scope family extracted VERBATIM from AtlasUniversalRealityCartographyService
 * (GOD-DEBULK split). Builds the workspace_scope projection: readiness, runtime-projection
 * replay, artifact graph/lake replay, and artifact workroom state. Behavior-preserving —
 * method bodies are identical to the façade original; only the entry point was renamed to
 * build().
 */
final class CartographyWorkspaceScopeSection
{
    public function __construct(
        private readonly AtlasWorkspaceIntelligenceRuntimeService $workspaceIntelligence,
        private readonly AtlasWorkspaceArtifactWorkroomService $artifactWorkroom,
        private readonly AtlasWorkspaceRuntimeProjectionRepository $runtimeProjections,
        private readonly AtlasWorkspaceArtifactIntelligenceRepository $artifactIntelligence,
    ) {}

    /**
     * @return array<string,mixed>
     */
    public function build(?string $workspace): array
    {
        $report = $this->workspaceIntelligence->certify(workspace: $workspace ?? 'atlas');
        $workspaceId = data_get($report, 'workspace.workspace_id');
        $workspaceHash = data_get($report, 'workspace.workspace_hash');
        $projectionReplay = $this->runtimeProjectionReplayState(
            is_string($workspaceId) ? $workspaceId : null,
            is_string($workspaceHash) ? $workspaceHash : null,
        );
        $artifactGraphReplay = $this->artifactGraphReplayState(
            is_string($workspaceId) ? $workspaceId : null,
            is_string($workspaceHash) ? $workspaceHash : null,
        );
        $artifactLakeReplay = $this->artifactLakeReplayState(
            is_string($workspaceId) ? $workspaceId : null,
        );
        $artifactWorkroom = $this->artifactWorkroomState($report);

        return [
            'schema_version' => 'atlas.universal_reality_cartography.workspace_scope.v1',
            'status' => (string) data_get($report, 'workspace.readiness_status', 'blocked'),
            'requested_workspace' => $workspace,
            'active_workspace_id' => $workspaceId,
            'active_workspace_name' => data_get($report, 'workspace.workspace_name'),
            'workspace_hash' => $workspaceHash,
            'cartography_scope' => data_get($report, 'workspace.cartography_scope'),
            'source_path' => 'docs/engineering-knowledge-base/atlas-workspace-intelligence-system.md',
            'runtime_hash' => data_get($report, 'runtime_hash'),
            'blockers' => data_get($report, 'workspace.blockers', []),
            'runtime_projection_replay' => $projectionReplay,
            'artifact_graph_replay' => $artifactGraphReplay,
            'artifact_lake_replay' => $artifactLakeReplay,
            'artifact_workroom' => $artifactWorkroom,
            'awis_certified' => ($report['status'] ?? null) === 'ready',
        ];
    }

    /**
     * @param  array<string,mixed>  $report
     * @return array<string,mixed>
     */
    private function artifactWorkroomState(array $report): array
    {
        $workroom = $this->artifactWorkroom->build($report, null, 'task_packet');
        if (($workroom['status'] ?? null) !== 'ready') {
            return [
                'schema_version' => 'atlas.universal_reality_cartography.artifact_workroom.v1',
                'status' => 'blocked',
                'reason' => (string) data_get($workroom, 'blockers.0', 'artifact_workroom_unavailable'),
                'workroom_count' => 0,
                'source_policy' => (array) ($workroom['source_policy'] ?? []),
                'claim_policy' => (array) ($workroom['claim_policy'] ?? []),
            ];
        }

        return [
            'schema_version' => 'atlas.universal_reality_cartography.artifact_workroom.v1',
            'status' => 'ready',
            'workroom_count' => 1,
            'artifact_type' => $workroom['artifact_type'] ?? null,
            'artifact_hash' => $workroom['artifact_hash'] ?? null,
            'quality_score' => $workroom['quality_score'] ?? null,
            'route_target' => data_get($workroom, 'routes.0.target'),
            'mode_30s' => data_get($workroom, 'human_packet.mode_30s'),
            'replay_status' => data_get($workroom, 'replay_point.status'),
            'workroom_hash' => $workroom['workroom_hash'] ?? null,
            'inspect_endpoint' => '/atlas-code/workspace-intelligence/artifact-workroom?workspace='.data_get($workroom, 'workspace_id').'&artifact={artifact}',
            'source_policy' => (array) ($workroom['source_policy'] ?? []),
            'claim_policy' => (array) ($workroom['claim_policy'] ?? []),
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function runtimeProjectionReplayState(?string $workspaceId, ?string $currentWorkspaceHash): array
    {
        $families = ['AWTR', 'AWCO', 'AWEF'];
        $items = [];
        $stale = [];
        $missing = [];

        foreach ($families as $family) {
            $snapshot = $workspaceId === null ? null : $this->runtimeProjections->latest($workspaceId, $family);
            if ($snapshot === null) {
                $missing[] = $family;
                $items[] = [
                    'family' => $family,
                    'status' => 'missing',
                    'stale' => false,
                    'reason' => 'projection_not_persisted',
                ];

                continue;
            }

            $snapshotWorkspaceHash = data_get($snapshot->payload, 'awis_projection.workspace_hash');
            $reason = null;
            if (! is_string($snapshotWorkspaceHash) || $snapshotWorkspaceHash === '') {
                $reason = 'projection_missing_workspace_hash';
            } elseif (! is_string($currentWorkspaceHash) || $currentWorkspaceHash === '') {
                $reason = 'current_workspace_hash_unavailable';
            } elseif (! hash_equals($snapshotWorkspaceHash, $currentWorkspaceHash)) {
                $reason = 'workspace_hash_changed';
            }

            if ($reason !== null) {
                $stale[] = $family;
            }

            $items[] = [
                'family' => $family,
                'status' => (string) $snapshot->status,
                'stale' => $reason !== null,
                'reason' => $reason,
                'snapshot_id' => (string) $snapshot->id,
                'runtime_hash' => $snapshot->runtime_hash,
                'projection_hash' => $snapshot->projection_hash,
                'captured_at' => $snapshot->captured_at?->toJSON(),
            ];
        }

        return [
            'schema_version' => 'atlas.universal_reality_cartography.runtime_projection_replay.v1',
            'status' => $stale === [] ? 'ready' : 'blocked',
            'stale_count' => count($stale),
            'missing_count' => count($missing),
            'stale_families' => $stale,
            'missing_families' => $missing,
            'items' => $items,
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function artifactGraphReplayState(?string $workspaceId, ?string $currentWorkspaceHash): array
    {
        $snapshot = $workspaceId === null ? null : $this->artifactIntelligence->latest($workspaceId);
        if ($snapshot === null) {
            return [
                'schema_version' => 'atlas.universal_reality_cartography.artifact_graph_replay.v1',
                'status' => 'ready',
                'stale' => false,
                'reason' => 'artifact_graph_not_persisted',
                'snapshot_id' => null,
            ];
        }

        $snapshotWorkspaceHash = data_get($snapshot->payload, 'workspace_hash');
        $reason = null;
        if (! is_string($snapshotWorkspaceHash) || $snapshotWorkspaceHash === '') {
            $reason = 'artifact_graph_missing_workspace_hash';
        } elseif (! is_string($currentWorkspaceHash) || $currentWorkspaceHash === '') {
            $reason = 'current_workspace_hash_unavailable';
        } elseif (! hash_equals($snapshotWorkspaceHash, $currentWorkspaceHash)) {
            $reason = 'workspace_hash_changed';
        }

        return [
            'schema_version' => 'atlas.universal_reality_cartography.artifact_graph_replay.v1',
            'status' => $reason === null ? 'ready' : 'blocked',
            'stale' => $reason !== null,
            'reason' => $reason,
            'snapshot_id' => (string) $snapshot->id,
            'runtime_hash' => $snapshot->runtime_hash,
            'artifact_intelligence_hash' => $snapshot->artifact_intelligence_hash,
            'graph_hash' => $snapshot->graph_hash,
            'captured_at' => $snapshot->captured_at?->toJSON(),
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function artifactLakeReplayState(?string $workspaceId): array
    {
        if ($workspaceId === null || $workspaceId === '') {
            return [
                'schema_version' => 'atlas.universal_reality_cartography.artifact_lake_replay.v1',
                'status' => 'ready',
                'reason' => 'workspace_unavailable',
                'artifact_count' => 0,
                'conversation_fusion_pack_count' => 0,
                'latest_artifacts' => [],
                'source_policy' => [
                    'raw_conversation_returned' => false,
                    'full_message_content_returned' => false,
                    'workspace_scope_required' => true,
                    'hashes_are_authoritative' => true,
                ],
            ];
        }

        $index = $this->artifactIntelligence->listProviderSafe($workspaceId, null, 6);
        $blockers = (array) ($index['blockers'] ?? []);
        if (($index['status'] ?? null) === 'blocked' && in_array('artifact_lake_table_missing', $blockers, true)) {
            return [
                'schema_version' => 'atlas.universal_reality_cartography.artifact_lake_replay.v1',
                'status' => 'ready',
                'reason' => 'artifact_lake_table_missing',
                'artifact_count' => 0,
                'conversation_fusion_pack_count' => 0,
                'latest_artifacts' => [],
                'source_policy' => (array) ($index['source_policy'] ?? []),
            ];
        }

        $artifacts = collect((array) ($index['artifacts'] ?? []))
            ->filter(static fn (mixed $artifact): bool => is_array($artifact))
            ->map(static fn (array $artifact): array => Arr::only($artifact, [
                'artifact_id',
                'artifact_hash',
                'runtime_hash',
                'artifact_type',
                'status',
                'consumer',
                'source_hash_count',
                'quality_score',
                'captured_at',
            ]))
            ->values()
            ->all();

        return [
            'schema_version' => 'atlas.universal_reality_cartography.artifact_lake_replay.v1',
            'status' => ($index['status'] ?? null) === 'ready' ? 'ready' : 'blocked',
            'reason' => ($index['status'] ?? null) === 'ready' ? null : ($blockers[0] ?? 'artifact_lake_unavailable'),
            'artifact_count' => count($artifacts),
            'conversation_fusion_pack_count' => collect($artifacts)
                ->filter(static fn (array $artifact): bool => ($artifact['artifact_type'] ?? null) === 'conversation_fusion_pack')
                ->count(),
            'latest_artifacts' => $artifacts,
            'inspect_endpoint' => '/atlas-code/workspace-intelligence/artifact-lake/{artifact}?workspace='.$workspaceId,
            'source_policy' => (array) ($index['source_policy'] ?? []),
            'claim_policy' => (array) ($index['claim_policy'] ?? []),
        ];
    }
}
