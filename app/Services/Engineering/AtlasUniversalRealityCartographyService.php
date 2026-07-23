<?php

declare(strict_types=1);

namespace App\Services\Engineering;

use App\Services\Ai\WorkspaceIntelligence\AtlasWorkspaceArtifactIntelligenceRepository;
use App\Services\Ai\WorkspaceIntelligence\AtlasWorkspaceArtifactWorkroomService;
use App\Services\Ai\WorkspaceIntelligence\AtlasWorkspaceIntelligenceRuntimeService;
use App\Services\Ai\WorkspaceIntelligence\AtlasWorkspaceRuntimeProjectionRepository;
use App\Services\Ai\Support\DatabaseTableAvailability;
use App\Services\Engineering\UniversalRealityCartography\CartographyVisualSceneSection;
use App\Services\Engineering\UniversalRealityCartography\CartographyWorkspaceScopeSection;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;

final class AtlasUniversalRealityCartographyService
{
    public const SCHEMA_VERSION = 'atlas.universal_reality_cartography.v1';

    public const HUMAN_CLARITY_SCHEMA_VERSION = 'atlas.universal_reality_cartography.human_clarity.v1';

    // Method-family sections split out of this façade (GOD-DEBULK). Built from the same
    // injected service instances the façade received, so behavior (and test mocks) are
    // identical whether the façade is container-resolved or manually constructed.
    private readonly CartographyWorkspaceScopeSection $workspaceScopeSection;

    private readonly CartographyVisualSceneSection $visualSection;

    public function __construct(
        private readonly AtlasDocumentationRealitySystemService $documentationReality,
        private readonly AtlasCodeRealityUsageIntelligenceService $codeReality,
        private readonly AtlasWorkspaceIntelligenceRuntimeService $workspaceIntelligence,
        private readonly AtlasWorkspaceArtifactIntelligenceRepository $artifactIntelligence,
        private readonly AtlasWorkspaceArtifactWorkroomService $artifactWorkroom,
        private readonly AtlasWorkspaceRuntimeProjectionRepository $runtimeProjections,
        // The COMPLETE real system structure (areas -> subsystems -> leaves +
        // dependency/containment edges) is DERIVED at runtime from the live code
        // index by AtlasSystemStructureService, NOT hand-authored here. AURC embeds
        // it verbatim as the canonical structure a fresh AI reconstructs from, while
        // the curated nodes()/edges() below stay a small human ENTRYPOINT projection.
        // Trailing + nullable so every existing caller (and constructor-injection
        // site) keeps working; resolved from the container when absent.
        private readonly ?AtlasSystemStructureService $systemStructure = null,
    ) {
        $this->workspaceScopeSection = new CartographyWorkspaceScopeSection(
            $workspaceIntelligence,
            $artifactWorkroom,
            $runtimeProjections,
            $artifactIntelligence,
        );
        $this->visualSection = new CartographyVisualSceneSection();
    }

    /**
     * @return array<string,mixed>
     */
    public function map(string $mode = 'universe', ?string $workspace = null): array
    {
        $adrs = $this->documentationReality->report();
        $acrui = $this->codeReality->classify('app/Services/Engineering/AtlasCodeRealityUsageIntelligenceService.php');
        $workspaceScope = $this->workspaceScopeSection->build($workspace);
        $nodes = $this->nodes($adrs, $acrui, $workspaceScope);
        // ONE bounded pass that adds an HONEST, RESOLVED maturity badge to each of the
        // ~23 curated nodes — composed only from signals already resolved+cached above
        // ($adrs, $acrui) plus a tiny in-pass classify memo over the <=3 distinct CODE
        // targets the curated nodes name. It iterates count($nodes) (==23) times, NEVER
        // the 737-node complete_derived_structure and NEVER the code index, so it cannot
        // re-create the 2026-06-02 per-node-classify outage. The badge is cache-wrapped
        // on (adrs.certification_hash + codeIndexSignature + workspace_hash) so an
        // unchanged world serves the whole badge layer from a single cache read.
        $nodes = $this->decorateBadges($nodes, $adrs, $acrui, $workspaceScope);
        $edges = $this->edges();
        $coverage = $this->visualSection->coverage($nodes, $edges);
        $mode = $this->mode($mode);
        $visualScene = $this->visualSection->visualScene($nodes, $edges, $mode, $workspaceScope);
        $semanticZoomScenes = $this->visualSection->semanticZoomScenes($nodes);
        $humanRouteMap = $this->visualSection->humanRouteMap($nodes);
        $taskSimulator = $this->visualSection->taskSimulator($nodes);
        $aiNavigationSlice = $this->visualSection->aiNavigationSlice($nodes);
        $humanClarity = $this->visualSection->humanClarity($coverage, $visualScene, $semanticZoomScenes, $humanRouteMap, $taskSimulator);

        // The COMPLETE structure (the canonical truth a fresh AI reconstructs the
        // whole system from) is DERIVED live from the code index — never the 23-node
        // curated map below. It is a separate, un-scored, separately-keyed layer so
        // it cannot leak into the bounded-cognition visual scene / clarity scoring.
        $completeStructure = $this->completeDerivedStructure();
        $completeSummary = is_array($completeStructure['summary'] ?? null) ? $completeStructure['summary'] : [];

        $payload = [
            'schema_version' => self::SCHEMA_VERSION,
            'status' => $coverage['missing_source_count'] === 0 && $coverage['missing_modal_count'] === 0 ? 'ready' : 'review',
            'mode' => $mode,
            'workspace_scope' => $workspaceScope,
            'summary' => [
                // node_count/edge_count below describe the CURATED macro projection
                // only (the small human entrypoint). The COMPLETE real structure is
                // carried by complete_node_count/complete_edge_count, sourced from the
                // derived layer, so downstream consumers (ADRS runtime_evidence) read
                // the real ~737/~1430 instead of mistaking 23/31 for "the structure".
                'node_count' => count($nodes),
                'edge_count' => count($edges),
                'curated_node_count' => count($nodes),
                'curated_edge_count' => count($edges),
                'complete_node_count' => $completeSummary['node_count'] ?? null,
                'complete_edge_count' => $completeSummary['edge_count'] ?? null,
                'complete_service_count' => $completeSummary['service_count'] ?? null,
                'complete_command_count' => $completeSummary['command_count'] ?? null,
                'complete_structure_available' => ($completeStructure['available'] ?? false) === true,
                'complete_structure_source' => $completeStructure['source'] ?? null,
                'semantic_levels' => ['universe', 'organization', 'project', 'system', 'flow', 'component', 'evidence'],
                'visual_first_contract' => 'human_should_understand_macro_flow_from_nodes_edges_state_before_reading_modal',
                'human_clarity_target_score' => 9.8,
                // Honest maturity split of the curated nodes (resolved, not declared):
                // how many of the ~23 actually badge real/live vs the honest downgrades.
                'badge_summary' => $this->badgeSummary($nodes),
            ],
            'curated_macro_projection' => [
                'schema_version' => 'atlas.universal_reality_cartography.curated_macro_projection.v1',
                'layer' => 'curated_macro_projection',
                'is_complete_structure' => false,
                'is_human_entrypoint' => true,
                'node_count' => count($nodes),
                'edge_count' => count($edges),
                'description' => 'curated_macro_human_entrypoint_not_the_complete_structure_see_complete_derived_structure',
                'complete_structure_key' => 'complete_derived_structure',
            ],
            'nodes' => $nodes,
            'edges' => $edges,
            // The complete, auto-derived structure. `complete_derived_structure` is the
            // checklist/report key; `system_structure` is the verbatim C1 reconstruction
            // surface (a fresh AI reads THIS one object, no source access, to rebuild the
            // full areas -> subsystems -> leaves + edges + summary). Both point at the
            // same derived payload so they can never disagree.
            'complete_derived_structure' => $completeStructure,
            'system_structure' => $completeStructure,
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
                // ANTI-OVER-CLAIM contract for the curated node badges: a node may read
                // as real/live ONLY when its code-reality classification is live AND its
                // evidence_refs resolve. Every honest downgrade (declared/spec/scaffold/
                // unproven/unknown) is derived from those same real signals, never a
                // stored label. A beautiful-but-false map is worse than no map.
                'no_node_presented_as_real_without_resolved_evidence' => true,
                'node_badge_maturity_is_resolved_from_signals_not_hardcoded' => true,
                'real_requires_active_runtime_code_reality_and_resolved_evidence' => true,
                'live_requires_active_read_only_or_headless_code_reality_and_resolved_evidence' => true,
                'node_without_resolved_evidence_defaults_unproven_never_real' => true,
            ],
            'writes' => false,
        ];

        $payload['cartography_hash'] = hash('sha256', json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));

        return $payload;
    }

    /**
     * Cached entry point for the complete derived structure. The derivation
     * (deriveCompleteStructure -> AtlasSystemStructureService::deriveStructure('auto'))
     * scans the full code index (atlas_engineering_code_symbols, ~134k rows) +
     * filesystem on every call, and the HTTP cartography surface renders map() once
     * per request — so without this cache every request re-ran the full scan and could
     * exceed php-fpm's 30s budget. Cache keyed on a cheap index SIGNATURE (count + max
     * id + max updated_at): a real index change moves the signature -> recompute (the
     * derived layer still tracks the index, and the fail-on-stub test that inserts /
     * deletes a symbol still observes the change), while repeated renders of an
     * unchanged index are served from cache.
     *
     * @return array<string,mixed>
     */
    private function completeDerivedStructure(): array
    {
        $signature = $this->codeIndexSignature();
        // No index table (degraded env): the derive call is cheap (it returns the
        // short unavailable shape) — don't cache a transient degrade.
        if ($signature === null) {
            return $this->deriveCompleteStructure();
        }

        $ttl = (int) config('atlas_vault.structure_cache_seconds', 120);
        if ($ttl <= 0) {
            return $this->deriveCompleteStructure();
        }

        return Cache::remember(
            'aurc:complete-structure:'.$signature,
            $ttl,
            fn (): array => $this->deriveCompleteStructure(),
        );
    }

    /**
     * Cheap fingerprint of the live code index so the structure cache invalidates the
     * instant the index actually changes (insert / delete / in-place update) instead
     * of a blind TTL. Returns null when the table is absent so the caller skips
     * caching and lets the derivation report its own honest degrade.
     */
    private function codeIndexSignature(): ?string
    {
        try {
            if (! DatabaseTableAvailability::has('atlas_engineering_code_symbols')) {
                return null;
            }
            $row = DB::table('atlas_engineering_code_symbols')
                ->selectRaw('COUNT(*) AS c, MAX(id) AS mi, MAX(updated_at) AS mu')
                ->first();

            return ((string) ($row->c ?? '0'))
                .':'.((string) ($row->mi ?? '0'))
                .':'.((string) ($row->mu ?? '0'));
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * The COMPLETE Atlas system structure, DERIVED at runtime from the live code
     * index (atlas_engineering_code_symbols + filesystem fallback) via
     * AtlasSystemStructureService::deriveStructure('auto'). This is the canonical
     * structure layer a fresh AI reconstructs the whole system from — areas ->
     * subsystems -> service/command leaves + containment/dependency edges + the full
     * computed summary cardinalities — and it is emitted VERBATIM (no hand-authored
     * node list) so inserting/removing a symbol in the index changes this layer.
     *
     * Honest degrade is MIRRORED, never upgraded: when deriveStructure reports
     * available=false (index AND filesystem both gone) this returns the same short
     * unavailable shape with its reason; when it falls back to the filesystem source
     * (empty index) this surfaces source='filesystem' with zero dependency edges. We
     * only ANNOTATE provenance (layer + reconstruction claim); we never fabricate a
     * structure the service did not derive.
     *
     * @return array<string,mixed>
     */
    private function deriveCompleteStructure(): array
    {
        $service = $this->systemStructure ?? app(AtlasSystemStructureService::class);
        $structure = $service->deriveStructure('auto');

        // Mirror an honest degrade verbatim — no summary/nodes/edges exist on the
        // short unavailable payload, so never index them and never invent placeholders.
        if (($structure['available'] ?? false) !== true) {
            return [
                'schema_version' => $structure['schema_version'] ?? AtlasSystemStructureService::SCHEMA_VERSION,
                'available' => false,
                'source' => $structure['source'] ?? 'unavailable',
                'status' => 'degraded',
                'reason' => $structure['reason'] ?? 'code_index_and_filesystem_both_unavailable',
                'summary' => [],
                'nodes' => [],
                'edges' => [],
                'layer' => 'complete_derived_structure',
                'claim_policy' => [
                    'structure_is_derived_not_authored' => true,
                    'is_curated_macro_projection' => false,
                    'is_complete_structure' => true,
                    'degraded' => true,
                    'data_source' => $structure['source'] ?? 'unavailable',
                ],
                'reconstruction' => [
                    'is_complete_reconstruction_layer' => true,
                    'ground_truth_command' => 'php artisan atlas:system-structure --json',
                    'note' => 'derived layer is degraded; ground truth is also degraded in this environment',
                ],
                'writes' => false,
            ];
        }

        // Available: pass the derived structure through verbatim and annotate
        // provenance so the layer is honestly labelled the COMPLETE structure (not
        // the curated macro projection) and points a fresh AI at how it was derived.
        $structure['layer'] = 'complete_derived_structure';
        $structure['status'] = $structure['status'] ?? 'ready';
        $structure['claim_policy'] = array_merge(
            is_array($structure['claim_policy'] ?? null) ? $structure['claim_policy'] : [],
            [
                'is_curated_macro_projection' => false,
                'is_complete_structure' => true,
            ],
        );
        $structure['reconstruction'] = [
            'is_complete_reconstruction_layer' => true,
            'is_curated_macro_projection' => false,
            'ground_truth_command' => 'php artisan atlas:system-structure --json',
            'reconstruct_from' => 'this object: nodes[] + edges[] + summary{} fully recover areas -> subsystems -> emitted leaves + containment/dependency edges with no source access',
            'emission_note' => 'leaf nodes are capped at MAX_EMITTED_LEAVES; full leaf totals are carried by summary.service_count/command_count/total_leaf_count',
        ];

        return $structure;
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
     * Add an HONEST, RESOLVED maturity badge to each curated node in ONE bounded pass.
     *
     * Hermes's core point: a beautiful but epistemically false cartography is WORSE than
     * none — it gives the human false confidence. So a node may carry a real/live badge
     * ONLY when its code-reality classification is live AND its evidence_refs resolve;
     * otherwise it is badged honestly (declared/spec/scaffold/unproven/...). The maturity
     * is a PURE FUNCTION of (code_reality bucket, evidence_resolved, ADRS execution) —
     * never a stored per-node label — so flipping any one input flips the badge, which is
     * exactly what the fail-on-stub test proves.
     *
     * PERF (the load-bearing guard — this is the AURC outage surface): every heavy signal
     * is READ, never recomputed. $adrs is the already-cached report() from map() line 44;
     * $acrui is the single classify() from line 45. For the curated CODE targets we call
     * codeReality->classify($path) at most ONCE per distinct path (in-pass $classifyMemo),
     * which is <=3 distinct paths total (the three runtime services), NOT O(737). The whole
     * decorated set is cache-wrapped on (adrs.certification_hash + codeIndexSignature +
     * workspace_hash): on an unchanged world the badge layer is a single cache read; the
     * classify calls only run when a real doc edit / real index change / workspace switch
     * moves a signature — exactly when the badges SHOULD recompute. The 737-node
     * complete_derived_structure / deriveStructure('auto') is NOT touched or consulted.
     *
     * @param  array<int,array<string,mixed>>  $nodes
     * @param  array<string,mixed>  $adrs
     * @param  array<string,mixed>  $acrui
     * @param  array<string,mixed>  $workspaceScope
     * @return array<int,array<string,mixed>>
     */
    private function decorateBadges(array $nodes, array $adrs, array $acrui, array $workspaceScope): array
    {
        $ttl = (int) config('atlas_vault.structure_cache_seconds', 120);
        $signature = $this->codeIndexSignature();
        $cacheKey = $this->badgeCacheKey($adrs, $workspaceScope, $signature);

        // Cache the whole decorated badge SET, not per node. Skip the cache only when the
        // env is genuinely degraded (no index signature) or caching is disabled — there
        // the bounded pass is cheap anyway and we never want to pin a transient degrade.
        if ($cacheKey === null || $ttl <= 0) {
            return $this->resolveDecoratedBadges($nodes, $adrs, $acrui);
        }

        return Cache::remember(
            'aurc:badges:'.$cacheKey,
            $ttl,
            fn (): array => $this->resolveDecoratedBadges($nodes, $adrs, $acrui),
        );
    }

    /**
     * The actual bounded decoration (un-cached inner). Iterates exactly count($nodes)
     * (==23) times and memoizes classify() per distinct curated CODE target so the same
     * path is classified at most once. NEVER iterates complete_derived_structure.
     *
     * @param  array<int,array<string,mixed>>  $nodes
     * @param  array<string,mixed>  $adrs
     * @param  array<string,mixed>  $acrui
     * @return array<int,array<string,mixed>>
     */
    private function resolveDecoratedBadges(array $nodes, array $adrs, array $acrui): array
    {
        $adrsSignalsById = $this->adrsSignalIndex($adrs);
        $lastCheck = (string) ($adrs['generated_at'] ?? now()->toJSON());
        $drift = $this->driftSignal($adrs);
        // The whole-report doc paths, resolved ONCE from the already-loaded ADRS
        // source_registry — a node's docs/*.md evidence ref resolves against this set
        // without a new file read.
        $registryPaths = collect(is_array($adrs['source_registry'] ?? null) ? $adrs['source_registry'] : [])
            ->filter(static fn (mixed $entry): bool => is_array($entry) && ($entry['exists'] ?? false) === true)
            ->map(static fn (array $entry): string => (string) ($entry['path'] ?? ''))
            ->filter(static fn (string $path): bool => $path !== '')
            ->values()
            ->all();

        // In-pass classify memo: the SAME classify result is reused for any node whose
        // source_path is the same code target. Seed it with the one classify map() already
        // ran (line 45) so we never re-classify ACRUI's own path. Bound: <=3 distinct app/
        // paths across the curated set (the three runtime services), so <=2 added calls.
        $classifyMemo = [];
        $acruiPath = (string) ($acrui['target_path'] ?? 'app/Services/Engineering/AtlasCodeRealityUsageIntelligenceService.php');
        if ($acruiPath !== '') {
            $classifyMemo[$acruiPath] = $acrui;
        }

        foreach ($nodes as $index => $node) {
            $nodes[$index]['badge'] = $this->buildBadge($node, $adrsSignalsById, $classifyMemo, $drift, $lastCheck, $registryPaths);
        }

        return $nodes;
    }

    /**
     * Resolve ONE node's honest badge from real signals.
     *
     * @param  array<string,mixed>  $node
     * @param  array<string,array<string,mixed>>  $adrsSignalsById
     * @param  array<string,array<string,mixed>>  $classifyMemo  passed by reference so a
     *                                                           classify of a curated code target is
     *                                                           memoized for the whole pass
     * @param  array<string,mixed>  $drift
     * @param  array<int,string>  $registryPaths
     * @return array<string,mixed>
     */
    private function buildBadge(array $node, array $adrsSignalsById, array &$classifyMemo, array $drift, string $lastCheck, array $registryPaths): array
    {
        $id = (string) ($node['id'] ?? '');
        $owner = (string) ($node['owner'] ?? 'unknown');
        $sourcePath = (string) ($node['source_path'] ?? '');
        $evidenceRefs = is_array($node['evidence_refs'] ?? null) ? $node['evidence_refs'] : [];
        $adrsSignals = $adrsSignalsById[$id] ?? [];

        // (1) code_reality — classify ONLY when this node's source_path is a real CODE
        // target (app/...). Doc/spec nodes (universe/org/project/flow/evidence) are
        // 'not_applicable' and never trigger a classify, keeping the bound at <=3.
        $codeReality = $this->codeRealityBucketForNode($sourcePath, $classifyMemo);

        // (2) evidence_resolved — bounded, stat/index-presence only resolution of THIS
        // node's existing evidence_refs[] (<=4 refs), reusing the classify memo + the
        // ADRS source_registry. Flipping one ref flips the rollup.
        $refResolution = $this->resolveEvidenceRefs($evidenceRefs, $classifyMemo, $registryPaths);
        $evidenceResolved = $refResolution['resolved'];

        // (3) maturity — the single gate. Pure function of (bucket, evidence_resolved,
        // adrs execution). No hardcoded-label path can bypass it.
        $adrsExecution = isset($adrsSignals['execution']) ? (string) $adrsSignals['execution'] : null;
        $adrsBlockStatus = isset($adrsSignals['block_status']) ? (string) $adrsSignals['block_status'] : null;
        $maturity = $this->resolveBadgeMaturity(
            $codeReality,
            $evidenceResolved,
            $adrsExecution,
            $adrsBlockStatus,
            $sourcePath,
            $evidenceRefs,
            $adrsSignals,
        );

        // Drift is surfaced ONLY on nodes whose governance area is the documentation-
        // reality corpus the Drift & Duplication Guard actually measures — never smeared
        // across unrelated (e.g. workspace) nodes.
        $driftScoped = in_array($id, $this->driftScopedNodeIds(), true);
        $driftCount = (int) ($drift['count'] ?? 0);

        return [
            'maturity' => $maturity,
            'tone' => $this->badgeTone($maturity),
            'evidence_resolved' => $evidenceResolved,
            'code_reality' => $codeReality,
            'owner' => $owner,
            'drift' => [
                'detected' => $driftScoped && $driftCount > 0,
                'count' => $driftScoped ? $driftCount : 0,
                'source' => 'adrs.drift_duplication_guard',
            ],
            'last_check' => $lastCheck,
            'evidence_refs_resolved' => $refResolution['refs'],
            'signal_basis' => $this->signalBasis($codeReality, $adrsExecution, $sourcePath),
        ];
    }

    /**
     * THE GATE. A node badges 'real' IFF code-reality is live (active_runtime) AND its
     * evidence resolves; 'live' IFF code-reality is active_read_only/headless_available
     * AND evidence resolves. Otherwise the HONEST downgrade is computed strictly from the
     * signals — never a stored label. Invariants (a)-(c) from the design are enforced
     * here as code so a declared/scaffold/no-evidence node can never read as real.
     *
     * @param  array<int,string>  $evidenceRefs
     * @param  array<string,mixed>  $adrsSignals
     */
    private function resolveBadgeMaturity(
        string $codeReality,
        bool $evidenceResolved,
        ?string $adrsExecution,
        ?string $adrsBlockStatus,
        string $sourcePath,
        array $evidenceRefs,
        array $adrsSignals,
    ): string {
        // (c) ADRS declared/spec ALWAYS dominates — a stub class that happens to exist
        // can never lift a declared block to real (mirrors ADRS's own honesty stamp:
        // execution=='declared' forces status='spec').
        if ($adrsExecution === 'declared' || $adrsBlockStatus === 'spec') {
            return 'declared';
        }

        // Signals genuinely unavailable (degraded index/corpus) -> honest "can't tell".
        if ($codeReality === 'unknown_requires_audit' && $adrsExecution === null && $evidenceRefs === []) {
            return 'unknown';
        }

        // (a)+(b) real/live require BOTH live code-reality AND resolved evidence.
        if ($evidenceResolved && $codeReality === 'active_runtime') {
            return 'real';
        }
        if ($evidenceResolved && in_array($codeReality, ['active_read_only', 'headless_available'], true)) {
            return 'live';
        }

        // ADRS partial (real but narrow) -> 'partial'.
        if ($adrsExecution === 'partial') {
            return 'partial';
        }

        // Code exists but is an unused candidate (structure present, behavior unproven).
        if ($codeReality === 'unused_candidate') {
            return 'scaffold';
        }

        // Evidence does not resolve, or classify is unknown -> 'unproven' (the DEFAULT
        // for a node added with no resolving evidence; rule (a)).
        if (! $evidenceResolved || $codeReality === 'unknown_requires_audit') {
            return 'unproven';
        }

        // A doc/spec node (code_reality not_applicable) whose evidence resolves and whose
        // ADRS area executes read-only -> honestly 'live' (the doc-backed area runs,
        // read-only). With no ADRS execution signal at all it is an honest 'spec' (the
        // flow is DEFINED, not proven to run) rather than a silent 'real'.
        if ($codeReality === 'not_applicable') {
            if ($adrsExecution === 'executes' && $evidenceResolved) {
                return 'live';
            }

            return $evidenceResolved ? 'spec' : 'unproven';
        }

        // Fallthrough is conservative, never optimistic.
        return 'unproven';
    }

    /**
     * Classify a node's PRIMARY code target — but ONLY when its source_path is a real
     * code file (app/...). Doc/spec source paths return 'not_applicable' WITHOUT a
     * classify call, which is what keeps the pass bounded to <=3 distinct classify calls.
     * Results are memoized per distinct path for the whole decoration pass.
     *
     * @param  array<string,array<string,mixed>>  $classifyMemo
     */
    private function codeRealityBucketForNode(string $sourcePath, array &$classifyMemo): string
    {
        if ($sourcePath === '' || ! str_starts_with($sourcePath, 'app/') || ! str_ends_with($sourcePath, '.php')) {
            return 'not_applicable';
        }

        if (! array_key_exists($sourcePath, $classifyMemo)) {
            $classifyMemo[$sourcePath] = $this->codeReality->classify($sourcePath);
        }

        $bucket = (string) ($classifyMemo[$sourcePath]['classification'] ?? 'unknown_requires_audit');

        return in_array($bucket, [
            'active_runtime',
            'active_read_only',
            'headless_available',
            'unused_candidate',
            'unknown_requires_audit',
        ], true) ? $bucket : 'unknown_requires_audit';
    }

    /**
     * Bounded resolver over a node's existing evidence_refs[] (<=4 refs/node). Resolution
     * is filesystem-stat / in-memory lookup ONLY — never a DB query per ref and never a
     * glob/recursive walk — and reuses signals already computed: a *Test.php ref ->
     * File::exists; a 'php artisan ...' ref -> command-class existence (cheap); a service
     * class ref -> reuse the classify memo; a docs/*.md ref -> the ADRS source_registry.
     * evidence_resolved is the AND-rollup, so making any single ref unresolvable flips it.
     *
     * @param  array<int,string>  $evidenceRefs
     * @param  array<string,array<string,mixed>>  $classifyMemo  read-only here (only the
     *                                                           classify map() already populated is
     *                                                           consulted; never mutated during ref
     *                                                           resolution) so it is passed by value
     * @param  array<int,string>  $registryPaths
     * @return array{resolved:bool,refs:array<int,array<string,mixed>>}
     */
    private function resolveEvidenceRefs(array $evidenceRefs, array $classifyMemo, array $registryPaths): array
    {
        // A node with NO evidence_refs cannot resolve -> defaults to unproven (rule a).
        if ($evidenceRefs === []) {
            return ['resolved' => false, 'refs' => []];
        }

        $resolvedRefs = [];
        $allResolved = true;

        foreach ($evidenceRefs as $ref) {
            $ref = (string) $ref;
            [$kind, $resolved] = $this->resolveSingleEvidenceRef($ref, $classifyMemo, $registryPaths);
            $resolvedRefs[] = ['ref' => $ref, 'resolved' => $resolved, 'kind' => $kind];
            if (! $resolved) {
                $allResolved = false;
            }
        }

        return ['resolved' => $allResolved, 'refs' => $resolvedRefs];
    }

    /**
     * Resolve ONE evidence ref by its shape. O(1): File::exists on the explicit path,
     * class_exists for a command, classify-memo / file-stat for a service, or a registry
     * lookup for a doc. No globbing, no per-ref DB query.
     *
     * @param  array<string,array<string,mixed>>  $classifyMemo  read-only (by value)
     * @param  array<int,string>  $registryPaths
     * @return array{0:string,1:bool}
     */
    private function resolveSingleEvidenceRef(string $ref, array $classifyMemo, array $registryPaths): array
    {
        $ref = trim($ref);
        if ($ref === '') {
            return ['unknown', false];
        }

        // A 'php artisan <name> ...' command ref -> the command is registered (cheap; the
        // Artisan registry is already booted). Resolves when a matching command exists.
        if (str_starts_with($ref, 'php artisan ')) {
            return ['command', $this->commandRefResolves($ref)];
        }

        // A test file ref -> direct File::exists on the explicit path (a single stat).
        if (str_ends_with($ref, 'Test.php')) {
            return ['test', File::exists(base_path($ref))];
        }

        // A docs/*.md ref -> resolve against the ADRS source_registry first (already in
        // memory), falling back to a single File::exists stat for non-registry docs.
        if (str_ends_with($ref, '.md')) {
            $resolved = in_array($ref, $registryPaths, true) || File::exists(base_path($ref));

            return ['doc', $resolved];
        }

        // A concrete service/class file path -> reuse the classify memo when present
        // (no new classify), else a single File::exists stat.
        if (str_starts_with($ref, 'app/') && str_ends_with($ref, '.php')) {
            $resolved = array_key_exists($ref, $classifyMemo)
                ? (bool) ($classifyMemo[$ref]['exists'] ?? File::exists(base_path($ref)))
                : File::exists(base_path($ref));

            return ['service', $resolved];
        }

        // A bare table/schema/identifier ref (e.g. 'atlas_workspace_artifacts',
        // 'artifact_hash', a schema version) is a declared marker, not a filesystem
        // artifact — it is honestly UNRESOLVED here (so such nodes never read as real on
        // a marker alone; they need a resolving test/service/doc/command ref to qualify).
        return ['declared_marker', false];
    }

    /**
     * Does a 'php artisan <name> ...' ref map to a registered command? Cheap: reads the
     * already-booted Artisan registry, no command is executed. The signature suffix
     * (flags/args) is ignored — only the command NAME is matched.
     */
    private function commandRefResolves(string $ref): bool
    {
        $rest = trim(substr($ref, strlen('php artisan ')));
        if ($rest === '') {
            return false;
        }
        $name = explode(' ', $rest)[0];
        if ($name === '') {
            return false;
        }

        try {
            return array_key_exists($name, app(Kernel::class)->all());
        } catch (\Throwable) {
            return false;
        }
    }

    /**
     * Map each curated node id to its ADRS signal slice (execution / block_status /
     * readiness_level / runtime_status) by a small STATIC id->adrs-key table. Read from
     * the already-cached $adrs — no new report() call. Only the handful of nodes that
     * correspond to a real ADRS block/evaluation get a slice; the rest derive from
     * code-reality + evidence alone.
     *
     * @param  array<string,mixed>  $adrs
     * @return array<string,array<string,mixed>>
     */
    private function adrsSignalIndex(array $adrs): array
    {
        $status = (string) ($adrs['status'] ?? 'unknown');
        $summary = is_array($adrs['summary'] ?? null) ? $adrs['summary'] : [];
        $evaluations = is_array($adrs['evaluations'] ?? null) ? $adrs['evaluations'] : [];
        $executingCount = (int) ($summary['executing_block_count'] ?? 0);

        // The whole-report execution stance: the ADRS runtime itself executes read-only
        // when the report is ready AND at least one block executes. This is the signal the
        // ADRS-area nodes (system.adrs / project.atlas.documentation-reality) inherit.
        $reportExecutes = $status === 'ready' && $executingCount > 0;
        $reportExecution = $reportExecutes ? 'executes' : ($status === 'blocked' ? 'declared' : 'partial');

        $acruiEval = is_array($evaluations['acrui_operational_reality'] ?? null) ? $evaluations['acrui_operational_reality'] : [];
        $aurcEval = is_array($evaluations['aurc_visual_reality'] ?? null) ? $evaluations['aurc_visual_reality'] : [];

        $index = [];

        // ADRS-area nodes inherit the whole-report stance.
        $index['system.adrs'] = [
            'execution' => $reportExecution,
            'block_status' => $reportExecutes ? 'integrated' : 'spec',
            'source' => 'adrs_status',
        ];
        $index['project.atlas.documentation-reality'] = $index['system.adrs'];
        $index['component.adrs-runtime'] = $index['system.adrs'];

        // ACRUI / AURC system + component nodes inherit their own ADRS evaluation status.
        $index['system.acrui'] = [
            'execution' => ($acruiEval['status'] ?? null) === 'ready' ? 'executes' : 'partial',
            'block_status' => (string) ($acruiEval['status'] ?? 'missing'),
            'source' => 'adrs_block',
        ];
        $index['component.acrui-runtime'] = $index['system.acrui'];
        $index['system.aurc'] = [
            'execution' => ($aurcEval['status'] ?? null) === 'ready' ? 'executes' : 'partial',
            'block_status' => (string) ($aurcEval['status'] ?? 'missing'),
            'source' => 'adrs_block',
        ];
        $index['component.aurc-runtime'] = $index['system.aurc'];

        // The ADRS evidence/gates node: read-only proof anchor, executes when the report
        // is ready (its gate commands run).
        $index['evidence.adrs-gates'] = [
            'execution' => $status === 'ready' ? 'executes' : 'partial',
            'block_status' => $status === 'ready' ? 'integrated' : 'spec',
            'source' => 'adrs_status',
        ];

        return $index;
    }

    /**
     * Real doc-vs-code drift from the ADRS Drift & Duplication Guard (already in $adrs).
     *
     * @param  array<string,mixed>  $adrs
     * @return array{count:int,status:string}
     */
    private function driftSignal(array $adrs): array
    {
        $guard = data_get($adrs, 'evaluations.drift_duplication_guard', []);

        return [
            'count' => (int) data_get($guard, 'drift_count', 0),
            'status' => (string) data_get($guard, 'status', 'unknown'),
        ];
    }

    /**
     * The curated nodes whose governance AREA is the documentation-reality corpus the
     * Drift & Duplication Guard actually measures — so drift is surfaced only where it is
     * real, never smeared across unrelated nodes (e.g. workspace nodes).
     *
     * @return array<int,string>
     */
    private function driftScopedNodeIds(): array
    {
        return [
            'project.atlas.documentation-reality',
            'system.adrs',
            'system.acrui',
            'system.aurc',
            'component.adrs-runtime',
            'component.acrui-runtime',
            'component.aurc-runtime',
            'evidence.adrs-gates',
            'flow.adrs-to-acrui-to-aurc',
        ];
    }

    /**
     * Names which cached source decided this badge — kills "where did this label come
     * from" ambiguity.
     */
    private function signalBasis(string $codeReality, ?string $adrsExecution, string $sourcePath): string
    {
        if ($adrsExecution === 'declared') {
            return 'adrs_status';
        }
        if (str_starts_with($sourcePath, 'app/') && $codeReality !== 'not_applicable') {
            return 'code_reality_classify';
        }
        if ($adrsExecution !== null) {
            return 'adrs_block';
        }

        return 'workspace_replay';
    }

    private function badgeTone(string $maturity): string
    {
        return match ($maturity) {
            'real', 'live' => 'healthy',
            'partial', 'declared', 'spec', 'scaffold' => 'attention',
            'legacy' => 'neutral',
            'unproven', 'unknown' => 'attention',
            default => 'attention',
        };
    }

    /**
     * Honest split of the curated node badges (resolved, not declared).
     *
     * @param  array<int,array<string,mixed>>  $nodes
     * @return array<string,mixed>
     */
    private function badgeSummary(array $nodes): array
    {
        $byMaturity = [];
        $realLive = 0;
        $downgraded = 0;
        foreach ($nodes as $node) {
            $maturity = (string) data_get($node, 'badge.maturity', 'unknown');
            $byMaturity[$maturity] = ($byMaturity[$maturity] ?? 0) + 1;
            if (in_array($maturity, ['real', 'live'], true)) {
                $realLive++;
            } else {
                $downgraded++;
            }
        }
        ksort($byMaturity);

        return [
            'schema_version' => 'atlas.universal_reality_cartography.badge_summary.v1',
            'curated_node_count' => count($nodes),
            'real_count' => $byMaturity['real'] ?? 0,
            'live_count' => $byMaturity['live'] ?? 0,
            'real_or_live_count' => $realLive,
            'declared_or_unproven_count' => $downgraded,
            'by_maturity' => $byMaturity,
            'rule' => 'real_or_live_requires_live_code_reality_and_resolved_evidence_else_honest_downgrade',
        ];
    }

    /**
     * Cache key for the whole decorated badge set. Composed of the ADRS certification
     * hash (moves on any real doc edit), the cheap code-index signature (moves on a real
     * index change), and the workspace hash (moves on a workspace switch) — exactly the
     * three worlds whose change SHOULD recompute the badges. Null when degraded (skip
     * cache). Mirrors the proven completeDerivedStructure() index-signature cache.
     *
     * @param  array<string,mixed>  $adrs
     * @param  array<string,mixed>  $workspaceScope
     */
    private function badgeCacheKey(array $adrs, array $workspaceScope, ?string $signature): ?string
    {
        if ($signature === null) {
            return null;
        }
        $adrsHash = (string) ($adrs['certification_hash'] ?? '');
        $workspaceHash = (string) (data_get($workspaceScope, 'workspace_hash') ?? '');
        if ($adrsHash === '') {
            return null;
        }

        return hash('sha256', $adrsHash.'|'.$signature.'|'.$workspaceHash);
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
            // Every node minted here belongs to the CURATED macro projection (the
            // bounded human entrypoint). The COMPLETE auto-derived structure lives
            // under payload['complete_derived_structure'] and is built from the live
            // code index, NOT from this factory.
            'layer' => 'curated_macro_projection',
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
