<?php

namespace App\Services\Ai\SelfConstruction;

final class AtlasSelfConstructionReadinessService
{
    /**
     * @param  array{workspace?: string|null}  $options
     * @return array<string, mixed>
     */
    public function snapshot(array $options = []): array
    {
        $workspace = $options['workspace'] ?: base_path();
        $requiredDocs = $this->requiredDocs();
        $docs = array_map(fn (string $path): array => $this->docStatus($path), $requiredDocs);
        $missing = array_values(array_filter($docs, fn (array $doc): bool => ! $doc['exists']));

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

    /**
     * @param  array{workspace?: string|null, target?: string|null}  $options
     * @return array<string, mixed>
     */
    public function metaSddPacket(array $options = []): array
    {
        $snapshot = $this->snapshot($options);
        $target = $options['target'] ?: 'meta_sdd_artifact_generator';

        return [
            'schema_version' => 'atlas.self_construction_meta_sdd.v1',
            'status' => data_get($snapshot, 'summary.missing_doc_count') === 0 ? 'candidate_ready' : 'blocked_missing_docs',
            'mode' => 'read_only_candidate',
            'execution_allowed' => false,
            'meta_spec' => [
                'id' => 'META-SDD-SELF-CONSTRUCTION-PHASE-3',
                'title' => 'Meta-SDD artifact generator for Atlas Self-Construction OS',
                'target_layer' => '0.8-self-construction',
                'target_capability' => $target,
                'current_maturity' => data_get($snapshot, 'maturity.runtime'),
                'target_maturity' => 'L2_meta_sdd_artifact_generator',
                'problem' => 'Atlas has read-only Self-Construction readiness, but still needs structured Meta-SDD candidate packets for safe next-step planning.',
                'goal' => 'Generate a read-only Meta-SDD packet with assumptions, priority, build graph, tasks, gates and safety boundaries.',
                'non_goals' => [
                    'no code patch execution',
                    'no self-programming authorization',
                    'no auto-merge',
                    'no critical policy mutation',
                ],
                'risk_level' => 'low',
                'autonomy_allowed' => 'read_only_candidate_generation',
                'rollback_strategy' => 'remove generated candidate output; no persistent mutation is performed',
            ],
            'assumptions' => [
                [
                    'id' => 'A1',
                    'text' => 'The required Self-Construction OS docs are present and indexed.',
                    'confidence' => data_get($snapshot, 'summary.missing_doc_count') === 0 ? 0.95 : 0.2,
                    'blocking' => data_get($snapshot, 'summary.missing_doc_count') !== 0,
                    'evidence' => ['required_docs.missing_doc_count'],
                ],
                [
                    'id' => 'A2',
                    'text' => 'The next safe implementation should stay read-only until receipt preview and drift checks exist.',
                    'confidence' => 0.93,
                    'blocking' => false,
                    'evidence' => ['self-programming safety contract', 'runtime implementation roadmap'],
                ],
            ],
            'priority' => [
                'p_level' => 'P0',
                'score' => 9.1,
                'rationale' => 'Meta-SDD packets unlock safer future self-construction without enabling writes.',
                'unlocks' => data_get($snapshot, 'build_graph.unlocks'),
                'blocked_by' => [],
                'smallest_safe_slice' => 'read-only packet generation via CLI/service with focused tests',
            ],
            'build_graph' => [
                'target_capability' => $target,
                'prerequisites' => data_get($snapshot, 'build_graph.prerequisites'),
                'downstream_capabilities' => data_get($snapshot, 'build_graph.unlocks'),
                'maturity_before' => data_get($snapshot, 'maturity.runtime'),
                'maturity_after' => 'L2_meta_sdd_artifact_generator',
            ],
            'tasks' => [
                [
                    'id' => 'T1',
                    'title' => 'Generate Meta-SDD candidate packet from readiness snapshot',
                    'type' => 'read_only_runtime',
                    'allowed_files' => [
                        'app/Services/Ai/SelfConstruction/AtlasSelfConstructionReadinessService.php',
                        'app/Console/Commands/AtlasAiSelfConstructionCommand.php',
                    ],
                ],
                [
                    'id' => 'T2',
                    'title' => 'Cover candidate packet schema and safety fields',
                    'type' => 'test',
                    'allowed_files' => [
                        'tests/Feature/Ai/AtlasAiSelfConstructionCommandTest.php',
                    ],
                ],
                [
                    'id' => 'T3',
                    'title' => 'Document command usage and phase boundary',
                    'type' => 'documentation',
                    'allowed_files' => [
                        'docs/engineering-knowledge-base/atlas-ai-self-construction-os.md',
                        'docs/engineering-knowledge-base/self-construction/runtime-implementation-roadmap.md',
                    ],
                ],
            ],
            'required_gates' => [
                'php artisan atlas:ai:self-construction --traceability --json',
                'php artisan test tests/Feature/Ai/AtlasAiSelfConstructionCommandTest.php',
                'php artisan atlas:engineering:knowledge docs-health --json',
                'php artisan atlas:ai:architecture-validate --json',
                'git diff --check',
            ],
            'safety_contract' => data_get($snapshot, 'safety_contract'),
            'human_summary' => 'Candidate Meta-SDD packet is generated for planning only. It does not write files, authorize self-programming or bypass Decision Receipt.',
        ];
    }

    /**
     * @param  array{workspace?: string|null, target?: string|null}  $options
     * @return array<string, mixed>
     */
    public function receiptPreview(array $options = []): array
    {
        $packet = $this->metaSddPacket($options);
        $target = data_get($packet, 'meta_spec.target_capability');

        return [
            'schema_version' => 'atlas.self_construction_receipt_preview.v1',
            'status' => data_get($packet, 'status') === 'candidate_ready' ? 'preview_ready' : 'blocked',
            'mode' => 'receipt_preview_only',
            'execution_allowed' => false,
            'receipt_preview' => [
                'id' => 'DR-PREVIEW-SELF-CONSTRUCTION-PHASE-4',
                'operation_id' => 'OP-PREVIEW-SELF-CONSTRUCTION',
                'meta_spec_id' => data_get($packet, 'meta_spec.id'),
                'target_capability' => $target,
                'signed_by' => 'atlas_kernel_preview_only',
                'autonomy_level' => 'L0_preview_only',
                'risk_level' => data_get($packet, 'meta_spec.risk_level'),
                'decision' => [
                    'action' => 'prepare_receipt_scoped_task_plan',
                    'rationale' => 'Prepare a safe execution envelope for future human/agent review without executing writes.',
                    'selected_runtime' => 'laravel_cli_read_only',
                    'selected_agents' => [
                        'Context Scout',
                        'Spec Critic',
                        'Architecture Agent',
                        'QA Agent',
                        'Evidence Agent',
                    ],
                ],
                'scope' => [
                    'allowed_actions' => [
                        'read_canonical_docs',
                        'generate_candidate_meta_sdd',
                        'generate_receipt_preview',
                        'run_validation_commands',
                    ],
                    'forbidden_actions' => [
                        'apply_patch_without_signed_receipt',
                        'auto_merge',
                        'change_autonomy_policy',
                        'enable_self_programming_writes',
                        'modify_provider_policy',
                        'modify_memory_privacy_policy',
                    ],
                    'allowed_files' => $this->receiptAllowedFiles(),
                    'forbidden_files' => [
                        'app/Services/Ai/Kernel/**',
                        'app/Services/Ai/Memory/**',
                        'config/**',
                        'routes/api.php',
                        'database/migrations/**',
                        'runtimes/python/voice_realtime/**',
                    ],
                    'allowed_commands' => data_get($packet, 'required_gates'),
                    'forbidden_commands' => [
                        'git reset --hard',
                        'git checkout --',
                        'php artisan migrate',
                        'composer require',
                        'npm install',
                    ],
                ],
                'tasks' => data_get($packet, 'tasks'),
                'gates' => [
                    'required' => data_get($packet, 'required_gates'),
                    'blocking_failures' => [
                        'focused_test_failure',
                        'docs_health_violation',
                        'architecture_validate_failure',
                        'diff_check_failure',
                    ],
                ],
                'rollback' => [
                    'strategy' => 'revert only files listed in allowed_files for this preview scope',
                    'restore_points' => [
                        'git diff before scoped implementation',
                        'test output before scoped implementation',
                    ],
                ],
                'evidence' => [
                    'required_events' => [
                        'meta_sdd_packet',
                        'receipt_preview',
                        'focused_test_output',
                        'docs_health_output',
                        'architecture_validate_output',
                        'diff_check_output',
                    ],
                    'append_only' => true,
                ],
            ],
            'human_summary' => 'Receipt preview is ready for review. It does not sign execution, apply patches, run migrations or enable self-programming writes.',
        ];
    }

    /**
     * @param  array{workspace?: string|null, target?: string|null}  $options
     * @return array<string, mixed>
     */
    public function traceabilityAudit(array $options = []): array
    {
        $snapshot = $this->snapshot($options);
        $rootDoc = 'docs/engineering-knowledge-base/atlas-ai-self-construction-os.md';
        $rootContent = $this->docContent($rootDoc);
        $docs = (array) data_get($snapshot, 'required_docs', []);
        $items = array_map(function (array $doc) use ($rootContent, $rootDoc): array {
            $path = (string) $doc['path'];
            $content = $this->docContent($path);
            $isAp = str_starts_with($path, 'docs/ap/');

            return [
                'path' => $path,
                'exists' => (bool) $doc['exists'],
                'declares_self_construction_tag' => str_contains($content, 'self-construction'),
                'declares_layer' => $isAp || str_contains($content, 'layer: 0.8-self-construction'),
                'listed_by_root_doc' => $path === $rootDoc || str_contains($rootContent, $path),
                'has_related_paths' => str_contains($content, 'related_paths:'),
                'line_count' => $doc['line_count'],
            ];
        }, $docs);

        $violations = [];
        foreach ($items as $item) {
            foreach (['exists', 'declares_self_construction_tag', 'declares_layer', 'listed_by_root_doc'] as $field) {
                if (! $item[$field]) {
                    $violations[] = [
                        'path' => $item['path'],
                        'field' => $field,
                        'message' => "Self-Construction traceability field [{$field}] failed.",
                    ];
                }
            }
        }

        return [
            'schema_version' => 'atlas.self_construction_traceability_audit.v1',
            'status' => $violations === [] ? 'traceable' : 'blocked_traceability_violation',
            'mode' => 'read_only_traceability_audit',
            'execution_allowed' => false,
            'summary' => [
                'required_doc_count' => count($items),
                'violation_count' => count($violations),
                'root_doc' => $rootDoc,
                'message' => $violations === []
                    ? 'Self-Construction law is traceable from the root doc to required canonical artifacts.'
                    : 'Self-Construction law has traceability violations that must be fixed before promotion.',
            ],
            'traceability_items' => $items,
            'violations' => $violations,
            'required_gates' => [
                'php artisan atlas:ai:self-construction --traceability --json',
                'php artisan test tests/Feature/Ai/AtlasAiSelfConstructionCommandTest.php',
                'php artisan atlas:engineering:knowledge docs-health --json',
                'git diff --check',
            ],
            'safety_contract' => data_get($snapshot, 'safety_contract'),
            'human_summary' => 'Traceability audit is read-only. It proves Self-Construction docs are discoverable, tagged and rooted before runtime promotion.',
        ];
    }

    /**
     * @param  array{workspace?: string|null, target?: string|null}  $options
     * @return array<string, mixed>
     */
    public function promotionGate(array $options = []): array
    {
        $snapshot = $this->snapshot($options);
        $packet = $this->metaSddPacket($options);
        $receipt = $this->receiptPreview($options);
        $traceability = $this->traceabilityAudit($options);

        $checks = [
            [
                'id' => 'documentation_complete',
                'passed' => data_get($snapshot, 'summary.missing_doc_count') === 0,
                'evidence' => 'readiness.summary.missing_doc_count',
                'blocks_promotion' => true,
            ],
            [
                'id' => 'meta_sdd_candidate_ready',
                'passed' => data_get($packet, 'status') === 'candidate_ready',
                'evidence' => 'meta_sdd.status',
                'blocks_promotion' => true,
            ],
            [
                'id' => 'receipt_preview_ready',
                'passed' => data_get($receipt, 'status') === 'preview_ready'
                    && data_get($receipt, 'execution_allowed') === false,
                'evidence' => 'receipt_preview.status + execution_allowed',
                'blocks_promotion' => true,
            ],
            [
                'id' => 'traceability_clean',
                'passed' => data_get($traceability, 'summary.violation_count') === 0,
                'evidence' => 'traceability.summary.violation_count',
                'blocks_promotion' => true,
            ],
            [
                'id' => 'self_programming_still_disabled',
                'passed' => data_get($snapshot, 'safety_contract.self_programming_allowed') === false
                    && data_get($snapshot, 'safety_contract.write_tools_allowed') === false,
                'evidence' => 'safety_contract',
                'blocks_promotion' => true,
            ],
        ];

        $failed = array_values(array_filter($checks, fn (array $check): bool => ! $check['passed']));
        $canPromote = $failed === [];

        return [
            'schema_version' => 'atlas.self_construction_promotion_gate.v1',
            'status' => $canPromote ? 'promotion_ready' : 'blocked',
            'mode' => 'read_only_promotion_gate',
            'execution_allowed' => false,
            'current_phase' => 'phase_4_5_traceability_guardrail',
            'recommended_next_phase' => $canPromote ? 'phase_5_low_risk_agent_execution_candidate' : 'remain_in_current_phase',
            'maturity_delta' => [
                'current_runtime' => 'L2_receipt_preview_and_traceability',
                'candidate_runtime' => $canPromote ? 'L3_low_risk_execution_candidate' : 'blocked',
                'autonomous_self_programming' => 'L0_not_allowed',
            ],
            'checks' => $checks,
            'blocking_failures' => $failed,
            'promotion_conditions' => [
                'human_review_required' => true,
                'signed_decision_receipt_required' => true,
                'allowed_first_execution_scope' => [
                    'docs_only',
                    'tests_only',
                    'read_only_report_generation',
                ],
                'forbidden_first_execution_scope' => [
                    'kernel_policy_mutation',
                    'memory_privacy_mutation',
                    'provider_runtime_change',
                    'voice_realtime_runtime_change',
                    'database_migration',
                    'route_mutation',
                ],
            ],
            'required_gates' => [
                'php artisan atlas:ai:self-construction --promotion-gate --json',
                'php artisan atlas:ai:self-construction --traceability --json',
                'php artisan atlas:ai:self-construction --receipt-preview --json',
                'php artisan test tests/Feature/Ai/AtlasAiSelfConstructionCommandTest.php',
                'php artisan atlas:engineering:knowledge docs-health --json',
                'php artisan atlas:ai:architecture-validate --json',
                'git diff --check',
            ],
            'next_safe_block' => [
                'block' => 'Phase 5 low-risk execution candidate',
                'scope' => 'generate a signed preview for docs-only/test-only self-construction work before any patch execution',
                'risk' => 'medium',
                'requires_user_approval' => true,
            ],
            'human_summary' => $canPromote
                ? 'Self-Construction OS is ready for human-reviewed Phase 5 candidate planning. Execution remains disabled until a signed Decision Receipt exists.'
                : 'Self-Construction OS is blocked from promotion until all gate checks pass.',
        ];
    }

    /**
     * @return list<string>
     */
    private function requiredDocs(): array
    {
        return [
            'docs/engineering-knowledge-base/atlas-ai-self-construction-os.md',
            'docs/engineering-knowledge-base/self-construction/constitution.md',
            'docs/engineering-knowledge-base/self-construction/meta-sdd-contract.md',
            'docs/engineering-knowledge-base/self-construction/capability-maturity-ladder.md',
            'docs/engineering-knowledge-base/self-construction/build-graph.md',
            'docs/engineering-knowledge-base/self-construction/implementation-priority-engine.md',
            'docs/engineering-knowledge-base/self-construction/autonomous-implementation-loop.md',
            'docs/engineering-knowledge-base/self-construction/self-programming-safety-contract.md',
            'docs/engineering-knowledge-base/self-construction/quality-bar-and-metrics.md',
            'docs/engineering-knowledge-base/self-construction/failure-modes.md',
            'docs/engineering-knowledge-base/self-construction/builder-persona-and-handoff.md',
            'docs/engineering-knowledge-base/self-construction/runtime-implementation-roadmap.md',
            'docs/ap/AP-691-atlas-self-construction-os-contract.md',
        ];
    }

    /**
     * @return list<string>
     */
    private function receiptAllowedFiles(): array
    {
        return [
            'app/Services/Ai/SelfConstruction/AtlasSelfConstructionReadinessService.php',
            'app/Console/Commands/AtlasAiSelfConstructionCommand.php',
            'tests/Feature/Ai/AtlasAiSelfConstructionCommandTest.php',
            'docs/engineering-knowledge-base/atlas-ai-self-construction-os.md',
            'docs/engineering-knowledge-base/self-construction/runtime-implementation-roadmap.md',
        ];
    }

    /**
     * @return array{path: string, exists: bool, line_count: int|null}
     */
    private function docStatus(string $path): array
    {
        $absolutePath = base_path($path);

        return [
            'path' => $path,
            'exists' => is_file($absolutePath),
            'line_count' => is_file($absolutePath) ? count(file($absolutePath, FILE_IGNORE_NEW_LINES)) : null,
        ];
    }

    private function docContent(string $path): string
    {
        $absolutePath = base_path($path);

        return is_file($absolutePath) ? (string) file_get_contents($absolutePath) : '';
    }
}
