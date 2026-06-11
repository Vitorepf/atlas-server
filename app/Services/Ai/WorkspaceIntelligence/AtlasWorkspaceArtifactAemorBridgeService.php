<?php

declare(strict_types=1);

namespace App\Services\Ai\WorkspaceIntelligence;

use App\Services\Ai\Aemor\AtlasAemorRuntimeService;
use App\Services\Ai\Support\AiValueNormalizer;
use App\Services\Ai\Support\DatabaseTableAvailability;

final class AtlasWorkspaceArtifactAemorBridgeService
{
    public function __construct(
        private readonly AtlasAemorRuntimeService $aemor,
    ) {}

    /**
     * @param  array<string,mixed>  $workroom
     * @param  array<string,mixed>  $timelineEvent
     * @return array<string,mixed>
     */
    public function recordArtifactOutcome(array $workroom, array $timelineEvent, string $outcomeStatus, string $summary): array
    {
        if (! $this->aemorTablesReady()) {
            return [
                'schema_version' => 'atlas.workspace_artifact_aemor_bridge.v1',
                'status' => 'blocked',
                'blockers' => ['aemor_tables_missing'],
                'writes' => false,
                'claim_policy' => $this->claimPolicy(),
            ];
        }

        if (($timelineEvent['status'] ?? null) !== 'ready') {
            return [
                'schema_version' => 'atlas.workspace_artifact_aemor_bridge.v1',
                'status' => 'blocked',
                'blockers' => ['timeline_event_not_ready'],
                'writes' => false,
                'claim_policy' => $this->claimPolicy(),
            ];
        }

        $workspaceId = AiValueNormalizer::trimmedScalarStringOrNull($workroom['workspace_id'] ?? null) ?? 'unknown';
        $artifactHash = AiValueNormalizer::trimmedScalarStringOrNull($workroom['artifact_hash'] ?? null) ?? 'unknown';
        $artifactType = AiValueNormalizer::trimmedScalarStringOrNull($workroom['artifact_type'] ?? null) ?? 'unknown';
        $eventHash = AiValueNormalizer::trimmedScalarStringOrNull($timelineEvent['event_hash'] ?? null) ?? hash('sha256', $artifactHash.$outcomeStatus.$summary);
        $evidenceRefs = [
            'awis_artifact:'.$artifactHash,
            'awis_artifact_timeline:'.$eventHash,
        ];

        $episode = $this->aemor->openEpisode([
            'objective' => sprintf('AWIS artifact outcome: %s/%s', $workspaceId, $artifactType),
            'workspace' => $workspaceId,
            'scope_type' => 'workspace_artifact',
            'scope_id' => $artifactHash,
            'surface_id' => 'atlas_workspace_artifact_operating_layer',
            'domain' => 'programming',
            'flow_id' => 'awis_artifact_outcome',
            'persistent_context_hash' => strlen($artifactHash) === 64 ? $artifactHash : null,
            'evidence_refs' => $evidenceRefs,
            'source' => 'awis_artifact_outcome_bridge',
        ]);

        if (! is_string($episode['episode_id'] ?? null)) {
            return [
                'schema_version' => 'atlas.workspace_artifact_aemor_bridge.v1',
                'status' => 'blocked',
                'blockers' => ['aemor_episode_not_persisted'],
                'writes' => false,
                'claim_policy' => $this->claimPolicy(),
            ];
        }

        $event = $this->aemor->observe([
            'episode_id' => $episode['episode_id'],
            'event_type' => 'workspace_artifact_outcome_recorded',
            'stage' => 'awaol_outcome',
            'status' => $this->normalizedStatus($outcomeStatus),
            'payload' => [
                'workspace_id' => $workspaceId,
                'artifact_hash' => $artifactHash,
                'artifact_type' => $artifactType,
                'timeline_event_hash' => $eventHash,
                'outcome_status' => $outcomeStatus,
                'summary' => $summary,
                'route_target' => data_get($workroom, 'routes.0.target'),
                'source_policy' => [
                    'raw_conversation_included' => false,
                    'artifact_body_included' => false,
                ],
            ],
            'evidence_refs' => $evidenceRefs,
        ]);

        $outcome = $this->aemor->closeOutcome([
            'episode_id' => $episode['episode_id'],
            'status' => $this->normalizedStatus($outcomeStatus),
            'outcome_type' => $this->outcomeType($outcomeStatus),
            'summary' => $summary,
            'metrics' => [
                'workspace_artifact_outcome' => true,
                'timeline_event_recorded' => true,
                'artifact_body_included' => false,
                'raw_conversation_included' => false,
            ],
            'evidence_refs' => $evidenceRefs,
            'patch_outcome' => [
                'artifact_hash' => $artifactHash,
                'artifact_type' => $artifactType,
                'timeline_event_hash' => $eventHash,
            ],
        ]);

        $distill = $this->aemor->distill([
            'episode_id' => $episode['episode_id'],
            'outcome_id' => $outcome['outcome_id'] ?? null,
            'claim' => 'Use prior AWIS artifact outcome before reusing this artifact route.',
            'signal_type' => $this->outcomeType($outcomeStatus) === 'success' ? 'execution_strategy' : 'failure_pattern',
            'evidence_refs' => $evidenceRefs,
        ]);

        return [
            'schema_version' => 'atlas.workspace_artifact_aemor_bridge.v1',
            'status' => ($outcome['status'] ?? null) === 'blocked' ? 'blocked' : 'ready',
            'episode_id' => $episode['episode_id'],
            'episode_hash' => $episode['episode_hash'] ?? null,
            'event_id' => $event['event_id'] ?? null,
            'event_hash' => $event['event_hash'] ?? null,
            'outcome_id' => $outcome['outcome_id'] ?? null,
            'outcome_hash' => $outcome['outcome_hash'] ?? null,
            'memory_candidate_id' => $distill['memory_candidate_id'] ?? null,
            'learning_signal_id' => $distill['learning_signal_id'] ?? null,
            'evidence_refs' => $evidenceRefs,
            'writes' => true,
            'claim_policy' => $this->claimPolicy(),
        ];
    }

    private function aemorTablesReady(): bool
    {
        return DatabaseTableAvailability::all([
            'atlas_aemor_execution_episodes',
            'atlas_aemor_execution_events',
            'atlas_aemor_outcomes',
            'atlas_aemor_learning_signals',
            'atlas_aemor_memory_candidates',
        ]);
    }

    private function outcomeType(string $status): string
    {
        return in_array($this->normalizedStatus($status), ['succeeded'], true) ? 'success' : 'failure';
    }

    private function normalizedStatus(string $status): string
    {
        $value = strtolower(trim($status));

        return match ($value) {
            'passed', 'pass', 'success', 'successful', 'succeeded', 'green', 'ready' => 'succeeded',
            'blocked', 'failed', 'failure', 'error', 'red' => 'failed',
            default => 'observed',
        };
    }

    /**
     * @return array<string,bool>
     */
    private function claimPolicy(): array
    {
        return [
            'provider_calls_made' => false,
            'spends_tokens' => false,
            'raw_conversation_returned' => false,
            'artifact_body_returned' => false,
            'auto_promotes_memory' => false,
        ];
    }

}
