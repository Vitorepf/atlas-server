<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Support\StringOrNull;
use App\Services\Ai\WorkspaceIntelligence\AtlasWorkspaceArtifactAemorBridgeService;
use App\Services\Ai\WorkspaceIntelligence\AtlasWorkspaceArtifactIntelligenceRepository;
use App\Services\Ai\WorkspaceIntelligence\AtlasWorkspaceArtifactShadowExecutionService;
use App\Services\Ai\WorkspaceIntelligence\AtlasWorkspaceArtifactWorkroomService;
use App\Services\Ai\WorkspaceIntelligence\AtlasWorkspaceIntelligenceRuntimeService;
use Illuminate\Console\Command;

final class AtlasWorkspaceArtifactsCommand extends Command
{
    protected $signature = 'atlas:workspace-artifacts
        {action=certify : certify|graph|replay|simulate|shadow|workroom|route|diff|replay-point|timeline|outcome|retire|retirement-queue|retirement-apply}
        {--workspace= : Workspace slug, defaults to configured Atlas workspace}
        {--task= : Task used to generate task/context artifacts}
        {--artifact= : Artifact hash or type for AWAOL actions}
        {--against= : Artifact hash or graph reference used by diff action}
        {--reason= : Retirement reason for retire action}
        {--proposal= : Retirement proposal id/hash for retirement-apply}
        {--replacement-artifact= : Replacement artifact hash/type required for critical retirement apply}
        {--outcome-status= : Outcome status for outcome action}
        {--summary= : Human-safe outcome summary}
        {--mode=dev : Shadow execution mode: conversation|dev|forge|patch|test|index-code}
        {--latest : Replay latest persisted artifact graph for this workspace}
        {--persist : Persist the generated artifact graph}
        {--json : Print machine-readable JSON}
        {--strict : Exit non-zero unless status === ready}';

    protected $description = 'Exposes AWAIR artifact graph, replay, simulation, shadow execution and AWAOL workrooms without returning the full AWIS envelope.';

    public function handle(
        AtlasWorkspaceIntelligenceRuntimeService $runtime,
        AtlasWorkspaceArtifactIntelligenceRepository $repository,
        AtlasWorkspaceArtifactAemorBridgeService $aemorBridge,
        AtlasWorkspaceArtifactShadowExecutionService $shadowExecution,
        AtlasWorkspaceArtifactWorkroomService $workroom,
    ): int {
        $action = (string) $this->argument('action');
        $workspace = $this->stringOption('workspace');
        $task = $this->stringOption('task') ?? '';
        $report = $runtime->certify(workspace: $workspace, task: $task);
        $awair = (array) ($report['awair'] ?? []);

        if ((bool) $this->option('latest')) {
            $workspaceId = (string) data_get($report, 'workspace.workspace_id', $workspace ?? '');
            $latest = $workspaceId !== '' ? $repository->latest($workspaceId) : null;
            if ($latest !== null) {
                $awair = $this->staleArtifactGraphPayload((array) $latest->payload, $this->currentWorkspaceHash($report))
                    ?? (array) $latest->payload;
            } else {
                $awair = [
                    'schema_version' => 'atlas.workspace_artifact_intelligence.v1',
                    'status' => 'blocked',
                    'error' => 'latest_artifact_graph_not_found',
                    'workspace_id' => $workspaceId !== '' ? $workspaceId : null,
                ];
            }
        } elseif ((bool) $this->option('persist')) {
            $persisted = $repository->persist($report);
            $awair['persisted_artifact_graph_id'] = $persisted?->id;
        }

        $payload = match ($action) {
            'certify' => $awair,
            'graph' => [
                'schema_version' => 'atlas.workspace_artifact_graph_projection.v1',
                'status' => (string) ($awair['status'] ?? 'blocked'),
                'workspace_id' => $awair['workspace_id'] ?? null,
                'stale' => $awair['stale'] ?? false,
                'reason' => $awair['reason'] ?? null,
                'blockers' => $awair['blockers'] ?? [],
                'snapshot_workspace_hash' => $awair['snapshot_workspace_hash'] ?? null,
                'current_workspace_hash' => $awair['current_workspace_hash'] ?? null,
                'artifact_intelligence_hash' => $awair['artifact_intelligence_hash'] ?? null,
                'artifact_graph' => $awair['artifact_graph'] ?? [],
            ],
            'replay' => [
                'schema_version' => 'atlas.workspace_artifact_replay_projection.v1',
                'status' => data_get($awair, 'artifact_replay.replay_ready') === true ? 'ready' : 'blocked',
                'workspace_id' => $awair['workspace_id'] ?? null,
                'stale' => $awair['stale'] ?? false,
                'reason' => $awair['reason'] ?? null,
                'blockers' => $awair['blockers'] ?? [],
                'snapshot_workspace_hash' => $awair['snapshot_workspace_hash'] ?? null,
                'current_workspace_hash' => $awair['current_workspace_hash'] ?? null,
                'artifact_intelligence_hash' => $awair['artifact_intelligence_hash'] ?? null,
                'artifact_replay' => $awair['artifact_replay'] ?? [],
            ],
            'simulate' => [
                'schema_version' => 'atlas.workspace_artifact_simulation_projection.v1',
                'status' => data_get($awair, 'artifact_simulation.decision') === 'ready' ? 'ready' : 'blocked',
                'workspace_id' => $awair['workspace_id'] ?? null,
                'stale' => $awair['stale'] ?? false,
                'reason' => $awair['reason'] ?? null,
                'blockers' => $awair['blockers'] ?? [],
                'snapshot_workspace_hash' => $awair['snapshot_workspace_hash'] ?? null,
                'current_workspace_hash' => $awair['current_workspace_hash'] ?? null,
                'artifact_intelligence_hash' => $awair['artifact_intelligence_hash'] ?? null,
                'artifact_simulation' => $awair['artifact_simulation'] ?? [],
            ],
            'shadow' => $shadowExecution->evaluate(['workspace' => $report['workspace'] ?? [], 'awair' => $awair], $this->stringOption('mode') ?? 'dev'),
            'workroom' => $workroom->build($report, $awair, $this->stringOption('artifact')),
            'route' => $this->routeProjection($workroom->build($report, $awair, $this->stringOption('artifact')), $repository),
            'diff' => $this->diffProjection($workroom->build($report, $awair, $this->stringOption('artifact')), $this->stringOption('against')),
            'replay-point' => $this->replayPointProjection($workroom->build($report, $awair, $this->stringOption('artifact'))),
            'timeline' => $repository->timelineProviderSafe((string) data_get($report, 'workspace.workspace_id', $workspace ?? ''), $this->stringOption('artifact')),
            'outcome' => $this->outcomeProjection($workroom->build($report, $awair, $this->stringOption('artifact')), $repository, $aemorBridge),
            'retire' => $repository->persistRetirementProposal($workroom->retirementProposal($workroom->build($report, $awair, $this->stringOption('artifact')), $this->stringOption('reason'))),
            'retirement-queue' => $repository->retirementQueueProviderSafe((string) data_get($report, 'workspace.workspace_id', $workspace ?? ''), $this->stringOption('artifact')),
            'retirement-apply' => $repository->applyRetirementProposal(
                (string) data_get($report, 'workspace.workspace_id', $workspace ?? ''),
                $this->stringOption('proposal'),
                $this->stringOption('artifact'),
                $this->stringOption('replacement-artifact'),
            ),
            default => [
                'schema_version' => 'atlas.workspace_artifact_intelligence.v1',
                'status' => 'blocked',
                'error' => 'unknown_action',
                'allowed_actions' => ['certify', 'graph', 'replay', 'simulate', 'shadow', 'workroom', 'route', 'diff', 'replay-point', 'timeline', 'outcome', 'retire', 'retirement-queue', 'retirement-apply'],
            ],
        };

        $this->outputPayload($payload);

        if (($payload['error'] ?? null) === 'unknown_action') {
            return self::FAILURE;
        }

        if ((bool) $this->option('strict') && ($payload['status'] ?? null) !== 'ready') {
            return self::FAILURE;
        }

        return self::SUCCESS;
    }

    private function stringOption(string $name): ?string
    {
        $value = $this->option($name);

        return StringOrNull::trimmed($value);
    }

    /**
     * @param  array<string,mixed>  $report
     */
    private function currentWorkspaceHash(array $report): ?string
    {
        $hash = data_get($report, 'workspace.workspace_hash');

        return is_string($hash) && $hash !== '' ? $hash : null;
    }

    /**
     * @param  array<string,mixed>  $payload
     * @return array<string,mixed>|null
     */
    private function staleArtifactGraphPayload(array $payload, ?string $currentWorkspaceHash): ?array
    {
        $snapshotWorkspaceHash = data_get($payload, 'workspace_hash');
        if (! is_string($snapshotWorkspaceHash) || $snapshotWorkspaceHash === '') {
            $reason = 'snapshot_missing_workspace_hash';
        } elseif ($currentWorkspaceHash === null) {
            $reason = 'current_workspace_hash_unavailable';
        } elseif (! hash_equals($snapshotWorkspaceHash, $currentWorkspaceHash)) {
            $reason = 'workspace_hash_changed';
        } else {
            return null;
        }

        return [
            'schema_version' => 'atlas.awair.artifact_graph_stale.v1',
            'status' => 'blocked',
            'family' => 'AWAIR',
            'stale' => true,
            'reason' => $reason,
            'workspace_id' => data_get($payload, 'workspace_id'),
            'snapshot_workspace_hash' => $snapshotWorkspaceHash,
            'current_workspace_hash' => $currentWorkspaceHash,
            'artifact_intelligence_hash' => data_get($payload, 'artifact_intelligence_hash'),
            'artifact_graph_hash' => data_get($payload, 'artifact_graph.graph_hash'),
            'blockers' => ['workspace_artifact_graph_stale'],
        ];
    }

    /**
     * @param  array<string,mixed>  $workroom
     * @return array<string,mixed>
     */
    private function routeProjection(array $workroom, AtlasWorkspaceArtifactIntelligenceRepository $repository): array
    {
        $route = (array) data_get($workroom, 'routes.0', []);
        $timelineEvent = $repository->recordTimelineEvent($workroom, 'route_decision', (string) ($route['decision'] ?? 'blocked'), [
            'route' => $route,
            'artifact_hash' => $workroom['artifact_hash'] ?? null,
            'artifact_type' => $workroom['artifact_type'] ?? null,
        ]);

        return [
            'schema_version' => 'atlas.workspace_artifact_route_projection.v1',
            'status' => ($workroom['status'] ?? null) === 'ready' && ($timelineEvent['status'] ?? null) === 'ready' ? 'ready' : 'blocked',
            'workspace_id' => $workroom['workspace_id'] ?? null,
            'artifact_hash' => $workroom['artifact_hash'] ?? null,
            'artifact_type' => $workroom['artifact_type'] ?? null,
            'route' => $route,
            'agent_packet' => $workroom['agent_packet'] ?? null,
            'timeline_event' => $timelineEvent,
            'source_policy' => $workroom['source_policy'] ?? [],
            'claim_policy' => $workroom['claim_policy'] ?? [],
            'projection_hash' => $this->projectionHash('route', $workroom),
        ];
    }

    /**
     * @param  array<string,mixed>  $workroom
     * @return array<string,mixed>
     */
    private function diffProjection(array $workroom, ?string $against): array
    {
        $diffs = array_values(array_filter((array) ($workroom['diffs'] ?? []), 'is_array'));
        if ($against !== null && $diffs !== []) {
            $diffs[0]['against'] = $against;
            $diffs[0]['state'] = hash_equals((string) ($workroom['artifact_hash'] ?? ''), $against) ? 'unchanged' : 'changed_or_external_reference';
            $diffs[0]['human_label'] = $diffs[0]['state'] === 'unchanged'
                ? 'Artifact atual tem o mesmo hash informado.'
                : 'Artifact atual difere da referencia informada ou a referencia nao esta no grafo atual.';
        }

        return [
            'schema_version' => 'atlas.workspace_artifact_diff_projection.v1',
            'status' => (string) ($workroom['status'] ?? 'blocked'),
            'workspace_id' => $workroom['workspace_id'] ?? null,
            'artifact_hash' => $workroom['artifact_hash'] ?? null,
            'artifact_type' => $workroom['artifact_type'] ?? null,
            'against' => $against ?? 'current_runtime_artifact_graph',
            'diffs' => $diffs,
            'source_policy' => $workroom['source_policy'] ?? [],
            'claim_policy' => $workroom['claim_policy'] ?? [],
            'projection_hash' => $this->projectionHash('diff', [$workroom, $against]),
        ];
    }

    /**
     * @param  array<string,mixed>  $workroom
     * @return array<string,mixed>
     */
    private function replayPointProjection(array $workroom): array
    {
        return [
            'schema_version' => 'atlas.workspace_artifact_replay_point_projection.v1',
            'status' => (string) data_get($workroom, 'replay_point.status', $workroom['status'] ?? 'blocked'),
            'workspace_id' => $workroom['workspace_id'] ?? null,
            'artifact_hash' => $workroom['artifact_hash'] ?? null,
            'artifact_type' => $workroom['artifact_type'] ?? null,
            'replay_point' => $workroom['replay_point'] ?? [],
            'timeline' => $workroom['timeline'] ?? [],
            'source_policy' => $workroom['source_policy'] ?? [],
            'claim_policy' => $workroom['claim_policy'] ?? [],
            'projection_hash' => $this->projectionHash('replay-point', $workroom),
        ];
    }

    /**
     * @param  array<string,mixed>  $workroom
     * @return array<string,mixed>
     */
    private function outcomeProjection(
        array $workroom,
        AtlasWorkspaceArtifactIntelligenceRepository $repository,
        AtlasWorkspaceArtifactAemorBridgeService $aemorBridge,
    ): array {
        $outcomeStatus = $this->stringOption('outcome-status') ?? 'observed';
        $summary = $this->stringOption('summary') ?? 'Outcome registrado sem corpo bruto.';
        $event = $repository->recordTimelineEvent($workroom, 'outcome_recorded', $outcomeStatus, [
            'summary' => $summary,
            'artifact_hash' => $workroom['artifact_hash'] ?? null,
            'artifact_type' => $workroom['artifact_type'] ?? null,
            'route' => data_get($workroom, 'routes.0', []),
        ]);
        $aemor = $aemorBridge->recordArtifactOutcome($workroom, $event, $outcomeStatus, $summary);

        return [
            'schema_version' => 'atlas.workspace_artifact_outcome_projection.v1',
            'status' => ($event['status'] ?? null) === 'ready' && ($aemor['status'] ?? null) === 'ready' ? 'ready' : 'blocked',
            'workspace_id' => $workroom['workspace_id'] ?? null,
            'artifact_hash' => $workroom['artifact_hash'] ?? null,
            'artifact_type' => $workroom['artifact_type'] ?? null,
            'outcome_status' => $outcomeStatus,
            'summary' => $summary,
            'timeline_event' => $event,
            'aemor_bridge' => $aemor,
            'source_policy' => $workroom['source_policy'] ?? [],
            'claim_policy' => $workroom['claim_policy'] ?? [],
            'projection_hash' => $this->projectionHash('outcome', [$workroom, $outcomeStatus, $summary]),
        ];
    }

    private function projectionHash(string $type, mixed $payload): string
    {
        return hash('sha256', json_encode([
            'type' => $type,
            'payload' => $payload,
        ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
    }

    /**
     * @param  array<string,mixed>  $payload
     */
    private function outputPayload(array $payload): void
    {
        if ((bool) $this->option('json')) {
            $this->line(json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));

            return;
        }

        $this->components->info((string) ($payload['schema_version'] ?? 'atlas.workspace_artifact_intelligence.v1'));
        $this->components->twoColumnDetail('Status', (string) ($payload['status'] ?? 'unknown'));
        $this->components->twoColumnDetail('Workspace', (string) ($payload['workspace_id'] ?? '-'));
    }
}
