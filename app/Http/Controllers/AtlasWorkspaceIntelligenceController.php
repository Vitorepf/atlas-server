<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Services\Ai\WorkspaceIntelligence\AtlasWorkspaceArtifactAemorBridgeService;
use App\Services\Ai\WorkspaceIntelligence\AtlasWorkspaceArtifactIntelligenceRepository;
use App\Services\Ai\WorkspaceIntelligence\AtlasWorkspaceArtifactWorkroomService;
use App\Services\Ai\WorkspaceIntelligence\AtlasWorkspaceHandoffPackService;
use App\Services\Ai\WorkspaceIntelligence\AtlasWorkspaceIntelligenceExecutionGateService;
use App\Services\Ai\WorkspaceIntelligence\AtlasWorkspaceIntelligenceRuntimeService;
use App\Services\Ai\WorkspaceIntelligence\AtlasWorkspaceIntelligenceSnapshotRepository;
use App\Services\Ai\WorkspaceIntelligence\AtlasWorkspaceRuntimeProjectionRepository;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class AtlasWorkspaceIntelligenceController extends Controller
{
    public function show(
        Request $request,
        AtlasWorkspaceIntelligenceRuntimeService $runtime,
        AtlasWorkspaceIntelligenceSnapshotRepository $snapshots,
        AtlasWorkspaceArtifactIntelligenceRepository $artifactIntelligence,
        AtlasWorkspaceRuntimeProjectionRepository $projections,
    ): JsonResponse {
        if ($request->boolean('latest')) {
            $latest = $snapshots->latest($this->stringQuery($request, 'workspace') ?? 'atlas');
            if ($latest !== null) {
                $currentWorkspaceHash = $this->currentWorkspaceHash($runtime, $request);
                $snapshotWorkspaceHash = is_string($latest->workspace_hash) && $latest->workspace_hash !== ''
                    ? $latest->workspace_hash
                    : null;
                if ($snapshotWorkspaceHash === null || $currentWorkspaceHash === null || ! hash_equals($snapshotWorkspaceHash, $currentWorkspaceHash)) {
                    return response()->json([
                        'schema_version' => 'atlas.awis.runtime_snapshot_stale.v1',
                        'status' => 'blocked',
                        'stale' => true,
                        'reason' => $snapshotWorkspaceHash === null ? 'snapshot_missing_workspace_hash' : 'workspace_hash_changed',
                        'workspace_id' => $latest->workspace_id,
                        'snapshot_workspace_hash' => $snapshotWorkspaceHash,
                        'current_workspace_hash' => $currentWorkspaceHash,
                        'runtime_hash' => $latest->runtime_hash,
                        'blockers' => ['workspace_intelligence_snapshot_stale'],
                    ], 409);
                }

                return response()->json($latest->payload);
            }
        }

        $report = $runtime->certify(
            workspace: $this->stringQuery($request, 'workspace'),
            task: $this->stringQuery($request, 'task') ?? '',
            conversationTexts: [],
        );
        if ($request->boolean('persist')) {
            $snapshot = $snapshots->persist($report);
            $artifactGraph = $artifactIntelligence->persist($report);
            $projectionIds = $projections->persist($report);
            $report['persisted_snapshot_id'] = $snapshot?->id;
            $report['persisted_artifact_graph_id'] = $artifactGraph?->id;
            $report['persisted_projection_ids'] = $projectionIds;
        }

        return response()->json($report, $report['status'] === 'blocked' ? 422 : 200);
    }

    public function artifacts(
        Request $request,
        AtlasWorkspaceIntelligenceRuntimeService $runtime,
        AtlasWorkspaceIntelligenceSnapshotRepository $snapshots,
    ): JsonResponse {
        if ($request->boolean('latest')) {
            $latest = $snapshots->latest($this->stringQuery($request, 'workspace') ?? 'atlas');
            if ($latest !== null) {
                return response()->json(data_get($latest->payload, 'awaf', []));
            }
        }

        $report = $runtime->certify(
            workspace: $this->stringQuery($request, 'workspace'),
            task: $this->stringQuery($request, 'task') ?? '',
            conversationTexts: [],
        );

        return response()->json($report['awaf'] ?? [], $report['status'] === 'blocked' ? 422 : 200);
    }

    public function twin(
        Request $request,
        AtlasWorkspaceIntelligenceRuntimeService $runtime,
        AtlasWorkspaceIntelligenceSnapshotRepository $snapshots,
        AtlasWorkspaceRuntimeProjectionRepository $projections,
    ): JsonResponse {
        if ($request->boolean('latest')) {
            $latestProjection = $projections->latest($this->stringQuery($request, 'workspace') ?? 'atlas', 'AWTR');
            if ($latestProjection !== null) {
                $stale = $this->staleProjectionResponse($latestProjection->payload, 'AWTR', $this->currentWorkspaceHash($runtime, $request));
                if ($stale !== null) {
                    return $stale;
                }

                return response()->json($latestProjection->payload);
            }

            $latest = $snapshots->latest($this->stringQuery($request, 'workspace') ?? 'atlas');
            if ($latest !== null) {
                return response()->json(data_get($latest->payload, 'awtr', []));
            }
        }

        $payload = $runtime->twin($this->stringQuery($request, 'workspace'));

        return response()->json($payload, $payload['status'] === 'blocked' ? 422 : 200);
    }

    public function artifactIntelligence(
        Request $request,
        AtlasWorkspaceIntelligenceRuntimeService $runtime,
        AtlasWorkspaceIntelligenceSnapshotRepository $snapshots,
        AtlasWorkspaceArtifactIntelligenceRepository $artifactIntelligence,
    ): JsonResponse {
        if ($request->boolean('latest')) {
            $latestArtifactGraph = $artifactIntelligence->latest($this->stringQuery($request, 'workspace') ?? 'atlas');
            if ($latestArtifactGraph !== null) {
                $stale = $this->staleArtifactGraphResponse($latestArtifactGraph->payload, $this->currentWorkspaceHash($runtime, $request));
                if ($stale !== null) {
                    return $stale;
                }

                return response()->json($latestArtifactGraph->payload);
            }

            $latest = $snapshots->latest($this->stringQuery($request, 'workspace') ?? 'atlas');
            if ($latest !== null) {
                $stale = $this->staleArtifactGraphResponse((array) data_get($latest->payload, 'awair', []), $this->currentWorkspaceHash($runtime, $request));
                if ($stale !== null) {
                    return $stale;
                }

                return response()->json(data_get($latest->payload, 'awair', []));
            }
        }

        $report = $runtime->certify(
            workspace: $this->stringQuery($request, 'workspace'),
            task: $this->stringQuery($request, 'task') ?? '',
            conversationTexts: [],
        );
        if ($request->boolean('persist')) {
            $artifactIntelligence->persist($report);
        }

        return response()->json($report['awair'] ?? [], $report['status'] === 'blocked' ? 422 : 200);
    }

    public function artifactLake(
        Request $request,
        AtlasWorkspaceArtifactIntelligenceRepository $artifactIntelligence,
    ): JsonResponse {
        $payload = $artifactIntelligence->listProviderSafe(
            workspaceId: $this->stringQuery($request, 'workspace') ?? 'atlas',
            artifactType: $this->stringQuery($request, 'type'),
            limit: (int) ($request->integer('limit', 20)),
        );

        return response()->json($payload, ($payload['status'] ?? null) === 'blocked' ? 422 : 200);
    }

    public function artifactLakeShow(
        Request $request,
        string $artifact,
        AtlasWorkspaceArtifactIntelligenceRepository $artifactIntelligence,
    ): JsonResponse {
        $payload = $artifactIntelligence->inspectProviderSafe(
            workspaceId: $this->stringQuery($request, 'workspace') ?? 'atlas',
            artifact: $artifact,
        );

        return response()->json($payload, ($payload['status'] ?? null) === 'blocked' ? 404 : 200);
    }

    public function artifactWorkroom(
        Request $request,
        AtlasWorkspaceIntelligenceRuntimeService $runtime,
        AtlasWorkspaceArtifactWorkroomService $workroom,
    ): JsonResponse {
        $report = $runtime->certify(
            workspace: $this->stringQuery($request, 'workspace'),
            task: $this->stringQuery($request, 'task') ?? '',
            conversationTexts: [],
        );

        $payload = $workroom->build(
            report: $report,
            awairOverride: null,
            artifact: $this->stringQuery($request, 'artifact'),
        );

        return response()->json($payload, ($payload['status'] ?? null) === 'blocked' ? 422 : 200);
    }

    public function artifactTimeline(
        Request $request,
        AtlasWorkspaceArtifactIntelligenceRepository $artifactIntelligence,
    ): JsonResponse {
        $payload = $artifactIntelligence->timelineProviderSafe(
            workspaceId: $this->stringQuery($request, 'workspace') ?? 'atlas',
            artifact: $this->stringQuery($request, 'artifact'),
            limit: (int) ($request->integer('limit', 30)),
        );

        return response()->json($payload, ($payload['status'] ?? null) === 'blocked' ? 422 : 200);
    }

    public function artifactOutcome(
        Request $request,
        AtlasWorkspaceIntelligenceRuntimeService $runtime,
        AtlasWorkspaceArtifactWorkroomService $workroom,
        AtlasWorkspaceArtifactIntelligenceRepository $artifactIntelligence,
        AtlasWorkspaceArtifactAemorBridgeService $aemorBridge,
    ): JsonResponse {
        $report = $runtime->certify(
            workspace: $this->stringQuery($request, 'workspace'),
            task: $this->stringQuery($request, 'task') ?? '',
            conversationTexts: [],
        );
        $workroomPayload = $workroom->build($report, null, $this->stringQuery($request, 'artifact'));
        $event = $artifactIntelligence->recordTimelineEvent($workroomPayload, 'outcome_recorded', $this->stringInput($request, 'outcome_status') ?? 'observed', [
            'summary' => $this->stringInput($request, 'summary') ?? 'Outcome registrado sem corpo bruto.',
            'artifact_hash' => $workroomPayload['artifact_hash'] ?? null,
            'artifact_type' => $workroomPayload['artifact_type'] ?? null,
            'route' => data_get($workroomPayload, 'routes.0', []),
        ]);
        $aemor = $aemorBridge->recordArtifactOutcome(
            $workroomPayload,
            $event,
            $this->stringInput($request, 'outcome_status') ?? 'observed',
            $this->stringInput($request, 'summary') ?? 'Outcome registrado sem corpo bruto.',
        );

        $payload = [
            'schema_version' => 'atlas.workspace_artifact_outcome_projection.v1',
            'status' => ($event['status'] ?? null) === 'ready' && ($aemor['status'] ?? null) === 'ready' ? 'ready' : 'blocked',
            'workspace_id' => $workroomPayload['workspace_id'] ?? null,
            'artifact_hash' => $workroomPayload['artifact_hash'] ?? null,
            'artifact_type' => $workroomPayload['artifact_type'] ?? null,
            'timeline_event' => $event,
            'aemor_bridge' => $aemor,
            'source_policy' => $workroomPayload['source_policy'] ?? [],
            'claim_policy' => $workroomPayload['claim_policy'] ?? [],
        ];

        return response()->json($payload, $payload['status'] === 'blocked' ? 422 : 200);
    }

    public function artifactRetirement(
        Request $request,
        AtlasWorkspaceIntelligenceRuntimeService $runtime,
        AtlasWorkspaceArtifactWorkroomService $workroom,
        AtlasWorkspaceArtifactIntelligenceRepository $artifactIntelligence,
    ): JsonResponse {
        $report = $runtime->certify(
            workspace: $this->stringQuery($request, 'workspace'),
            task: $this->stringQuery($request, 'task') ?? '',
            conversationTexts: [],
        );
        $proposal = $workroom->retirementProposal(
            $workroom->build($report, null, $this->stringQuery($request, 'artifact')),
            $this->stringInput($request, 'reason'),
        );
        $payload = $artifactIntelligence->persistRetirementProposal($proposal);

        return response()->json($payload, ($payload['status'] ?? null) === 'blocked' ? 422 : 200);
    }

    public function artifactRetirementQueue(
        Request $request,
        AtlasWorkspaceArtifactIntelligenceRepository $artifactIntelligence,
    ): JsonResponse {
        $payload = $artifactIntelligence->retirementQueueProviderSafe(
            workspaceId: $this->stringQuery($request, 'workspace') ?? 'atlas',
            artifact: $this->stringQuery($request, 'artifact'),
            limit: (int) ($request->integer('limit', 30)),
        );

        return response()->json($payload, ($payload['status'] ?? null) === 'blocked' ? 422 : 200);
    }

    public function artifactRetirementApply(
        Request $request,
        AtlasWorkspaceArtifactIntelligenceRepository $artifactIntelligence,
    ): JsonResponse {
        $payload = $artifactIntelligence->applyRetirementProposal(
            workspaceId: $this->stringInput($request, 'workspace') ?? 'atlas',
            proposal: $this->stringInput($request, 'proposal'),
            artifact: $this->stringInput($request, 'artifact'),
            replacementArtifact: $this->stringInput($request, 'replacement_artifact'),
        );

        return response()->json($payload, ($payload['status'] ?? null) === 'blocked' ? 422 : 200);
    }

    public function contracts(
        Request $request,
        AtlasWorkspaceIntelligenceRuntimeService $runtime,
        AtlasWorkspaceIntelligenceSnapshotRepository $snapshots,
        AtlasWorkspaceRuntimeProjectionRepository $projections,
    ): JsonResponse {
        if ($request->boolean('latest')) {
            $latestProjection = $projections->latest($this->stringQuery($request, 'workspace') ?? 'atlas', 'AWCO');
            if ($latestProjection !== null) {
                $stale = $this->staleProjectionResponse($latestProjection->payload, 'AWCO', $this->currentWorkspaceHash($runtime, $request));
                if ($stale !== null) {
                    return $stale;
                }

                return response()->json($latestProjection->payload);
            }

            $latest = $snapshots->latest($this->stringQuery($request, 'workspace') ?? 'atlas');
            if ($latest !== null) {
                return response()->json(data_get($latest->payload, 'awco', []));
            }
        }

        $payload = $runtime->contractOrchestration(
            workspace: $this->stringQuery($request, 'workspace'),
            task: $this->stringQuery($request, 'task') ?? '',
        );

        return response()->json($payload, $payload['status'] === 'blocked' ? 422 : 200);
    }

    public function evolution(
        Request $request,
        AtlasWorkspaceIntelligenceRuntimeService $runtime,
        AtlasWorkspaceIntelligenceSnapshotRepository $snapshots,
        AtlasWorkspaceRuntimeProjectionRepository $projections,
    ): JsonResponse {
        if ($request->boolean('latest')) {
            $latestProjection = $projections->latest($this->stringQuery($request, 'workspace') ?? 'atlas', 'AWEF');
            if ($latestProjection !== null) {
                $stale = $this->staleProjectionResponse($latestProjection->payload, 'AWEF', $this->currentWorkspaceHash($runtime, $request));
                if ($stale !== null) {
                    return $stale;
                }

                return response()->json($latestProjection->payload);
            }

            $latest = $snapshots->latest($this->stringQuery($request, 'workspace') ?? 'atlas');
            if ($latest !== null) {
                return response()->json(data_get($latest->payload, 'awef', []));
            }
        }

        $payload = $runtime->evolutionFabric($this->stringQuery($request, 'workspace'));

        return response()->json($payload, $payload['status'] === 'blocked' ? 422 : 200);
    }

    public function gate(
        Request $request,
        AtlasWorkspaceIntelligenceExecutionGateService $gate,
    ): JsonResponse {
        $report = $gate->gate(
            workspace: $this->stringQuery($request, 'workspace'),
            mode: $this->stringQuery($request, 'mode') ?? 'conversation',
            task: $this->stringQuery($request, 'task') ?? '',
            conversationTexts: [],
        );

        return response()->json($report, ($report['allowed'] ?? false) === true ? 200 : 422);
    }

    public function handoffPack(
        Request $request,
        AtlasWorkspaceHandoffPackService $handoffPack,
    ): JsonResponse {
        $threads = $request->query('thread', []);
        $threads = is_array($threads)
            ? array_values(array_filter($threads, 'is_string'))
            : [];

        $payload = $handoffPack->build(
            workspace: $this->stringQuery($request, 'workspace'),
            task: $this->stringQuery($request, 'task') ?? '',
            consumer: $this->stringQuery($request, 'consumer') ?? 'atlas_dev',
            threadIds: $threads,
        );

        return response()->json($payload, ($payload['status'] ?? null) === 'blocked' ? 422 : 200);
    }

    private function stringQuery(Request $request, string $key): ?string
    {
        $value = $request->query($key);
        if (! is_string($value)) {
            return null;
        }
        $value = trim($value);

        return $value === '' ? null : $value;
    }

    private function stringInput(Request $request, string $key): ?string
    {
        $value = $request->input($key);
        if (! is_string($value)) {
            return null;
        }
        $value = trim($value);

        return $value === '' ? null : $value;
    }

    private function currentWorkspaceHash(AtlasWorkspaceIntelligenceRuntimeService $runtime, Request $request): ?string
    {
        $report = $runtime->certify(
            workspace: $this->stringQuery($request, 'workspace'),
            task: $this->stringQuery($request, 'task') ?? '',
            conversationTexts: [],
        );
        $hash = data_get($report, 'workspace.workspace_hash');

        return is_string($hash) && $hash !== '' ? $hash : null;
    }

    /**
     * @param  array<string,mixed>  $payload
     */
    private function staleProjectionResponse(array $payload, string $family, ?string $currentWorkspaceHash): ?JsonResponse
    {
        $snapshotWorkspaceHash = data_get($payload, 'awis_projection.workspace_hash');
        if (! is_string($snapshotWorkspaceHash) || $snapshotWorkspaceHash === '') {
            return response()->json($this->staleProjectionPayload($payload, $family, $currentWorkspaceHash, 'snapshot_missing_workspace_hash'), 409);
        }
        if ($currentWorkspaceHash === null) {
            return response()->json($this->staleProjectionPayload($payload, $family, null, 'current_workspace_hash_unavailable'), 409);
        }
        if (! hash_equals($snapshotWorkspaceHash, $currentWorkspaceHash)) {
            return response()->json($this->staleProjectionPayload($payload, $family, $currentWorkspaceHash, 'workspace_hash_changed'), 409);
        }

        return null;
    }

    /**
     * @param  array<string,mixed>  $payload
     * @return array<string,mixed>
     */
    private function staleProjectionPayload(array $payload, string $family, ?string $currentWorkspaceHash, string $reason): array
    {
        return [
            'schema_version' => 'atlas.awis.runtime_projection_stale.v1',
            'status' => 'blocked',
            'family' => $family,
            'stale' => true,
            'reason' => $reason,
            'workspace_id' => data_get($payload, 'awis_projection.workspace_id'),
            'snapshot_workspace_hash' => data_get($payload, 'awis_projection.workspace_hash'),
            'current_workspace_hash' => $currentWorkspaceHash,
            'runtime_hash' => data_get($payload, 'awis_projection.runtime_hash'),
            'projection_hash' => data_get($payload, 'awis_projection.projection_hash'),
            'blockers' => ['workspace_runtime_projection_stale'],
        ];
    }

    /**
     * @param  array<string,mixed>  $payload
     */
    private function staleArtifactGraphResponse(array $payload, ?string $currentWorkspaceHash): ?JsonResponse
    {
        $snapshotWorkspaceHash = data_get($payload, 'workspace_hash');
        if (! is_string($snapshotWorkspaceHash) || $snapshotWorkspaceHash === '') {
            return response()->json($this->staleArtifactGraphPayload($payload, $currentWorkspaceHash, 'snapshot_missing_workspace_hash'), 409);
        }
        if ($currentWorkspaceHash === null) {
            return response()->json($this->staleArtifactGraphPayload($payload, null, 'current_workspace_hash_unavailable'), 409);
        }
        if (! hash_equals($snapshotWorkspaceHash, $currentWorkspaceHash)) {
            return response()->json($this->staleArtifactGraphPayload($payload, $currentWorkspaceHash, 'workspace_hash_changed'), 409);
        }

        return null;
    }

    /**
     * @param  array<string,mixed>  $payload
     * @return array<string,mixed>
     */
    private function staleArtifactGraphPayload(array $payload, ?string $currentWorkspaceHash, string $reason): array
    {
        return [
            'schema_version' => 'atlas.awair.artifact_graph_stale.v1',
            'status' => 'blocked',
            'family' => 'AWAIR',
            'stale' => true,
            'reason' => $reason,
            'workspace_id' => data_get($payload, 'workspace_id'),
            'snapshot_workspace_hash' => data_get($payload, 'workspace_hash'),
            'current_workspace_hash' => $currentWorkspaceHash,
            'artifact_intelligence_hash' => data_get($payload, 'artifact_intelligence_hash'),
            'artifact_graph_hash' => data_get($payload, 'artifact_graph.graph_hash'),
            'blockers' => ['workspace_artifact_graph_stale'],
        ];
    }
}
