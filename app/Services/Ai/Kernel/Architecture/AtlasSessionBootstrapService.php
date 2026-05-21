<?php

namespace App\Services\Ai\Kernel\Architecture;

use App\Services\Ai\AtlasProviderProjectionService;
use App\Services\Engineering\AtlasDocumentationRealitySystemService;
use App\Services\Engineering\AtlasUniversalRealityCartographyService;
use App\Services\Engineering\EngineeringDocumentationHealthService;
use App\Services\Engineering\EngineeringKnowledgeBaseService;

class AtlasSessionBootstrapService
{
    public function __construct(
        private readonly AtlasFeaturePlacementService $placement,
        private readonly AtlasDocumentationSplitPlanService $splitPlan,
        private readonly AtlasArchitectureReadinessService $readiness,
        private readonly AtlasArchitectureOperationsCatalog $operations,
        private readonly EngineeringDocumentationHealthService $docs,
        private readonly EngineeringKnowledgeBaseService $knowledge,
        private readonly AtlasProviderProjectionService $projections,
        private readonly AtlasDocumentationRealitySystemService $documentationReality,
        private readonly AtlasUniversalRealityCartographyService $cartography,
    ) {}

    /**
     * @return array<string,mixed>
     */
    public function bootstrap(string $task = '', array $options = []): array
    {
        $task = trim($task) !== '' ? trim($task) : 'atlas ai session bootstrap';
        $docs = $this->docs->report();
        $kb = $this->knowledge->summary();
        $projection = $this->projections->status('all', [
            'workspace' => $options['workspace'] ?? base_path(),
        ], [
            'workspace' => $options['workspace'] ?? base_path(),
        ]);
        $placement = $this->placement->place($task);
        $documentationReality = $this->documentationRealityGate($task, $placement);
        $splitOwner = $this->splitOwner($placement['placement'] ?? [], $task);
        $splitPlan = $this->splitPlan->plan(['owner' => $splitOwner]);
        $readiness = $this->readiness->snapshot([
            'workspace' => $options['workspace'] ?? base_path(),
            'owner' => $splitOwner,
        ]);
        $coverageBoundary = $readiness['coverage_boundary'] ?? [];
        $safeNextBlocks = $readiness['safe_next_blocks'] ?? [];
        $risks = array_values(array_unique(array_merge(
            (array) $placement['risks'],
            $this->readinessRisks($readiness),
            $this->bootstrapRisks($docs, $kb, $projection),
        )));

        return [
            'schema_version' => 'atlas.session_bootstrap.v1',
            'status' => 'ok',
            'task' => $task,
            'read_first' => $this->readFirst($task),
            'placement' => $placement['placement'],
            'gate_status' => $placement['gate_status'] ?? 'unknown',
            'owner_docs' => $placement['owner_docs'],
            'duplicate_candidates' => $placement['duplicate_candidates'],
            'documentation_reality_gate' => $documentationReality,
            'code_reality_anti_duplicate' => $placement['code_reality_anti_duplicate'] ?? [],
            'cartography_navigation_slice' => $documentationReality['aurc_navigation_slice'],
            'docs_split_plan' => [
                'owner' => $splitOwner,
                'status' => $splitPlan['status'] ?? 'unknown',
                'split_required_count' => $splitPlan['split_required_count'] ?? 0,
                'total_split_required_count' => $splitPlan['total_split_required_count'] ?? 0,
                'execution_order' => $splitPlan['execution_order'] ?? [],
                'first_doc' => data_get($splitPlan, 'docs.0'),
                'command' => 'php artisan atlas:ai:docs-split-plan --owner='.$splitOwner.' --json',
            ],
            'architecture_readiness' => [
                'schema_version' => $readiness['schema_version'] ?? 'atlas.architecture_readiness.v1',
                'status' => $readiness['status'] ?? 'unknown',
                'ready' => (bool) ($readiness['ready'] ?? false),
                'owner' => $readiness['owner'] ?? $splitOwner,
                'checks' => $readiness['checks'] ?? [],
                'review_signal' => $readiness['review_signal'] ?? [],
                'coverage_boundary' => $coverageBoundary,
                'safe_next_blocks' => $safeNextBlocks,
                'command' => 'php artisan atlas:ai:architecture-readiness --owner='.$splitOwner.' --json',
            ],
            'coverage_boundary' => $coverageBoundary,
            'safe_next_blocks' => $safeNextBlocks,
            'implementation_contract' => $placement['implementation_contract'] ?? [],
            'pre_implementation_checklist' => $placement['pre_implementation_checklist'] ?? [],
            'blocked_when' => $placement['blocked_when'] ?? [],
            'next_actions' => $placement['next_actions'] ?? [],
            'risks' => $risks,
            'docs_health' => [
                'status' => $docs['status'] ?? 'unknown',
                'required_doc_count' => data_get($docs, 'summary.required_doc_count'),
                'required_missing_count' => data_get($docs, 'summary.required_missing_count'),
                'oversized_count' => data_get($docs, 'summary.oversized_count'),
                'frontmatter_violation_count' => data_get($docs, 'summary.frontmatter_violation_count'),
            ],
            'kb_status' => [
                'status' => $kb['status'] ?? 'unknown',
                'active' => $kb['active'] ?? 0,
                'canonical_doc_count' => $kb['canonical_doc_count'] ?? 0,
                'last_indexed_at' => $kb['last_indexed_at'] ?? null,
            ],
            'provider_projection' => [
                'status' => $projection['status'] ?? 'unknown',
                'summary' => $projection['summary'] ?? [],
                'next_actions' => $projection['next_actions'] ?? [],
            ],
            'architecture_operations' => $this->sessionOperations($placement['placement'] ?? []),
            'required_validation' => [
                'git diff --check',
                'atlas engineering knowledge docs-health',
                'php artisan atlas:documentation-reality score --strict --json',
                'php artisan atlas:documentation-reality acceptance --strict --json',
                'php artisan atlas:code-reality anti-duplicate --feature="<feature>" --json',
                'php artisan atlas:code-reality reality-audit --json',
                'php artisan atlas:code-reality reachability --target="<target>" --json',
                'php artisan atlas:code-reality deletion-preflight --target="<target>" --json',
                'php artisan atlas:universal-reality-cartography navigation-slice --strict --json',
                'php artisan atlas:universal-reality-cartography visual-scene --mode=implementation --strict --json',
                'php artisan atlas:universal-reality-cartography human-clarity --mode=flow --strict --json',
                'php artisan atlas:software-twin quality-score --json',
                'php artisan atlas:software-twin impact --target="<target>" --json',
                'php artisan atlas:software-twin snapshot --target="<target>" --json',
                'php artisan atlas:verified-evolution boundary-contract --objective="<objective>" --target="<target>" --json',
                'php artisan atlas:verified-evolution proof-plan --objective="<objective>" --target="<target>" --json',
                'php artisan atlas:verified-evolution execution-contract --objective="<objective>" --target="<target>" --json',
                'php artisan atlas:verified-evolution drift-watch --objective="<objective>" --target="<target>" --changed-file="<path>" --json',
                'php artisan atlas:verified-evolution patch-simulation --objective="<objective>" --target="<target>" --changed-file="<path>" --json',
                'php artisan atlas:verified-evolution outcome-bridge --objective="<objective>" --target="<target>" --evidence="<evidence>" --json',
                'php artisan atlas:software-twin-verified-evolution:certify --json --strict',
                'php artisan atlas:ai:architecture-validate --json',
                'php artisan atlas:ai:runtime-boundary --json',
                'atlas memory projection status --target=all',
                'atlas engineering knowledge sync --prune',
                'atlas engineering knowledge index-code --prune',
            ],
            'session_gate' => [
                'status' => $placement['gate_status'] ?? 'unknown',
                'strict_blocks_session' => ($placement['gate_status'] ?? null) === 'blocked',
                'reason' => ($placement['blocked_when'] ?? []) !== []
                    ? 'feature_placement_gate_blocked'
                    : 'feature_placement_gate_clear',
                'blocked_when' => $placement['blocked_when'] ?? [],
                'required_before_code' => [
                    'read_read_first_docs',
                    'review_ap_agent_workflow_registry_when_touching_ap_or_architecture_governance',
                    'review_owner_docs',
                    'confirm_placement_and_business_context',
                    'review_documentation_reality_gate',
                    'review_code_reality_anti_duplicate',
                    'use_cartography_navigation_slice_as_map_not_source_of_truth',
                    'review_software_twin_impact_for_targeted_changes',
                    'review_verified_evolution_boundary_and_proof_plan_before_mutation',
                    'review_duplicate_candidates',
                    'run_runtime_language_boundary_when_touching_python_go_swift_or_rag_ml',
                    'run_required_validation_after_changes',
                ],
            ],
        ];
    }

    /**
     * @param  array<string,mixed>  $placement
     * @return array<string,mixed>
     */
    private function documentationRealityGate(string $task, array $placement): array
    {
        $adrs = $this->documentationReality->report();
        $cartography = $this->cartography->map('implementation');
        $summary = (array) ($adrs['summary'] ?? []);
        $score = (array) ($adrs['documentation_reality_score'] ?? []);
        $placementGate = (array) ($placement['documentation_reality_gate'] ?? []);
        $navigation = (array) ($cartography['ai_navigation_slice'] ?? []);

        return [
            'schema_version' => 'atlas.session_bootstrap.documentation_reality_gate.v1',
            'status' => (($adrs['status'] ?? null) === 'ready'
                && ($placementGate['status'] ?? null) === 'ready'
                && ($cartography['status'] ?? null) === 'ready')
                    ? 'ready'
                    : 'review',
            'task' => $task,
            'adrs' => [
                'schema_version' => $adrs['schema_version'] ?? AtlasDocumentationRealitySystemService::SCHEMA_VERSION,
                'status' => $adrs['status'] ?? 'unknown',
                'block_count' => $summary['block_count'] ?? null,
                'integrated_runtime_block_count' => $summary['integrated_runtime_block_count'] ?? null,
                'score_status' => $score['status'] ?? 'unknown',
                'command' => 'php artisan atlas:documentation-reality score --strict --json',
            ],
            'acrui_anti_duplicate' => $placement['code_reality_anti_duplicate'] ?? [],
            'aurc' => [
                'schema_version' => $cartography['schema_version'] ?? AtlasUniversalRealityCartographyService::SCHEMA_VERSION,
                'status' => $cartography['status'] ?? 'unknown',
                'node_count' => data_get($cartography, 'summary.node_count'),
                'edge_count' => data_get($cartography, 'summary.edge_count'),
                'command' => 'php artisan atlas:universal-reality-cartography navigation-slice --strict --json',
            ],
            'aurc_navigation_slice' => [
                'schema_version' => $navigation['schema_version'] ?? 'atlas.universal_reality_cartography.ai_navigation_slice.v1',
                'status' => $navigation['status'] ?? 'unknown',
                'provider_safe' => (bool) ($navigation['provider_safe'] ?? false),
                'node_count' => count((array) ($navigation['nodes'] ?? [])),
                'rule' => $navigation['rule'] ?? 'use_cartography_as_navigation_slice_not_as_primary_truth',
            ],
            'claim_policy' => [
                'read_only' => true,
                'providers_invoked' => false,
                'rivals_run' => false,
                'deletes_files' => false,
                'cartography_is_source_of_truth' => false,
                'canonical_docs_remain_authority' => true,
            ],
            'writes' => false,
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function sessionOperations(array $placement = []): array
    {
        $summary = $this->operations->summary();
        $requiredIds = [
            'architecture_readiness',
            'session_bootstrap',
            'feature_placement',
            'documentation_split_plan',
            'documentation_reality_score',
            'documentation_reality_acceptance_matrix',
            'code_reality_anti_duplicate',
            'code_reality_reality_audit',
            'code_reality_reachability',
            'code_reality_deletion_preflight',
            'universal_reality_cartography_navigation_slice',
            'universal_reality_cartography_visual_scene',
            'universal_reality_cartography_human_clarity',
            'software_twin_quality_score',
            'software_twin_impact',
            'verified_evolution_boundary_contract',
            'verified_evolution_proof_plan',
            'software_twin_verified_evolution_certification',
            'architecture_operations',
            'architecture_validate',
            'runtime_language_boundary',
            'documentation_health',
            'provider_projection_status',
            'knowledge_sync',
            'code_intelligence_index',
            'ap_agent_workflow_registry',
        ];
        if (($placement['surface'] ?? null) === 'voice_realtime' || ($placement['flow'] ?? null) === 'voice_realtime.session') {
            $requiredIds[] = 'voice_realtime_dependencies';
            $requiredIds[] = 'voice_realtime_dependency_install_plan';
        }
        $commands = collect((array) ($summary['commands'] ?? []))
            ->filter(fn (array $command): bool => in_array((string) ($command['id'] ?? ''), $requiredIds, true))
            ->values()
            ->all();

        return [
            'schema_version' => $summary['schema_version'] ?? 'atlas.architecture_operations.v1',
            'section' => $summary['section'] ?? 'arquitetura_mae',
            'operation_ids' => array_values(array_filter(array_map(
                fn (array $command): ?string => is_string($command['id'] ?? null) ? $command['id'] : null,
                $commands,
            ))),
            'command_count' => count($commands),
            'commands' => $commands,
            'owner_layer_operations' => [
                'runtime' => $this->operations->summary(['owner_layer' => 'runtime']),
            ],
        ];
    }

    /**
     * @return array<int,string>
     */
    private function readFirst(string $task): array
    {
        $docs = [
            'docs/engineering-knowledge-base/atlas-ai-session-bootstrap.md',
            'docs/engineering-knowledge-base/atlas-ai-canonical-architecture-index.md',
            'docs/engineering-knowledge-base/atlas-ai-documentation-operating-system.md',
            'docs/engineering-knowledge-base/atlas-ai-knowledge-governance-system.md',
            'docs/engineering-knowledge-base/START_HERE.md',
        ];

        $text = strtolower($task);
        if (str_contains($text, 'ap') || str_contains($text, 'architecture') || str_contains($text, 'arquitetura') || str_contains($text, 'governanca') || str_contains($text, 'governança')) {
            $docs[] = 'docs/ap/AP-204-ap-agent-workflow-registry.md';
        }
        if ($this->mentionsRuntimeBoundary($text)) {
            $docs[] = 'docs/engineering-knowledge-base/atlas-ai-runtime-language-boundaries.md';
        }
        if ($this->mentionsPythonAiDataRuntime($text)) {
            $docs[] = 'docs/engineering-knowledge-base/atlas-ai-local-performance-memory-strategy.md';
        }
        if ($this->mentionsSwiftNativeRuntime($text)) {
            $docs[] = 'docs/engineering-knowledge-base/atlas-native-mac-agent.md';
        }
        if (str_contains($text, 'voice') || str_contains($text, 'voz') || str_contains($text, 'livekit')) {
            $docs[] = 'docs/engineering-knowledge-base/atlas-ai-voice-realtime-surface.md';
        }

        return array_values(array_unique($docs));
    }

    private function mentionsRuntimeBoundary(string $text): bool
    {
        return $this->mentionsPythonAiDataRuntime($text)
            || $this->mentionsSwiftNativeRuntime($text)
            || str_contains($text, 'voice')
            || str_contains($text, 'voz')
            || str_contains($text, ' go ')
            || str_contains($text, 'golang')
            || str_contains($text, 'go edge')
            || str_contains($text, 'webhook')
            || str_contains($text, 'postback')
            || str_contains($text, 'streaming')
            || str_contains($text, 'livekit');
    }

    private function mentionsPythonAiDataRuntime(string $text): bool
    {
        return str_contains($text, 'python')
            || str_contains($text, 'rag')
            || str_contains($text, 'embedding')
            || str_contains($text, 'rerank')
            || str_contains($text, 'faiss')
            || str_contains($text, 'graph')
            || str_contains($text, 'machine learning')
            || str_contains($text, 'pandas');
    }

    private function mentionsSwiftNativeRuntime(string $text): bool
    {
        return str_contains($text, 'swift')
            || str_contains($text, 'macos')
            || str_contains($text, 'keychain')
            || str_contains($text, 'touch id')
            || str_contains($text, 'screencapture')
            || str_contains($text, 'fsevents')
            || str_contains($text, 'accessibility api')
            || str_contains($text, 'core ml');
    }

    /**
     * @param  array<string,mixed>  $placement
     */
    private function splitOwner(array $placement, string $task): string
    {
        $text = strtolower($task);

        if (str_contains($text, 'memory') || str_contains($text, 'memoria') || str_contains($text, 'open brain')) {
            return 'memory_open_brain';
        }
        if (str_contains($text, 'obsidian') || str_contains($text, 'vault')) {
            return 'human_knowledge_surface';
        }
        if (str_contains($text, 'tool') || str_contains($text, 'runtime')) {
            return 'tool_runtime';
        }
        if (($placement['domain'] ?? 'general') !== 'general') {
            return 'domain_architecture';
        }

        return match ((string) ($placement['layer'] ?? '')) {
            'documentation_governance' => 'knowledge_governance',
            'provider_evolution' => 'roadmap_backlog',
            'surface', 'runtime', 'evidence' => 'knowledge_governance',
            default => 'kernel_architecture',
        };
    }

    /**
     * @param  array<string,mixed>  $docs
     * @param  array<string,mixed>  $kb
     * @param  array<string,mixed>  $projection
     * @return array<int,string>
     */
    private function bootstrapRisks(array $docs, array $kb, array $projection): array
    {
        $risks = [];
        if (($docs['status'] ?? null) !== 'ok') {
            $risks[] = 'docs_health_not_ok_do_not_start_structural_work';
        }
        if ((int) data_get($docs, 'summary.required_missing_count', 0) > 0) {
            $risks[] = 'required_bootstrap_doc_missing';
        }
        if (($kb['status'] ?? null) !== 'ready') {
            $risks[] = 'postgres_kb_not_ready_or_not_synced';
        }
        if (($projection['status'] ?? null) !== 'passed') {
            $risks[] = 'provider_projection_needs_review';
        }

        return $risks;
    }

    /**
     * @param  array<string,mixed>  $readiness
     * @return array<int,string>
     */
    private function readinessRisks(array $readiness): array
    {
        if (($readiness['status'] ?? null) === 'ready') {
            return [];
        }

        return ['architecture_readiness_attention_fix_before_structural_expansion'];
    }
}
