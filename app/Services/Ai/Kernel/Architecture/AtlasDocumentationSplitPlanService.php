<?php

namespace App\Services\Ai\Kernel\Architecture;

use App\Services\Engineering\EngineeringDocumentationHealthService;

final class AtlasDocumentationSplitPlanService
{
    public function __construct(
        private readonly EngineeringDocumentationHealthService $documentationHealth,
    ) {}

    /**
     * @return array<string,mixed>
     */
    public function plan(array $filters = []): array
    {
        $filters = $this->normalizeFilters($filters);
        $health = $this->documentationHealth->report();
        $allDocs = collect((array) ($health['oversized_docs'] ?? []))
            ->map(fn (array $doc): array => $this->splitItem($doc))
            ->values();

        $docs = $allDocs
            ->filter(fn (array $doc): bool => $this->matchesFilters($doc, $filters))
            ->sortBy([
                ['priority_rank', 'asc'],
                ['excess_lines', 'desc'],
                ['path', 'asc'],
            ])
            ->values()
            ->all();

        return [
            'schema_version' => 'atlas.documentation_split_plan.v1',
            'status' => 'ok',
            'filters' => $filters,
            'total_split_required_count' => $allDocs->count(),
            'split_required_count' => count($docs),
            'blocking_count' => collect($docs)->where('blocking', true)->count(),
            'grandfathered_count' => collect($docs)->where('status', 'split_required_grandfathered')->count(),
            'execution_order' => collect($docs)->pluck('path')->take(10)->values()->all(),
            'policy' => [
                'authoring_truth' => 'docs/engineering-knowledge-base',
                'line_limits_source' => 'EngineeringDocumentationHealthService',
                'rule' => 'Do not add new responsibilities to split_required docs; create focused child specs and link them back.',
                'growth_gate' => 'If a split_required doc is touched, the change must either reduce it or add a focused child spec and backlink.',
                'ai_authoring_rule' => 'A new AI session must use this plan before expanding canonical architecture docs.',
                'docs_health_command' => 'atlas engineering knowledge docs-health --json',
            ],
            'docs' => $docs,
            'required_validation' => [
                'atlas engineering knowledge docs-health',
                'php artisan atlas:ai:architecture-validate --json',
                'atlas engineering knowledge sync --prune',
                'atlas engineering knowledge index-code --prune',
            ],
            'generated_at' => now()->toJSON(),
        ];
    }

    /**
     * @param  array<string,mixed>  $filters
     * @return array{owner:?string,severity:?string,status:?string}
     */
    private function normalizeFilters(array $filters): array
    {
        return [
            'owner' => $this->nullableToken($filters['owner'] ?? $filters['owner_area'] ?? null),
            'severity' => $this->nullableToken($filters['severity'] ?? null),
            'status' => $this->nullableToken($filters['status'] ?? null),
        ];
    }

    private function nullableToken(mixed $value): ?string
    {
        if (! is_scalar($value)) {
            return null;
        }

        $value = strtolower(trim((string) $value));

        return $value === '' ? null : $value;
    }

    /**
     * @param  array<string,mixed>  $doc
     * @param  array{owner:?string,severity:?string,status:?string}  $filters
     */
    private function matchesFilters(array $doc, array $filters): bool
    {
        if ($filters['owner'] !== null && strtolower((string) ($doc['owner_area'] ?? '')) !== $filters['owner']) {
            return false;
        }

        if ($filters['severity'] !== null && strtolower((string) ($doc['severity'] ?? '')) !== $filters['severity']) {
            return false;
        }

        if ($filters['status'] !== null && strtolower((string) ($doc['status'] ?? '')) !== $filters['status']) {
            return false;
        }

        return true;
    }

    /**
     * @param  array<string,mixed>  $doc
     * @return array<string,mixed>
     */
    private function splitItem(array $doc): array
    {
        $lineCount = (int) ($doc['line_count'] ?? 0);
        $limit = (int) ($doc['limit'] ?? 0);
        $status = (string) ($doc['status'] ?? 'split_required');
        $path = (string) ($doc['path'] ?? '');
        $excess = max(0, $lineCount - $limit);

        return [
            'path' => $path,
            'line_count' => $lineCount,
            'limit' => $limit,
            'excess_lines' => $excess,
            'status' => $status,
            'severity' => $this->severity($status, $excess),
            'blocking' => $status === 'split_required',
            'priority_rank' => $status === 'split_required' ? 1 : 2,
            'owner_area' => $this->ownerArea($path),
            'recommended_action' => (string) ($doc['recommended_action'] ?? 'split this active doc into focused specs before adding new responsibilities'),
            'target_shape' => $this->targetShape($path),
            'owner_update' => $this->ownerUpdate($path),
            'proposed_child_docs' => $this->proposedChildDocs($path),
            'migration_steps' => $this->migrationSteps($path),
            'acceptance_criteria' => [
                'owner_doc_stays_or_becomes_index_only',
                'child_docs_have_frontmatter_related_paths_and_backlinks',
                'canonical_index_or_readme_points_to_child_docs',
                'docs_health_does_not_increase_split_required_count',
                'sync_and_index_code_are_rerun',
            ],
        ];
    }

    private function severity(string $status, int $excess): string
    {
        if ($status === 'split_required_grandfathered') {
            return $excess >= 1000 ? 'legacy_critical' : 'legacy_managed';
        }
        if ($excess >= 1000) {
            return 'critical';
        }
        if ($excess >= 300) {
            return 'high';
        }
        if ($excess >= 80) {
            return 'medium';
        }

        return 'low';
    }

    private function ownerArea(string $path): string
    {
        return match (true) {
            str_contains($path, '/domains/') => 'domain_architecture',
            str_contains($path, 'kernel') => 'kernel_architecture',
            str_contains($path, 'memory') || str_contains($path, 'open-brain') => 'memory_open_brain',
            str_contains($path, 'obsidian') => 'human_knowledge_surface',
            str_contains($path, 'tool') => 'tool_runtime',
            str_contains($path, 'blueprint') => 'programming_blueprint',
            str_contains($path, 'legacy') => 'legacy_cleanup',
            str_contains($path, 'roadmap') => 'roadmap_backlog',
            default => 'knowledge_governance',
        };
    }

    private function targetShape(string $path): string
    {
        if (str_contains($path, '/domains/')) {
            return 'Extract flow-specific APs or child domain specs; keep the domain doc as <=260-line contract and index.';
        }
        if (str_contains($path, 'kernel')) {
            return 'Move mature AP appendices into docs/ap or focused kernel contract docs; keep kernel as index plus invariants.';
        }
        if (str_contains($path, 'memory')) {
            return 'Split implementation history from current contract; keep active doc as compact API/contract/read-model spec.';
        }
        if (str_contains($path, 'roadmap')) {
            return 'Split phase backlog into AP docs; keep roadmap as phased priority map.';
        }

        return 'Create focused child docs with frontmatter, related_paths and backlinks; preserve this doc as canonical index.';
    }

    private function ownerUpdate(string $path): string
    {
        if (str_contains($path, 'START_HERE') || str_contains($path, 'README')) {
            return 'Update bootstrap/index docs only with links, not long architecture content.';
        }

        return 'Update this owner doc with a short redirect section and move expanded material to child specs.';
    }

    /**
     * @return array<int,string>
     */
    private function proposedChildDocs(string $path): array
    {
        return match (true) {
            str_contains($path, 'kernel') => [
                'docs/engineering-knowledge-base/kernel/contracts.md',
                'docs/engineering-knowledge-base/kernel/static-scans.md',
                'docs/engineering-knowledge-base/kernel/roadmap-ap-index.md',
            ],
            str_contains($path, 'memory') || str_contains($path, 'open-brain') => [
                'docs/engineering-knowledge-base/memory/contracts.md',
                'docs/engineering-knowledge-base/memory/open-brain-mcp.md',
                'docs/engineering-knowledge-base/memory/retrieval-and-context.md',
            ],
            str_contains($path, 'super-tool') || str_contains($path, 'power-tools') => [
                'docs/engineering-knowledge-base/tools/runtime-contract.md',
                'docs/engineering-knowledge-base/tools/authority-matrix.md',
                'docs/engineering-knowledge-base/tools/recipes-and-gates.md',
            ],
            str_contains($path, '/domains/') => [
                preg_replace('/\\.md$/', '-flows.md', $path) ?: $path,
                preg_replace('/\\.md$/', '-gates.md', $path) ?: $path,
            ],
            str_contains($path, 'roadmap') => [
                'docs/ap/<next-ap-id>-<focused-topic>.md',
                'docs/engineering-knowledge-base/roadmap/<phase>-priority-map.md',
            ],
            default => [
                preg_replace('/\\.md$/', '-contracts.md', $path) ?: $path,
                preg_replace('/\\.md$/', '-runbook.md', $path) ?: $path,
            ],
        };
    }

    /**
     * @return array<int,string>
     */
    private function migrationSteps(string $path): array
    {
        return [
            'identify_sections_that_are_current_contract_vs_history',
            'extract_one_cohesive_child_doc_at_a_time',
            'replace_extracted_section_with_short_summary_and_link',
            'update_canonical_index_or_domain_readme',
            'run_docs_health_sync_index_and_architecture_validate',
        ];
    }
}
