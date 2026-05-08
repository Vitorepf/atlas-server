<?php

namespace App\Services\Ai\Kernel\Architecture;

use App\Services\Ai\AtlasProviderProjectionService;
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
        $splitOwner = $this->splitOwner($placement['placement'] ?? [], $task);
        $splitPlan = $this->splitPlan->plan(['owner' => $splitOwner]);
        $readiness = $this->readiness->snapshot([
            'workspace' => $options['workspace'] ?? base_path(),
            'owner' => $splitOwner,
        ]);
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
                'command' => 'php artisan atlas:ai:architecture-readiness --owner='.$splitOwner.' --json',
            ],
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
            'architecture_operations' => $this->sessionOperations(),
            'required_validation' => [
                'git diff --check',
                'atlas engineering knowledge docs-health',
                'php artisan atlas:ai:architecture-validate --json',
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
                    'review_duplicate_candidates',
                    'run_required_validation_after_changes',
                ],
            ],
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function sessionOperations(): array
    {
        $summary = $this->operations->summary();
        $requiredIds = [
            'architecture_readiness',
            'session_bootstrap',
            'feature_placement',
            'documentation_split_plan',
            'architecture_operations',
            'architecture_validate',
            'documentation_health',
            'provider_projection_status',
            'knowledge_sync',
            'code_intelligence_index',
            'ap_agent_workflow_registry',
        ];
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

        return array_values(array_unique($docs));
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
