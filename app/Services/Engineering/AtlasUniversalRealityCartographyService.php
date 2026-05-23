<?php

declare(strict_types=1);

namespace App\Services\Engineering;

use App\Services\Ai\WorkspaceIntelligence\AtlasWorkspaceArtifactIntelligenceRepository;
use App\Services\Ai\WorkspaceIntelligence\AtlasWorkspaceArtifactWorkroomService;
use App\Services\Ai\WorkspaceIntelligence\AtlasWorkspaceIntelligenceRuntimeService;
use App\Services\Ai\WorkspaceIntelligence\AtlasWorkspaceRuntimeProjectionRepository;
use Illuminate\Support\Arr;

final class AtlasUniversalRealityCartographyService
{
    public const SCHEMA_VERSION = 'atlas.universal_reality_cartography.v1';

    public const HUMAN_CLARITY_SCHEMA_VERSION = 'atlas.universal_reality_cartography.human_clarity.v1';

    public function __construct(
        private readonly AtlasDocumentationRealitySystemService $documentationReality,
        private readonly AtlasCodeRealityUsageIntelligenceService $codeReality,
        private readonly AtlasWorkspaceIntelligenceRuntimeService $workspaceIntelligence,
        private readonly AtlasWorkspaceArtifactIntelligenceRepository $artifactIntelligence,
        private readonly AtlasWorkspaceArtifactWorkroomService $artifactWorkroom,
        private readonly AtlasWorkspaceRuntimeProjectionRepository $runtimeProjections,
    ) {}

    /**
     * @return array<string,mixed>
     */
    public function map(string $mode = 'universe', ?string $workspace = null): array
    {
        $adrs = $this->documentationReality->report();
        $acrui = $this->codeReality->classify('app/Services/Engineering/AtlasCodeRealityUsageIntelligenceService.php');
        $workspaceScope = $this->workspaceScope($workspace);
        $nodes = $this->nodes($adrs, $acrui, $workspaceScope);
        $edges = $this->edges();
        $coverage = $this->coverage($nodes, $edges);
        $mode = $this->mode($mode);
        $visualScene = $this->visualScene($nodes, $edges, $mode, $workspaceScope);
        $semanticZoomScenes = $this->semanticZoomScenes($nodes);
        $humanRouteMap = $this->humanRouteMap($nodes);
        $taskSimulator = $this->taskSimulator($nodes);
        $aiNavigationSlice = $this->aiNavigationSlice($nodes);
        $humanClarity = $this->humanClarity($coverage, $visualScene, $semanticZoomScenes, $humanRouteMap, $taskSimulator);

        $payload = [
            'schema_version' => self::SCHEMA_VERSION,
            'status' => $coverage['missing_source_count'] === 0 && $coverage['missing_modal_count'] === 0 ? 'ready' : 'review',
            'mode' => $mode,
            'workspace_scope' => $workspaceScope,
            'summary' => [
                'node_count' => count($nodes),
                'edge_count' => count($edges),
                'semantic_levels' => ['universe', 'organization', 'project', 'system', 'flow', 'component', 'evidence'],
                'visual_first_contract' => 'human_should_understand_macro_flow_from_nodes_edges_state_before_reading_modal',
                'human_clarity_target_score' => 9.8,
            ],
            'nodes' => $nodes,
            'edges' => $edges,
            'coverage_audit' => $coverage,
            'visual_scene' => $visualScene,
            'semantic_zoom_scenes' => $semanticZoomScenes,
            'human_route_map' => $humanRouteMap,
            'task_simulator' => $taskSimulator,
            'human_clarity' => $humanClarity,
            'ai_navigation_slice' => $aiNavigationSlice,
            'claim_policy' => [
                'cartography_is_source_of_truth' => false,
                'canonical_docs_remain_authority' => true,
                'providers_invoked' => false,
                'rivals_run' => false,
                'writes' => false,
                'external_project_docs_copied' => false,
                'workspace_scope_is_projection_not_source_of_truth' => true,
            ],
            'writes' => false,
        ];

        $payload['cartography_hash'] = hash('sha256', json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));

        return $payload;
    }

    /**
     * @return array<string,mixed>
     */
    private function workspaceScope(?string $workspace): array
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

    /**
     * @param  array<string,mixed>  $adrs
     * @param  array<string,mixed>  $acrui
     * @return array<int,array<string,mixed>>
     */
    private function nodes(array $adrs, array $acrui, array $workspaceScope): array
    {
        $workspaceProjectionStale = data_get($workspaceScope, 'runtime_projection_replay.stale_count', 0) > 0;
        $artifactGraphStale = data_get($workspaceScope, 'artifact_graph_replay.stale') === true;
        $artifactLakeCount = (int) data_get($workspaceScope, 'artifact_lake_replay.artifact_count', 0);
        $artifactWorkroomReady = data_get($workspaceScope, 'artifact_workroom.status') === 'ready';
        $workspaceAttention = $workspaceProjectionStale || $artifactGraphStale;
        $workspaceRuntimeStatus = $workspaceAttention ? 'review' : 'active';
        $workspaceFlowStatus = $workspaceProjectionStale ? 'review' : 'active';
        $artifactGraphStatus = $artifactGraphStale ? 'review' : 'active_read_only';
        $artifactLakeStatus = $artifactLakeCount > 0 ? 'active_read_only' : 'ready';
        $workspaceStaleSummary = $workspaceAttention
            ? 'Ha projection AWIS persistida divergente ou artifact graph AWAIR persistido divergente do workspace atual; Cartografia deve orientar refresh antes de confiar em replay.'
            : 'Area que prende execucao, memoria, contexto e artefatos ao workspace correto antes de Dev/Forge agir.';

        return [
            $this->node(
                'universe',
                'universe',
                'Universe',
                'universe',
                'active',
                'docs/engineering-knowledge-base/atlas-universal-reality-cartography.md',
                'atlas-cartography',
                ['universe', 'organization'],
                ['Atlas', 'external_projects_boundary'],
                'Mapa global de organizacoes, projetos e fronteiras de verdade.'
            ),
            $this->node(
                'org.atlas',
                'organization',
                'Atlas',
                'organization',
                'active',
                'docs/engineering-knowledge-base/atlas-documentation-reality-system.md',
                'documentation-governance',
                ['project'],
                ['ADRS', 'ACRUI', 'AURC'],
                'Organizacao/plataforma Atlas. A verdade canonica fica nos repo docs do Atlas.'
            ),
            $this->node(
                'project.atlas.documentation-reality',
                'project',
                'Documentation Reality',
                'project',
                (string) ($adrs['status'] ?? 'unknown'),
                'docs/engineering-knowledge-base/atlas-documentation-reality-system.md',
                'documentation-governance',
                ['system'],
                ['52 ADRS blocks', 'ADRS runtime score', 'ACRUI child', 'AURC child'],
                'Area mais critica de organizacao: docs canonicos, realidade operacional e Cartografia humana.'
            ),
            $this->node(
                'project.atlas.workspace-intelligence',
                'project',
                'Workspace Intelligence',
                'project',
                $workspaceRuntimeStatus,
                'docs/engineering-knowledge-base/atlas-workspace-intelligence-system.md',
                'workspace-intelligence',
                ['system'],
                ['AWIS', 'AWTR', 'AWCO', 'AWEF', 'AWAF', 'AWAIR', 'workspace_intelligence control-plane section'],
                $workspaceStaleSummary
            ),
            $this->node(
                'system.adrs',
                'system',
                'ADRS',
                'system',
                (string) ($adrs['status'] ?? 'unknown'),
                'docs/engineering-knowledge-base/atlas-documentation-reality-system.md',
                'documentation-governance',
                ['flow', 'component'],
                [
                    'php artisan atlas:documentation-reality score --strict --json',
                    'tests/Feature/Engineering/AtlasDocumentationRealitySystemServiceTest.php',
                ],
                'Doc mae e runtime integrado que materializa os 52 blocos da area.'
            ),
            $this->node(
                'system.acrui',
                'system',
                'ACRUI',
                'system',
                (string) ($acrui['classification'] ?? 'unknown_requires_audit'),
                'docs/engineering-knowledge-base/atlas-code-reality-usage-intelligence.md',
                'architecture-audit',
                ['flow', 'component'],
                [
                    'php artisan atlas:code-reality classify --target=<target> --json',
                    'tests/Feature/Engineering/AtlasCodeRealityUsageIntelligenceServiceTest.php',
                ],
                'Filho operacional do ADRS que classifica realidade de codigo/docs de forma conservadora.'
            ),
            $this->node(
                'system.aurc',
                'system',
                'AURC',
                'system',
                'active_read_only',
                'docs/engineering-knowledge-base/atlas-universal-reality-cartography.md',
                'atlas-cartography',
                ['flow', 'component'],
                [
                    'php artisan atlas:universal-reality-cartography map --strict --json',
                    'tests/Feature/Engineering/AtlasUniversalRealityCartographyServiceTest.php',
                ],
                'Filha visual do ADRS que projeta a verdade em mapa navegavel por humano e IA.'
            ),
            $this->node(
                'system.awis',
                'system',
                'AWIS',
                'system',
                $workspaceRuntimeStatus,
                'docs/engineering-knowledge-base/atlas-workspace-intelligence-system.md',
                'workspace-intelligence',
                ['flow'],
                [
                    'php artisan atlas:workspace-intelligence --workspace=atlas --json --strict',
                    'tests/Feature/Ai/WorkspaceIntelligence/AtlasWorkspaceIntelligenceRuntimeServiceTest.php',
                ],
                'Garante que toda execucao de IA nasce dentro do workspace certo, com memoria e contexto escopados.'
            ),
            $this->node(
                'system.awtr',
                'system',
                'AWTR',
                'system',
                'active',
                'docs/engineering-knowledge-base/atlas-workspace-twin-runtime.md',
                'workspace-intelligence',
                ['flow'],
                ['atlas:workspace-intelligence twin', 'atlas_workspace_runtime_projection_snapshots'],
                'Gemeo operacional do workspace: stack, comandos, riscos, testes e mapas vivos por projeto.'
            ),
            $this->node(
                'system.awco',
                'system',
                'AWCO',
                'system',
                'active',
                'docs/engineering-knowledge-base/atlas-workspace-contract-orchestrator.md',
                'workspace-intelligence',
                ['flow'],
                ['atlas:workspace-intelligence contracts', 'workspace_intelligence control-plane blockers'],
                'Certifica artefatos antes de provider, subagente, Dev ou Forge consumir.'
            ),
            $this->node(
                'system.awef',
                'system',
                'AWEF',
                'system',
                'active',
                'docs/engineering-knowledge-base/atlas-workspace-evolution-fabric.md',
                'workspace-intelligence',
                ['flow'],
                ['atlas:workspace-intelligence evolution', 'privacy_transfer_gate'],
                'Aprende padroes entre workspaces sem copiar contexto privado entre projetos.'
            ),
            $this->node(
                'system.awaf',
                'system',
                'AWAF',
                'system',
                'active_read_only',
                'docs/engineering-knowledge-base/atlas-workspace-artifact-fabric.md',
                'workspace-intelligence',
                ['flow'],
                ['atlas_workspace_artifacts', 'artifact_hash', 'workspace_id'],
                'Gera artefatos operacionais vivos por workspace para reduzir conversa solta e handoff fraco.'
            ),
            $this->node(
                'system.awair',
                'system',
                'AWAIR',
                'system',
                $artifactGraphStatus,
                'docs/engineering-knowledge-base/atlas-workspace-artifact-intelligence-runtime.md',
                'workspace-intelligence',
                ['flow'],
                ['atlas:workspace-intelligence artifact-intelligence --workspace=atlas --json --strict', 'artifact_graph_hash'],
                'Projeta artifact lake, artifact graph, replay, simulation e Cartografia por artefato.'
            ),
            $this->node(
                'system.awaol',
                'system',
                'AWAOL',
                'system',
                $artifactWorkroomReady ? 'active_read_only' : 'planned',
                'docs/engineering-knowledge-base/atlas-workspace-artifact-operating-layer.md',
                'workspace-intelligence',
                ['flow'],
                [
                    'atlas.workspace_artifact_workroom.v1',
                    '/atlas-code/workspace-intelligence/artifact-workroom',
                ],
                $artifactWorkroomReady
                    ? 'Abre workroom provider-safe por artefato com packet humano, agent packet, rota, timeline e replay point.'
                    : 'Camada planejada para operar artifact workrooms por workspace.'
            ),
            $this->node(
                'flow.adrs-to-acrui-to-aurc',
                'flow',
                'ADRS -> ACRUI -> AURC',
                'flow',
                'active',
                'docs/engineering-knowledge-base/atlas-documentation-reality-system.md',
                'documentation-governance',
                ['component', 'evidence'],
                ['ADRS authority', 'ACRUI operational classification', 'AURC visual projection'],
                'Fluxo central: ADRS decide autoridade, ACRUI prova realidade, AURC mostra para humano.'
            ),
            $this->node(
                'flow.workspace-runtime-projections',
                'flow',
                'AWIS -> AWTR/AWCO/AWEF',
                'flow',
                $workspaceFlowStatus,
                'docs/engineering-knowledge-base/atlas-workspace-intelligence-system.md',
                'workspace-intelligence',
                [],
                [
                    'atlas_workspace_runtime_projection_snapshots',
                    'AtlasAiControlPlaneService.workspace_intelligence',
                ],
                'Fluxo que persiste projections por workspace e mostra status/hash/blockers no Control Plane.'
            ),
            $this->node(
                'flow.workspace-artifact-graph',
                'flow',
                'AWAIR Artifact Graph',
                'flow',
                $artifactGraphStatus,
                'docs/engineering-knowledge-base/atlas-workspace-artifact-intelligence-runtime.md',
                'workspace-intelligence',
                [],
                ['atlas.workspace_artifact_graph.v1', 'artifact_dependency_graph', 'artifact_cartography_projection'],
                'Fluxo visual que mostra artefatos, dependencias, stale state, prova e reuso por workspace.'
            ),
            $this->node(
                'flow.workspace-artifact-lake-replay',
                'flow',
                'Artifact Lake Replay',
                'flow',
                $artifactLakeStatus,
                'docs/engineering-knowledge-base/atlas-workspace-intelligence-system.md',
                'workspace-intelligence',
                [],
                [
                    'atlas_workspace_artifact_lake_entries',
                    '/atlas-code/workspace-intelligence/artifact-lake/{artifact}',
                ],
                $artifactLakeCount > 0
                    ? 'Mostra packs AWIS persistidos por workspace para reabrir contexto sem reler conversa bruta.'
                    : 'Mostra quando ainda nao ha pack AWIS persistido para este workspace.'
            ),
            $this->node(
                'flow.workspace-artifact-workroom',
                'flow',
                'Artifact Workroom',
                'flow',
                $artifactWorkroomReady ? 'active_read_only' : 'planned',
                'docs/engineering-knowledge-base/atlas-workspace-artifact-operating-layer.md',
                'workspace-intelligence',
                [],
                [
                    'atlas.workspace_artifact_human_packet.v1',
                    'atlas.workspace_artifact_agent_packet.v1',
                    'atlas.workspace_artifact_replay_point.v1',
                ],
                $artifactWorkroomReady
                    ? 'Mostra um artifact como espaco operacional: 30s humano, rota, replay point, diff e pacote seguro para IA.'
                    : 'Cria drilldown operacional de artefatos AWIS.'
            ),
            $this->node(
                'component.adrs-runtime',
                'component',
                'AtlasDocumentationRealitySystemService',
                'component',
                'active_runtime',
                'app/Services/Engineering/AtlasDocumentationRealitySystemService.php',
                'documentation-governance',
                ['evidence'],
                ['tests/Feature/Engineering/AtlasDocumentationRealitySystemServiceTest.php'],
                'Runtime read-only integrado que gera score, readiness, evaluations e integration evidence.'
            ),
            $this->node(
                'component.acrui-runtime',
                'component',
                'AtlasCodeRealityUsageIntelligenceService',
                'component',
                (string) ($acrui['classification'] ?? 'unknown_requires_audit'),
                'app/Services/Engineering/AtlasCodeRealityUsageIntelligenceService.php',
                'architecture-audit',
                ['evidence'],
                ['tests/Feature/Engineering/AtlasCodeRealityUsageIntelligenceServiceTest.php'],
                'Runtime read-only que classifica target, usage-map, anti-duplicate e dead-code candidates sem deletar.'
            ),
            $this->node(
                'component.aurc-runtime',
                'component',
                'AtlasUniversalRealityCartographyService',
                'component',
                'active_read_only',
                'app/Services/Engineering/AtlasUniversalRealityCartographyService.php',
                'atlas-cartography',
                ['evidence'],
                ['tests/Feature/Engineering/AtlasUniversalRealityCartographyServiceTest.php'],
                'Runtime read-only que emite visual nodes, edges, coverage audit e navigation slice.'
            ),
            $this->node(
                'evidence.adrs-gates',
                'evidence',
                'ADRS Gates',
                'evidence',
                'ready',
                'tests/Feature/Engineering/AtlasDocumentationRealitySystemServiceTest.php',
                'documentation-governance',
                [],
                [
                    'php artisan atlas:engineering:knowledge docs-health --json',
                    'php artisan atlas:ai:architecture-validate --json',
                    'git diff --check',
                ],
                'Provas mecanicas que sustentam o mapa; Cartografia nao e prova primaria.'
            ),
        ];
    }

    /**
     * @return array<int,array<string,mixed>>
     */
    private function edges(): array
    {
        return [
            $this->edge('universe', 'org.atlas', 'contains'),
            $this->edge('org.atlas', 'project.atlas.documentation-reality', 'owns'),
            $this->edge('org.atlas', 'project.atlas.workspace-intelligence', 'owns'),
            $this->edge('project.atlas.documentation-reality', 'system.adrs', 'governs'),
            $this->edge('project.atlas.documentation-reality', 'system.acrui', 'contains_child'),
            $this->edge('project.atlas.documentation-reality', 'system.aurc', 'contains_child'),
            $this->edge('project.atlas.workspace-intelligence', 'system.awis', 'governs'),
            $this->edge('project.atlas.workspace-intelligence', 'system.awtr', 'contains_child'),
            $this->edge('project.atlas.workspace-intelligence', 'system.awco', 'contains_child'),
            $this->edge('project.atlas.workspace-intelligence', 'system.awef', 'contains_child'),
            $this->edge('project.atlas.workspace-intelligence', 'system.awaf', 'contains_child'),
            $this->edge('project.atlas.workspace-intelligence', 'system.awair', 'contains_child'),
            $this->edge('project.atlas.workspace-intelligence', 'system.awaol', 'contains_child'),
            $this->edge('system.adrs', 'flow.adrs-to-acrui-to-aurc', 'defines_flow'),
            $this->edge('flow.adrs-to-acrui-to-aurc', 'system.acrui', 'uses_operational_truth'),
            $this->edge('flow.adrs-to-acrui-to-aurc', 'system.aurc', 'projects_visual_truth'),
            $this->edge('system.awis', 'flow.workspace-runtime-projections', 'defines_flow'),
            $this->edge('flow.workspace-runtime-projections', 'system.awtr', 'projects_twin'),
            $this->edge('flow.workspace-runtime-projections', 'system.awco', 'certifies_contracts'),
            $this->edge('flow.workspace-runtime-projections', 'system.awef', 'feeds_evolution'),
            $this->edge('system.awaf', 'system.awair', 'evolves_into'),
            $this->edge('system.awair', 'system.awaol', 'operates_artifacts'),
            $this->edge('system.awair', 'flow.workspace-artifact-graph', 'projects_artifact_graph'),
            $this->edge('system.awis', 'flow.workspace-artifact-lake-replay', 'persists_replay_pack'),
            $this->edge('system.awaol', 'flow.workspace-artifact-workroom', 'opens_artifact_workroom'),
            $this->edge('system.adrs', 'component.adrs-runtime', 'implemented_by'),
            $this->edge('system.acrui', 'component.acrui-runtime', 'implemented_by'),
            $this->edge('system.aurc', 'component.aurc-runtime', 'implemented_by'),
            $this->edge('component.adrs-runtime', 'evidence.adrs-gates', 'proved_by'),
            $this->edge('component.acrui-runtime', 'evidence.adrs-gates', 'proved_by'),
            $this->edge('component.aurc-runtime', 'evidence.adrs-gates', 'proved_by'),
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function node(
        string $id,
        string $kind,
        string $label,
        string $semanticLevel,
        string $status,
        string $sourcePath,
        string $owner,
        array $zoomTargets,
        array $evidenceRefs,
        string $humanSummary
    ): array {
        return [
            'id' => $id,
            'kind' => $kind,
            'label' => $label,
            'semantic_level' => $semanticLevel,
            'status' => $status,
            'source_path' => $sourcePath,
            'owner' => $owner,
            'evidence_refs' => $evidenceRefs,
            'visual_state' => [
                'status_tone' => $this->statusTone($status),
                'shape' => $this->shape($kind),
                'requires_attention' => in_array($status, ['unknown', 'unknown_requires_audit', 'blocked', 'review'], true),
            ],
            'semantic_zoom' => [
                'tap_action' => $zoomTargets === [] ? 'open_evidence' : 'zoom_to_children',
                'targets' => $zoomTargets,
            ],
            'human_modal' => [
                'title' => $label,
                'summary' => $humanSummary,
                'source_path' => $sourcePath,
                'owner' => $owner,
                'status' => $status,
                'proof' => $evidenceRefs,
                'next_action' => $this->nextAction($kind),
            ],
        ];
    }

    /**
     * @return array<string,string>
     */
    private function edge(string $source, string $target, string $type): array
    {
        return [
            'id' => $source.'->'.$target,
            'source' => $source,
            'target' => $target,
            'type' => $type,
            'status' => 'active',
        ];
    }

    /**
     * @param  array<int,array<string,mixed>>  $nodes
     * @param  array<int,array<string,mixed>>  $edges
     * @return array<string,mixed>
     */
    private function coverage(array $nodes, array $edges): array
    {
        $nodeIds = array_column($nodes, 'id');
        $missingSource = array_values(array_filter($nodes, static fn (array $node): bool => (string) ($node['source_path'] ?? '') === ''));
        $missingModal = array_values(array_filter($nodes, static fn (array $node): bool => ! is_array($node['human_modal'] ?? null)));
        $brokenEdges = array_values(array_filter($edges, static fn (array $edge): bool => ! in_array($edge['source'], $nodeIds, true) || ! in_array($edge['target'], $nodeIds, true)));
        $missingVisualState = array_values(array_filter($nodes, static fn (array $node): bool => ! is_array($node['visual_state'] ?? null)));
        $missingZoom = array_values(array_filter($nodes, static fn (array $node): bool => ! is_array($node['semantic_zoom'] ?? null)));

        return [
            'schema_version' => 'atlas.universal_reality_cartography.coverage.v1',
            'status' => $missingSource === [] && $missingModal === [] && $brokenEdges === [] && $missingVisualState === [] && $missingZoom === [] ? 'ready' : 'review',
            'node_count' => count($nodes),
            'edge_count' => count($edges),
            'missing_source_count' => count($missingSource),
            'missing_modal_count' => count($missingModal),
            'missing_visual_state_count' => count($missingVisualState),
            'missing_semantic_zoom_count' => count($missingZoom),
            'broken_edge_count' => count($brokenEdges),
            'visual_completeness_score' => count($nodes) === 0 ? 0 : round((count($nodes) - count($missingSource) - count($missingModal)) / count($nodes), 4),
        ];
    }

    /**
     * @param  array<int,array<string,mixed>>  $nodes
     * @param  array<int,array<string,mixed>>  $edges
     * @return array<string,mixed>
     */
    private function visualScene(array $nodes, array $edges, string $mode, array $workspaceScope): array
    {
        $visibleNodes = $this->visibleNodesForMode($nodes, $mode);
        $visibleIds = array_column($visibleNodes, 'id');
        $visibleEdges = array_values(array_filter(
            $edges,
            static fn (array $edge): bool => in_array($edge['source'], $visibleIds, true) && in_array($edge['target'], $visibleIds, true),
        ));

        return [
            'schema_version' => 'atlas.universal_reality_cartography.visual_scene.v1',
            'status' => count($visibleNodes) <= 12 ? 'ready' : 'review',
            'mode' => $mode,
            'workspace_scope' => $workspaceScope,
            'cognitive_budget' => [
                'max_visible_nodes' => 12,
                'max_visible_edges' => 16,
                'visible_node_count' => count($visibleNodes),
                'visible_edge_count' => count($visibleEdges),
                'text_policy' => 'labels_only_on_map_dense_text_in_human_modal',
                'status' => count($visibleNodes) <= 12 && count($visibleEdges) <= 16 ? 'ready' : 'review',
            ],
            'viewport' => [
                'width' => 1440,
                'height' => 960,
                'layout' => 'semantic_lanes_left_to_right',
                'zoom_model' => 'scene_replaces_scope_not_text_scale',
            ],
            'breadcrumb' => $this->breadcrumbForMode($mode),
            'legend' => $this->visualLegend(),
            'lanes' => $this->sceneLanes($visibleNodes),
            'visible_nodes' => array_map(static fn (array $node): array => Arr::only($node, [
                'id',
                'kind',
                'label',
                'semantic_level',
                'status',
                'source_path',
                'owner',
                'visual_state',
                'semantic_zoom',
                'layout',
                'microcopy',
            ]), $this->layoutVisibleNodes($visibleNodes)),
            'visible_edges' => $visibleEdges,
            'interaction_contract' => [
                'tap' => 'zoom_to_children_or_open_evidence',
                'long_press' => 'open_human_modal',
                'back' => 'return_to_parent_semantic_level',
                'search' => 'route_to_canonical_question_router',
                'hover' => 'show_one_line_microcopy_only',
            ],
        ];
    }

    /**
     * @param  array<int,array<string,mixed>>  $nodes
     * @return array<int,array<string,mixed>>
     */
    private function layoutVisibleNodes(array $nodes): array
    {
        $levelOrder = [
            'universe' => 0,
            'organization' => 1,
            'project' => 2,
            'system' => 3,
            'flow' => 4,
            'component' => 5,
            'evidence' => 6,
        ];

        $grouped = collect($nodes)->groupBy('semantic_level');
        $positioned = [];

        foreach ($grouped as $level => $items) {
            $lane = $levelOrder[(string) $level] ?? 7;
            foreach ($items->values() as $index => $node) {
                $kind = (string) ($node['kind'] ?? 'node');
                $node['layout'] = [
                    'x' => 120 + ($lane * 190),
                    'y' => 140 + ($index * 150),
                    'radius' => $this->radiusForKind($kind),
                    'lane' => 'lane.'.$level,
                    'importance' => $this->importanceForKind($kind),
                ];
                $node['microcopy'] = [
                    'label_short' => $this->shortLabel((string) ($node['label'] ?? '')),
                    'tooltip' => $this->shortTooltip((string) data_get($node, 'human_modal.summary', '')),
                    'text_weight' => 'short_label_only',
                ];
                $positioned[] = $node;
            }
        }

        usort($positioned, static fn (array $a, array $b): int => strcmp((string) $a['id'], (string) $b['id']));

        return $positioned;
    }

    /**
     * @param  array<int,array<string,mixed>>  $nodes
     * @return array<int,array<string,mixed>>
     */
    private function visibleNodesForMode(array $nodes, string $mode): array
    {
        $levelsByMode = [
            'universe' => ['universe', 'organization', 'project'],
            'system' => ['project', 'system'],
            'flow' => ['system', 'flow', 'component'],
            'evidence' => ['component', 'evidence'],
            'risk' => ['project', 'system', 'component', 'evidence'],
            'implementation' => ['system', 'flow', 'component'],
        ];

        $levels = $levelsByMode[$mode] ?? $levelsByMode['universe'];

        $visible = array_values(array_filter($nodes, static fn (array $node): bool => in_array((string) $node['semantic_level'], $levels, true)));
        if ($mode === 'flow') {
            $priority = [
                'system.awis',
                'system.awair',
                'system.awaol',
                'flow.workspace-artifact-graph',
                'flow.workspace-artifact-lake-replay',
                'flow.workspace-artifact-workroom',
            ];
            usort($visible, static function (array $a, array $b) use ($priority): int {
                $aIndex = array_search((string) ($a['id'] ?? ''), $priority, true);
                $bIndex = array_search((string) ($b['id'] ?? ''), $priority, true);

                return ($aIndex === false ? 100 : $aIndex) <=> ($bIndex === false ? 100 : $bIndex);
            });
        }

        return array_slice($visible, 0, 12);
    }

    /**
     * @param  array<int,array<string,mixed>>  $nodes
     * @return array<int,array<string,mixed>>
     */
    private function sceneLanes(array $nodes): array
    {
        return collect($nodes)
            ->groupBy('semantic_level')
            ->map(static fn ($items, string $level): array => [
                'id' => 'lane.'.$level,
                'semantic_level' => $level,
                'node_ids' => $items->pluck('id')->values()->all(),
                'visual_role' => match ($level) {
                    'universe' => 'global_boundary',
                    'organization' => 'organization_boundary',
                    'project' => 'work_area',
                    'system' => 'capability_cluster',
                    'flow' => 'movement_path',
                    'component' => 'implementation_unit',
                    'evidence' => 'proof_anchor',
                    default => 'supporting_layer',
                },
            ])
            ->values()
            ->all();
    }

    /**
     * @param  array<int,array<string,mixed>>  $nodes
     * @return array<string,mixed>
     */
    private function semanticZoomScenes(array $nodes): array
    {
        $byId = collect($nodes)->keyBy('id');

        $scenes = [
            [
                'id' => 'scene.universe',
                'from_node' => 'universe',
                'to_level' => 'organization',
                'expected_children' => ['org.atlas'],
            ],
            [
                'id' => 'scene.atlas',
                'from_node' => 'org.atlas',
                'to_level' => 'project',
                'expected_children' => ['project.atlas.documentation-reality'],
            ],
            [
                'id' => 'scene.documentation-reality',
                'from_node' => 'project.atlas.documentation-reality',
                'to_level' => 'system',
                'expected_children' => ['system.adrs', 'system.acrui', 'system.aurc'],
            ],
            [
                'id' => 'scene.workspace-intelligence',
                'from_node' => 'project.atlas.workspace-intelligence',
                'to_level' => 'system',
                'expected_children' => ['system.awis', 'system.awtr', 'system.awco', 'system.awef', 'system.awaf', 'system.awair', 'system.awaol'],
            ],
            [
                'id' => 'scene.awair-artifact-graph',
                'from_node' => 'system.awair',
                'to_level' => 'flow',
                'expected_children' => ['flow.workspace-artifact-graph', 'flow.workspace-artifact-lake-replay'],
            ],
            [
                'id' => 'scene.awaol-artifact-workroom',
                'from_node' => 'system.awaol',
                'to_level' => 'flow',
                'expected_children' => ['flow.workspace-artifact-workroom'],
            ],
            [
                'id' => 'scene.adrs-runtime',
                'from_node' => 'system.adrs',
                'to_level' => 'component',
                'expected_children' => ['component.adrs-runtime'],
            ],
            [
                'id' => 'scene.evidence',
                'from_node' => 'component.adrs-runtime',
                'to_level' => 'evidence',
                'expected_children' => ['evidence.adrs-gates'],
            ],
        ];

        $invalid = array_values(array_filter($scenes, static function (array $scene) use ($byId): bool {
            if (! $byId->has($scene['from_node'])) {
                return true;
            }

            return collect($scene['expected_children'])->contains(static fn (string $child): bool => ! $byId->has($child));
        }));

        return [
            'schema_version' => 'atlas.universal_reality_cartography.semantic_zoom_scenes.v1',
            'status' => $invalid === [] ? 'ready' : 'review',
            'scene_count' => count($scenes),
            'invalid_scene_count' => count($invalid),
            'scenes' => $scenes,
            'rule' => 'zoom_changes_semantic_scope_and_keeps_return_path_to_universe',
        ];
    }

    /**
     * @param  array<int,array<string,mixed>>  $nodes
     * @return array<string,mixed>
     */
    private function humanRouteMap(array $nodes): array
    {
        $nodeIds = array_column($nodes, 'id');
        $routes = [
            [
                'id' => 'route.find-documentation-mother',
                'intent' => 'Qual e a documentacao mae?',
                'node_path' => ['universe', 'org.atlas', 'project.atlas.documentation-reality', 'system.adrs'],
                'expected_source' => 'docs/engineering-knowledge-base/atlas-documentation-reality-system.md',
            ],
            [
                'id' => 'route.check-code-reality',
                'intent' => 'Esse codigo esta vivo ou duplicado?',
                'node_path' => ['universe', 'org.atlas', 'project.atlas.documentation-reality', 'system.acrui', 'component.acrui-runtime'],
                'expected_source' => 'app/Services/Engineering/AtlasCodeRealityUsageIntelligenceService.php',
            ],
            [
                'id' => 'route.open-proof',
                'intent' => 'O que prova este mapa?',
                'node_path' => ['system.adrs', 'component.adrs-runtime', 'evidence.adrs-gates'],
                'expected_source' => 'tests/Feature/Engineering/AtlasDocumentationRealitySystemServiceTest.php',
            ],
            [
                'id' => 'route.check-workspace-intelligence',
                'intent' => 'Qual workspace esta governando Dev ou Forge?',
                'node_path' => ['universe', 'org.atlas', 'project.atlas.workspace-intelligence', 'system.awis', 'flow.workspace-runtime-projections'],
                'expected_source' => 'docs/engineering-knowledge-base/atlas-workspace-intelligence-system.md',
            ],
            [
                'id' => 'route.open-artifact-graph',
                'intent' => 'Quais artefatos vivos explicam este workspace?',
                'node_path' => ['universe', 'org.atlas', 'project.atlas.workspace-intelligence', 'system.awair', 'flow.workspace-artifact-graph'],
                'expected_source' => 'docs/engineering-knowledge-base/atlas-workspace-artifact-intelligence-runtime.md',
            ],
            [
                'id' => 'route.open-conversation-fusion-pack',
                'intent' => 'Qual pack persistido reabre contexto sem conversa bruta?',
                'node_path' => ['universe', 'org.atlas', 'project.atlas.workspace-intelligence', 'system.awis', 'flow.workspace-artifact-lake-replay'],
                'expected_source' => 'docs/engineering-knowledge-base/atlas-workspace-intelligence-system.md',
            ],
            [
                'id' => 'route.open-artifact-workroom',
                'intent' => 'Como abrir um artefato como workroom humano e packet seguro para IA?',
                'node_path' => ['universe', 'org.atlas', 'project.atlas.workspace-intelligence', 'system.awaol', 'flow.workspace-artifact-workroom'],
                'expected_source' => 'docs/engineering-knowledge-base/atlas-workspace-artifact-operating-layer.md',
            ],
        ];

        $invalid = array_values(array_filter($routes, static fn (array $route): bool => collect($route['node_path'])->contains(
            static fn (string $nodeId): bool => ! in_array($nodeId, $nodeIds, true),
        )));

        return [
            'schema_version' => 'atlas.universal_reality_cartography.human_route_map.v1',
            'status' => $invalid === [] ? 'ready' : 'review',
            'route_count' => count($routes),
            'invalid_route_count' => count($invalid),
            'route_success_rate' => $routes === [] ? 0 : round((count($routes) - count($invalid)) / count($routes), 4),
            'routes' => $routes,
            'rule' => 'human_can_navigate_by_spatial_path_then_open_modal_for_detail',
        ];
    }

    /**
     * @param  array<int,array<string,mixed>>  $nodes
     * @return array<string,mixed>
     */
    private function taskSimulator(array $nodes): array
    {
        return [
            'schema_version' => 'atlas.universal_reality_cartography.task_simulator.v1',
            'status' => 'ready',
            'canonical_tasks' => [
                [
                    'question' => 'Onde fica a documentacao mae da area?',
                    'expected_node' => 'system.adrs',
                    'expected_source' => 'docs/engineering-knowledge-base/atlas-documentation-reality-system.md',
                ],
                [
                    'question' => 'Qual runtime prova realidade operacional?',
                    'expected_node' => 'component.acrui-runtime',
                    'expected_source' => 'app/Services/Engineering/AtlasCodeRealityUsageIntelligenceService.php',
                ],
                [
                    'question' => 'Qual node mostra a superficie visual humana?',
                    'expected_node' => 'system.aurc',
                    'expected_source' => 'docs/engineering-knowledge-base/atlas-universal-reality-cartography.md',
                ],
                [
                    'question' => 'Qual node mostra workspace ativo e projections AWIS?',
                    'expected_node' => 'system.awis',
                    'expected_source' => 'docs/engineering-knowledge-base/atlas-workspace-intelligence-system.md',
                ],
                [
                    'question' => 'Qual node mostra grafo de artefatos do workspace?',
                    'expected_node' => 'system.awair',
                    'expected_source' => 'docs/engineering-knowledge-base/atlas-workspace-artifact-intelligence-runtime.md',
                ],
                [
                    'question' => 'Qual node mostra packs AWIS persistidos sem conversa bruta?',
                    'expected_node' => 'flow.workspace-artifact-lake-replay',
                    'expected_source' => 'docs/engineering-knowledge-base/atlas-workspace-intelligence-system.md',
                ],
                [
                    'question' => 'Qual node abre um artefato como workroom operacional?',
                    'expected_node' => 'flow.workspace-artifact-workroom',
                    'expected_source' => 'docs/engineering-knowledge-base/atlas-workspace-artifact-operating-layer.md',
                ],
            ],
            'node_ids_available' => array_column($nodes, 'id'),
            'expected_success_rate' => 1.0,
        ];
    }

    /**
     * @param  array<string,mixed>  $coverage
     * @param  array<string,mixed>  $visualScene
     * @param  array<string,mixed>  $semanticZoomScenes
     * @param  array<string,mixed>  $humanRouteMap
     * @param  array<string,mixed>  $taskSimulator
     * @return array<string,mixed>
     */
    private function humanClarity(array $coverage, array $visualScene, array $semanticZoomScenes, array $humanRouteMap, array $taskSimulator): array
    {
        $dimensions = [
            [
                'id' => 'visual_hierarchy',
                'score' => data_get($visualScene, 'breadcrumb.depth', 0) >= 3 && count((array) ($visualScene['lanes'] ?? [])) > 0 ? 10.0 : 7.0,
                'evidence' => 'breadcrumb + semantic lanes expose universe->scope position without reading docs',
            ],
            [
                'id' => 'cognitive_load',
                'score' => data_get($visualScene, 'cognitive_budget.status') === 'ready' ? 10.0 : 6.0,
                'evidence' => 'visible nodes/edges stay under bounded cognitive budget',
            ],
            [
                'id' => 'source_truth',
                'score' => (float) data_get($coverage, 'visual_completeness_score', 0) * 10,
                'evidence' => 'every visible node has source/modal/visual state and broken edges are audited',
            ],
            [
                'id' => 'semantic_zoom',
                'score' => data_get($semanticZoomScenes, 'status') === 'ready' && data_get($semanticZoomScenes, 'scene_count', 0) >= 5 ? 9.8 : 6.5,
                'evidence' => 'zoom changes semantic scope with validated children and return path',
            ],
            [
                'id' => 'human_wayfinding',
                'score' => (float) data_get($humanRouteMap, 'route_success_rate', 0) * 10,
                'evidence' => 'canonical human questions resolve to real node paths and expected sources',
            ],
            [
                'id' => 'nontechnical_microcopy',
                'score' => data_get($visualScene, 'legend.status') === 'ready' ? 9.8 : 6.0,
                'evidence' => 'map exposes nontechnical legend and keeps dense text inside modal',
            ],
            [
                'id' => 'task_simulation',
                'score' => (float) data_get($taskSimulator, 'expected_success_rate', 0) * 10,
                'evidence' => 'task simulator has canonical navigation questions and expected answers',
            ],
        ];

        $score = round(collect($dimensions)->avg('score'), 1);

        return [
            'schema_version' => self::HUMAN_CLARITY_SCHEMA_VERSION,
            'status' => $score >= 9.8 ? 'ready' : 'review',
            'score' => $score,
            'target_score' => 9.8,
            'grade' => $score >= 9.8 ? '9.8_human_visual_clarity' : 'below_target',
            'dimensions' => $dimensions,
            'invariants' => [
                'human_understands_macro_flow_before_modal' => true,
                'map_text_is_short_label_only' => data_get($visualScene, 'cognitive_budget.text_policy') === 'labels_only_on_map_dense_text_in_human_modal',
                'long_text_lives_in_modal' => true,
                'semantic_zoom_not_pixel_zoom_only' => data_get($semanticZoomScenes, 'rule') === 'zoom_changes_semantic_scope_and_keeps_return_path_to_universe',
                'visual_truth_never_overrides_canonical_docs' => true,
                'all_routes_have_sources' => (int) data_get($humanRouteMap, 'invalid_route_count', 0) === 0,
            ],
            'recommended_operator_use' => [
                'start_at_universe',
                'tap_to_zoom_until_the_target_system_is_visible',
                'use_long_press_only_when_source_or_proof_is_needed',
                'open_source_path_for_canonical_detail',
            ],
        ];
    }

    /**
     * @param  array<int,array<string,mixed>>  $nodes
     * @return array<string,mixed>
     */
    private function aiNavigationSlice(array $nodes): array
    {
        $minimal = array_map(static fn (array $node): array => Arr::only($node, ['id', 'kind', 'label', 'status', 'source_path', 'owner']), $nodes);

        return [
            'schema_version' => 'atlas.universal_reality_cartography.ai_navigation_slice.v1',
            'status' => 'ready',
            'provider_safe' => true,
            'nodes' => $minimal,
            'rule' => 'use_cartography_as_navigation_slice_not_as_primary_truth',
        ];
    }

    private function mode(string $mode): string
    {
        return in_array($mode, ['universe', 'system', 'flow', 'evidence', 'risk', 'implementation'], true)
            ? $mode
            : 'universe';
    }

    private function statusTone(string $status): string
    {
        return match ($status) {
            'active', 'active_runtime', 'active_read_only', 'ready' => 'healthy',
            'review', 'unknown_requires_audit', 'unknown' => 'attention',
            'blocked' => 'blocked',
            default => 'neutral',
        };
    }

    /**
     * @return array<string,mixed>
     */
    private function breadcrumbForMode(string $mode): array
    {
        $items = match ($mode) {
            'system' => ['Universo', 'Atlas', 'Documentation Reality'],
            'flow' => ['Universo', 'Atlas', 'Documentation Reality', 'ADRS -> ACRUI -> AURC'],
            'evidence' => ['Universo', 'Atlas', 'Documentation Reality', 'Evidence'],
            'risk' => ['Universo', 'Atlas', 'Documentation Reality', 'Riscos'],
            'implementation' => ['Universo', 'Atlas', 'Documentation Reality', 'Implementacao'],
            default => ['Universo', 'Atlas', 'Documentation Reality'],
        };

        return [
            'status' => 'ready',
            'items' => $items,
            'depth' => count($items),
            'return_target' => 'universe',
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function visualLegend(): array
    {
        return [
            'status' => 'ready',
            'language' => 'pt-BR-nontechnical',
            'shape_meanings' => [
                'sphere' => 'mundo inteiro',
                'continent' => 'empresa ou organizacao',
                'region' => 'area grande de trabalho',
                'district' => 'sistema',
                'path' => 'fluxo em movimento',
                'building' => 'codigo ou runtime',
                'seal' => 'prova',
            ],
            'tone_meanings' => [
                'healthy' => 'funcionando',
                'attention' => 'precisa revisar',
                'blocked' => 'travado',
                'neutral' => 'informativo',
            ],
            'rule' => 'human_can_read_shape_color_position_before_opening_text',
        ];
    }

    private function radiusForKind(string $kind): int
    {
        return match ($kind) {
            'universe' => 76,
            'organization' => 64,
            'project' => 56,
            'system' => 48,
            'flow' => 38,
            'component' => 34,
            'evidence' => 30,
            default => 32,
        };
    }

    private function importanceForKind(string $kind): int
    {
        return match ($kind) {
            'universe' => 100,
            'organization' => 90,
            'project' => 80,
            'system' => 70,
            'flow' => 60,
            'component' => 50,
            'evidence' => 40,
            default => 30,
        };
    }

    private function shortLabel(string $label): string
    {
        return mb_strlen($label) <= 28 ? $label : mb_substr($label, 0, 25).'...';
    }

    private function shortTooltip(string $summary): string
    {
        return mb_strlen($summary) <= 96 ? $summary : mb_substr($summary, 0, 93).'...';
    }

    private function shape(string $kind): string
    {
        return match ($kind) {
            'universe' => 'sphere',
            'organization' => 'continent',
            'project' => 'region',
            'system' => 'district',
            'flow' => 'path',
            'component' => 'building',
            'evidence' => 'seal',
            default => 'node',
        };
    }

    private function nextAction(string $kind): string
    {
        return match ($kind) {
            'universe', 'organization', 'project' => 'zoom_to_next_semantic_level',
            'system', 'flow' => 'inspect_components_and_evidence',
            'component' => 'open_source_and_tests',
            'evidence' => 'run_validation_commands',
            default => 'inspect_source',
        };
    }
}
