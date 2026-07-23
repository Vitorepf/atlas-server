<?php

declare(strict_types=1);

namespace App\Services\Ai\ControlPlane\ControlPlane;

use App\Models\AiAtlasDecisionReceipt;
use App\Models\AiAtlasRouterDecision;
use App\Models\AiAtlasRuntimeDispatch;
use App\Models\AiEngineeringCompanyCertification;
use App\Models\AiEngineeringCompanyEngagement;
use App\Models\AiEngineeringCompanyReleasePack;
use App\Models\AiEngineeringCompanyRoleRun;
use App\Models\AiEvidencePack;
use App\Models\AiHoldingExternalActionMandate;
use App\Models\AiHoldingExternalCutoverRuntimeInvocation;
use App\Models\AiHoldingExternalCutoverWorkItem;
use App\Models\AiHoldingExternalCutoverWorkOrder;
use App\Models\AiJob;
use App\Models\AiOperatorApproval;
use App\Models\AiQualityAction;
use App\Models\AiQualityEvaluation;
use App\Models\AiRealExecutionForgeHandoff;
use App\Models\AiTrace;
use App\Models\AtlasAaelAuditReport;
use App\Models\AtlasAaelEvolutionExperiment;
use App\Models\AtlasAaelOpportunity;
use App\Models\AtlasAaelPortfolioCycle;
use App\Models\AtlasAaelPromotionDecision;
use App\Models\AtlasAarsCertification;
use App\Models\AtlasAarsRiskProjection;
use App\Models\AtlasAarsScenario;
use App\Models\AtlasAarsSimulation;
use App\Models\AtlasAemorExecutionEpisode;
use App\Models\AtlasAemorJudgmentReport;
use App\Models\AtlasAemorLearningSignal;
use App\Models\AtlasAemorMemoryCandidate;
use App\Models\AtlasAemorOutcome;
use App\Models\AtlasAgenticWorkcell;
use App\Models\AtlasAgenticWorkcellOrgPattern;
use App\Models\AtlasAgenticWorkcellOutcome;
use App\Models\AtlasAverCertifiedExecution;
use App\Models\AtlasAverExecution;
use App\Models\AtlasAweosCertifiedOutcome;
use App\Models\AtlasAweosExecution;
use App\Models\AtlasExecutiveBriefing;
use App\Models\AtlasIntelligenceFactoryCapability;
use App\Models\AtlasIntelligenceFactoryDecision;
use App\Models\AtlasIntelligenceFactoryEvolutionEvent;
use App\Models\AtlasIntelligenceFactoryGap;
use App\Models\AtlasIntelligenceFactorySimulation;
use App\Models\AtlasOpportunitySignal;
use App\Models\AtlasPersistentContextPack;
use App\Models\AtlasRealityEntity;
use App\Models\AtlasRiskSignal;
use App\Models\AtlasRuntimeEfficiencyDecision;
use App\Models\AtlasRuntimeEfficiencyOutcome;
use App\Models\AtlasStrategicDecision;
use App\Models\AtlasWorkspaceArtifactGraphSnapshot;
use App\Models\AtlasWorkspaceRuntimeProjectionSnapshot;
use App\Services\Ai\Compounding\AtlasLearningSignalScanner;
use App\Services\Ai\OperatorApproval\OperatorApprovalCanon;
use App\Services\Ai\RouterRuntime\RouterRuntimeCanon;
use App\Services\Ai\SelfConstruction\AgentControlPlaneTaskQueueOrchestrator;
use App\Services\Ai\SelfConstruction\ControlPlane\AgentControlPlaneTaskPacketBuilder;
use App\Services\Ai\SelfConstruction\ControlPlane\AgentMergeReviewPacketBuilder;
use App\Services\Ai\SelfConstruction\ControlPlane\AgentRuntimeRegistryHandoffProtocolBuilder;
use App\Services\Ai\SelfConstruction\Support\AgentValidationGateDryRunEvaluator;
use App\Services\Ai\Support\DatabaseTableAvailability;
use App\Services\Ai\WorkspaceIntelligence\AtlasWorkspaceArtifactShadowExecutionService;
use App\Services\Ai\WorkspaceIntelligence\AtlasWorkspaceIntelligenceRuntimeService;
use Carbon\CarbonImmutable;
use Illuminate\Support\Arr;
use Illuminate\Support\Collection;
use Throwable;
use App\Services\Ai\ControlPlane\AtlasAiControlPlaneService;

final class WorkspaceContextSection
{
    public function __construct(private readonly ControlPlaneSupport $support) {}

    /**
     * @return array<string,mixed>
     */
    public function persistentContext(CarbonImmutable $since): array
    {
        $empty = [
            'status' => 'missing',
            'total' => 0,
            'ready' => 0,
            'degraded' => 0,
            'blocked' => 0,
            'by_flow' => [],
            'by_scope_type' => [],
            'recent' => [],
            'blockers' => [],
        ];
        if (! DatabaseTableAvailability::has('atlas_persistent_context_packs')) {
            return $empty;
        }

        try {
            $packs = AtlasPersistentContextPack::query()
                ->where('created_at', '>=', $since)
                ->orderByDesc('created_at')
                ->limit(200)
                ->get(['id', 'uuid', 'status', 'scope_type', 'scope_id', 'workspace', 'domain', 'flow_id', 'sufficiency_status', 'context_pack_hash', 'must_know_ledger_hash', 'provider_handoff', 'created_at']);
        } catch (Throwable) {
            return ['status' => 'degraded'] + $empty;
        }

        $section = $empty;
        $section['status'] = 'ready';
        $section['total'] = count($packs);
        foreach ($packs as $pack) {
            $status = $this->support->stringOrNull($pack->status) ?? 'unknown';
            if (isset($section[$status]) && is_int($section[$status])) {
                $section[$status]++;
            }
            $flowId = $this->support->stringOrNull($pack->flow_id) ?? 'unknown';
            $scopeType = $this->support->stringOrNull($pack->scope_type) ?? 'unknown';
            $section['by_flow'][$flowId] = ($section['by_flow'][$flowId] ?? 0) + 1;
            $section['by_scope_type'][$scopeType] = ($section['by_scope_type'][$scopeType] ?? 0) + 1;

            if ($status === 'blocked') {
                $section['blockers'][] = [
                    'kind' => 'persistent_context_blocked',
                    'persistent_context_pack_uuid' => $this->support->stringOrNull($pack->uuid),
                    'flow_id' => $flowId,
                    'scope_type' => $scopeType,
                    'scope_id' => $this->support->stringOrNull($pack->scope_id),
                    'detail' => 'APCR sufficiency gate blocked provider handoff',
                ];
            }

            if (count($section['recent']) < AtlasAiControlPlaneService::RECENT_LIMIT) {
                $section['recent'][] = [
                    'uuid' => $this->support->stringOrNull($pack->uuid),
                    'status' => $status,
                    'sufficiency_status' => $this->support->stringOrNull($pack->sufficiency_status),
                    'scope_type' => $scopeType,
                    'scope_id' => $this->support->stringOrNull($pack->scope_id),
                    'domain' => $this->support->stringOrNull($pack->domain),
                    'flow_id' => $flowId,
                    'context_pack_hash' => $this->support->stringOrNull($pack->context_pack_hash),
                    'must_know_ledger_hash' => $this->support->stringOrNull($pack->must_know_ledger_hash),
                    'execution_allowed' => (bool) data_get($pack->provider_handoff, 'execution_allowed', false),
                    'created_at' => $pack->created_at?->toJSON(),
                ];
            }
        }

        ksort($section['by_flow']);
        ksort($section['by_scope_type']);

        return $section;
    }

    /**
     * @return array<string,mixed>
     */
    public function workspaceIntelligence(CarbonImmutable $since): array
    {
        $empty = [
            'schema_version' => 'atlas.workspace_intelligence.control_plane.v1',
            'status' => 'missing',
            'summary' => [
                'total' => 0,
                'ready' => 0,
                'limited' => 0,
                'blocked' => 0,
                'stale' => 0,
                'artifact_graph_total' => 0,
                'artifact_graph_blocked' => 0,
                'artifact_graph_stale' => 0,
                'workspaces_total' => 0,
                'families_total' => 0,
                'required_families_total' => count(AtlasAiControlPlaneService::REQUIRED_WORKSPACE_INTELLIGENCE_FAMILIES),
                'missing_required_families' => 0,
            ],
            'required_families' => AtlasAiControlPlaneService::REQUIRED_WORKSPACE_INTELLIGENCE_FAMILIES,
            'by_family' => [],
            'by_workspace' => [],
            'latest' => [],
            'artifact_graph' => [
                'schema_version' => 'atlas.workspace_artifact_graph.control_plane.v1',
                'status' => 'missing',
                'summary' => ['total' => 0, 'ready' => 0, 'blocked' => 0, 'stale' => 0],
                'latest' => [],
            ],
            'shadow_execution' => [
                'schema_version' => 'atlas.workspace_artifact_shadow_execution.control_plane.v1',
                'status' => 'ready',
                'summary' => ['total' => 0, 'ready' => 0, 'blocked' => 0],
                'items' => [],
            ],
            'blockers' => [],
        ];

        if (! DatabaseTableAvailability::has('atlas_workspace_runtime_projection_snapshots')) {
            return $empty;
        }

        try {
            $snapshots = AtlasWorkspaceRuntimeProjectionSnapshot::query()
                ->where('captured_at', '>=', $since)
                ->orderByDesc('captured_at')
                ->limit(200)
                ->get(['id', 'workspace_id', 'family', 'schema_version', 'runtime_hash', 'projection_hash', 'status', 'payload', 'captured_at']);
        } catch (Throwable) {
            $empty['status'] = 'degraded';

            return $empty;
        }

        $summary = $empty['summary'];
        $summary['total'] = count($snapshots);
        $byFamily = [];
        $byWorkspace = [];
        $latest = [];
        $blockers = [];
        $workspaceIds = [];
        $families = [];
        $workspaceHashCache = [];

        foreach ($snapshots as $snapshot) {
            $family = $this->support->stringOrNull($snapshot->family) ?? 'unknown';
            $workspaceId = $this->support->stringOrNull($snapshot->workspace_id) ?? 'unknown';
            $status = $this->support->stringOrNull($snapshot->status) ?? 'unknown';
            $staleReason = $this->workspaceProjectionStaleReason($snapshot, $workspaceId, $workspaceHashCache);
            $workspaceIds[$workspaceId] = true;
            $families[$family] = true;

            if (isset($summary[$status]) && is_int($summary[$status])) {
                $summary[$status]++;
            }
            if ($staleReason !== null) {
                $summary['stale']++;
            }

            $byFamily[$family] ??= [
                'family' => $family,
                'total' => 0,
                'by_status' => [],
                'stale_count' => 0,
                'latest_projection_hash' => null,
                'latest_runtime_hash' => null,
                'latest_at' => null,
            ];
            $byFamily[$family]['total']++;
            $byFamily[$family]['by_status'][$status] = ($byFamily[$family]['by_status'][$status] ?? 0) + 1;
            if ($staleReason !== null) {
                $byFamily[$family]['stale_count']++;
            }

            $byWorkspace[$workspaceId] ??= [
                'workspace_id' => $workspaceId,
                'total' => 0,
                'families' => [],
                'latest_at' => null,
            ];
            $byWorkspace[$workspaceId]['total']++;
            $byWorkspace[$workspaceId]['families'][$family] = true;

            $capturedAt = $snapshot->captured_at?->toJSON();
            if ($byFamily[$family]['latest_at'] === null || ($capturedAt !== null && $capturedAt > $byFamily[$family]['latest_at'])) {
                $byFamily[$family]['latest_projection_hash'] = $this->support->stringOrNull($snapshot->projection_hash);
                $byFamily[$family]['latest_runtime_hash'] = $this->support->stringOrNull($snapshot->runtime_hash);
                $byFamily[$family]['latest_at'] = $capturedAt;
            }
            if ($byWorkspace[$workspaceId]['latest_at'] === null || ($capturedAt !== null && $capturedAt > $byWorkspace[$workspaceId]['latest_at'])) {
                $byWorkspace[$workspaceId]['latest_at'] = $capturedAt;
            }

            if (count($latest) < AtlasAiControlPlaneService::RECENT_LIMIT) {
                $latest[] = [
                    'snapshot_id' => (string) $snapshot->id,
                    'workspace_id' => $workspaceId,
                    'family' => $family,
                    'status' => $status,
                    'schema_version' => $this->support->stringOrNull($snapshot->schema_version),
                    'runtime_hash' => $this->support->stringOrNull($snapshot->runtime_hash),
                    'projection_hash' => $this->support->stringOrNull($snapshot->projection_hash),
                    'stale' => $staleReason !== null,
                    'stale_reason' => $staleReason,
                    'captured_at' => $capturedAt,
                ];
            }

            if ($status === 'blocked') {
                $blockers[] = [
                    'kind' => 'workspace_intelligence_projection_blocked',
                    'workspace_id' => $workspaceId,
                    'family' => $family,
                    'snapshot_id' => (string) $snapshot->id,
                    'detail' => 'AWIS runtime projection persisted a blocked status',
                ];
            }
            if ($staleReason !== null) {
                $blockers[] = [
                    'kind' => 'workspace_intelligence_projection_stale',
                    'workspace_id' => $workspaceId,
                    'family' => $family,
                    'snapshot_id' => (string) $snapshot->id,
                    'reason' => $staleReason,
                    'detail' => 'AWIS runtime projection no longer matches current workspace hash',
                ];
            }
        }

        $artifactGraph = $this->workspaceArtifactGraphSection($since, $workspaceHashCache);
        foreach ((array) ($artifactGraph['workspace_ids'] ?? []) as $workspaceId) {
            if (is_string($workspaceId) && $workspaceId !== '') {
                $workspaceIds[$workspaceId] = true;
            }
        }
        foreach ((array) ($artifactGraph['blockers'] ?? []) as $blocker) {
            if (is_array($blocker)) {
                $blockers[] = $blocker;
            }
        }

        $summary['workspaces_total'] = count($workspaceIds);
        $summary['families_total'] = count($families);
        $summary['artifact_graph_total'] = (int) data_get($artifactGraph, 'summary.total', 0);
        $summary['artifact_graph_blocked'] = (int) data_get($artifactGraph, 'summary.blocked', 0);
        $summary['artifact_graph_stale'] = (int) data_get($artifactGraph, 'summary.stale', 0);
        foreach ($byWorkspace as $workspaceId => &$workspace) {
            $presentFamilies = array_keys((array) ($workspace['families'] ?? []));
            $missingFamilies = array_values(array_diff(AtlasAiControlPlaneService::REQUIRED_WORKSPACE_INTELLIGENCE_FAMILIES, $presentFamilies));
            sort($missingFamilies);
            $workspace['missing_required_families'] = $missingFamilies;
            $summary['missing_required_families'] += count($missingFamilies);
            foreach ($missingFamilies as $family) {
                $blockers[] = [
                    'kind' => 'workspace_intelligence_required_projection_missing',
                    'workspace_id' => (string) $workspaceId,
                    'family' => $family,
                    'detail' => 'AWIS control plane requires the full runtime projection family for an operational workspace',
                ];
            }
        }
        unset($workspace);
        $shadowExecution = $this->workspaceArtifactShadowExecution(array_keys($workspaceIds));
        foreach ((array) ($shadowExecution['blockers'] ?? []) as $blocker) {
            if (is_array($blocker)) {
                $blockers[] = $blocker;
            }
        }
        ksort($byFamily);
        ksort($byWorkspace);

        return [
            'schema_version' => 'atlas.workspace_intelligence.control_plane.v1',
            'status' => $blockers === [] ? 'ready' : 'blocked',
            'summary' => $summary,
            'required_families' => AtlasAiControlPlaneService::REQUIRED_WORKSPACE_INTELLIGENCE_FAMILIES,
            'by_family' => array_values($byFamily),
            'by_workspace' => array_map(static function (array $workspace): array {
                $workspace['families'] = array_keys($workspace['families']);
                sort($workspace['families']);

                return $workspace;
            }, array_values($byWorkspace)),
            'latest' => $latest,
            'artifact_graph' => Arr::except($artifactGraph, ['blockers', 'workspace_ids']),
            'shadow_execution' => Arr::except($shadowExecution, ['blockers']),
            'blockers' => $blockers,
        ];
    }

    /**
     * @param  array<string,string|null>  $workspaceHashCache
     * @return array<string,mixed>
     */
    public function workspaceArtifactGraphSection(CarbonImmutable $since, array &$workspaceHashCache): array
    {
        $section = [
            'schema_version' => 'atlas.workspace_artifact_graph.control_plane.v1',
            'status' => 'missing',
            'summary' => ['total' => 0, 'ready' => 0, 'blocked' => 0, 'stale' => 0],
            'latest' => [],
            'blockers' => [],
            'workspace_ids' => [],
        ];
        if (! DatabaseTableAvailability::has('atlas_workspace_artifact_graph_snapshots')) {
            return $section;
        }

        try {
            $snapshots = AtlasWorkspaceArtifactGraphSnapshot::query()
                ->where('captured_at', '>=', $since)
                ->orderByDesc('captured_at')
                ->limit(200)
                ->get(['id', 'workspace_id', 'runtime_hash', 'artifact_intelligence_hash', 'status', 'graph_hash', 'artifact_count', 'replay_ready', 'simulation_decision', 'payload', 'captured_at']);
        } catch (Throwable) {
            $section['status'] = 'degraded';

            return $section;
        }

        $summary = $section['summary'];
        $summary['total'] = count($snapshots);
        foreach ($snapshots as $snapshot) {
            $workspaceId = $this->support->stringOrNull($snapshot->workspace_id) ?? 'unknown';
            $status = $this->support->stringOrNull($snapshot->status) ?? 'unknown';
            $staleReason = $this->workspaceArtifactGraphStaleReason(is_array($snapshot->payload) ? $snapshot->payload : [], $workspaceId, $workspaceHashCache);
            $section['workspace_ids'][] = $workspaceId;
            if ($status === 'ready') {
                $summary['ready']++;
            }
            if ($status === 'blocked') {
                $summary['blocked']++;
                $section['blockers'][] = [
                    'kind' => 'workspace_artifact_graph_blocked',
                    'workspace_id' => $workspaceId,
                    'snapshot_id' => (string) $snapshot->id,
                    'detail' => 'AWAIR artifact graph persisted a blocked status',
                ];
            }
            if ($staleReason !== null) {
                $summary['stale']++;
                $section['blockers'][] = [
                    'kind' => 'workspace_artifact_graph_stale',
                    'workspace_id' => $workspaceId,
                    'snapshot_id' => (string) $snapshot->id,
                    'reason' => $staleReason,
                    'detail' => 'AWAIR artifact graph no longer matches current workspace hash',
                ];
            }
            if (count($section['latest']) < AtlasAiControlPlaneService::RECENT_LIMIT) {
                $section['latest'][] = [
                    'snapshot_id' => (string) $snapshot->id,
                    'workspace_id' => $workspaceId,
                    'status' => $status,
                    'runtime_hash' => $this->support->stringOrNull($snapshot->runtime_hash),
                    'artifact_intelligence_hash' => $this->support->stringOrNull($snapshot->artifact_intelligence_hash),
                    'graph_hash' => $this->support->stringOrNull($snapshot->graph_hash),
                    'artifact_count' => (int) $snapshot->artifact_count,
                    'replay_ready' => (bool) $snapshot->replay_ready,
                    'simulation_decision' => $this->support->stringOrNull($snapshot->simulation_decision),
                    'stale' => $staleReason !== null,
                    'stale_reason' => $staleReason,
                    'captured_at' => $snapshot->captured_at?->toJSON(),
                ];
            }
        }

        $section['summary'] = $summary;
        $section['workspace_ids'] = array_values(array_unique($section['workspace_ids']));
        $section['status'] = $section['blockers'] === [] ? 'ready' : 'blocked';

        return $section;
    }

    /**
     * @param  array<int,string>  $workspaceIds
     * @return array<string,mixed>
     */
    public function workspaceArtifactShadowExecution(array $workspaceIds): array
    {
        $workspaceIds = array_values(array_unique(array_filter($workspaceIds, static fn (string $id): bool => $id !== '' && $id !== 'unknown')));
        $items = [];
        $blockers = [];
        $shadow = app(AtlasWorkspaceArtifactShadowExecutionService::class);
        $runtime = app(AtlasWorkspaceIntelligenceRuntimeService::class);

        foreach (array_slice($workspaceIds, 0, AtlasAiControlPlaneService::RECENT_LIMIT) as $workspaceId) {
            try {
                $evaluation = $shadow->evaluate($runtime->certify($workspaceId), 'control_plane');
            } catch (Throwable $exception) {
                $evaluation = [
                    'schema_version' => AtlasWorkspaceArtifactShadowExecutionService::SCHEMA_VERSION,
                    'status' => 'blocked',
                    'mode' => 'control_plane',
                    'workspace_id' => $workspaceId,
                    'blockers' => ['artifact_shadow_execution_unavailable'],
                    'error_class' => $exception::class,
                ];
            }

            $items[] = [
                'workspace_id' => $workspaceId,
                'status' => $this->support->stringOrNull($evaluation['status'] ?? null) ?? 'unknown',
                'runtime_hash' => $this->support->stringOrNull($evaluation['runtime_hash'] ?? null),
                'artifact_intelligence_hash' => $this->support->stringOrNull($evaluation['artifact_intelligence_hash'] ?? null),
                'node_count' => (int) ($evaluation['node_count'] ?? 0),
                'edge_count' => (int) ($evaluation['edge_count'] ?? 0),
                'replay_ready' => ($evaluation['replay_ready'] ?? null) === true,
                'simulation_decision' => $this->support->stringOrNull($evaluation['simulation_decision'] ?? null),
                'quality_ready' => ($evaluation['quality_ready'] ?? null) === true,
                'provider_called' => ($evaluation['provider_called'] ?? null) === true,
                'workspace_mutated' => ($evaluation['workspace_mutated'] ?? null) === true,
                'blockers' => array_values((array) ($evaluation['blockers'] ?? [])),
                'shadow_execution_hash' => $this->support->stringOrNull($evaluation['shadow_execution_hash'] ?? null),
            ];

            if (($evaluation['status'] ?? null) !== 'ready') {
                $blockers[] = [
                    'kind' => 'workspace_artifact_shadow_execution_blocked',
                    'workspace_id' => $workspaceId,
                    'detail' => 'AWAIR artifact shadow execution is not ready for provider handoff',
                    'reasons' => array_values((array) ($evaluation['blockers'] ?? [])),
                ];
            }
        }

        $blocked = count(array_filter($items, static fn (array $item): bool => ($item['status'] ?? null) !== 'ready'));

        return [
            'schema_version' => 'atlas.workspace_artifact_shadow_execution.control_plane.v1',
            'status' => $blocked === 0 ? 'ready' : 'blocked',
            'summary' => [
                'total' => count($items),
                'ready' => count($items) - $blocked,
                'blocked' => $blocked,
            ],
            'items' => $items,
            'blockers' => $blockers,
        ];
    }

    /**
     * @param  array<string,string|null>  $workspaceHashCache
     */
    public function workspaceProjectionStaleReason(AtlasWorkspaceRuntimeProjectionSnapshot $snapshot, string $workspaceId, array &$workspaceHashCache): ?string
    {
        $payload = is_array($snapshot->payload) ? $snapshot->payload : [];
        $snapshotWorkspaceHash = data_get($payload, 'awis_projection.workspace_hash');
        if (! is_string($snapshotWorkspaceHash) || $snapshotWorkspaceHash === '') {
            return 'projection_missing_workspace_hash';
        }

        if (! array_key_exists($workspaceId, $workspaceHashCache)) {
            try {
                $report = app(AtlasWorkspaceIntelligenceRuntimeService::class)->certify($workspaceId);
                $hash = data_get($report, 'workspace.workspace_hash');
                $workspaceHashCache[$workspaceId] = is_string($hash) && $hash !== '' ? $hash : null;
            } catch (Throwable) {
                $workspaceHashCache[$workspaceId] = null;
            }
        }

        $currentWorkspaceHash = $workspaceHashCache[$workspaceId];
        if (! is_string($currentWorkspaceHash) || $currentWorkspaceHash === '') {
            return 'current_workspace_hash_unavailable';
        }

        return hash_equals($snapshotWorkspaceHash, $currentWorkspaceHash) ? null : 'workspace_hash_changed';
    }

    /**
     * @param  array<string,mixed>  $payload
     * @param  array<string,string|null>  $workspaceHashCache
     */
    public function workspaceArtifactGraphStaleReason(array $payload, string $workspaceId, array &$workspaceHashCache): ?string
    {
        $snapshotWorkspaceHash = data_get($payload, 'workspace_hash');
        if (! is_string($snapshotWorkspaceHash) || $snapshotWorkspaceHash === '') {
            return 'artifact_graph_missing_workspace_hash';
        }

        if (! array_key_exists($workspaceId, $workspaceHashCache)) {
            try {
                $report = app(AtlasWorkspaceIntelligenceRuntimeService::class)->certify($workspaceId);
                $hash = data_get($report, 'workspace.workspace_hash');
                $workspaceHashCache[$workspaceId] = is_string($hash) && $hash !== '' ? $hash : null;
            } catch (Throwable) {
                $workspaceHashCache[$workspaceId] = null;
            }
        }

        $currentWorkspaceHash = $workspaceHashCache[$workspaceId];
        if (! is_string($currentWorkspaceHash) || $currentWorkspaceHash === '') {
            return 'current_workspace_hash_unavailable';
        }

        return hash_equals($snapshotWorkspaceHash, $currentWorkspaceHash) ? null : 'workspace_hash_changed';
    }
}
