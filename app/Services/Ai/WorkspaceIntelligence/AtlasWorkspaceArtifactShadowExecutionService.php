<?php

declare(strict_types=1);

namespace App\Services\Ai\WorkspaceIntelligence;

final class AtlasWorkspaceArtifactShadowExecutionService
{
    public const SCHEMA_VERSION = 'atlas.workspace_artifact_shadow_execution.v1';

    public function __construct(
        private readonly AtlasWorkspaceIntelligenceListNormalizer $listNormalizer = new AtlasWorkspaceIntelligenceListNormalizer,
    ) {}

    /**
     * @return array<string,mixed>
     */
    public function evaluate(array $report, string $mode): array
    {
        $nodeCount = count((array) data_get($report, 'awair.artifact_graph.nodes', []));
        $edgeCount = count((array) data_get($report, 'awair.artifact_graph.edges', []));
        $blockers = [];

        if (data_get($report, 'awair.artifact_replay.replay_ready') !== true) {
            $blockers[] = 'artifact_replay_not_ready';
        }
        if (data_get($report, 'awair.artifact_simulation.decision') !== 'ready') {
            $blockers[] = 'artifact_simulation_blocked';
        }
        if ($nodeCount < 10) {
            $blockers[] = 'artifact_graph_missing_nodes';
        }
        if ($edgeCount < 8) {
            $blockers[] = 'artifact_graph_missing_edges';
        }
        if (data_get($report, 'awair.artifact_quality_governor.all_executable_artifacts_ready') !== true) {
            $blockers[] = 'artifact_quality_gate_failed';
        }
        if (data_get($report, 'awair.artifact_context_compiler.raw_conversation_included') !== false) {
            $blockers[] = 'raw_conversation_in_artifact_context';
        }

        $payload = [
            'schema_version' => self::SCHEMA_VERSION,
            'status' => $blockers === [] ? 'ready' : 'blocked',
            'mode' => $mode,
            'workspace_id' => data_get($report, 'workspace.workspace_id'),
            'runtime_hash' => $report['runtime_hash'] ?? null,
            'artifact_intelligence_hash' => data_get($report, 'awair.artifact_intelligence_hash'),
            'replay_ready' => data_get($report, 'awair.artifact_replay.replay_ready') === true,
            'simulation_decision' => (string) data_get($report, 'awair.artifact_simulation.decision', 'blocked'),
            'node_count' => $nodeCount,
            'edge_count' => $edgeCount,
            'quality_ready' => data_get($report, 'awair.artifact_quality_governor.all_executable_artifacts_ready') === true,
            'raw_conversation_included' => data_get($report, 'awair.artifact_context_compiler.raw_conversation_included') === true,
            'provider_called' => false,
            'workspace_mutated' => false,
            'blockers' => $this->listNormalizer->uniqueStrings($blockers),
        ];
        $payload['shadow_execution_hash'] = $this->hashPayload($payload);

        return $payload;
    }

    /**
     * @param  array<string,mixed>  $payload
     */
    private function hashPayload(array $payload): string
    {
        unset($payload['shadow_execution_hash']);

        return hash('sha256', json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
    }
}
