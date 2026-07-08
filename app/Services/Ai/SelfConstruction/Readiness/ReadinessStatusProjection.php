<?php

declare(strict_types=1);

namespace App\Services\Ai\SelfConstruction\Readiness;

use App\Services\Ai\SelfConstruction\Support\ReadinessDocumentProbe;

/**
 * Family #1 — Readiness Status Projection (SCOS compaction seam).
 *
 * Extracted from AtlasSelfConstructionReadinessService::snapshot() without
 * schema/hash drift. Mother delegates here.
 */
final class ReadinessStatusProjection
{
    /**
     * @param  array{workspace?: string|null}  $options
     * @param  callable(): list<string>  $requiredDocs
     * @return array<string, mixed>
     */
    public function snapshot(array $options, callable $requiredDocs): array
    {
        $workspace = ($options['workspace'] ?? null) ?: base_path();
        $docs = array_map(static fn (string $path): array => ReadinessDocumentProbe::status($path), $requiredDocs());
        $missing = array_values(array_filter($docs, static fn (array $doc): bool => ! $doc['exists']));

        return [
            'schema_version' => 'atlas.self_construction_readiness.v1',
            'status' => $missing === [] ? 'ready_for_phase_2' : 'blocked_missing_docs',
            'mode' => 'read_only_advisory',
            'workspace' => $workspace,
            'summary' => [
                'required_doc_count' => count($docs),
                'missing_doc_count' => count($missing),
                'runtime_phase' => 'phase_2_read_only_gap_report',
                'autonomous_execution_allowed' => false,
                'message' => $missing === []
                    ? 'Self-Construction OS law is documented and ready for read-only gap reporting.'
                    : 'Self-Construction OS is missing required canonical docs.',
            ],
            'maturity' => [
                'documentation' => 'L1/L2',
                'runtime' => 'L1_read_only_advisory',
                'autonomous_self_programming' => 'L0_not_allowed',
                'next_target' => 'L2_meta_sdd_artifact_generator',
            ],
            'required_docs' => $docs,
            'build_graph' => [
                'target_capability' => 'self_construction_os',
                'prerequisites' => [
                    'documentation_operating_system',
                    'knowledge_governance_system',
                    'evidence_ledger',
                    'code_intelligence',
                    'cognitive_runtime',
                    'research_self_improvement_runtime',
                    'spec_operating_system',
                    'tool_runtime_quality_gates',
                ],
                'unlocks' => [
                    'meta_sdd_runtime',
                    'receipt_scoped_self_programming',
                    'strategic_self_construction',
                ],
            ],
            'priority_engine' => [
                'current_p0_bias' => [
                    'memory',
                    'retrieval',
                    'sdd_runtime',
                    'evidence',
                    'drift_detection',
                    'research_verification',
                ],
                'deprioritize' => [
                    'decorative_product_work_without_core_unlock',
                    'provider_wrapper_without_governance',
                    'autonomy_without_rollback',
                ],
            ],
            'safety_contract' => [
                'self_programming_allowed' => false,
                'write_tools_allowed' => false,
                'requires_decision_receipt_for_code_changes' => true,
                'requires_human_gate_for_critical_policy' => true,
            ],
            'next_safe_blocks' => [
                [
                    'order' => 1,
                    'block' => 'Meta-SDD artifact generator',
                    'risk' => 'low',
                    'allowed_scope' => 'read-only service/command/tests that generate candidate packets',
                ],
                [
                    'order' => 2,
                    'block' => 'Traceability check for self-construction docs',
                    'risk' => 'low',
                    'allowed_scope' => 'docs/tests only',
                ],
                [
                    'order' => 3,
                    'block' => 'Decision Receipt preview for self-construction',
                    'risk' => 'medium',
                    'allowed_scope' => 'preview only; no execution',
                ],
            ],
            'recommended_commands' => [
                'php artisan atlas:ai:self-construction --traceability --json',
                'php artisan atlas:engineering:knowledge docs-health --json',
                'php artisan atlas:ai:architecture-validate --json',
                'php artisan atlas:engineering:knowledge sync --prune --json',
                'php artisan atlas:engineering:knowledge index-code --prune --json',
                'git diff --check',
            ],
        ];
    }
}
