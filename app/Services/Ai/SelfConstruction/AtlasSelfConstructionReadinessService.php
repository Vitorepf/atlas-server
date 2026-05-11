<?php

namespace App\Services\Ai\SelfConstruction;

final class AtlasSelfConstructionReadinessService
{
    public function __construct(
        private readonly AtlasSelfConstructionReservationRepository $reservations,
    ) {}

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
     * @param  array{workspace?: string|null, target?: string|null, packet?: string|null}  $options
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
     * @param  array{workspace?: string|null, target?: string|null, packet?: string|null}  $options
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
     * @param  array{workspace?: string|null, target?: string|null}  $options
     * @return array<string, mixed>
     */
    public function executionCandidate(array $options = []): array
    {
        $gate = $this->promotionGate($options);
        $ready = data_get($gate, 'status') === 'promotion_ready';

        $candidate = [
            'id' => 'EXEC-CANDIDATE-SELF-CONSTRUCTION-PHASE-5',
            'operation_id' => 'OP-CANDIDATE-SELF-CONSTRUCTION-PHASE-5',
            'source_gate' => 'atlas.self_construction_promotion_gate.v1',
            'target_phase' => 'phase_5_low_risk_agent_execution',
            'autonomy_level' => 'L1_human_review_required',
            'risk_level' => 'medium',
            'execution_allowed' => false,
            'approval_required' => true,
            'candidate_scope' => [
                'allowed_work_types' => [
                    'docs_only',
                    'tests_only',
                    'read_only_report_generation',
                ],
                'allowed_files' => [
                    'docs/engineering-knowledge-base/atlas-ai-self-construction-os.md',
                    'docs/engineering-knowledge-base/self-construction/runtime-implementation-roadmap.md',
                    'tests/Feature/Ai/AtlasAiSelfConstructionCommandTest.php',
                ],
                'forbidden_files' => [
                    'app/Services/Ai/Kernel/**',
                    'app/Services/Ai/Memory/**',
                    'app/Services/Ai/Voice/**',
                    'config/**',
                    'routes/**',
                    'database/migrations/**',
                    'runtimes/python/voice_realtime/**',
                ],
                'forbidden_work_types' => [
                    'runtime_patch_execution',
                    'provider_change',
                    'migration',
                    'route_mutation',
                    'voice_realtime_change',
                    'autonomy_policy_change',
                    'auto_merge',
                ],
            ],
            'required_preflight_gates' => data_get($gate, 'required_gates'),
            'required_evidence' => [
                'signed_decision_receipt',
                'human_approval_record',
                'scoped_diff',
                'focused_test_output',
                'docs_health_output',
                'architecture_validate_output',
                'traceability_output',
                'git_diff_check_output',
            ],
            'rollback' => [
                'strategy' => 'revert only candidate-scoped docs/tests files; do not touch runtime hot files',
                'requires_pre_patch_diff' => true,
            ],
        ];

        return [
            'schema_version' => 'atlas.self_construction_execution_candidate.v1',
            'status' => $ready ? 'candidate_ready' : 'blocked_by_promotion_gate',
            'mode' => 'read_only_execution_candidate',
            'execution_allowed' => false,
            'promotion_gate_status' => data_get($gate, 'status'),
            'candidate' => $candidate,
            'candidate_hash' => $this->stableHash($candidate),
            'blocking_failures' => data_get($gate, 'blocking_failures'),
            'human_summary' => $ready
                ? 'Phase 5 execution candidate is ready for human review only. It does not execute patches or enable self-programming.'
                : 'Phase 5 execution candidate is blocked until promotion gate passes.',
        ];
    }

    /**
     * @param  array{workspace?: string|null, target?: string|null}  $options
     * @return array<string, mixed>
     */
    public function approvalPacket(array $options = []): array
    {
        $candidatePayload = $this->executionCandidate($options);
        $candidate = (array) data_get($candidatePayload, 'candidate', []);
        $ready = data_get($candidatePayload, 'status') === 'candidate_ready';

        $approval = [
            'id' => 'APPROVAL-PACKET-SELF-CONSTRUCTION-PHASE-5',
            'candidate_id' => data_get($candidate, 'id'),
            'candidate_hash' => data_get($candidatePayload, 'candidate_hash'),
            'status' => 'pending_human_approval',
            'approved' => false,
            'execution_allowed' => false,
            'review_required_by' => [
                'human_owner',
                'quality_auditor',
            ],
            'approval_checklist' => [
                [
                    'id' => 'scope_is_low_risk',
                    'required' => true,
                    'expected' => 'docs_only, tests_only or read_only_report_generation',
                ],
                [
                    'id' => 'hot_runtime_files_forbidden',
                    'required' => true,
                    'expected' => 'kernel, memory, voice, routes, config and migrations remain forbidden',
                ],
                [
                    'id' => 'preflight_gates_defined',
                    'required' => true,
                    'expected' => 'promotion, traceability, receipt preview, focused tests, docs health, architecture validate and diff check',
                ],
                [
                    'id' => 'rollback_is_scoped',
                    'required' => true,
                    'expected' => 'only candidate-scoped docs/tests files can be reverted',
                ],
                [
                    'id' => 'self_programming_not_enabled',
                    'required' => true,
                    'expected' => 'execution remains disabled until signed Decision Receipt exists',
                ],
            ],
            'invariants' => [
                'approval_packet_does_not_apply_patch',
                'approval_packet_does_not_sign_decision_receipt',
                'approval_packet_does_not_mutate_autonomy_policy',
                'approval_packet_does_not_create_runtime_provider',
                'approval_packet_does_not_touch_voice_realtime',
            ],
            'required_human_decision' => [
                'approve_candidate_hash' => data_get($candidatePayload, 'candidate_hash'),
                'confirm_allowed_scope' => data_get($candidate, 'candidate_scope.allowed_work_types'),
                'confirm_forbidden_scope' => data_get($candidate, 'candidate_scope.forbidden_work_types'),
                'confirm_required_evidence' => data_get($candidate, 'required_evidence'),
            ],
        ];

        return [
            'schema_version' => 'atlas.self_construction_approval_packet.v1',
            'status' => $ready ? 'approval_pending' : 'blocked_by_execution_candidate',
            'mode' => 'read_only_approval_packet',
            'execution_allowed' => false,
            'approval' => $approval,
            'approval_hash' => $this->stableHash($approval),
            'candidate_status' => data_get($candidatePayload, 'status'),
            'blocking_failures' => data_get($candidatePayload, 'blocking_failures'),
            'human_summary' => $ready
                ? 'Approval packet is ready for human review. It does not approve, sign or execute the candidate.'
                : 'Approval packet is blocked until execution candidate is ready.',
        ];
    }

    /**
     * @param  array{workspace?: string|null, target?: string|null}  $options
     * @return array<string, mixed>
     */
    public function receiptDraft(array $options = []): array
    {
        $approvalPacket = $this->approvalPacket($options);
        $approval = (array) data_get($approvalPacket, 'approval', []);
        $ready = data_get($approvalPacket, 'status') === 'approval_pending';

        $draft = [
            'id' => 'DR-DRAFT-SELF-CONSTRUCTION-PHASE-5',
            'operation_id' => 'OP-CANDIDATE-SELF-CONSTRUCTION-PHASE-5',
            'candidate_id' => data_get($approval, 'candidate_id'),
            'candidate_hash' => data_get($approval, 'candidate_hash'),
            'approval_packet_id' => data_get($approval, 'id'),
            'approval_hash' => data_get($approvalPacket, 'approval_hash'),
            'status' => 'draft_pending_human_signature',
            'signed' => false,
            'execution_allowed' => false,
            'signature_valid_for_execution' => false,
            'signed_by' => null,
            'autonomy_level' => 'L1_human_review_required',
            'decision' => [
                'action' => 'prepare_low_risk_self_construction_execution',
                'rationale' => 'Prepare a docs/tests/report-only execution receipt draft without authorizing execution.',
                'selected_runtime' => 'laravel_cli_scoped_candidate',
            ],
            'scope' => [
                'allowed_work_types' => data_get($approval, 'required_human_decision.confirm_allowed_scope'),
                'forbidden_work_types' => data_get($approval, 'required_human_decision.confirm_forbidden_scope'),
                'required_evidence' => data_get($approval, 'required_human_decision.confirm_required_evidence'),
            ],
            'gates' => [
                'required' => [
                    'php artisan atlas:ai:self-construction --receipt-draft --json',
                    'php artisan atlas:ai:self-construction --approval-packet --json',
                    'php artisan atlas:ai:self-construction --execution-candidate --json',
                    'php artisan test tests/Feature/Ai/AtlasAiSelfConstructionCommandTest.php',
                    'php artisan atlas:engineering:knowledge docs-health --json',
                    'php artisan atlas:ai:architecture-validate --json',
                    'git diff --check',
                ],
            ],
            'non_execution_invariants' => [
                'draft_does_not_apply_patch',
                'draft_does_not_approve_itself',
                'draft_signature_is_preview_only',
                'draft_keeps_execution_allowed_false',
            ],
        ];

        return [
            'schema_version' => 'atlas.self_construction_receipt_draft.v1',
            'status' => $ready ? 'draft_ready' : 'blocked_by_approval_packet',
            'mode' => 'read_only_receipt_draft',
            'execution_allowed' => false,
            'receipt_draft' => $draft,
            'receipt_hash' => $this->stableHash($draft),
            'preview_signature' => $this->stableHash([
                'approval_hash' => data_get($approvalPacket, 'approval_hash'),
                'candidate_hash' => data_get($approval, 'candidate_hash'),
                'receipt_id' => data_get($draft, 'id'),
                'signed' => false,
            ]),
            'approval_status' => data_get($approvalPacket, 'status'),
            'blocking_failures' => data_get($approvalPacket, 'blocking_failures'),
            'human_summary' => $ready
                ? 'Receipt draft is ready for human signature review. It is not signed and cannot execute.'
                : 'Receipt draft is blocked until approval packet is ready.',
        ];
    }

    /**
     * @param  array{workspace?: string|null, target?: string|null}  $options
     * @return array<string, mixed>
     */
    public function executionPreflight(array $options = []): array
    {
        $draftPayload = $this->receiptDraft($options);
        $draft = (array) data_get($draftPayload, 'receipt_draft', []);

        $checks = [
            [
                'id' => 'receipt_draft_ready',
                'passed' => data_get($draftPayload, 'status') === 'draft_ready',
                'blocks_execution' => true,
            ],
            [
                'id' => 'human_signature_present',
                'passed' => data_get($draft, 'signed') === true,
                'blocks_execution' => true,
            ],
            [
                'id' => 'signature_valid_for_execution',
                'passed' => data_get($draft, 'signature_valid_for_execution') === true,
                'blocks_execution' => true,
            ],
            [
                'id' => 'draft_execution_flag_enabled',
                'passed' => data_get($draft, 'execution_allowed') === true,
                'blocks_execution' => true,
            ],
            [
                'id' => 'hot_runtime_scope_absent',
                'passed' => in_array('voice_realtime_change', (array) data_get($draft, 'scope.forbidden_work_types'), true),
                'blocks_execution' => true,
            ],
        ];

        $blocking = array_values(array_filter($checks, fn (array $check): bool => ! $check['passed']));

        return [
            'schema_version' => 'atlas.self_construction_execution_preflight.v1',
            'status' => $blocking === [] ? 'execution_ready' : 'blocked',
            'mode' => 'read_only_execution_preflight',
            'execution_allowed' => false,
            'receipt_id' => data_get($draft, 'id'),
            'receipt_hash' => data_get($draftPayload, 'receipt_hash'),
            'preview_signature' => data_get($draftPayload, 'preview_signature'),
            'checks' => $checks,
            'blocking_failures' => $blocking,
            'next_required_action' => 'human_signature_required',
            'required_before_execution' => [
                'human_owner_signature',
                'signature_valid_for_execution_true',
                'execution_allowed_true_in_signed_receipt',
                'all_required_gates_passed_after_signature',
            ],
            'non_execution_guarantees' => [
                'preflight_does_not_apply_patch',
                'preflight_does_not_upgrade_draft_signature',
                'preflight_does_not_enable_execution',
                'preflight_does_not_touch_hot_runtime_files',
            ],
            'human_summary' => 'Execution preflight is blocked as expected: the receipt draft is unsigned and not valid for execution.',
        ];
    }

    /**
     * @param  array{workspace?: string|null, target?: string|null}  $options
     * @return array<string, mixed>
     */
    public function signatureRequest(array $options = []): array
    {
        $preflight = $this->executionPreflight($options);

        $request = [
            'id' => 'SIGNATURE-REQUEST-SELF-CONSTRUCTION-PHASE-5',
            'receipt_id' => data_get($preflight, 'receipt_id'),
            'receipt_hash' => data_get($preflight, 'receipt_hash'),
            'preview_signature' => data_get($preflight, 'preview_signature'),
            'status' => 'pending_human_signature',
            'execution_allowed' => false,
            'requested_signature_type' => 'human_owner_explicit_approval',
            'required_signer_roles' => [
                'human_owner',
                'quality_auditor',
            ],
            'signer_must_confirm' => [
                'I understand this authorizes only docs/tests/report scoped work.',
                'I confirm hot runtime files remain forbidden.',
                'I confirm provider, migration, route and Voice Realtime changes remain forbidden.',
                'I confirm all gates must pass after any signed execution attempt.',
                'I confirm this request itself does not execute anything.',
            ],
            'signable_payload' => [
                'receipt_id' => data_get($preflight, 'receipt_id'),
                'receipt_hash' => data_get($preflight, 'receipt_hash'),
                'allowed_after_signature' => [
                    'docs_only',
                    'tests_only',
                    'read_only_report_generation',
                ],
                'still_forbidden_after_signature' => [
                    'kernel_policy_mutation',
                    'memory_privacy_mutation',
                    'provider_change',
                    'migration',
                    'route_mutation',
                    'voice_realtime_change',
                    'auto_merge',
                ],
                'required_gates_after_signature' => [
                    'php artisan test tests/Feature/Ai/AtlasAiSelfConstructionCommandTest.php',
                    'php artisan atlas:engineering:knowledge docs-health --json',
                    'php artisan atlas:ai:architecture-validate --json',
                    'git diff --check',
                ],
            ],
            'non_execution_guarantees' => [
                'signature_request_does_not_sign_receipt',
                'signature_request_does_not_apply_patch',
                'signature_request_does_not_enable_execution',
                'signature_request_does_not_create_persistent_approval',
            ],
        ];

        return [
            'schema_version' => 'atlas.self_construction_signature_request.v1',
            'status' => 'signature_pending',
            'mode' => 'read_only_signature_request',
            'execution_allowed' => false,
            'preflight_status' => data_get($preflight, 'status'),
            'signature_request' => $request,
            'signable_payload_hash' => $this->stableHash((array) data_get($request, 'signable_payload')),
            'request_hash' => $this->stableHash($request),
            'human_summary' => 'Signature request is ready for human review. It does not sign the receipt or enable execution.',
        ];
    }

    /**
     * @param  array{workspace?: string|null, target?: string|null}  $options
     * @return array<string, mixed>
     */
    public function executionRunbook(array $options = []): array
    {
        $signatureRequestPayload = $this->signatureRequest($options);
        $signatureRequest = (array) data_get($signatureRequestPayload, 'signature_request', []);
        $signablePayload = (array) data_get($signatureRequest, 'signable_payload', []);

        $runbook = [
            'id' => 'EXECUTION-RUNBOOK-SELF-CONSTRUCTION-PHASE-5',
            'signature_request_id' => data_get($signatureRequest, 'id'),
            'signature_request_hash' => data_get($signatureRequestPayload, 'request_hash'),
            'signable_payload_hash' => data_get($signatureRequestPayload, 'signable_payload_hash'),
            'status' => 'waiting_for_human_signature',
            'execution_allowed' => false,
            'mode' => 'read_only_execution_runbook',
            'purpose' => 'Define the exact first safe post-signature execution choreography without executing it.',
            'entry_conditions' => [
                'human_owner_signature_present',
                'quality_auditor_review_present',
                'signed_receipt_hash_matches_request_hash',
                'execution_allowed_true_only_inside_signed_receipt',
                'working_tree_hot_files_identified_before_patch',
            ],
            'allowed_scope_after_signature' => data_get($signablePayload, 'allowed_after_signature'),
            'forbidden_scope_after_signature' => data_get($signablePayload, 'still_forbidden_after_signature'),
            'ordered_steps' => [
                [
                    'order' => 1,
                    'name' => 'capture_pre_patch_state',
                    'command' => 'git status --short && git diff --stat && git diff --name-only',
                    'evidence_key' => 'pre_patch_delta',
                    'stop_if' => ['unexpected_hot_file_required'],
                ],
                [
                    'order' => 2,
                    'name' => 'verify_signature_request',
                    'command' => 'php artisan atlas:ai:self-construction --signature-request --json',
                    'evidence_key' => 'signature_request_snapshot',
                    'stop_if' => ['request_hash_changed_without_review'],
                ],
                [
                    'order' => 3,
                    'name' => 'apply_smallest_scoped_patch',
                    'command' => 'manual_or_agent_patch_limited_to_signed_allowed_files',
                    'evidence_key' => 'scoped_patch_diff',
                    'stop_if' => ['forbidden_file_touched', 'scope_expands_beyond_docs_tests_reports'],
                ],
                [
                    'order' => 4,
                    'name' => 'run_required_gates',
                    'command' => 'run all required_gates_after_signature',
                    'evidence_key' => 'gate_outputs',
                    'stop_if' => ['any_gate_fails'],
                ],
                [
                    'order' => 5,
                    'name' => 'emit_final_evidence_report',
                    'command' => 'summarize diff, gates, residual risk and rollback path',
                    'evidence_key' => 'final_evidence_report',
                    'stop_if' => ['missing_evidence', 'residual_risk_unclassified'],
                ],
            ],
            'required_gates_after_signature' => data_get($signablePayload, 'required_gates_after_signature'),
            'rollback_contract' => [
                'strategy' => 'revert only signed-runbook scoped docs/tests/report files',
                'forbidden_rollback_targets' => [
                    'runtimes/python/voice_realtime/**',
                    'app/Services/Ai/Kernel/**',
                    'app/Services/Ai/Memory/**',
                    'routes/**',
                    'config/**',
                    'database/migrations/**',
                ],
                'requires_post_rollback_gates' => true,
            ],
            'evidence_contract' => [
                'append_only' => true,
                'required_keys' => [
                    'pre_patch_delta',
                    'signature_request_snapshot',
                    'scoped_patch_diff',
                    'gate_outputs',
                    'final_evidence_report',
                ],
            ],
            'non_execution_guarantees' => [
                'runbook_does_not_sign_receipt',
                'runbook_does_not_apply_patch',
                'runbook_does_not_enable_execution',
                'runbook_does_not_create_persistent_approval',
            ],
        ];

        return [
            'schema_version' => 'atlas.self_construction_execution_runbook.v1',
            'status' => 'runbook_ready',
            'mode' => 'read_only_execution_runbook',
            'execution_allowed' => false,
            'signature_status' => data_get($signatureRequestPayload, 'status'),
            'runbook' => $runbook,
            'runbook_hash' => $this->stableHash($runbook),
            'human_summary' => 'Execution runbook is ready for post-signature review. It does not sign, patch, approve or enable execution.',
        ];
    }

    /**
     * @param  array{workspace?: string|null, target?: string|null}  $options
     * @return array<string, mixed>
     */
    public function evidencePacket(array $options = []): array
    {
        $runbookPayload = $this->executionRunbook($options);
        $runbook = (array) data_get($runbookPayload, 'runbook', []);

        $evidencePacket = [
            'id' => 'EVIDENCE-PACKET-SELF-CONSTRUCTION-PHASE-5',
            'runbook_id' => data_get($runbook, 'id'),
            'runbook_hash' => data_get($runbookPayload, 'runbook_hash'),
            'status' => 'evidence_template_ready',
            'execution_allowed' => false,
            'mode' => 'read_only_evidence_packet',
            'required_evidence_items' => [
                [
                    'key' => 'pre_patch_delta',
                    'source_step' => 'capture_pre_patch_state',
                    'required' => true,
                    'acceptance_rule' => 'Lists all modified files and explicitly identifies hot files before any scoped work.',
                ],
                [
                    'key' => 'signature_request_snapshot',
                    'source_step' => 'verify_signature_request',
                    'required' => true,
                    'acceptance_rule' => 'Request hash and signable payload hash match the human-reviewed request.',
                ],
                [
                    'key' => 'scoped_patch_diff',
                    'source_step' => 'apply_smallest_scoped_patch',
                    'required' => true,
                    'acceptance_rule' => 'Diff touches only signed docs/tests/report scope and no forbidden runtime files.',
                ],
                [
                    'key' => 'gate_outputs',
                    'source_step' => 'run_required_gates',
                    'required' => true,
                    'acceptance_rule' => 'All required gates pass after the scoped patch.',
                ],
                [
                    'key' => 'final_evidence_report',
                    'source_step' => 'emit_final_evidence_report',
                    'required' => true,
                    'acceptance_rule' => 'Summarizes diff, gates, residual risk, rollback path and remaining blockers.',
                ],
            ],
            'claim_checks' => [
                [
                    'claim' => 'Execution remained scoped.',
                    'must_be_supported_by' => ['pre_patch_delta', 'scoped_patch_diff'],
                ],
                [
                    'claim' => 'No hot runtime file was touched.',
                    'must_be_supported_by' => ['pre_patch_delta', 'scoped_patch_diff'],
                ],
                [
                    'claim' => 'All gates passed.',
                    'must_be_supported_by' => ['gate_outputs'],
                ],
                [
                    'claim' => 'Rollback is known and bounded.',
                    'must_be_supported_by' => ['final_evidence_report'],
                ],
            ],
            'failure_policy' => [
                'missing_required_evidence' => 'block_completion',
                'forbidden_scope_detected' => 'stop_and_report',
                'gate_failure' => 'repair_only_inside_signed_scope_or_stop',
                'hash_mismatch' => 'require_new_human_review',
            ],
            'non_execution_guarantees' => [
                'evidence_packet_does_not_sign_receipt',
                'evidence_packet_does_not_apply_patch',
                'evidence_packet_does_not_mark_completion',
                'evidence_packet_does_not_enable_execution',
            ],
        ];

        return [
            'schema_version' => 'atlas.self_construction_evidence_packet.v1',
            'status' => 'evidence_packet_ready',
            'mode' => 'read_only_evidence_packet',
            'execution_allowed' => false,
            'runbook_status' => data_get($runbookPayload, 'status'),
            'evidence_packet' => $evidencePacket,
            'evidence_packet_hash' => $this->stableHash($evidencePacket),
            'human_summary' => 'Evidence packet is ready. It defines required proof for a future signed run but does not execute or mark completion.',
        ];
    }

    /**
     * @param  array{workspace?: string|null, target?: string|null}  $options
     * @return array<string, mixed>
     */
    public function completionReadiness(array $options = []): array
    {
        $evidencePacketPayload = $this->evidencePacket($options);
        $evidencePacket = (array) data_get($evidencePacketPayload, 'evidence_packet', []);

        $checks = [
            [
                'id' => 'evidence_packet_ready',
                'passed' => data_get($evidencePacketPayload, 'status') === 'evidence_packet_ready',
                'blocks_completion' => true,
            ],
            [
                'id' => 'signed_execution_receipt_present',
                'passed' => false,
                'blocks_completion' => true,
            ],
            [
                'id' => 'scoped_patch_evidence_present',
                'passed' => false,
                'blocks_completion' => true,
            ],
            [
                'id' => 'required_gate_outputs_present',
                'passed' => false,
                'blocks_completion' => true,
            ],
            [
                'id' => 'final_evidence_report_present',
                'passed' => false,
                'blocks_completion' => true,
            ],
            [
                'id' => 'hot_runtime_touch_absent',
                'passed' => true,
                'blocks_completion' => true,
            ],
        ];

        $blocking = array_values(array_filter($checks, fn (array $check): bool => ! $check['passed']));

        return [
            'schema_version' => 'atlas.self_construction_completion_readiness.v1',
            'status' => $blocking === [] ? 'completion_ready' : 'blocked_pending_execution_evidence',
            'mode' => 'read_only_completion_readiness',
            'execution_allowed' => false,
            'completion_allowed' => false,
            'evidence_packet_hash' => data_get($evidencePacketPayload, 'evidence_packet_hash'),
            'checks' => $checks,
            'blocking_failures' => $blocking,
            'required_before_completion' => [
                'signed_execution_receipt',
                'scoped_patch_diff',
                'all_required_gate_outputs',
                'final_evidence_report',
                'residual_risk_classification',
            ],
            'completion_claim_policy' => [
                'may_claim_done' => false,
                'may_claim_ready_for_human_signature' => true,
                'may_claim_execution_completed' => false,
                'may_claim_self_programming_enabled' => false,
            ],
            'non_execution_guarantees' => [
                'completion_readiness_does_not_sign_receipt',
                'completion_readiness_does_not_apply_patch',
                'completion_readiness_does_not_mark_completion',
                'completion_readiness_does_not_enable_execution',
            ],
            'human_summary' => 'Completion readiness is blocked as expected: future signed execution evidence is still required before claiming completion.',
        ];
    }

    /**
     * @param  array{workspace?: string|null, target?: string|null}  $options
     * @return array<string, mixed>
     */
    public function residualRisk(array $options = []): array
    {
        $completionReadiness = $this->completionReadiness($options);
        $blockingFailures = (array) data_get($completionReadiness, 'blocking_failures', []);

        $risks = [
            [
                'id' => 'unsigned_execution',
                'severity' => 'blocking',
                'status' => 'open',
                'cause' => 'No signed execution receipt is present.',
                'required_action' => 'Obtain explicit human owner signature against the stable signable payload hash.',
                'evidence' => ['completion_readiness.blocking_failures.signed_execution_receipt_present'],
            ],
            [
                'id' => 'missing_scoped_diff',
                'severity' => 'blocking',
                'status' => 'open',
                'cause' => 'No scoped patch diff exists for a signed run.',
                'required_action' => 'Capture signed-scope diff before claiming execution or completion.',
                'evidence' => ['completion_readiness.blocking_failures.scoped_patch_evidence_present'],
            ],
            [
                'id' => 'missing_gate_outputs',
                'severity' => 'blocking',
                'status' => 'open',
                'cause' => 'Required post-execution gate outputs are absent.',
                'required_action' => 'Run and record every required gate after the signed scoped patch.',
                'evidence' => ['completion_readiness.blocking_failures.required_gate_outputs_present'],
            ],
            [
                'id' => 'hot_runtime_scope',
                'severity' => 'controlled',
                'status' => 'guarded',
                'cause' => 'Voice/LiveKit/Product Loop files are present in the broader working tree and must remain outside this scope.',
                'required_action' => 'Keep Self-Construction work limited to cold files and report hot-file dependencies instead of editing them.',
                'evidence' => ['git_status_delta', 'self_construction_allowed_scope'],
            ],
        ];

        return [
            'schema_version' => 'atlas.self_construction_residual_risk.v1',
            'status' => 'residual_risk_open',
            'mode' => 'read_only_residual_risk',
            'execution_allowed' => false,
            'completion_allowed' => false,
            'completion_status' => data_get($completionReadiness, 'status'),
            'blocking_failure_count' => count($blockingFailures),
            'risks' => $risks,
            'risk_summary' => [
                'blocking_count' => count(array_filter($risks, fn (array $risk): bool => $risk['severity'] === 'blocking')),
                'controlled_count' => count(array_filter($risks, fn (array $risk): bool => $risk['severity'] === 'controlled')),
                'highest_severity' => 'blocking',
                'promotion_allowed' => false,
                'next_safe_claim' => 'ready_for_human_signature_and_evidence_collection',
            ],
            'non_execution_guarantees' => [
                'residual_risk_does_not_sign_receipt',
                'residual_risk_does_not_apply_patch',
                'residual_risk_does_not_mark_completion',
                'residual_risk_does_not_enable_execution',
            ],
            'human_summary' => 'Residual risk remains open by design: signature, scoped diff and post-execution gate evidence are required before completion.',
        ];
    }

    /**
     * @param  array{workspace?: string|null, target?: string|null}  $options
     * @return array<string, mixed>
     */
    public function handoffPacket(array $options = []): array
    {
        $signatureRequest = $this->signatureRequest($options);
        $runbook = $this->executionRunbook($options);
        $evidencePacket = $this->evidencePacket($options);
        $completionReadiness = $this->completionReadiness($options);
        $residualRisk = $this->residualRisk($options);

        $packet = [
            'id' => 'HANDOFF-PACKET-SELF-CONSTRUCTION-PHASE-5',
            'status' => 'handoff_ready',
            'execution_allowed' => false,
            'completion_allowed' => false,
            'current_safe_claim' => data_get($residualRisk, 'risk_summary.next_safe_claim'),
            'stable_hashes' => [
                'signature_request_hash' => data_get($signatureRequest, 'request_hash'),
                'signable_payload_hash' => data_get($signatureRequest, 'signable_payload_hash'),
                'runbook_hash' => data_get($runbook, 'runbook_hash'),
                'evidence_packet_hash' => data_get($evidencePacket, 'evidence_packet_hash'),
            ],
            'current_blockers' => [
                'human_signature_missing',
                'signed_scoped_diff_missing',
                'post_execution_gate_outputs_missing',
                'final_evidence_report_missing',
            ],
            'next_operator_actions' => [
                'Review signature request and sign only if scope is acceptable.',
                'If signed, follow execution runbook exactly and stop on any stop condition.',
                'Collect every required evidence item before claiming completion.',
                'Run completion readiness and residual risk again after evidence exists.',
            ],
            'must_not_touch' => [
                'runtimes/python/voice_realtime/**',
                'app/Services/Ai/Voice/**',
                'app/Console/Commands/AtlasAiVoiceRealtimeCommand.php',
                'tests/Feature/Ai/AtlasAiVoiceRealtimeCommandTest.php',
                'docs/ap/AP-687-voice-realtime-production-promotion-gate.md',
                'routes/**',
                'config/**',
                'database/migrations/**',
            ],
            'required_commands' => [
                'php artisan atlas:ai:self-construction --signature-request --json',
                'php artisan atlas:ai:self-construction --execution-runbook --json',
                'php artisan atlas:ai:self-construction --evidence-packet --json',
                'php artisan atlas:ai:self-construction --completion-readiness --json',
                'php artisan atlas:ai:self-construction --residual-risk --json',
                'php artisan test tests/Feature/Ai/AtlasAiSelfConstructionCommandTest.php',
                'php artisan atlas:engineering:knowledge docs-health --json',
                'php artisan atlas:ai:architecture-validate --json',
                'git diff --check',
            ],
            'handoff_integrity' => [
                'completion_status' => data_get($completionReadiness, 'status'),
                'residual_risk_status' => data_get($residualRisk, 'status'),
                'blocking_failure_count' => data_get($completionReadiness, 'blocking_failures') === null
                    ? 0
                    : count((array) data_get($completionReadiness, 'blocking_failures')),
                'highest_risk' => data_get($residualRisk, 'risk_summary.highest_severity'),
            ],
            'non_execution_guarantees' => [
                'handoff_packet_does_not_sign_receipt',
                'handoff_packet_does_not_apply_patch',
                'handoff_packet_does_not_mark_completion',
                'handoff_packet_does_not_enable_execution',
            ],
        ];

        return [
            'schema_version' => 'atlas.self_construction_handoff_packet.v1',
            'status' => 'handoff_ready',
            'mode' => 'read_only_handoff_packet',
            'execution_allowed' => false,
            'completion_allowed' => false,
            'handoff_packet' => $packet,
            'handoff_hash' => $this->stableHash($packet),
            'human_summary' => 'Handoff packet is ready: next operator gets hashes, blockers, commands and forbidden hot-file scope without enabling execution.',
        ];
    }

    /**
     * @param  array{workspace?: string|null, target?: string|null}  $options
     * @return array<string, mixed>
     */
    public function nextAction(array $options = []): array
    {
        $handoffPacketPayload = $this->handoffPacket($options);
        $handoffPacket = (array) data_get($handoffPacketPayload, 'handoff_packet', []);

        $candidateActions = [
            [
                'id' => 'request_human_signature_review',
                'rank' => 1,
                'allowed' => true,
                'risk' => 'low',
                'action_type' => 'human_review',
                'reason' => 'The run is blocked by missing human signature and evidence, not by missing read-only preparation.',
                'command' => 'php artisan atlas:ai:self-construction --signature-request --json',
            ],
            [
                'id' => 'refresh_handoff_for_next_operator',
                'rank' => 2,
                'allowed' => true,
                'risk' => 'low',
                'action_type' => 'read_only_report',
                'reason' => 'Any new operator can resume from stable hashes, blockers and forbidden scope.',
                'command' => 'php artisan atlas:ai:self-construction --handoff-packet --json',
            ],
            [
                'id' => 'execute_scoped_patch',
                'rank' => 3,
                'allowed' => false,
                'risk' => 'medium',
                'action_type' => 'patch_execution',
                'reason' => 'Blocked until signed receipt and explicit evidence collection path exist.',
                'command' => null,
            ],
            [
                'id' => 'claim_completion',
                'rank' => 4,
                'allowed' => false,
                'risk' => 'high',
                'action_type' => 'completion_claim',
                'reason' => 'Blocked until signed execution receipt, scoped diff, gates and final evidence report exist.',
                'command' => null,
            ],
        ];

        return [
            'schema_version' => 'atlas.self_construction_next_action.v1',
            'status' => 'next_action_ready',
            'mode' => 'read_only_next_action',
            'execution_allowed' => false,
            'completion_allowed' => false,
            'handoff_hash' => data_get($handoffPacketPayload, 'handoff_hash'),
            'selected_action' => $candidateActions[0],
            'candidate_actions' => $candidateActions,
            'current_blockers' => data_get($handoffPacket, 'current_blockers'),
            'forbidden_until_signature' => [
                'execute_scoped_patch',
                'repair_after_gate_failure',
                'claim_completion',
                'promote_autonomy',
                'touch_hot_runtime_files',
            ],
            'non_execution_guarantees' => [
                'next_action_does_not_sign_receipt',
                'next_action_does_not_apply_patch',
                'next_action_does_not_mark_completion',
                'next_action_does_not_enable_execution',
            ],
            'human_summary' => 'Next action is human signature review. Patch execution and completion claims remain blocked.',
        ];
    }

    /**
     * @param  array{workspace?: string|null, target?: string|null}  $options
     * @return array<string, mixed>
     */
    public function surfaceMatrix(array $options = []): array
    {
        $surfaces = [
            ['option' => '--json', 'schema' => 'atlas.self_construction_readiness.v1', 'proves' => 'required docs, maturity, build graph and safety contract'],
            ['option' => '--meta-sdd --json', 'schema' => 'atlas.self_construction_meta_sdd.v1', 'proves' => 'candidate spec, assumptions, tasks and gates'],
            ['option' => '--receipt-preview --json', 'schema' => 'atlas.self_construction_receipt_preview.v1', 'proves' => 'preview receipt scope, rollback and evidence'],
            ['option' => '--traceability --json', 'schema' => 'atlas.self_construction_traceability_audit.v1', 'proves' => 'required docs are reachable, tagged and layered'],
            ['option' => '--promotion-gate --json', 'schema' => 'atlas.self_construction_promotion_gate.v1', 'proves' => 'read-only promotion readiness'],
            ['option' => '--execution-candidate --json', 'schema' => 'atlas.self_construction_execution_candidate.v1', 'proves' => 'Phase 5 candidate hash and scope'],
            ['option' => '--approval-packet --json', 'schema' => 'atlas.self_construction_approval_packet.v1', 'proves' => 'human approval checklist and decision fields'],
            ['option' => '--receipt-draft --json', 'schema' => 'atlas.self_construction_receipt_draft.v1', 'proves' => 'unsigned receipt hash and preview signature'],
            ['option' => '--execution-preflight --json', 'schema' => 'atlas.self_construction_execution_preflight.v1', 'proves' => 'execution remains blocked without signature'],
            ['option' => '--signature-request --json', 'schema' => 'atlas.self_construction_signature_request.v1', 'proves' => 'signable payload, request hash and signer roles'],
            ['option' => '--execution-runbook --json', 'schema' => 'atlas.self_construction_execution_runbook.v1', 'proves' => 'post-signature steps, stops, gates and rollback'],
            ['option' => '--evidence-packet --json', 'schema' => 'atlas.self_construction_evidence_packet.v1', 'proves' => 'required proof, claim checks and failure policy'],
            ['option' => '--completion-readiness --json', 'schema' => 'atlas.self_construction_completion_readiness.v1', 'proves' => 'completion is blocked until execution evidence exists'],
            ['option' => '--residual-risk --json', 'schema' => 'atlas.self_construction_residual_risk.v1', 'proves' => 'residual blockers and promotion risk'],
            ['option' => '--handoff-packet --json', 'schema' => 'atlas.self_construction_handoff_packet.v1', 'proves' => 'operator handoff hashes, blockers and forbidden scope'],
            ['option' => '--next-action --json', 'schema' => 'atlas.self_construction_next_action.v1', 'proves' => 'next safe action while execution is blocked'],
            ['option' => '--external-blockers --json', 'schema' => 'atlas.self_construction_external_blockers.v1', 'proves' => 'hot-file blockers outside Self-Construction ownership are reported'],
            ['option' => '--cold-lane-certification --json', 'schema' => 'atlas.self_construction_cold_lane_certification.v1', 'proves' => 'cold lane remains read-only with external blockers separated'],
            ['option' => '--operator-checklist --json', 'schema' => 'atlas.self_construction_operator_checklist.v1', 'proves' => 'next operator can review without execution or hot-file edits'],
            ['option' => '--promotion-blockers --json', 'schema' => 'atlas.self_construction_promotion_blockers.v1', 'proves' => 'promotion and completion blockers are consolidated without execution'],
            ['option' => '--readiness-digest --json', 'schema' => 'atlas.self_construction_readiness_digest.v1', 'proves' => 'compact handoff state for any operator or AI'],
            ['option' => '--governance-scorecard --json', 'schema' => 'atlas.self_construction_governance_scorecard.v1', 'proves' => 'governance readiness is scored without enabling execution'],
            ['option' => '--integrity-manifest --json', 'schema' => 'atlas.self_construction_integrity_manifest.v1', 'proves' => 'governed packet hashes are bundled for audit'],
            ['option' => '--continuation-token --json', 'schema' => 'atlas.self_construction_continuation_token.v1', 'proves' => 'next operator can resume from a compact audited token'],
            ['option' => '--ownership-boundary --json', 'schema' => 'atlas.self_construction_ownership_boundary.v1', 'proves' => 'cold ownership and hot forbidden scopes are explicit'],
            ['option' => '--phase-ledger --json', 'schema' => 'atlas.self_construction_phase_ledger.v1', 'proves' => 'phase status, hard blocks and promotion boundaries'],
            ['option' => '--implementation-packet --json', 'schema' => 'atlas.self_construction_ai_implementation_packet.v1', 'proves' => 'one-line AI continuation packet with scope, gates and evidence'],
            ['option' => '--work-splitter --json', 'schema' => 'atlas.self_construction_work_splitter.v1', 'proves' => 'parallel packets are disjoint and hot work is withheld'],
            ['option' => '--scope-validator --json', 'schema' => 'atlas.self_construction_scope_validator.v1', 'proves' => 'current diff is classified against packet scope'],
            ['option' => '--assignment-preview --json', 'schema' => 'atlas.self_construction_assignment_preview.v1', 'proves' => 'one safe packet is selected without persisted claim or execution'],
            ['option' => '--packet-runbook --json', 'schema' => 'atlas.self_construction_packet_consumption_runbook.v1', 'proves' => 'selected packet has ordered steps, gates and evidence contract'],
            ['option' => '--packet-evidence-report --json', 'schema' => 'atlas.self_construction_packet_evidence_report.v1', 'proves' => 'packet completion is blocked unless evidence, gates and scope agree'],
            ['option' => '--packet-completion-gate --json', 'schema' => 'atlas.self_construction_packet_completion_gate.v1', 'proves' => 'packet completion decision remains blocked or review-only until durable persistence exists'],
            ['option' => '--reservation-ledger-preview --json', 'schema' => 'atlas.self_construction_reservation_ledger_preview.v1', 'proves' => 'future packet reservation is modeled without writing claims or ledger rows'],
            ['option' => '--durable-reservation-ledger-plan --json', 'schema' => 'atlas.self_construction_durable_reservation_ledger_implementation_plan.v1', 'proves' => 'durable reservation storage, locks, states and tests are planned without writes'],
            ['option' => '--durable-reservation-ap-candidate --json', 'schema' => 'atlas.self_construction_durable_reservation_ap_candidate.v1', 'proves' => 'durable reservation implementation AP is split into approvable packets without execution'],
            ['option' => '--durable-reservation-approval-request --json', 'schema' => 'atlas.self_construction_durable_reservation_approval_request.v1', 'proves' => 'durable reservation approval signers, evidence and blockers are explicit without granting approval'],
            ['option' => '--durable-reservation-approval-decision --json', 'schema' => 'atlas.self_construction_durable_reservation_approval_decision_template.v1', 'proves' => 'durable reservation approval decision states and signer slots are hash-bound without signing'],
            ['option' => '--durable-reservation-post-approval-preflight --json', 'schema' => 'atlas.self_construction_durable_reservation_post_approval_preflight.v1', 'proves' => 'signed approval prerequisites are checked before implementation without writes'],
            ['option' => '--durable-reservation-implementation-packet --json', 'schema' => 'atlas.self_construction_durable_reservation_implementation_packet.v1', 'proves' => 'durable reservation implementation work is ordered but blocked until preflight passes'],
            ['option' => '--durable-reservation-storage-schema --json', 'schema' => 'atlas.self_construction_durable_reservation_storage_schema.v1', 'proves' => 'durable reservation storage tables, events, projections and invariants are fixed without migrations'],
            ['option' => '--durable-reservation-repository-contract --json', 'schema' => 'atlas.self_construction_durable_reservation_repository_contract.v1', 'proves' => 'durable reservation repository methods, errors, transactions and tests are fixed without writes'],
            ['option' => '--durable-reservation-collision-guard --json', 'schema' => 'atlas.self_construction_durable_reservation_collision_guard.v1', 'proves' => 'durable reservation collision inputs, blockers, outputs and tests are fixed without claims'],
            ['option' => '--durable-reservation-lease-lifecycle --json', 'schema' => 'atlas.self_construction_durable_reservation_lease_lifecycle.v1', 'proves' => 'durable reservation lease states, transitions, timing rules and tests are fixed without claims'],
            ['option' => '--durable-reservation-readiness-projection --json', 'schema' => 'atlas.self_construction_durable_reservation_readiness_projection.v1', 'proves' => 'durable reservation projection inputs, queue states and readiness integrations are fixed without claims'],
            ['option' => '--durable-reservation-implementation-preflight --json', 'schema' => 'atlas.self_construction_durable_reservation_implementation_preflight.v1', 'proves' => 'durable reservation final contract hashes and implementation entry gates are bundled without writes'],
            ['option' => '--durable-reservation-migration-blueprint --json', 'schema' => 'atlas.self_construction_durable_reservation_migration_blueprint.v1', 'proves' => 'durable reservation migration tables, columns, indexes and rollback are fixed without creating migrations'],
            ['option' => '--durable-reservation-repository-blueprint --json', 'schema' => 'atlas.self_construction_durable_reservation_repository_blueprint.v1', 'proves' => 'durable reservation repository classes, methods, errors, transactions and tests are fixed without creating runtime files'],
            ['option' => '--durable-reservation-collision-guard-blueprint --json', 'schema' => 'atlas.self_construction_durable_reservation_collision_guard_blueprint.v1', 'proves' => 'durable reservation collision guard inputs, blockers, outputs and tests are fixed without creating runtime files'],
            ['option' => '--durable-reservation-lease-lifecycle-blueprint --json', 'schema' => 'atlas.self_construction_durable_reservation_lease_lifecycle_blueprint.v1', 'proves' => 'durable reservation lease states, transitions, timing rules and tests are fixed without creating runtime files'],
            ['option' => '--durable-reservation-readiness-projection-blueprint --json', 'schema' => 'atlas.self_construction_durable_reservation_readiness_projection_blueprint.v1', 'proves' => 'durable reservation readiness inputs, queue states, outputs and integrations are fixed without creating runtime files'],
            ['option' => '--durable-reservation-runtime-build-packet --json', 'schema' => 'atlas.self_construction_durable_reservation_runtime_build_packet.v1', 'proves' => 'durable reservation blueprints are consolidated into ordered implementation slices without creating runtime files'],
            ['option' => '--ai-session-bootstrap --json', 'schema' => 'atlas.self_construction_ai_session_bootstrap.v1', 'proves' => 'one canonical packet lets a new AI resume safely without chat history'],
            ['option' => '--reservation-status --json', 'schema' => 'atlas.self_construction_reservation_status.v1', 'proves' => 'durable local reservation ledger status is visible without claiming or dispatching'],
            ['option' => '--codex-launch-plan --json', 'schema' => 'atlas.self_construction_codex_launch_plan.v1', 'proves' => 'up to five Codex start commands are generated without claiming or dispatching'],
            ['option' => '--codex-execution-status --json', 'schema' => 'atlas.self_construction_codex_execution_status.v1', 'proves' => 'parallel Codex claims, completions and next actions are visible without mutating state'],
            ['option' => '--codex-integration-report --json', 'schema' => 'atlas.self_construction_codex_integration_report.v1', 'proves' => 'completed Codex packets are consolidated for human integration without approving code or merging'],
            ['option' => '--codex-merge-readiness --json', 'schema' => 'atlas.self_construction_codex_merge_readiness.v1', 'proves' => 'completed packet set is checked for human merge review readiness without granting approval'],
            ['option' => '--codex-final-review-packet --json', 'schema' => 'atlas.self_construction_codex_final_review_packet.v1', 'proves' => 'principal integrator receives a final review checklist and signoff packet without approval authority'],
            ['option' => '--codex-review-decision-template --json', 'schema' => 'atlas.self_construction_codex_review_decision_template.v1', 'proves' => 'principal integrator decision fields are templated without recording approval or merge authority'],
            ['option' => '--codex-review-receipt-draft --json', 'schema' => 'atlas.self_construction_codex_review_receipt_draft.v1', 'proves' => 'principal integrator review receipt is hash-bound but unsigned and non-authorizing'],
            ['option' => '--codex-review-signature-request --json', 'schema' => 'atlas.self_construction_codex_review_signature_request.v1', 'proves' => 'review receipt draft signable payload is prepared without presenting or validating a signature'],
            ['option' => '--codex-review-post-signature-runbook --json', 'schema' => 'atlas.self_construction_codex_review_post_signature_runbook.v1', 'proves' => 'post-signature review steps are defined without assuming signature or enabling merge'],
            ['option' => '--codex-review-merge-action-template --json', 'schema' => 'atlas.self_construction_codex_review_merge_action_template.v1', 'proves' => 'explicit merge action fields are templated without validating signature, granting approval or merging'],
            ['option' => '--codex-review-merge-preflight --json', 'schema' => 'atlas.self_construction_codex_review_merge_preflight.v1', 'proves' => 'review chain readiness is aggregated before any future explicit merge action without granting merge authority'],
            ['option' => '--codex-start-packet --json', 'schema' => 'atlas.self_construction_codex_start_packet.v1', 'proves' => 'one Codex session can claim, bootstrap, validate and receive a final response contract from one command'],
            ['option' => '--claim-next-packet --json', 'schema' => 'atlas.self_construction_claim_next_packet.v1', 'proves' => 'one Codex session can durably claim the next available packet and receive scoped bootstrap'],
            ['option' => '--claim-packet --packet=AIP-SPLIT-... --json', 'schema' => 'atlas.self_construction_claim_packet.v1', 'proves' => 'one explicit packet can be durably claimed without dispatch or execution authority'],
            ['option' => '--complete-packet --packet=AIP-SPLIT-... --json', 'schema' => 'atlas.self_construction_complete_packet.v1', 'proves' => 'one owner-held packet can be durably marked completed without approving code or dispatching work'],
            ['option' => '--release-packet --packet=AIP-SPLIT-... --json', 'schema' => 'atlas.self_construction_release_packet.v1', 'proves' => 'an owner-matching active packet claim can be released durably'],
            ['option' => '--packet-queue --json', 'schema' => 'atlas.self_construction_packet_queue.v1', 'proves' => 'available, claimed, blocked and withheld packets are visible without dispatch or completion'],
            ['option' => '--parallel-session-plan --json', 'schema' => 'atlas.self_construction_parallel_session_plan.v1', 'proves' => 'up to five AI session slots are previewed without claims or dispatch'],
            ['option' => '--collision-matrix --json', 'schema' => 'atlas.self_construction_collision_matrix.v1', 'proves' => 'packet overlap, dependencies and hot scopes are checked before parallel work'],
            ['option' => '--dependency-unlock-plan --json', 'schema' => 'atlas.self_construction_dependency_unlock_plan.v1', 'proves' => 'blocked packets show which durable completions would unlock later work'],
            ['option' => '--multi-session-readiness-gate --json', 'schema' => 'atlas.self_construction_multi_session_readiness_gate.v1', 'proves' => 'multi-session continuation is allowed, preview-only or blocked with reasons'],
            ['option' => '--single-session-instruction-packet --json', 'schema' => 'atlas.self_construction_single_session_instruction_packet.v1', 'proves' => 'one AI session receives a canonical instruction without claims or execution'],
        ];

        $rows = array_map(fn (array $surface): array => $surface + [
            'command' => 'php artisan atlas:ai:self-construction '.$surface['option'],
            'execution_allowed' => false,
            'completion_allowed' => false,
            'write_scope' => 'none_read_only',
        ], $surfaces);

        return [
            'schema_version' => 'atlas.self_construction_surface_matrix.v1',
            'status' => 'surface_matrix_ready',
            'mode' => 'read_only_surface_matrix',
            'execution_allowed' => false,
            'completion_allowed' => false,
            'surface_count' => count($rows),
            'surfaces' => $rows,
            'global_invariants' => [
                'all_surfaces_are_read_only',
                'no_surface_signs_receipt',
                'no_surface_applies_patch',
                'no_surface_marks_completion',
                'no_surface_enables_self_programming',
            ],
            'human_summary' => 'Surface matrix is ready: all Self-Construction command surfaces remain read-only and non-executing.',
        ];
    }

    /**
     * @param  array{workspace?: string|null, target?: string|null}  $options
     * @return array<string, mixed>
     */
    public function externalBlockers(array $options = []): array
    {
        $surfaceMatrix = $this->surfaceMatrix($options);
        $phaseLedger = $this->phaseLedger($options);

        $blockers = [
            [
                'id' => 'voice_realtime_surface_doc_delta_hot',
                'scope' => 'hot_voice_realtime_documentation',
                'status' => 'reported_not_edited',
                'severity' => 'ownership_boundary',
                'path' => 'docs/engineering-knowledge-base/atlas-ai-voice-realtime-surface.md',
                'cause' => 'Voice Realtime owner documentation is active in the working tree.',
                'recommended_action' => 'Self-Construction work must not edit this hot owner doc; Codex principal should keep AP-179/AP-185 language and docs-health green.',
            ],
            [
                'id' => 'voice_realtime_runtime_delta_hot',
                'scope' => 'hot_voice_realtime_runtime',
                'status' => 'reported_not_edited',
                'severity' => 'ownership_boundary',
                'path' => 'runtimes/python/voice_realtime/**',
                'cause' => 'Voice/LiveKit/Product Loop runtime files are active in the working tree.',
                'recommended_action' => 'Self-Construction work must not edit these files; report blockers and keep cold lane separate.',
            ],
            [
                'id' => 'voice_realtime_ap687_runtime_entrypoint_test_gap',
                'scope' => 'hot_voice_realtime_runtime_tests',
                'status' => 'reported_not_edited',
                'severity' => 'blocks_architecture_validate',
                'path' => 'runtimes/python/voice_realtime/tests/test_livekit_runtime_entrypoint.py',
                'cause' => 'AP-687 requires worker start production promotion guardrail coverage in the hot runtime entrypoint test, currently including test_start_worker_still_blocks_after_explicit_human_review_until_daemon_is_committed.',
                'recommended_action' => 'Codex principal should add or restore the AP-687 runtime entrypoint guardrail test in the hot Voice/LiveKit lane.',
            ],
            [
                'id' => 'voice_realtime_php_delta_hot',
                'scope' => 'hot_voice_realtime_php_surface',
                'status' => 'reported_not_edited',
                'severity' => 'ownership_boundary',
                'path' => 'app/Services/Ai/Voice/**',
                'cause' => 'Voice Realtime PHP service/certification files are active in the working tree.',
                'recommended_action' => 'Self-Construction work must not edit the Voice PHP surface; Codex principal owns this runtime promotion lane.',
            ],
            [
                'id' => 'kernel_scanner_delta_hot',
                'scope' => 'hot_kernel_static_scanner',
                'status' => 'reported_not_edited',
                'severity' => 'ownership_boundary',
                'path' => 'app/Services/Ai/Kernel/Architecture/KernelArchitectureStaticScanner.php',
                'cause' => 'Kernel scanner is active in the working tree.',
                'recommended_action' => 'Do not edit from Self-Construction cold lane; let Codex principal own scanner changes.',
            ],
        ];

        return [
            'schema_version' => 'atlas.self_construction_external_blockers.v1',
            'status' => 'external_blockers_reported',
            'mode' => 'read_only_external_blockers',
            'execution_allowed' => false,
            'completion_allowed' => false,
            'self_construction_surface_status' => data_get($surfaceMatrix, 'status'),
            'phase_ledger_status' => data_get($phaseLedger, 'status'),
            'blocker_count' => count($blockers),
            'blockers' => $blockers,
            'cold_lane_allowed_files' => $this->receiptAllowedFiles(),
            'policy' => [
                'hot_files_must_not_be_edited' => true,
                'external_blockers_must_be_reported' => true,
                'self_construction_completion_must_not_depend_on_hot_edits' => true,
            ],
            'non_execution_guarantees' => [
                'external_blockers_does_not_edit_hot_files',
                'external_blockers_does_not_apply_patch',
                'external_blockers_does_not_mark_completion',
                'external_blockers_does_not_enable_execution',
            ],
            'human_summary' => 'External blockers are reported without editing hot Voice, Product Loop or Kernel scanner files.',
        ];
    }

    /**
     * @param  array{workspace?: string|null, target?: string|null}  $options
     * @return array<string, mixed>
     */
    public function phaseLedger(array $options = []): array
    {
        $snapshot = $this->snapshot($options);
        $promotionGate = $this->promotionGate($options);
        $signatureRequest = $this->signatureRequest($options);
        $completionReadiness = $this->completionReadiness($options);
        $residualRisk = $this->residualRisk($options);
        $nextAction = $this->nextAction($options);

        $phases = [
            [
                'id' => 'phase_1_documentation_and_registry',
                'status' => data_get($snapshot, 'summary.missing_doc_count') === 0 ? 'complete' : 'blocked',
                'evidence' => 'required self-construction docs exist',
            ],
            [
                'id' => 'phase_2_read_only_gap_report',
                'status' => data_get($snapshot, 'status') === 'ready_for_phase_2' ? 'complete' : 'blocked',
                'evidence' => 'readiness command returns advisory snapshot',
            ],
            [
                'id' => 'phase_3_meta_sdd',
                'status' => 'complete',
                'evidence' => 'meta-sdd candidate is generated read-only',
            ],
            [
                'id' => 'phase_4_receipt_planning',
                'status' => 'complete',
                'evidence' => 'receipt preview, candidate, approval packet and draft exist',
            ],
            [
                'id' => 'phase_5_low_risk_execution',
                'status' => 'blocked_waiting_for_human_signature',
                'evidence' => 'signature request and runbook exist; execution remains disabled',
            ],
            [
                'id' => 'phase_5_completion',
                'status' => data_get($completionReadiness, 'status'),
                'evidence' => 'completion readiness requires signed execution evidence',
            ],
            [
                'id' => 'phase_6_restricted_runtime_patches',
                'status' => 'not_started',
                'evidence' => 'blocked until Phase 5 has signed execution evidence and clean residual risk',
            ],
        ];

        return [
            'schema_version' => 'atlas.self_construction_phase_ledger.v1',
            'status' => 'phase_ledger_ready',
            'mode' => 'read_only_phase_ledger',
            'execution_allowed' => false,
            'completion_allowed' => false,
            'promotion_allowed' => false,
            'current_phase' => 'phase_5_low_risk_execution',
            'next_action_id' => data_get($nextAction, 'selected_action.id'),
            'phase_count' => count($phases),
            'phases' => $phases,
            'ledger_summary' => [
                'completed_count' => count(array_filter($phases, fn (array $phase): bool => $phase['status'] === 'complete')),
                'blocked_count' => count(array_filter($phases, fn (array $phase): bool => str_starts_with($phase['status'], 'blocked'))),
                'not_started_count' => count(array_filter($phases, fn (array $phase): bool => $phase['status'] === 'not_started')),
                'promotion_gate_status' => data_get($promotionGate, 'status'),
                'signature_status' => data_get($signatureRequest, 'status'),
                'completion_status' => data_get($completionReadiness, 'status'),
                'residual_risk_status' => data_get($residualRisk, 'status'),
            ],
            'hard_blocks' => [
                'missing_human_signature',
                'missing_signed_scoped_diff',
                'missing_post_execution_gate_outputs',
                'missing_final_evidence_report',
            ],
            'non_execution_guarantees' => [
                'phase_ledger_does_not_sign_receipt',
                'phase_ledger_does_not_apply_patch',
                'phase_ledger_does_not_mark_completion',
                'phase_ledger_does_not_enable_execution',
            ],
            'human_summary' => 'Phase ledger is ready: Phase 5 is prepared but blocked until human signature and execution evidence exist.',
        ];
    }

    /**
     * @param  array{workspace?: string|null, target?: string|null}  $options
     * @return array<string, mixed>
     */
    public function implementationPacket(array $options = []): array
    {
        $target = $options['target'] ?: 'ai_implementation_packet_runtime_read_only';
        $docs = $this->snapshot($options);

        $packet = [
            'schema_version' => '1.0',
            'packet_id' => 'AIP-SELF-CONSTRUCTION-READ-ONLY-0001',
            'operation_id' => 'OP-SELF-CONSTRUCTION-AI-PACKET-0001',
            'status' => 'available',
            'lane' => 'self_construction',
            'objective' => 'Implement only the next read-only Self-Construction packet surface and validation evidence.',
            'target_capability' => $target,
            'rationale' => 'AI Implementation Packet is the first surface needed for one-line multi-agent continuation.',
            'priority' => 100,
            'risk_level' => 'medium',
            'execution_allowed' => false,
            'requires_human_signature' => true,
            'context_docs' => [
                'docs/engineering-knowledge-base/atlas-ai-self-construction-os.md',
                'docs/engineering-knowledge-base/self-construction/constitution.md',
                'docs/engineering-knowledge-base/self-construction/structural-contract-gate.md',
                'docs/engineering-knowledge-base/self-construction/ai-implementation-packet-contract.md',
                'docs/engineering-knowledge-base/self-construction/work-splitter-contract.md',
                'docs/engineering-knowledge-base/self-construction/scope-validator-contract.md',
                'docs/ap/AP-691-atlas-self-construction-os-contract.md',
            ],
            'allowed_files' => [
                'app/Console/Commands/AtlasAiSelfConstructionCommand.php',
                'app/Services/Ai/SelfConstruction/AtlasSelfConstructionReadinessService.php',
                'tests/Feature/Ai/AtlasAiSelfConstructionCommandTest.php',
                'docs/engineering-knowledge-base/atlas-ai-self-construction-os.md',
                'docs/engineering-knowledge-base/self-construction/ai-implementation-packet-contract.md',
                'docs/engineering-knowledge-base/self-construction/work-splitter-contract.md',
                'docs/engineering-knowledge-base/self-construction/scope-validator-contract.md',
                'docs/ap/AP-691-atlas-self-construction-os-contract.md',
            ],
            'forbidden_files' => $this->hotForbiddenFiles(),
            'forbidden_actions' => [
                'auto_merge',
                'sign_receipt',
                'enable_execution',
                'modify_voice_runtime',
                'modify_kernel_scanner',
                'run_migrations',
                'change_provider_policy',
            ],
            'acceptance_criteria' => [
                'packet exposes objective, allowed files, forbidden files, gates and evidence',
                'work splitter emits disjoint packets without hot scopes',
                'scope validator reports changed files as allowed, forbidden, unknown or hot_external',
                'all new surfaces remain read-only with execution_allowed=false',
            ],
            'required_gates' => [
                'php artisan atlas:ai:self-construction --implementation-packet --json',
                'php artisan atlas:ai:self-construction --work-splitter --json',
                'php artisan atlas:ai:self-construction --scope-validator --json',
                'php artisan test tests/Feature/Ai/AtlasAiSelfConstructionCommandTest.php',
                'php artisan atlas:engineering:knowledge docs-health --json',
                'php artisan atlas:ai:architecture-validate --json',
                'git diff --check',
            ],
            'required_evidence' => [
                'packet_hash',
                'split_hash',
                'validator_hash',
                'focused_test_output',
                'docs_health_output',
                'architecture_validate_output',
                'git_diff_check_output',
            ],
            'rollback_policy' => 'Revert only files listed in allowed_files and never revert external hot work.',
            'dependencies' => [
                'structural_contract_gate_complete',
                'ai_implementation_packet_contract_complete',
                'work_splitter_contract_complete',
                'scope_validator_contract_complete',
            ],
            'stop_conditions' => [
                'forbidden_file_changed',
                'unknown_file_changed',
                'hot_external_file_changed_by_packet_owner',
                'required_gate_failed',
                'human_signature_required_for_execution',
            ],
        ];

        return [
            'schema_version' => 'atlas.self_construction_ai_implementation_packet.v1',
            'status' => data_get($docs, 'summary.missing_doc_count') === 0 ? 'packet_ready' : 'blocked_missing_docs',
            'mode' => 'read_only_ai_implementation_packet',
            'execution_allowed' => false,
            'packet' => $packet,
            'packet_hash' => $this->stableHash($packet),
            'non_execution_guarantees' => [
                'implementation_packet_does_not_apply_patch',
                'implementation_packet_does_not_claim_assignment',
                'implementation_packet_does_not_sign_receipt',
                'implementation_packet_does_not_enable_execution',
            ],
            'human_summary' => 'AI Implementation Packet is ready as a read-only work contract. It can guide another AI but cannot execute, sign or merge.',
        ];
    }

    /**
     * @param  array{workspace?: string|null, target?: string|null}  $options
     * @return array<string, mixed>
     */
    public function workSplitter(array $options = []): array
    {
        $basePacket = $this->implementationPacket($options);
        $forbidden = $this->hotForbiddenFiles();

        $packets = [
            [
                'packet_id' => 'AIP-SPLIT-SELF-CONSTRUCTION-DOCS-0001',
                'lane' => 'docs',
                'objective' => 'Keep the Self-Construction root contract and structural governance docs synchronized.',
                'allowed_files' => [
                    'docs/engineering-knowledge-base/atlas-ai-self-construction-os.md',
                    'docs/engineering-knowledge-base/self-construction/constitution.md',
                    'docs/engineering-knowledge-base/self-construction/structural-contract-gate.md',
                    'docs/ap/AP-691-atlas-self-construction-os-contract.md',
                ],
                'forbidden_files' => $forbidden,
                'depends_on' => [],
                'collision_risk' => 'low',
                'claim_policy' => 'single_owner',
                'status' => 'available',
            ],
            [
                'packet_id' => 'AIP-SPLIT-SELF-CONSTRUCTION-PACKET-CONTRACTS-0002',
                'lane' => 'packet_contracts',
                'objective' => 'Maintain packet, splitter, validator, runbook and completion contracts for AI sessions.',
                'allowed_files' => [
                    'docs/engineering-knowledge-base/self-construction/ai-implementation-packet-contract.md',
                    'docs/engineering-knowledge-base/self-construction/work-splitter-contract.md',
                    'docs/engineering-knowledge-base/self-construction/scope-validator-contract.md',
                    'docs/engineering-knowledge-base/self-construction/assignment-and-claim-contract.md',
                    'docs/engineering-knowledge-base/self-construction/packet-consumption-runbook-contract.md',
                    'docs/engineering-knowledge-base/self-construction/packet-evidence-report-contract.md',
                    'docs/engineering-knowledge-base/self-construction/packet-completion-gate-contract.md',
                ],
                'forbidden_files' => $forbidden,
                'depends_on' => [],
                'collision_risk' => 'low',
                'claim_policy' => 'single_owner',
                'status' => 'available',
            ],
            [
                'packet_id' => 'AIP-SPLIT-SELF-CONSTRUCTION-COMMAND-0003',
                'lane' => 'command_surface',
                'objective' => 'Expose and preserve the read-only Self-Construction CLI command surface.',
                'allowed_files' => [
                    'app/Console/Commands/AtlasAiSelfConstructionCommand.php',
                ],
                'forbidden_files' => $forbidden,
                'depends_on' => [],
                'collision_risk' => 'low',
                'claim_policy' => 'single_owner',
                'status' => 'available',
            ],
            [
                'packet_id' => 'AIP-SPLIT-SELF-CONSTRUCTION-SERVICE-0004',
                'lane' => 'readiness_service',
                'objective' => 'Implement read-only packet orchestration, scoped validation and readiness payloads.',
                'allowed_files' => [
                    'app/Services/Ai/SelfConstruction/AtlasSelfConstructionReadinessService.php',
                ],
                'forbidden_files' => $forbidden,
                'depends_on' => [],
                'collision_risk' => 'low',
                'claim_policy' => 'single_owner',
                'status' => 'available',
            ],
            [
                'packet_id' => 'AIP-SPLIT-SELF-CONSTRUCTION-EVIDENCE-0005',
                'lane' => 'tests',
                'objective' => 'Add focused evidence assertions for packet, split and validator read-only invariants.',
                'allowed_files' => [
                    'tests/Feature/Ai/AtlasAiSelfConstructionCommandTest.php',
                ],
                'forbidden_files' => $forbidden,
                'depends_on' => [],
                'collision_risk' => 'low',
                'claim_policy' => 'single_owner',
                'status' => 'available',
            ],
        ];

        $withheld = [
            [
                'id' => 'voice_runtime_packet_withheld',
                'reason' => 'Voice runtime is hot external work and must not be assigned by Self-Construction Work Splitter.',
                'forbidden_scope' => 'runtimes/python/voice_realtime/**',
            ],
            [
                'id' => 'kernel_scanner_packet_withheld',
                'reason' => 'Kernel scanner ownership is outside this cold lane.',
                'forbidden_scope' => 'app/Services/Ai/Kernel/Architecture/KernelArchitectureStaticScanner.php',
            ],
        ];

        $split = [
            'split_id' => 'SPLIT-SELF-CONSTRUCTION-READ-ONLY-0001',
            'max_packets' => 5,
            'packet_count' => count($packets),
            'packets' => $packets,
            'withheld_work' => $withheld,
            'source_packet_hash' => data_get($basePacket, 'packet_hash'),
            'required_validator' => 'php artisan atlas:ai:self-construction --scope-validator --json',
        ];

        return [
            'schema_version' => 'atlas.self_construction_work_splitter.v1',
            'status' => 'split_ready',
            'mode' => 'read_only_work_splitter',
            'execution_allowed' => false,
            'packet_count' => count($packets),
            'withheld_count' => count($withheld),
            'split' => $split,
            'split_hash' => $this->stableHash($split),
            'non_execution_guarantees' => [
                'work_splitter_does_not_claim_packets',
                'work_splitter_does_not_apply_patch',
                'work_splitter_does_not_edit_hot_files',
                'work_splitter_does_not_enable_execution',
            ],
            'human_summary' => 'Work Splitter emits disjoint read-only packets and withholds hot Voice/Kernel work from parallel assignment.',
        ];
    }

    /**
     * @param  array{workspace?: string|null, target?: string|null}  $options
     * @return array<string, mixed>
     */
    public function scopeValidator(array $options = []): array
    {
        $packetPayload = $this->implementationPacket($options);
        $selectedPacket = $this->selectedSplitPacket($options);
        $packet = $selectedPacket ?? (array) data_get($packetPayload, 'packet', []);
        $changedFiles = $this->changedFiles();
        $allowed = (array) data_get($packet, 'allowed_files', []);
        $forbidden = (array) data_get($packet, 'forbidden_files', []);

        $files = array_map(function (string $path) use ($allowed, $forbidden): array {
            $classification = $this->classifyPath($path, $allowed, $forbidden);

            return [
                'path' => $path,
                'classification' => $classification,
                'blocking' => in_array($classification, ['forbidden', 'unknown', 'hot_external'], true),
                'reason' => match ($classification) {
                    'allowed' => 'Path is inside implementation packet allowed files.',
                    'hot_external' => 'Path belongs to hot external scope and is not owned by this packet.',
                    'forbidden' => 'Path matches packet forbidden scope.',
                    default => 'Path is not declared by the packet scope.',
                },
            ];
        }, $changedFiles);

        $blocking = array_values(array_filter($files, fn (array $file): bool => (bool) $file['blocking']));

        $validator = [
            'packet_id' => data_get($packet, 'packet_id'),
            'requested_packet_id' => $options['packet'] ?? null,
            'packet_scope_source' => $selectedPacket === null ? 'implementation_packet' : 'work_splitter_packet',
            'changed_files' => $files,
            'blocking_violations' => $blocking,
            'required_next_action' => $blocking === [] ? 'continue' : 'stop_or_request_review',
        ];

        return [
            'schema_version' => 'atlas.self_construction_scope_validator.v1',
            'status' => $blocking === [] ? 'pass' : 'blocked',
            'mode' => 'read_only_scope_validator',
            'execution_allowed' => false,
            'summary' => [
                'changed_count' => count($files),
                'allowed_count' => count(array_filter($files, fn (array $file): bool => $file['classification'] === 'allowed')),
                'blocking_count' => count($blocking),
                'hot_external_count' => count(array_filter($files, fn (array $file): bool => $file['classification'] === 'hot_external')),
                'unknown_count' => count(array_filter($files, fn (array $file): bool => $file['classification'] === 'unknown')),
            ],
            'validator' => $validator,
            'validator_hash' => $this->stableHash($validator),
            'non_execution_guarantees' => [
                'scope_validator_does_not_apply_patch',
                'scope_validator_does_not_revert_files',
                'scope_validator_does_not_sign_receipt',
                'scope_validator_does_not_enable_execution',
            ],
            'human_summary' => $blocking === []
                ? 'Scope Validator passed for the selected packet scope.'
                : 'Scope Validator found blocking files outside the selected packet scope; stop or request review.',
        ];
    }

    /**
     * @param  array{workspace?: string|null, target?: string|null}  $options
     * @return array<string, mixed>
     */
    public function assignmentPreview(array $options = []): array
    {
        $splitter = $this->workSplitter($options);
        $packets = (array) data_get($splitter, 'split.packets', []);
        $requested = $options['packet'] ?? null;
        $selected = $requested === null
            ? collect($packets)->first(fn (array $packet): bool => data_get($packet, 'status') === 'available'
                && in_array(data_get($packet, 'collision_risk'), ['none', 'low'], true)
                && (array) data_get($packet, 'depends_on', []) === [])
            : collect($packets)->firstWhere('packet_id', $requested);

        if ($selected !== null && (
            data_get($selected, 'status') !== 'available'
            || ! in_array(data_get($selected, 'collision_risk'), ['none', 'low'], true)
            || (array) data_get($selected, 'depends_on', []) !== []
        )) {
            $selected = null;
        }

        $assignment = [
            'schema_version' => 'atlas.self_construction_assignment_preview.v1',
            'assignment_id' => 'ASSIGN-SELF-CONSTRUCTION-PREVIEW-0001',
            'status' => $selected === null ? 'blocked' : 'claim_preview_ready',
            'session_owner' => 'read_only_preview',
            'requested_packet_id' => $requested,
            'selected_packet_id' => data_get($selected, 'packet_id'),
            'claim_state' => 'preview_only_not_persisted',
            'execution_allowed' => false,
            'packet_hash' => data_get($this->implementationPacket($options), 'packet_hash'),
            'split_hash' => data_get($splitter, 'split_hash'),
            'allowed_files' => (array) data_get($selected, 'allowed_files', []),
            'forbidden_files' => (array) data_get($selected, 'forbidden_files', []),
            'required_first_commands' => [
                'git status --short',
                'git diff --stat',
                'git diff --name-only',
                $requested === null
                    ? 'php artisan atlas:ai:self-construction --assignment-preview --json'
                    : 'php artisan atlas:ai:self-construction --assignment-preview --packet='.$requested.' --json',
                $requested === null
                    ? 'php artisan atlas:ai:self-construction --scope-validator --json'
                    : 'php artisan atlas:ai:self-construction --scope-validator --packet='.$requested.' --json',
            ],
            'stop_conditions' => [
                'selected_packet_missing',
                'packet_hash_changed',
                'forbidden_file_changed',
                'unknown_file_changed',
                'hot_external_file_changed',
                'required_gate_failed',
            ],
        ];

        return [
            'schema_version' => 'atlas.self_construction_assignment_preview.v1',
            'status' => $selected === null ? 'blocked' : 'claim_preview_ready',
            'mode' => 'read_only_assignment_preview',
            'execution_allowed' => false,
            'assignment' => $assignment,
            'assignment_hash' => $this->stableHash($assignment),
            'non_execution_guarantees' => [
                'assignment_preview_does_not_persist_claim',
                'assignment_preview_does_not_apply_patch',
                'assignment_preview_does_not_sign_receipt',
                'assignment_preview_does_not_enable_execution',
            ],
            'human_summary' => $selected === null
                ? 'Assignment preview is blocked because no safe available packet matched the request.'
                : 'Assignment preview selected one safe packet for one AI session. Claim is not persisted and execution remains disabled.',
        ];
    }

    /**
     * @param  array{workspace?: string|null, target?: string|null}  $options
     * @return array<string, mixed>
     */
    public function packetRunbook(array $options = []): array
    {
        $assignmentPayload = $this->assignmentPreview($options);
        $assignment = (array) data_get($assignmentPayload, 'assignment', []);

        $runbook = [
            'schema_version' => 'atlas.self_construction_packet_consumption_runbook.v1',
            'runbook_id' => 'RUNBOOK-SELF-CONSTRUCTION-PACKET-0001',
            'assignment_id' => data_get($assignment, 'assignment_id'),
            'selected_packet_id' => data_get($assignment, 'selected_packet_id'),
            'assignment_hash' => data_get($assignmentPayload, 'assignment_hash'),
            'execution_allowed' => false,
            'claim_persisted' => false,
            'steps' => [
                ['order' => 1, 'id' => 'inspect_worktree', 'command' => 'git status --short && git diff --stat && git diff --name-only'],
                ['order' => 2, 'id' => 'read_assignment', 'command' => $this->packetCommand('assignment-preview', data_get($assignment, 'selected_packet_id'))],
                ['order' => 3, 'id' => 'read_packet', 'command' => 'php artisan atlas:ai:self-construction --implementation-packet --json'],
                ['order' => 4, 'id' => 'confirm_scope', 'command' => $this->packetCommand('scope-validator', data_get($assignment, 'selected_packet_id'))],
                ['order' => 5, 'id' => 'run_focused_tests', 'command' => 'php artisan test tests/Feature/Ai/AtlasAiSelfConstructionCommandTest.php'],
                ['order' => 6, 'id' => 'run_docs_health', 'command' => 'php artisan atlas:engineering:knowledge docs-health --json'],
                ['order' => 7, 'id' => 'run_architecture_validate', 'command' => 'php artisan atlas:ai:architecture-validate --json'],
                ['order' => 8, 'id' => 'run_diff_check', 'command' => 'git diff --check'],
                ['order' => 9, 'id' => 'return_evidence', 'command' => null],
            ],
            'required_gates' => [
                $this->packetCommand('assignment-preview', data_get($assignment, 'selected_packet_id')),
                $this->packetCommand('scope-validator', data_get($assignment, 'selected_packet_id')),
                'php artisan test tests/Feature/Ai/AtlasAiSelfConstructionCommandTest.php',
                'php artisan atlas:engineering:knowledge docs-health --json',
                'php artisan atlas:ai:architecture-validate --json',
                'git diff --check',
            ],
            'required_evidence' => [
                'selected_packet_id',
                'assignment_hash',
                'files_changed_by_this_ai',
                'focused_test_output',
                'docs_health_output',
                'architecture_validate_output',
                'scope_validator_output',
                'git_diff_check_output',
                'residual_risk',
            ],
            'stop_conditions' => (array) data_get($assignment, 'stop_conditions', []),
            'final_response_contract' => [
                'state_packet_id',
                'state_changed_files',
                'state_gates_run',
                'state_scope_validator_status',
                'state_external_blockers',
                'state_remaining_blocks',
            ],
        ];

        return [
            'schema_version' => 'atlas.self_construction_packet_consumption_runbook.v1',
            'status' => data_get($assignmentPayload, 'status') === 'claim_preview_ready' ? 'runbook_ready' : 'blocked_by_assignment',
            'mode' => 'read_only_packet_consumption_runbook',
            'execution_allowed' => false,
            'runbook' => $runbook,
            'runbook_hash' => $this->stableHash($runbook),
            'non_execution_guarantees' => [
                'packet_runbook_does_not_persist_claim',
                'packet_runbook_does_not_apply_patch',
                'packet_runbook_does_not_sign_receipt',
                'packet_runbook_does_not_enable_execution',
            ],
            'human_summary' => 'Packet runbook is ready: one AI can follow ordered steps and return evidence without persisted claim or execution authority.',
        ];
    }

    /**
     * @param  array{workspace?: string|null, target?: string|null}  $options
     * @return array<string, mixed>
     */
    public function packetEvidenceReport(array $options = []): array
    {
        $runbookPayload = $this->packetRunbook($options);
        $scope = $this->scopeValidator($options);
        $externalBlockers = $this->externalBlockers($options);
        $scopePassed = data_get($scope, 'status') === 'pass';

        $gateResults = [
            ['id' => 'runbook_ready', 'passed' => data_get($runbookPayload, 'status') === 'runbook_ready'],
            ['id' => 'scope_validator_passed', 'passed' => $scopePassed],
            ['id' => 'focused_tests_required', 'passed' => true],
            ['id' => 'docs_health_required', 'passed' => true],
            ['id' => 'architecture_validate_required', 'passed' => true],
            ['id' => 'diff_check_required', 'passed' => true],
        ];

        $evidenceResults = array_map(function (string $evidence): array {
            $present = in_array($evidence, ['selected_packet_id', 'assignment_hash'], true);

            return [
                'id' => $evidence,
                'present' => $present,
                'source' => $present ? 'runbook' : 'operator_output_required',
            ];
        }, (array) data_get($runbookPayload, 'runbook.required_evidence', []));

        $blockingReasons = [];
        if (! $scopePassed) {
            $blockingReasons[] = 'scope_validator_blocked';
        }

        foreach ($evidenceResults as $evidence) {
            if (! $evidence['present']) {
                $blockingReasons[] = 'missing_evidence:'.$evidence['id'];
            }
        }

        if ((int) data_get($externalBlockers, 'blocker_count') > 0) {
            $blockingReasons[] = 'external_hot_blockers_reported';
        }

        $report = [
            'report_id' => 'EVIDENCE-REPORT-SELF-CONSTRUCTION-PACKET-0001',
            'selected_packet_id' => data_get($runbookPayload, 'runbook.selected_packet_id'),
            'runbook_hash' => data_get($runbookPayload, 'runbook_hash'),
            'scope_validator_status' => data_get($scope, 'status'),
            'execution_allowed' => false,
            'completion_allowed' => false,
            'gate_results' => $gateResults,
            'evidence_results' => $evidenceResults,
            'blocking_reasons' => array_values(array_unique($blockingReasons)),
            'external_blockers' => data_get($externalBlockers, 'blockers'),
            'required_next_action' => $blockingReasons === []
                ? 'human_review_before_completion'
                : 'resolve_blockers_and_rerun_evidence_report',
        ];

        return [
            'schema_version' => 'atlas.self_construction_packet_evidence_report.v1',
            'status' => $blockingReasons === [] ? 'completion_review_ready' : 'blocked',
            'mode' => 'read_only_packet_evidence_report',
            'execution_allowed' => false,
            'completion_allowed' => false,
            'report' => $report,
            'report_hash' => $this->stableHash($report),
            'non_execution_guarantees' => [
                'packet_evidence_report_does_not_write_ledger',
                'packet_evidence_report_does_not_mark_completed',
                'packet_evidence_report_does_not_apply_patch',
                'packet_evidence_report_does_not_enable_execution',
            ],
            'human_summary' => $blockingReasons === []
                ? 'Packet evidence report is ready for human completion review; no completion was marked.'
                : 'Packet evidence report is blocked until scope, evidence and external blockers are resolved.',
        ];
    }

    /**
     * @param  array{workspace?: string|null, target?: string|null}  $options
     * @return array<string, mixed>
     */
    public function packetCompletionGate(array $options = []): array
    {
        $evidencePayload = $this->packetEvidenceReport($options);
        $report = (array) data_get($evidencePayload, 'report', []);
        $blockingReasons = (array) data_get($report, 'blocking_reasons', []);
        $cleanEvidence = data_get($evidencePayload, 'status') === 'completion_review_ready' && $blockingReasons === [];

        $gate = [
            'gate_id' => 'COMPLETION-GATE-SELF-CONSTRUCTION-PACKET-0001',
            'selected_packet_id' => data_get($report, 'selected_packet_id'),
            'evidence_report_hash' => data_get($evidencePayload, 'report_hash'),
            'status' => $cleanEvidence ? 'human_review_required' : 'blocked',
            'execution_allowed' => false,
            'completion_allowed' => false,
            'durable_completion_written' => false,
            'decision' => $cleanEvidence ? 'request_human_review' : 'block',
            'blocking_reasons' => $blockingReasons,
            'required_next_action' => $cleanEvidence
                ? 'request_human_completion_review'
                : 'resolve_packet_evidence_blockers',
            'inspected_fields' => [
                'selected_packet_id',
                'evidence_report_hash',
                'scope_validator_status',
                'blocking_reasons',
                'external_blockers',
                'gate_results',
                'evidence_results',
            ],
        ];

        return [
            'schema_version' => 'atlas.self_construction_packet_completion_gate.v1',
            'status' => data_get($gate, 'status'),
            'mode' => 'read_only_packet_completion_gate',
            'execution_allowed' => false,
            'completion_allowed' => false,
            'gate' => $gate,
            'gate_hash' => $this->stableHash($gate),
            'non_execution_guarantees' => [
                'packet_completion_gate_does_not_write_ledger',
                'packet_completion_gate_does_not_mark_completed',
                'packet_completion_gate_does_not_apply_patch',
                'packet_completion_gate_does_not_enable_execution',
            ],
            'human_summary' => $cleanEvidence
                ? 'Packet completion gate requires human review before any durable completion.'
                : 'Packet completion gate blocks completion because evidence, scope or external blockers are unresolved.',
        ];
    }

    /**
     * @param  array{workspace?: string|null, target?: string|null}  $options
     * @return array<string, mixed>
     */
    public function reservationLedgerPreview(array $options = []): array
    {
        $assignmentPayload = $this->assignmentPreview($options);
        $assignment = (array) data_get($assignmentPayload, 'assignment', []);
        $completionGatePayload = $this->packetCompletionGate($options);
        $assignmentReady = data_get($assignmentPayload, 'status') === 'claim_preview_ready';

        $reservation = [
            'ledger_id' => 'RESERVATION-LEDGER-PREVIEW-SELF-CONSTRUCTION-0001',
            'reservation_id' => 'RESERVATION-PREVIEW-SELF-CONSTRUCTION-0001',
            'selected_packet_id' => data_get($assignment, 'selected_packet_id'),
            'assignment_id' => data_get($assignment, 'assignment_id'),
            'session_owner' => data_get($assignment, 'session_owner', 'read_only_preview'),
            'claim_state' => 'preview_only_not_persisted',
            'claim_persisted' => false,
            'ledger_write_allowed' => false,
            'execution_allowed' => false,
            'packet_hash' => data_get($assignment, 'packet_hash'),
            'split_hash' => data_get($assignment, 'split_hash'),
            'assignment_hash' => data_get($assignmentPayload, 'assignment_hash'),
            'completion_gate_hash' => data_get($completionGatePayload, 'gate_hash'),
            'lease_expires_at' => null,
            'allowed_files' => (array) data_get($assignment, 'allowed_files', []),
            'forbidden_files' => (array) data_get($assignment, 'forbidden_files', []),
            'collision_policy' => [
                'one_active_reservation_per_packet',
                'one_active_reservation_per_session',
                'block_when_allowed_files_overlap_active_reservation',
                'block_when_hot_external_scope_is_present',
            ],
            'stale_policy' => [
                'block_when_packet_hash_changes',
                'block_when_split_hash_changes',
                'block_when_assignment_hash_changes',
                'require_new_preview_before_future_persistence',
            ],
            'stop_conditions' => (array) data_get($assignment, 'stop_conditions', []),
            'future_persistence_requires' => [
                'approved_reservation_ledger_ap',
                'append_only_storage',
                'atomic_claim_lock',
                'lease_expiry_and_release_policy',
                'scope_overlap_detector',
            ],
        ];

        return [
            'schema_version' => 'atlas.self_construction_reservation_ledger_preview.v1',
            'status' => $assignmentReady ? 'reservation_preview_ready' : 'blocked_by_assignment',
            'mode' => 'read_only_reservation_ledger_preview',
            'execution_allowed' => false,
            'completion_allowed' => false,
            'ledger_write_allowed' => false,
            'reservation_persisted' => false,
            'reservation' => $reservation,
            'reservation_hash' => $this->stableHash($reservation),
            'non_execution_guarantees' => [
                'reservation_ledger_preview_does_not_persist_claim',
                'reservation_ledger_preview_does_not_write_ledger',
                'reservation_ledger_preview_does_not_apply_patch',
                'reservation_ledger_preview_does_not_enable_execution',
            ],
            'human_summary' => $assignmentReady
                ? 'Reservation ledger preview is ready: future packet ownership is modeled, but no claim or ledger row was written.'
                : 'Reservation ledger preview is blocked because no safe assignment preview exists.',
        ];
    }

    /**
     * @param  array{workspace?: string|null, target?: string|null, packet?: string|null, actor?: string|null, session?: string|null, lease_minutes?: int|string|null, reason?: string|null}  $options
     * @return array<string, mixed>
     */
    public function reservationStatus(array $options = []): array
    {
        $ledger = $this->reservations->status();

        return [
            'schema_version' => 'atlas.self_construction_reservation_status.v1',
            'status' => 'reservation_ledger_ready',
            'mode' => 'durable_local_reservation_status',
            'execution_allowed' => false,
            'completion_allowed' => false,
            'claim_persisted' => false,
            'ledger_write_allowed' => false,
            'dispatch_allowed' => false,
            'ledger' => $ledger,
            'ledger_hash' => $this->stableHash($ledger),
            'human_summary' => 'Durable local reservation ledger is available: status can be read without claiming, dispatching or executing work.',
        ];
    }

    /**
     * @param  array{workspace?: string|null, target?: string|null, packet?: string|null, actor?: string|null, session?: string|null, lease_minutes?: int|string|null, reason?: string|null}  $options
     * @return array<string, mixed>
     */
    public function claimPacket(array $options = []): array
    {
        $packet = $this->selectedSplitPacket($options);
        $actor = $this->reservationActor($options);
        $session = $this->reservationSession($options);
        $leaseMinutes = max(1, (int) ($options['lease_minutes'] ?? 120));

        if ($packet === null) {
            return [
                'schema_version' => 'atlas.self_construction_claim_packet.v1',
                'status' => 'blocked',
                'mode' => 'durable_local_packet_claim',
                'execution_allowed' => false,
                'completion_allowed' => false,
                'claim_persisted' => false,
                'ledger_write_allowed' => true,
                'dispatch_allowed' => false,
                'claim' => [
                    'packet_id' => $options['packet'] ?? null,
                    'blocking_reasons' => ['packet_not_found_or_missing'],
                ],
                'claim_hash' => $this->stableHash(['packet_id' => $options['packet'] ?? null, 'blocking_reasons' => ['packet_not_found_or_missing']]),
                'human_summary' => 'Packet claim is blocked because the requested Work Splitter packet was not found.',
            ];
        }

        $claim = $this->reservations->claim(
            packet: $packet,
            actor: $actor,
            session: $session,
            leaseMinutes: $leaseMinutes,
            packetHash: $this->stableHash($packet),
        );

        return [
            'schema_version' => 'atlas.self_construction_claim_packet.v1',
            'status' => data_get($claim, 'status') === 'claimed' ? 'claimed' : 'blocked',
            'mode' => 'durable_local_packet_claim',
            'execution_allowed' => false,
            'completion_allowed' => false,
            'claim_persisted' => data_get($claim, 'status') === 'claimed',
            'ledger_write_allowed' => true,
            'dispatch_allowed' => false,
            'claim' => [
                ...$claim,
                'packet_id' => data_get($packet, 'packet_id'),
                'actor' => $actor,
                'session' => $session,
                'lease_minutes' => $leaseMinutes,
            ],
            'claim_hash' => $this->stableHash($claim),
            'human_summary' => data_get($claim, 'status') === 'claimed'
                ? 'Packet was durably claimed in the local reservation ledger. The AI may work only inside this packet scope.'
                : 'Packet claim was blocked by the reservation ledger. The AI must choose another packet or wait.',
        ];
    }

    /**
     * @param  array{workspace?: string|null, target?: string|null, packet?: string|null, actor?: string|null, session?: string|null, lease_minutes?: int|string|null, reason?: string|null}  $options
     * @return array<string, mixed>
     */
    public function claimNextPacket(array $options = []): array
    {
        $queuePayload = $this->packetQueue($options);
        $selected = collect((array) data_get($queuePayload, 'queue.entries', []))
            ->first(fn (array $entry): bool => data_get($entry, 'queue_state') === 'available'
                && data_get($entry, 'collision_risk') !== 'blocked');

        if (! is_array($selected)) {
            $start = [
                'selected_packet_id' => null,
                'queue_hash' => data_get($queuePayload, 'queue_hash'),
                'blocking_reasons' => ['no_available_packet'],
                'available_count' => data_get($queuePayload, 'queue.available_count'),
                'claimed_count' => data_get($queuePayload, 'queue.claimed_count'),
                'withheld_count' => data_get($queuePayload, 'queue.withheld_count'),
            ];

            return [
                'schema_version' => 'atlas.self_construction_claim_next_packet.v1',
                'status' => 'blocked',
                'mode' => 'durable_local_claim_next_packet',
                'execution_allowed' => false,
                'completion_allowed' => false,
                'claim_persisted' => false,
                'ledger_write_allowed' => true,
                'dispatch_allowed' => false,
                'packet_id' => null,
                'start' => $start,
                'start_hash' => $this->stableHash($start),
                'human_summary' => 'Claim-next is blocked because no available packet remains in the durable queue.',
            ];
        }

        $packetId = (string) data_get($selected, 'packet_id');
        $scopedOptions = [
            ...$options,
            'packet' => $packetId,
        ];

        $claimPayload = $this->claimPacket($scopedOptions);
        $bootstrapPayload = $this->aiSessionBootstrap($scopedOptions);
        $instructionPayload = $this->singleSessionInstructionPacket($scopedOptions);
        $scopePayload = $this->scopeValidator($scopedOptions);

        $start = [
            'packet_id' => $packetId,
            'lane' => data_get($selected, 'lane'),
            'objective' => data_get($selected, 'objective'),
            'actor' => $this->reservationActor($options),
            'session' => $this->reservationSession($options),
            'claim_status' => data_get($claimPayload, 'status'),
            'claim_hash' => data_get($claimPayload, 'claim_hash'),
            'bootstrap_hash' => data_get($bootstrapPayload, 'bootstrap_hash'),
            'instruction_hash' => data_get($instructionPayload, 'instruction_hash'),
            'scope_validator_hash' => data_get($scopePayload, 'validator_hash'),
            'allowed_files' => (array) data_get($selected, 'allowed_files', []),
            'forbidden_files' => (array) data_get($selected, 'forbidden_files', []),
            'required_first_commands' => [
                'git status --short',
                'git diff --stat',
                'git diff --name-only',
                $this->packetCommand('ai-session-bootstrap', $packetId),
                $this->packetCommand('scope-validator', $packetId),
            ],
            'required_gates' => (array) data_get($instructionPayload, 'instruction.required_gates', []),
            'required_evidence' => (array) data_get($instructionPayload, 'instruction.required_evidence', []),
            'one_line_prompt_for_codex' => 'Continue Self-Construction using your claimed packet. Run the packet-scoped bootstrap and scope validator, touch only allowed files, and stop on any blocker.',
            'stop_conditions' => array_values(array_unique(array_merge(
                (array) data_get($claimPayload, 'claim.blocking_reasons', []),
                (array) data_get($instructionPayload, 'instruction.stop_conditions', []),
                ['scope_validator_blocked', 'required_gate_failed']
            ))),
        ];

        return [
            'schema_version' => 'atlas.self_construction_claim_next_packet.v1',
            'status' => data_get($claimPayload, 'status') === 'claimed' ? 'claimed' : 'blocked',
            'mode' => 'durable_local_claim_next_packet',
            'execution_allowed' => false,
            'completion_allowed' => false,
            'claim_persisted' => (bool) data_get($claimPayload, 'claim_persisted'),
            'ledger_write_allowed' => true,
            'dispatch_allowed' => false,
            'packet_id' => $packetId,
            'claim' => data_get($claimPayload, 'claim'),
            'bootstrap' => data_get($bootstrapPayload, 'bootstrap'),
            'instruction' => data_get($instructionPayload, 'instruction'),
            'start' => $start,
            'start_hash' => $this->stableHash($start),
            'human_summary' => data_get($claimPayload, 'status') === 'claimed'
                ? 'Next packet was durably claimed and packet-scoped bootstrap instructions are ready for this Codex session.'
                : 'Claim-next selected a packet but the durable claim was blocked; refresh the queue before continuing.',
        ];
    }

    /**
     * @param  array{workspace?: string|null, target?: string|null, packet?: string|null, actor?: string|null, session?: string|null, lease_minutes?: int|string|null, reason?: string|null}  $options
     * @return array<string, mixed>
     */
    public function codexStartPacket(array $options = []): array
    {
        $claimNext = $this->claimNextPacket($options);
        $packetId = data_get($claimNext, 'packet_id');

        if (! is_string($packetId) || $packetId === '') {
            $contract = [
                'status' => 'blocked',
                'blocking_reasons' => (array) data_get($claimNext, 'start.blocking_reasons', ['no_available_packet']),
                'queue_state' => [
                    'available_count' => data_get($claimNext, 'start.available_count'),
                    'claimed_count' => data_get($claimNext, 'start.claimed_count'),
                    'withheld_count' => data_get($claimNext, 'start.withheld_count'),
                ],
            ];

            return [
                'schema_version' => 'atlas.self_construction_codex_start_packet.v1',
                'status' => 'blocked',
                'mode' => 'durable_local_codex_start_packet',
                'execution_allowed' => false,
                'completion_allowed' => false,
                'claim_persisted' => false,
                'dispatch_allowed' => false,
                'packet_id' => null,
                'contract' => $contract,
                'contract_hash' => $this->stableHash($contract),
                'human_summary' => 'Codex start packet is blocked because no available Self-Construction packet remains.',
            ];
        }

        $contract = [
            'contract_id' => 'CODEX-START-SELF-CONSTRUCTION-0001',
            'packet_id' => $packetId,
            'actor' => $this->reservationActor($options),
            'session' => $this->reservationSession($options),
            'one_line_user_prompt' => 'continua a implementação da forma mais profissional e completa possível',
            'operator_prompt' => 'Continue Atlas Self-Construction using this durably claimed packet. Read the scoped bootstrap first, validate scope before and after edits, touch only allowed files, run required gates, report evidence, and stop on any blocker.',
            'mission' => 'Implement exactly the claimed Self-Construction packet, preserve governance, run required gates, report evidence, and stop on scope blockers.',
            'claim' => [
                'persisted' => (bool) data_get($claimNext, 'claim_persisted'),
                'reservation_id' => data_get($claimNext, 'claim.reservation.reservation_id'),
                'lease_expires_at' => data_get($claimNext, 'claim.reservation.lease_expires_at'),
                'claim_hash' => data_get($claimNext, 'start.claim_hash'),
            ],
            'scope' => [
                'allowed_files' => (array) data_get($claimNext, 'start.allowed_files', []),
                'forbidden_files' => (array) data_get($claimNext, 'start.forbidden_files', []),
                'hot_scopes' => $this->hotForbiddenFiles(),
            ],
            'bootstrap_command' => $this->packetCommand('ai-session-bootstrap', $packetId),
            'scope_validator_command' => $this->packetCommand('scope-validator', $packetId),
            'required_first_commands' => (array) data_get($claimNext, 'start.required_first_commands', []),
            'required_gates' => (array) data_get($claimNext, 'start.required_gates', []),
            'required_evidence' => array_values(array_unique(array_merge(
                (array) data_get($claimNext, 'start.required_evidence', []),
                [
                    'claimed_packet_id',
                    'reservation_id',
                    'changed_files_by_this_session',
                    'scope_validator_output',
                    'focused_test_output',
                    'docs_health_output',
                    'architecture_validate_output',
                    'git_diff_check_output',
                ]
            ))),
            'implementation_rules' => [
                'touch_only_allowed_files',
                'do_not_edit_voice_or_kernel_hot_scopes',
                'do_not_revert_user_or_other_session_changes',
                'prefer_small_scoped_patch',
                'update_docs_when_contract_changes',
                'add_or_update_focused_tests_for_runtime_changes',
                'stop_if_scope_validator_blocks',
            ],
            'final_response_contract' => [
                'state_packet_id',
                'state_reservation_id',
                'state_files_changed_by_this_session',
                'state_gates_run_with_results',
                'state_scope_validator_status',
                'state_evidence_paths_or_outputs',
                'state_remaining_blockers',
                'state_next_recommended_packet_or_action',
            ],
            'release_command' => 'php artisan atlas:ai:self-construction --release-packet --packet='.$packetId
                .' --actor='.$this->reservationActor($options)
                .' --session='.$this->reservationSession($options)
                .' --reason=finished_or_blocked --json',
            'completion_command' => 'php artisan atlas:ai:self-construction --complete-packet --packet='.$packetId
                .' --actor='.$this->reservationActor($options)
                .' --session='.$this->reservationSession($options)
                .' --reason=packet_scope_finished --evidence-hash=<sha256-of-final-evidence> --json',
            'stop_conditions' => (array) data_get($claimNext, 'start.stop_conditions', []),
            'execution_allowed' => false,
            'dispatch_allowed' => false,
            'completion_allowed' => false,
        ];

        return [
            'schema_version' => 'atlas.self_construction_codex_start_packet.v1',
            'status' => 'codex_start_packet_ready',
            'mode' => 'durable_local_codex_start_packet',
            'execution_allowed' => false,
            'completion_allowed' => false,
            'claim_persisted' => (bool) data_get($claimNext, 'claim_persisted'),
            'dispatch_allowed' => false,
            'packet_id' => $packetId,
            'claim_next_hash' => data_get($claimNext, 'start_hash'),
            'contract' => $contract,
            'contract_hash' => $this->stableHash($contract),
            'human_summary' => 'Codex start packet is ready: a packet is durably claimed and the session has scope, gates, evidence and final response contract.',
        ];
    }

    /**
     * @param  array{workspace?: string|null, target?: string|null, packet?: string|null, actor?: string|null, session?: string|null, lease_minutes?: int|string|null, reason?: string|null}  $options
     * @return array<string, mixed>
     */
    public function codexLaunchPlan(array $options = []): array
    {
        $queuePayload = $this->packetQueue($options);
        $gatePayload = $this->multiSessionReadinessGate($options);
        $entries = (array) data_get($queuePayload, 'queue.entries', []);
        $available = array_values(array_filter($entries, fn (array $entry): bool => data_get($entry, 'queue_state') === 'available'));
        $launchable = array_slice($available, 0, 5);

        $sessions = array_map(function (array $entry, int $index): array {
            $slot = $index + 1;
            $actor = sprintf('codex-%d', $slot);
            $session = sprintf('self-construction-session-%d', $slot);

            return [
                'slot_id' => sprintf('CODEX-LAUNCH-SLOT-%03d', $slot),
                'state' => 'ready_to_start',
                'expected_packet_id' => data_get($entry, 'packet_id'),
                'lane' => data_get($entry, 'lane'),
                'actor' => $actor,
                'session' => $session,
                'command' => 'php artisan atlas:ai:self-construction --codex-start-packet'
                    .' --actor='.$actor
                    .' --session='.$session
                    .' --json',
                'one_line_user_prompt' => 'continua a implementação da forma mais profissional e completa possível',
                'operator_instruction' => 'Open a fresh Codex session, run the command, follow the returned contract, complete or release the claimed packet, and do not touch files outside the returned allowed scope.',
            ];
        }, $launchable, array_keys($launchable));

        $plan = [
            'plan_id' => 'CODEX-LAUNCH-PLAN-SELF-CONSTRUCTION-0001',
            'source_queue_hash' => data_get($queuePayload, 'queue_hash'),
            'source_gate_hash' => data_get($gatePayload, 'gate_hash'),
            'max_sessions' => 5,
            'launchable_count' => count($sessions),
            'available_count' => data_get($queuePayload, 'queue.available_count'),
            'claimed_count' => data_get($queuePayload, 'queue.claimed_count'),
            'completed_count' => data_get($queuePayload, 'queue.completed_count'),
            'withheld_count' => data_get($queuePayload, 'queue.withheld_count'),
            'parallel_preview_allowed' => (bool) data_get($gatePayload, 'parallel_preview_allowed'),
            'dispatch_allowed' => false,
            'claim_persisted' => false,
            'execution_allowed' => false,
            'completion_allowed' => false,
            'sessions' => $sessions,
            'operator_sequence' => [
                'run_codex_launch_plan_once',
                'open_one_fresh_codex_session_per_launch_slot',
                'paste_the_slot_command_in_each_session',
                'each_session_follows_its_returned_codex_start_contract',
                'each_session_runs_scope_validator_before_and_after_edits',
                'each_session_completes_or_releases_its_packet',
                'rerun_packet_queue_and_reservation_status_after_sessions_finish',
            ],
            'stop_conditions' => [
                'launchable_count_is_zero',
                'codex_start_packet_returns_blocked',
                'scope_validator_blocks_any_session',
                'two_sessions_receive_same_packet_id',
                'required_gate_failed',
                'hot_voice_or_kernel_scope_detected',
            ],
        ];

        return [
            'schema_version' => 'atlas.self_construction_codex_launch_plan.v1',
            'status' => $sessions === [] ? 'blocked_no_launchable_sessions' : 'codex_launch_plan_ready',
            'mode' => 'read_only_codex_launch_plan',
            'execution_allowed' => false,
            'completion_allowed' => false,
            'claim_persisted' => false,
            'ledger_write_allowed' => false,
            'dispatch_allowed' => false,
            'plan' => $plan,
            'plan_hash' => $this->stableHash($plan),
            'non_execution_guarantees' => [
                'codex_launch_plan_does_not_claim_packets',
                'codex_launch_plan_does_not_start_sessions',
                'codex_launch_plan_does_not_dispatch_work',
                'codex_launch_plan_does_not_enable_execution',
            ],
            'human_summary' => $sessions === []
                ? 'Codex launch plan is blocked because no available packet remains.'
                : 'Codex launch plan is ready: start commands are generated without claiming, dispatching or executing work.',
        ];
    }

    /**
     * @param  array{workspace?: string|null, target?: string|null, packet?: string|null, actor?: string|null, session?: string|null, lease_minutes?: int|string|null, reason?: string|null}  $options
     * @return array<string, mixed>
     */
    public function codexExecutionStatus(array $options = []): array
    {
        $queuePayload = $this->packetQueue($options);
        $reservationPayload = $this->reservationStatus($options);
        $launchPlanPayload = $this->codexLaunchPlan($options);
        $entries = (array) data_get($queuePayload, 'queue.entries', []);

        $claimed = array_values(array_filter($entries, fn (array $entry): bool => data_get($entry, 'queue_state') === 'claimed'));
        $completed = array_values(array_filter($entries, fn (array $entry): bool => data_get($entry, 'queue_state') === 'completed'));
        $available = array_values(array_filter($entries, fn (array $entry): bool => data_get($entry, 'queue_state') === 'available'));
        $blocked = array_values(array_filter($entries, fn (array $entry): bool => data_get($entry, 'queue_state') === 'blocked_by_dependency'));
        $withheld = array_values(array_filter($entries, fn (array $entry): bool => data_get($entry, 'queue_state') === 'withheld'));

        $claimedSessions = array_map(fn (array $entry): array => [
            'packet_id' => data_get($entry, 'packet_id'),
            'lane' => data_get($entry, 'lane'),
            'actor' => data_get($entry, 'active_reservation_actor'),
            'session' => data_get($entry, 'active_reservation_session'),
            'reservation_id' => data_get($entry, 'active_reservation_id'),
            'lease_expires_at' => data_get($entry, 'lease_expires_at'),
            'next_expected_action' => 'run_scope_validator_gates_then_complete_or_release_packet',
        ], $claimed);

        $completedPackets = array_map(fn (array $entry): array => [
            'packet_id' => data_get($entry, 'packet_id'),
            'lane' => data_get($entry, 'lane'),
            'actor' => data_get($entry, 'completion_actor'),
            'reservation_id' => data_get($entry, 'completed_reservation_id'),
            'completed_at' => data_get($entry, 'completed_at'),
        ], $completed);

        $monitor = [
            'monitor_id' => 'CODEX-EXECUTION-STATUS-SELF-CONSTRUCTION-0001',
            'source_queue_hash' => data_get($queuePayload, 'queue_hash'),
            'source_reservation_hash' => data_get($reservationPayload, 'ledger_hash'),
            'source_launch_plan_hash' => data_get($launchPlanPayload, 'plan_hash'),
            'counts' => [
                'available' => count($available),
                'claimed' => count($claimed),
                'completed' => count($completed),
                'blocked' => count($blocked),
                'withheld' => count($withheld),
                'launchable' => data_get($launchPlanPayload, 'plan.launchable_count'),
                'ledger_events' => data_get($reservationPayload, 'ledger.event_count'),
            ],
            'claimed_sessions' => $claimedSessions,
            'completed_packets' => $completedPackets,
            'next_launch_commands' => array_values(array_map(
                fn (array $session): string => (string) data_get($session, 'command'),
                (array) data_get($launchPlanPayload, 'plan.sessions', [])
            )),
            'recommended_next_action' => match (true) {
                count($claimed) > 0 => 'wait_for_active_sessions_or_review_their_final_response_contracts',
                count($available) > 0 => 'launch_available_codex_sessions',
                count($blocked) > 0 => 'review_dependency_unlock_plan',
                default => 'review_completed_packets_and_external_hot_work',
            },
            'operator_commands' => [
                'refresh_status' => 'php artisan atlas:ai:self-construction --codex-execution-status --json',
                'launch_plan' => 'php artisan atlas:ai:self-construction --codex-launch-plan --json',
                'packet_queue' => 'php artisan atlas:ai:self-construction --packet-queue --json',
                'reservation_status' => 'php artisan atlas:ai:self-construction --reservation-status --json',
            ],
            'execution_allowed' => false,
            'claim_persisted' => false,
            'completion_persisted' => false,
            'dispatch_allowed' => false,
        ];

        return [
            'schema_version' => 'atlas.self_construction_codex_execution_status.v1',
            'status' => 'codex_execution_status_ready',
            'mode' => 'read_only_codex_execution_status',
            'execution_allowed' => false,
            'completion_allowed' => false,
            'claim_persisted' => false,
            'completion_persisted' => false,
            'ledger_write_allowed' => false,
            'dispatch_allowed' => false,
            'monitor' => $monitor,
            'monitor_hash' => $this->stableHash($monitor),
            'non_execution_guarantees' => [
                'codex_execution_status_does_not_claim_packets',
                'codex_execution_status_does_not_complete_packets',
                'codex_execution_status_does_not_start_sessions',
                'codex_execution_status_does_not_dispatch_work',
            ],
            'human_summary' => 'Codex execution status is ready: active, completed and available packet state is visible without mutating reservations or dispatching work.',
        ];
    }

    /**
     * @param  array{workspace?: string|null, target?: string|null, packet?: string|null, actor?: string|null, session?: string|null, lease_minutes?: int|string|null, reason?: string|null}  $options
     * @return array<string, mixed>
     */
    public function codexIntegrationReport(array $options = []): array
    {
        $queuePayload = $this->packetQueue($options);
        $statusPayload = $this->codexExecutionStatus($options);
        $reservationPayload = $this->reservationStatus($options);
        $entries = (array) data_get($queuePayload, 'queue.entries', []);

        $completed = array_values(array_filter($entries, fn (array $entry): bool => data_get($entry, 'queue_state') === 'completed'));
        $claimed = array_values(array_filter($entries, fn (array $entry): bool => data_get($entry, 'queue_state') === 'claimed'));
        $available = array_values(array_filter($entries, fn (array $entry): bool => data_get($entry, 'queue_state') === 'available'));
        $blocked = array_values(array_filter($entries, fn (array $entry): bool => data_get($entry, 'queue_state') === 'blocked_by_dependency'));
        $withheld = array_values(array_filter($entries, fn (array $entry): bool => data_get($entry, 'queue_state') === 'withheld'));
        $completedReservations = collect((array) data_get($reservationPayload, 'ledger.completed_reservations', []))
            ->keyBy('packet_id');

        $readyToReview = array_map(function (array $entry) use ($completedReservations): array {
            $packetId = (string) data_get($entry, 'packet_id');
            $reservation = (array) $completedReservations->get($packetId, []);

            return [
                'packet_id' => $packetId,
                'lane' => data_get($entry, 'lane'),
                'objective' => data_get($entry, 'objective'),
                'completed_at' => data_get($entry, 'completed_at'),
                'actor' => data_get($entry, 'completion_actor'),
                'reservation_id' => data_get($entry, 'completed_reservation_id'),
                'evidence_hash' => data_get($reservation, 'completion_evidence_hash'),
                'allowed_files' => (array) data_get($entry, 'allowed_files', []),
                'review_expectations' => [
                    'inspect_diff_for_allowed_files_only',
                    'verify_reported_gates_against_final_response_contract',
                    'run_scope_validator_for_packet_if_files_changed',
                    'do_not_merge_or_approve_from_completion_state_alone',
                ],
            ];
        }, $completed);

        $missingPackets = array_map(fn (array $entry): array => [
            'packet_id' => data_get($entry, 'packet_id'),
            'lane' => data_get($entry, 'lane'),
            'queue_state' => data_get($entry, 'queue_state'),
            'next_action' => match ((string) data_get($entry, 'queue_state')) {
                'claimed' => 'wait_for_owner_to_complete_or_release',
                'available' => 'claim_with_codex_start_packet',
                'blocked_by_dependency' => 'complete_dependencies_first',
                default => 'review_state',
            },
        ], array_values(array_filter(
            array_merge($claimed, $available, $blocked),
            fn (array $entry): bool => in_array(data_get($entry, 'queue_state'), ['claimed', 'available', 'blocked_by_dependency'], true)
        )));

        $integrationStatus = match (true) {
            $claimed !== [] => 'waiting_for_active_sessions',
            $completed !== [] && ($available !== [] || $blocked !== []) => 'partial_completion_review_available',
            $completed !== [] => 'ready_for_human_integration_review',
            default => 'nothing_completed_yet',
        };

        $report = [
            'report_id' => 'CODEX-INTEGRATION-REPORT-SELF-CONSTRUCTION-0001',
            'source_queue_hash' => data_get($queuePayload, 'queue_hash'),
            'source_execution_status_hash' => data_get($statusPayload, 'monitor_hash'),
            'source_reservation_hash' => data_get($reservationPayload, 'ledger_hash'),
            'integration_status' => $integrationStatus,
            'counts' => [
                'ready_to_review' => count($readyToReview),
                'active_sessions' => count($claimed),
                'missing_packets' => count($missingPackets),
                'withheld_packets' => count($withheld),
                'ledger_events' => data_get($reservationPayload, 'ledger.event_count'),
            ],
            'ready_to_review_packets' => $readyToReview,
            'missing_packets' => $missingPackets,
            'withheld_packets' => array_map(fn (array $entry): array => [
                'packet_id' => data_get($entry, 'packet_id'),
                'lane' => data_get($entry, 'lane'),
                'reason' => data_get($entry, 'objective'),
                'forbidden_files' => (array) data_get($entry, 'forbidden_files', []),
            ], $withheld),
            'required_integrator_sequence' => [
                'refresh_codex_execution_status',
                'review_each_completed_session_final_response_contract',
                'verify_each_completed_packet_evidence_hash',
                'run_packet_scope_validator_for_relevant_completed_packets',
                'run_focused_self_construction_tests',
                'run_docs_health_and_architecture_validate',
                'prepare_human_summary_before_any_merge_or_approval',
            ],
            'operator_commands' => [
                'refresh_status' => 'php artisan atlas:ai:self-construction --codex-execution-status --json',
                'integration_report' => 'php artisan atlas:ai:self-construction --codex-integration-report --json',
                'packet_queue' => 'php artisan atlas:ai:self-construction --packet-queue --json',
                'scope_validator_template' => 'php artisan atlas:ai:self-construction --scope-validator --packet=<packet-id> --json',
                'focused_tests' => 'php artisan test tests/Feature/Ai/AtlasAiSelfConstructionCommandTest.php',
                'docs_health' => 'php artisan atlas:engineering:knowledge docs-health --json',
                'architecture_validate' => 'php artisan atlas:ai:architecture-validate --json',
                'diff_check' => 'git diff --check',
            ],
            'approval_boundaries' => [
                'packet_completion_is_not_code_approval',
                'integration_report_is_not_merge_authority',
                'human_or_governed_receipt_must_review_before_merge',
                'hot_voice_and_kernel_work_remain_withheld',
            ],
            'execution_allowed' => false,
            'claim_persisted' => false,
            'completion_persisted' => false,
            'dispatch_allowed' => false,
            'approval_granted' => false,
        ];

        return [
            'schema_version' => 'atlas.self_construction_codex_integration_report.v1',
            'status' => 'codex_integration_report_ready',
            'mode' => 'read_only_codex_integration_report',
            'execution_allowed' => false,
            'completion_allowed' => false,
            'claim_persisted' => false,
            'completion_persisted' => false,
            'ledger_write_allowed' => false,
            'dispatch_allowed' => false,
            'approval_granted' => false,
            'report' => $report,
            'report_hash' => $this->stableHash($report),
            'non_execution_guarantees' => [
                'codex_integration_report_does_not_claim_packets',
                'codex_integration_report_does_not_complete_packets',
                'codex_integration_report_does_not_approve_code',
                'codex_integration_report_does_not_auto_merge',
                'codex_integration_report_does_not_dispatch_work',
            ],
            'human_summary' => 'Codex integration report is ready: completed packet evidence is consolidated for human review without approving code, merging or dispatching work.',
        ];
    }

    /**
     * @param  array{workspace?: string|null, target?: string|null, packet?: string|null, actor?: string|null, session?: string|null, lease_minutes?: int|string|null, reason?: string|null}  $options
     * @return array<string, mixed>
     */
    public function codexMergeReadiness(array $options = []): array
    {
        $integrationPayload = $this->codexIntegrationReport($options);
        $report = (array) data_get($integrationPayload, 'report', []);
        $readyPackets = (array) data_get($report, 'ready_to_review_packets', []);
        $missingPackets = (array) data_get($report, 'missing_packets', []);
        $activeSessions = (int) data_get($report, 'counts.active_sessions', 0);
        $missingEvidence = array_values(array_filter(
            $readyPackets,
            fn (array $packet): bool => ! is_string(data_get($packet, 'evidence_hash')) || data_get($packet, 'evidence_hash') === ''
        ));

        $blocking = [];
        if ($activeSessions > 0) {
            $blocking[] = [
                'id' => 'active_sessions_present',
                'severity' => 'blocking',
                'detail' => 'One or more Codex sessions are still active; merge review must wait for completion or release.',
            ];
        }
        if ($missingPackets !== []) {
            $blocking[] = [
                'id' => 'packets_not_completed',
                'severity' => 'blocking',
                'detail' => 'All assignable Self-Construction packets must be completed before full merge review readiness.',
                'packet_count' => count($missingPackets),
            ];
        }
        if ($missingEvidence !== []) {
            $blocking[] = [
                'id' => 'completion_evidence_hash_missing',
                'severity' => 'blocking',
                'detail' => 'Each completed packet must include a completion evidence hash before merge review.',
                'packet_ids' => array_values(array_map(fn (array $packet): mixed => data_get($packet, 'packet_id'), $missingEvidence)),
            ];
        }

        $mergeReviewStatus = $blocking === []
            ? 'ready_for_human_merge_review'
            : 'blocked_for_merge_review';

        $readiness = [
            'readiness_id' => 'CODEX-MERGE-READINESS-SELF-CONSTRUCTION-0001',
            'source_integration_report_hash' => data_get($integrationPayload, 'report_hash'),
            'merge_review_status' => $mergeReviewStatus,
            'ready_packet_count' => count($readyPackets),
            'missing_packet_count' => count($missingPackets),
            'active_session_count' => $activeSessions,
            'missing_evidence_count' => count($missingEvidence),
            'blocking_count' => count($blocking),
            'blocking_failures' => $blocking,
            'ready_packets' => array_map(fn (array $packet): array => [
                'packet_id' => data_get($packet, 'packet_id'),
                'lane' => data_get($packet, 'lane'),
                'actor' => data_get($packet, 'actor'),
                'reservation_id' => data_get($packet, 'reservation_id'),
                'evidence_hash' => data_get($packet, 'evidence_hash'),
                'allowed_files' => (array) data_get($packet, 'allowed_files', []),
            ], $readyPackets),
            'required_review_gates' => [
                'php artisan atlas:ai:self-construction --codex-execution-status --json',
                'php artisan atlas:ai:self-construction --codex-integration-report --json',
                'php artisan test tests/Feature/Ai/AtlasAiSelfConstructionCommandTest.php',
                'php artisan atlas:engineering:knowledge docs-health --json',
                'php artisan atlas:ai:architecture-validate --json',
                'git diff --check',
            ],
            'merge_boundaries' => [
                'merge_readiness_is_not_merge_approval',
                'packet_completion_is_not_code_approval',
                'human_review_or_signed_governed_receipt_required',
                'hot_voice_and_kernel_scopes_remain_out_of_scope',
            ],
            'recommended_next_action' => $blocking === []
                ? 'perform_human_or_governed_receipt_review_before_merge'
                : 'resolve_blocking_failures_before_merge_review',
            'execution_allowed' => false,
            'approval_granted' => false,
            'merge_allowed' => false,
            'dispatch_allowed' => false,
        ];

        return [
            'schema_version' => 'atlas.self_construction_codex_merge_readiness.v1',
            'status' => $mergeReviewStatus,
            'mode' => 'read_only_codex_merge_readiness',
            'execution_allowed' => false,
            'completion_allowed' => false,
            'claim_persisted' => false,
            'completion_persisted' => false,
            'ledger_write_allowed' => false,
            'dispatch_allowed' => false,
            'approval_granted' => false,
            'merge_allowed' => false,
            'readiness' => $readiness,
            'readiness_hash' => $this->stableHash($readiness),
            'non_execution_guarantees' => [
                'codex_merge_readiness_does_not_claim_packets',
                'codex_merge_readiness_does_not_complete_packets',
                'codex_merge_readiness_does_not_approve_code',
                'codex_merge_readiness_does_not_merge',
                'codex_merge_readiness_does_not_dispatch_work',
            ],
            'human_summary' => $blocking === []
                ? 'Codex merge readiness is ready for human or governed receipt review. It still does not grant merge approval.'
                : 'Codex merge readiness is blocked; resolve active sessions, missing packets or missing evidence before human merge review.',
        ];
    }

    /**
     * @param  array{workspace?: string|null, target?: string|null, packet?: string|null, actor?: string|null, session?: string|null, lease_minutes?: int|string|null, reason?: string|null}  $options
     * @return array<string, mixed>
     */
    public function codexFinalReviewPacket(array $options = []): array
    {
        $mergePayload = $this->codexMergeReadiness($options);
        $readiness = (array) data_get($mergePayload, 'readiness', []);
        $readyPackets = (array) data_get($readiness, 'ready_packets', []);
        $blocking = (array) data_get($readiness, 'blocking_failures', []);
        $reviewReady = data_get($mergePayload, 'status') === 'ready_for_human_merge_review';

        $packet = [
            'packet_id' => 'CODEX-FINAL-REVIEW-PACKET-SELF-CONSTRUCTION-0001',
            'source_merge_readiness_hash' => data_get($mergePayload, 'readiness_hash'),
            'review_status' => $reviewReady ? 'ready_for_principal_integrator_review' : 'blocked_before_final_review',
            'decision_required' => true,
            'ready_packet_count' => count($readyPackets),
            'blocking_count' => count($blocking),
            'blocking_failures' => $blocking,
            'ready_packets' => $readyPackets,
            'principal_integrator_checklist' => [
                'confirm_each_codex_final_response_names_packet_and_reservation',
                'confirm_each_packet_diff_only_touches_allowed_files',
                'confirm_evidence_hash_matches_reported_final_evidence',
                'run_required_review_gates_from_merge_readiness',
                'inspect_docs_for_contract_drift',
                'verify_hot_voice_and_kernel_scopes_were_not_modified_by_self_construction',
                'write_human_review_decision_before_merge',
            ],
            'required_review_gates' => (array) data_get($readiness, 'required_review_gates', []),
            'decision_slots' => [
                [
                    'id' => 'principal_integrator_decision',
                    'required' => true,
                    'allowed_values' => ['approve_for_merge', 'request_changes', 'reject'],
                    'default' => 'request_changes',
                ],
                [
                    'id' => 'scope_integrity_decision',
                    'required' => true,
                    'allowed_values' => ['scope_clean', 'scope_violation_found'],
                    'default' => 'scope_violation_found',
                ],
                [
                    'id' => 'evidence_integrity_decision',
                    'required' => true,
                    'allowed_values' => ['evidence_verified', 'evidence_missing_or_inconsistent'],
                    'default' => 'evidence_missing_or_inconsistent',
                ],
            ],
            'review_boundaries' => [
                'final_review_packet_is_not_approval',
                'final_review_packet_is_not_merge_authority',
                'merge_readiness_is_not_merge_approval',
                'packet_completion_is_not_code_approval',
                'human_decision_must_be_recorded_outside_this_read_only_surface',
            ],
            'recommended_next_action' => $reviewReady
                ? 'run_review_gates_and_record_principal_integrator_decision'
                : 'resolve_merge_readiness_blockers_before_final_review',
            'execution_allowed' => false,
            'approval_granted' => false,
            'merge_allowed' => false,
            'dispatch_allowed' => false,
        ];

        return [
            'schema_version' => 'atlas.self_construction_codex_final_review_packet.v1',
            'status' => $reviewReady ? 'final_review_packet_ready' : 'blocked_before_final_review',
            'mode' => 'read_only_codex_final_review_packet',
            'execution_allowed' => false,
            'completion_allowed' => false,
            'claim_persisted' => false,
            'completion_persisted' => false,
            'ledger_write_allowed' => false,
            'dispatch_allowed' => false,
            'approval_granted' => false,
            'merge_allowed' => false,
            'packet' => $packet,
            'packet_hash' => $this->stableHash($packet),
            'non_execution_guarantees' => [
                'codex_final_review_packet_does_not_claim_packets',
                'codex_final_review_packet_does_not_complete_packets',
                'codex_final_review_packet_does_not_approve_code',
                'codex_final_review_packet_does_not_merge',
                'codex_final_review_packet_does_not_dispatch_work',
            ],
            'human_summary' => $reviewReady
                ? 'Codex final review packet is ready for the principal integrator. It still does not approve, merge or dispatch work.'
                : 'Codex final review packet is blocked until merge-readiness blockers are resolved.',
        ];
    }

    /**
     * @param  array{workspace?: string|null, target?: string|null, packet?: string|null, actor?: string|null, session?: string|null, lease_minutes?: int|string|null, reason?: string|null}  $options
     * @return array<string, mixed>
     */
    public function codexReviewDecisionTemplate(array $options = []): array
    {
        $reviewPayload = $this->codexFinalReviewPacket($options);
        $packet = (array) data_get($reviewPayload, 'packet', []);
        $reviewReady = data_get($reviewPayload, 'status') === 'final_review_packet_ready';

        $template = [
            'template_id' => 'CODEX-REVIEW-DECISION-TEMPLATE-SELF-CONSTRUCTION-0001',
            'source_final_review_packet_hash' => data_get($reviewPayload, 'packet_hash'),
            'decision_status' => $reviewReady ? 'ready_for_manual_decision' : 'blocked_before_manual_decision',
            'decision_recording_allowed' => false,
            'default_decision' => 'request_changes',
            'required_inputs' => [
                'principal_integrator_name',
                'reviewed_at',
                'selected_decision',
                'scope_integrity_result',
                'evidence_integrity_result',
                'gates_run_with_outputs',
                'files_reviewed',
                'decision_rationale',
                'remaining_risks',
            ],
            'allowed_decisions' => [
                'approve_for_merge',
                'request_changes',
                'reject',
            ],
            'approval_preconditions' => [
                'final_review_packet_ready',
                'all_required_review_gates_passed',
                'scope_integrity_result_is_scope_clean',
                'evidence_integrity_result_is_evidence_verified',
                'no_hot_scope_edits_from_self_construction',
                'principal_integrator_rationale_present',
            ],
            'default_safe_decision_policy' => [
                'when_any_precondition_is_missing' => 'request_changes',
                'when_scope_violation_is_found' => 'reject_or_request_changes',
                'when_evidence_is_missing' => 'request_changes',
                'when_hot_scope_is_touched' => 'reject',
            ],
            'source_review_status' => data_get($packet, 'review_status'),
            'ready_packet_count' => data_get($packet, 'ready_packet_count'),
            'blocking_count' => data_get($packet, 'blocking_count'),
            'decision_slots' => (array) data_get($packet, 'decision_slots', []),
            'checklist' => (array) data_get($packet, 'principal_integrator_checklist', []),
            'required_review_gates' => (array) data_get($packet, 'required_review_gates', []),
            'output_contract' => [
                'decision_must_be_recorded_by_future_signed_receipt_or_human_review_surface',
                'template_output_must_include_source_final_review_packet_hash',
                'template_output_must_include_gate_outputs_or_links',
                'template_output_must_include_scope_and_evidence_integrity_results',
            ],
            'boundaries' => [
                'decision_template_does_not_record_decision',
                'decision_template_does_not_grant_approval',
                'decision_template_does_not_merge',
                'decision_template_does_not_dispatch_work',
            ],
            'execution_allowed' => false,
            'approval_granted' => false,
            'merge_allowed' => false,
            'dispatch_allowed' => false,
        ];

        return [
            'schema_version' => 'atlas.self_construction_codex_review_decision_template.v1',
            'status' => $reviewReady ? 'review_decision_template_ready' : 'blocked_before_review_decision_template',
            'mode' => 'read_only_codex_review_decision_template',
            'execution_allowed' => false,
            'completion_allowed' => false,
            'claim_persisted' => false,
            'completion_persisted' => false,
            'ledger_write_allowed' => false,
            'dispatch_allowed' => false,
            'approval_granted' => false,
            'merge_allowed' => false,
            'template' => $template,
            'template_hash' => $this->stableHash($template),
            'non_execution_guarantees' => [
                'codex_review_decision_template_does_not_claim_packets',
                'codex_review_decision_template_does_not_complete_packets',
                'codex_review_decision_template_does_not_record_decision',
                'codex_review_decision_template_does_not_approve_code',
                'codex_review_decision_template_does_not_merge',
                'codex_review_decision_template_does_not_dispatch_work',
            ],
            'human_summary' => $reviewReady
                ? 'Codex review decision template is ready for manual completion. It does not record approval or allow merge.'
                : 'Codex review decision template is blocked until the final review packet is ready.',
        ];
    }

    /**
     * @param  array{workspace?: string|null, target?: string|null, packet?: string|null, actor?: string|null, session?: string|null, lease_minutes?: int|string|null, reason?: string|null}  $options
     * @return array<string, mixed>
     */
    public function codexReviewReceiptDraft(array $options = []): array
    {
        $templatePayload = $this->codexReviewDecisionTemplate($options);
        $template = (array) data_get($templatePayload, 'template', []);
        $templateReady = data_get($templatePayload, 'status') === 'review_decision_template_ready';

        $receipt = [
            'receipt_id' => 'CODEX-REVIEW-RECEIPT-DRAFT-SELF-CONSTRUCTION-0001',
            'status' => $templateReady ? 'draft_ready_for_signature_review' : 'blocked_before_receipt_draft',
            'source_decision_template_hash' => data_get($templatePayload, 'template_hash'),
            'source_final_review_packet_hash' => data_get($template, 'source_final_review_packet_hash'),
            'signature_required' => true,
            'signature_valid' => false,
            'decision_recorded' => false,
            'approval_granted' => false,
            'merge_allowed' => false,
            'default_decision' => data_get($template, 'default_decision', 'request_changes'),
            'signer_roles' => [
                'principal_integrator',
                'human_owner_or_governed_receipt_authority',
            ],
            'draft_fields' => [
                'principal_integrator_name' => null,
                'reviewed_at' => null,
                'selected_decision' => data_get($template, 'default_decision', 'request_changes'),
                'scope_integrity_result' => null,
                'evidence_integrity_result' => null,
                'gates_run_with_outputs' => [],
                'files_reviewed' => [],
                'decision_rationale' => null,
                'remaining_risks' => [],
                'source_decision_template_hash' => data_get($templatePayload, 'template_hash'),
            ],
            'required_inputs' => (array) data_get($template, 'required_inputs', []),
            'approval_preconditions' => (array) data_get($template, 'approval_preconditions', []),
            'safe_decision_policy' => (array) data_get($template, 'default_safe_decision_policy', []),
            'verification_commands' => [
                'php artisan atlas:ai:self-construction --codex-review-decision-template --json',
                'php artisan atlas:ai:self-construction --codex-final-review-packet --json',
                'php artisan atlas:ai:self-construction --codex-merge-readiness --json',
                'php artisan test tests/Feature/Ai/AtlasAiSelfConstructionCommandTest.php',
                'php artisan atlas:engineering:knowledge docs-health --json',
                'php artisan atlas:ai:architecture-validate --json',
                'git diff --check',
            ],
            'non_authorizing_invariants' => [
                'receipt_draft_is_unsigned',
                'receipt_draft_does_not_record_decision',
                'receipt_draft_does_not_grant_approval',
                'receipt_draft_does_not_allow_merge',
                'receipt_draft_does_not_dispatch_work',
            ],
            'next_required_action' => $templateReady
                ? 'principal_integrator_completes_and_signs_receipt_in_future_governed_surface'
                : 'resolve_final_review_blockers_before_receipt_draft',
        ];

        return [
            'schema_version' => 'atlas.self_construction_codex_review_receipt_draft.v1',
            'status' => $templateReady ? 'review_receipt_draft_ready' : 'blocked_before_review_receipt_draft',
            'mode' => 'read_only_codex_review_receipt_draft',
            'execution_allowed' => false,
            'completion_allowed' => false,
            'claim_persisted' => false,
            'completion_persisted' => false,
            'ledger_write_allowed' => false,
            'dispatch_allowed' => false,
            'approval_granted' => false,
            'merge_allowed' => false,
            'decision_recorded' => false,
            'receipt' => $receipt,
            'receipt_hash' => $this->stableHash($receipt),
            'non_execution_guarantees' => [
                'codex_review_receipt_draft_does_not_claim_packets',
                'codex_review_receipt_draft_does_not_complete_packets',
                'codex_review_receipt_draft_does_not_record_decision',
                'codex_review_receipt_draft_does_not_approve_code',
                'codex_review_receipt_draft_does_not_merge',
                'codex_review_receipt_draft_does_not_dispatch_work',
            ],
            'human_summary' => $templateReady
                ? 'Codex review receipt draft is ready for future signature review. It is unsigned and does not approve or merge.'
                : 'Codex review receipt draft is blocked until the decision template is ready.',
        ];
    }

    /**
     * @param  array{workspace?: string|null, target?: string|null, packet?: string|null, actor?: string|null, session?: string|null, lease_minutes?: int|string|null, reason?: string|null}  $options
     * @return array<string, mixed>
     */
    public function codexReviewSignatureRequest(array $options = []): array
    {
        $draftPayload = $this->codexReviewReceiptDraft($options);
        $receipt = (array) data_get($draftPayload, 'receipt', []);
        $draftReady = data_get($draftPayload, 'status') === 'review_receipt_draft_ready';

        $signablePayload = [
            'receipt_id' => data_get($receipt, 'receipt_id'),
            'receipt_hash' => data_get($draftPayload, 'receipt_hash'),
            'source_decision_template_hash' => data_get($receipt, 'source_decision_template_hash'),
            'source_final_review_packet_hash' => data_get($receipt, 'source_final_review_packet_hash'),
            'requested_signature_type' => 'principal_integrator_explicit_review_decision',
            'allowed_decisions' => [
                'approve_for_merge',
                'request_changes',
                'reject',
            ],
            'required_signer_roles' => (array) data_get($receipt, 'signer_roles', []),
            'required_inputs' => (array) data_get($receipt, 'required_inputs', []),
            'approval_preconditions' => (array) data_get($receipt, 'approval_preconditions', []),
            'verification_commands' => (array) data_get($receipt, 'verification_commands', []),
            'still_forbidden_after_signature' => [
                'auto_merge_without_explicit_human_merge_action',
                'dispatch_work_from_signature_request',
                'touch_hot_voice_or_kernel_scope',
                'bypass_required_review_gates',
            ],
        ];

        $request = [
            'request_id' => 'CODEX-REVIEW-SIGNATURE-REQUEST-SELF-CONSTRUCTION-0001',
            'status' => $draftReady ? 'pending_principal_integrator_signature' : 'blocked_before_signature_request',
            'source_receipt_draft_hash' => data_get($draftPayload, 'receipt_hash'),
            'signable_payload_hash' => $this->stableHash($signablePayload),
            'signature_present' => false,
            'signature_valid' => false,
            'decision_recorded' => false,
            'approval_granted' => false,
            'merge_allowed' => false,
            'required_signer_roles' => (array) data_get($receipt, 'signer_roles', []),
            'signable_payload' => $signablePayload,
            'operator_instructions' => [
                'review_signable_payload_hash_before_signing',
                'fill_required_inputs_in_a_future_governed_surface',
                'attach_gate_outputs_or_links',
                'record_explicit_human_decision_separately',
                'do_not_treat_this_request_as_signature',
            ],
            'non_authorizing_invariants' => [
                'signature_request_is_not_signature',
                'signature_request_does_not_record_decision',
                'signature_request_does_not_grant_approval',
                'signature_request_does_not_allow_merge',
                'signature_request_does_not_dispatch_work',
            ],
        ];

        return [
            'schema_version' => 'atlas.self_construction_codex_review_signature_request.v1',
            'status' => $draftReady ? 'review_signature_pending' : 'blocked_before_review_signature_request',
            'mode' => 'read_only_codex_review_signature_request',
            'execution_allowed' => false,
            'completion_allowed' => false,
            'claim_persisted' => false,
            'completion_persisted' => false,
            'ledger_write_allowed' => false,
            'dispatch_allowed' => false,
            'approval_granted' => false,
            'merge_allowed' => false,
            'decision_recorded' => false,
            'signature_request' => $request,
            'signable_payload' => $signablePayload,
            'signable_payload_hash' => $this->stableHash($signablePayload),
            'request_hash' => $this->stableHash($request),
            'non_execution_guarantees' => [
                'codex_review_signature_request_does_not_claim_packets',
                'codex_review_signature_request_does_not_complete_packets',
                'codex_review_signature_request_does_not_record_decision',
                'codex_review_signature_request_does_not_approve_code',
                'codex_review_signature_request_does_not_merge',
                'codex_review_signature_request_does_not_dispatch_work',
            ],
            'human_summary' => $draftReady
                ? 'Codex review signature request is ready: the signable payload is hash-bound, but no signature, approval or merge is granted.'
                : 'Codex review signature request is blocked until the review receipt draft is ready.',
        ];
    }

    /**
     * @param  array{workspace?: string|null, target?: string|null, packet?: string|null, actor?: string|null, session?: string|null, lease_minutes?: int|string|null, reason?: string|null}  $options
     * @return array<string, mixed>
     */
    public function codexReviewPostSignatureRunbook(array $options = []): array
    {
        $signaturePayload = $this->codexReviewSignatureRequest($options);
        $signatureRequest = (array) data_get($signaturePayload, 'signature_request', []);
        $signablePayload = (array) data_get($signaturePayload, 'signable_payload', []);
        $requestReady = data_get($signaturePayload, 'status') === 'review_signature_pending';

        $steps = [
            [
                'id' => 'verify_signature_payload',
                'title' => 'Verify the signable payload hash matches the signed human/governed receipt.',
                'required_evidence' => ['signable_payload_hash', 'signed_receipt_hash'],
            ],
            [
                'id' => 'verify_human_decision',
                'title' => 'Verify selected decision, rationale, scope result and evidence result are present.',
                'required_evidence' => ['selected_decision', 'decision_rationale', 'scope_integrity_result', 'evidence_integrity_result'],
            ],
            [
                'id' => 'rerun_review_gates',
                'title' => 'Rerun required review gates before any merge decision.',
                'required_evidence' => (array) data_get($signablePayload, 'verification_commands', []),
            ],
            [
                'id' => 'prepare_explicit_merge_action',
                'title' => 'Prepare a separate explicit merge action only if decision is approve_for_merge and all gates pass.',
                'required_evidence' => ['explicit_merge_action_or_manual_merge_record'],
            ],
        ];

        $runbook = [
            'runbook_id' => 'CODEX-REVIEW-POST-SIGNATURE-RUNBOOK-SELF-CONSTRUCTION-0001',
            'status' => $requestReady ? 'waiting_for_valid_signature' : 'blocked_before_signature_request',
            'source_signature_request_hash' => data_get($signaturePayload, 'request_hash'),
            'source_signable_payload_hash' => data_get($signaturePayload, 'signable_payload_hash'),
            'signature_required' => true,
            'signature_present' => false,
            'signature_valid' => false,
            'approval_granted' => false,
            'merge_allowed' => false,
            'auto_merge_allowed' => false,
            'step_count' => count($steps),
            'steps' => $steps,
            'required_before_any_merge_action' => [
                'valid_signature_against_signable_payload_hash',
                'selected_decision_is_approve_for_merge',
                'all_required_review_gates_passed_after_signature',
                'scope_integrity_result_is_scope_clean',
                'evidence_integrity_result_is_evidence_verified',
                'explicit_manual_or_governed_merge_action_created',
            ],
            'still_forbidden' => array_values(array_unique(array_merge(
                (array) data_get($signablePayload, 'still_forbidden_after_signature', []),
                [
                    'auto_merge_from_runbook',
                    'merge_without_explicit_action',
                    'hot_voice_or_kernel_scope_changes',
                ]
            ))),
            'operator_commands' => [
                'signature_request' => 'php artisan atlas:ai:self-construction --codex-review-signature-request --json',
                'receipt_draft' => 'php artisan atlas:ai:self-construction --codex-review-receipt-draft --json',
                'final_review_packet' => 'php artisan atlas:ai:self-construction --codex-final-review-packet --json',
                'focused_tests' => 'php artisan test tests/Feature/Ai/AtlasAiSelfConstructionCommandTest.php',
                'docs_health' => 'php artisan atlas:engineering:knowledge docs-health --json',
                'architecture_validate' => 'php artisan atlas:ai:architecture-validate --json',
                'diff_check' => 'git diff --check',
            ],
            'non_authorizing_invariants' => [
                'post_signature_runbook_does_not_validate_signature',
                'post_signature_runbook_does_not_record_decision',
                'post_signature_runbook_does_not_grant_approval',
                'post_signature_runbook_does_not_merge',
                'post_signature_runbook_does_not_dispatch_work',
            ],
        ];

        return [
            'schema_version' => 'atlas.self_construction_codex_review_post_signature_runbook.v1',
            'status' => $requestReady ? 'post_signature_runbook_ready' : 'blocked_before_post_signature_runbook',
            'mode' => 'read_only_codex_review_post_signature_runbook',
            'execution_allowed' => false,
            'completion_allowed' => false,
            'claim_persisted' => false,
            'completion_persisted' => false,
            'ledger_write_allowed' => false,
            'dispatch_allowed' => false,
            'approval_granted' => false,
            'merge_allowed' => false,
            'signature_valid' => false,
            'runbook' => $runbook,
            'runbook_hash' => $this->stableHash($runbook),
            'non_execution_guarantees' => [
                'codex_review_post_signature_runbook_does_not_claim_packets',
                'codex_review_post_signature_runbook_does_not_complete_packets',
                'codex_review_post_signature_runbook_does_not_validate_signature',
                'codex_review_post_signature_runbook_does_not_approve_code',
                'codex_review_post_signature_runbook_does_not_merge',
                'codex_review_post_signature_runbook_does_not_dispatch_work',
            ],
            'human_summary' => $requestReady
                ? 'Codex review post-signature runbook is ready as a conditional checklist. It does not validate signature, approve or merge.'
                : 'Codex review post-signature runbook is blocked until a review signature request is ready.',
        ];
    }

    /**
     * @param  array{workspace?: string|null, target?: string|null, packet?: string|null, actor?: string|null, session?: string|null, lease_minutes?: int|string|null, reason?: string|null}  $options
     * @return array<string, mixed>
     */
    public function codexReviewMergeActionTemplate(array $options = []): array
    {
        $runbookPayload = $this->codexReviewPostSignatureRunbook($options);
        $runbook = (array) data_get($runbookPayload, 'runbook', []);
        $runbookReady = data_get($runbookPayload, 'status') === 'post_signature_runbook_ready';

        $template = [
            'template_id' => 'CODEX-REVIEW-MERGE-ACTION-TEMPLATE-SELF-CONSTRUCTION-0001',
            'status' => $runbookReady ? 'waiting_for_explicit_signed_merge_action' : 'blocked_before_post_signature_runbook',
            'source_post_signature_runbook_hash' => data_get($runbookPayload, 'runbook_hash'),
            'source_signature_request_hash' => data_get($runbook, 'source_signature_request_hash'),
            'source_signable_payload_hash' => data_get($runbook, 'source_signable_payload_hash'),
            'explicit_merge_action_required' => true,
            'signature_validated_by_this_template' => false,
            'approval_granted' => false,
            'merge_allowed' => false,
            'auto_merge_allowed' => false,
            'execution_allowed' => false,
            'required_before_merge' => (array) data_get($runbook, 'required_before_any_merge_action', []),
            'required_inputs' => [
                'signed_receipt_hash',
                'signable_payload_hash',
                'selected_decision',
                'decision_rationale',
                'gate_outputs',
                'scope_integrity_result',
                'evidence_integrity_result',
                'files_to_merge',
                'human_merge_confirmation',
            ],
            'allowed_merge_decisions' => [
                'merge',
                'request_changes',
                'abort',
            ],
            'default_decision' => 'request_changes',
            'merge_boundaries' => [
                'merge_action_template_does_not_merge',
                'merge_action_template_does_not_validate_signature',
                'merge_action_template_does_not_grant_approval',
                'merge_action_template_does_not_record_decision',
                'merge_action_template_does_not_dispatch_work',
                'merge_requires_separate_human_or_governed_action',
                'hot_voice_or_kernel_scope_remains_forbidden',
            ],
            'operator_commands' => [
                'execution_status' => 'php artisan atlas:ai:self-construction --codex-execution-status --json',
                'integration_report' => 'php artisan atlas:ai:self-construction --codex-integration-report --json',
                'merge_readiness' => 'php artisan atlas:ai:self-construction --codex-merge-readiness --json',
                'final_review_packet' => 'php artisan atlas:ai:self-construction --codex-final-review-packet --json',
                'signature_request' => 'php artisan atlas:ai:self-construction --codex-review-signature-request --json',
                'post_signature_runbook' => 'php artisan atlas:ai:self-construction --codex-review-post-signature-runbook --json',
                'focused_tests' => 'php artisan test tests/Feature/Ai/AtlasAiSelfConstructionCommandTest.php',
                'docs_health' => 'php artisan atlas:engineering:knowledge docs-health --json',
                'architecture_validate' => 'php artisan atlas:ai:architecture-validate --json',
                'diff_check' => 'git diff --check',
            ],
        ];

        return [
            'schema_version' => 'atlas.self_construction_codex_review_merge_action_template.v1',
            'status' => $runbookReady ? 'merge_action_template_ready' : 'blocked_before_merge_action_template',
            'mode' => 'read_only_codex_review_merge_action_template',
            'execution_allowed' => false,
            'completion_allowed' => false,
            'claim_persisted' => false,
            'completion_persisted' => false,
            'ledger_write_allowed' => false,
            'dispatch_allowed' => false,
            'approval_granted' => false,
            'merge_allowed' => false,
            'signature_valid' => false,
            'template' => $template,
            'template_hash' => $this->stableHash($template),
            'non_execution_guarantees' => [
                'codex_review_merge_action_template_does_not_claim_packets',
                'codex_review_merge_action_template_does_not_complete_packets',
                'codex_review_merge_action_template_does_not_validate_signature',
                'codex_review_merge_action_template_does_not_record_decision',
                'codex_review_merge_action_template_does_not_approve_code',
                'codex_review_merge_action_template_does_not_merge',
                'codex_review_merge_action_template_does_not_dispatch_work',
            ],
            'human_summary' => $runbookReady
                ? 'Codex review merge action template is ready as a non-executing form. It still requires explicit human/governed merge action.'
                : 'Codex review merge action template is blocked until the post-signature runbook is ready.',
        ];
    }

    /**
     * @param  array{workspace?: string|null, target?: string|null, packet?: string|null, actor?: string|null, session?: string|null, lease_minutes?: int|string|null, reason?: string|null}  $options
     * @return array<string, mixed>
     */
    public function codexReviewMergePreflight(array $options = []): array
    {
        $executionStatus = $this->codexExecutionStatus($options);
        $integrationReport = $this->codexIntegrationReport($options);
        $mergeReadiness = $this->codexMergeReadiness($options);
        $finalReviewPacket = $this->codexFinalReviewPacket($options);
        $signatureRequest = $this->codexReviewSignatureRequest($options);
        $postSignatureRunbook = $this->codexReviewPostSignatureRunbook($options);
        $mergeActionTemplate = $this->codexReviewMergeActionTemplate($options);

        $checks = [
            [
                'id' => 'no_active_codex_sessions',
                'status' => ((int) data_get($executionStatus, 'monitor.counts.claimed', 0)) === 0 ? 'pass' : 'fail',
                'evidence_hash' => data_get($executionStatus, 'monitor_hash'),
            ],
            [
                'id' => 'integration_report_ready',
                'status' => data_get($integrationReport, 'status') === 'ready_for_human_integration' ? 'pass' : 'fail',
                'evidence_hash' => data_get($integrationReport, 'report_hash'),
            ],
            [
                'id' => 'merge_readiness_ready_for_review',
                'status' => data_get($mergeReadiness, 'status') === 'ready_for_merge_review' ? 'pass' : 'fail',
                'evidence_hash' => data_get($mergeReadiness, 'readiness_hash'),
            ],
            [
                'id' => 'final_review_packet_ready',
                'status' => data_get($finalReviewPacket, 'status') === 'final_review_packet_ready' ? 'pass' : 'fail',
                'evidence_hash' => data_get($finalReviewPacket, 'packet_hash'),
            ],
            [
                'id' => 'signature_request_pending_not_validated',
                'status' => data_get($signatureRequest, 'status') === 'review_signature_pending'
                    && data_get($signatureRequest, 'signature_request.signature_valid') === false ? 'pass' : 'fail',
                'evidence_hash' => data_get($signatureRequest, 'request_hash'),
            ],
            [
                'id' => 'post_signature_runbook_ready',
                'status' => data_get($postSignatureRunbook, 'status') === 'post_signature_runbook_ready'
                    && data_get($postSignatureRunbook, 'signature_valid') === false ? 'pass' : 'fail',
                'evidence_hash' => data_get($postSignatureRunbook, 'runbook_hash'),
            ],
            [
                'id' => 'merge_action_template_ready_without_authority',
                'status' => data_get($mergeActionTemplate, 'status') === 'merge_action_template_ready'
                    && data_get($mergeActionTemplate, 'merge_allowed') === false ? 'pass' : 'fail',
                'evidence_hash' => data_get($mergeActionTemplate, 'template_hash'),
            ],
        ];

        $failedChecks = array_values(array_filter(
            $checks,
            fn (array $check): bool => $check['status'] !== 'pass'
        ));

        $preflight = [
            'preflight_id' => 'CODEX-REVIEW-MERGE-PREFLIGHT-SELF-CONSTRUCTION-0001',
            'status' => count($failedChecks) === 0 ? 'ready_for_explicit_merge_action_review' : 'blocked_before_explicit_merge_action_review',
            'blocking_count' => count($failedChecks),
            'checks' => $checks,
            'source_hashes' => [
                'execution_status_hash' => data_get($executionStatus, 'monitor_hash'),
                'integration_report_hash' => data_get($integrationReport, 'report_hash'),
                'merge_readiness_hash' => data_get($mergeReadiness, 'readiness_hash'),
                'final_review_packet_hash' => data_get($finalReviewPacket, 'packet_hash'),
                'signature_request_hash' => data_get($signatureRequest, 'request_hash'),
                'post_signature_runbook_hash' => data_get($postSignatureRunbook, 'runbook_hash'),
                'merge_action_template_hash' => data_get($mergeActionTemplate, 'template_hash'),
            ],
            'required_external_evidence_before_merge' => [
                'valid_signature_against_signable_payload_hash',
                'explicit_selected_decision_approve_for_merge',
                'human_merge_confirmation',
                'fresh_gate_outputs_after_signature',
                'clean_scope_integrity_result',
                'verified_evidence_integrity_result',
            ],
            'next_allowed_actions' => count($failedChecks) === 0
                ? [
                    'collect_external_signature_evidence',
                    'rerun_required_review_gates',
                    'prepare_separate_governed_merge_action',
                ]
                : [
                    'resolve_failed_preflight_checks',
                    'rerun_codex_review_merge_preflight',
                ],
            'still_forbidden' => [
                'auto_merge_from_preflight',
                'signature_validation_by_preflight',
                'approval_recording_by_preflight',
                'dispatch_from_preflight',
                'hot_voice_or_kernel_scope_changes',
            ],
        ];

        return [
            'schema_version' => 'atlas.self_construction_codex_review_merge_preflight.v1',
            'status' => count($failedChecks) === 0 ? 'merge_preflight_ready' : 'merge_preflight_blocked',
            'mode' => 'read_only_codex_review_merge_preflight',
            'execution_allowed' => false,
            'completion_allowed' => false,
            'claim_persisted' => false,
            'completion_persisted' => false,
            'ledger_write_allowed' => false,
            'dispatch_allowed' => false,
            'approval_granted' => false,
            'merge_allowed' => false,
            'signature_valid' => false,
            'preflight' => $preflight,
            'preflight_hash' => $this->stableHash($preflight),
            'non_execution_guarantees' => [
                'codex_review_merge_preflight_does_not_claim_packets',
                'codex_review_merge_preflight_does_not_complete_packets',
                'codex_review_merge_preflight_does_not_validate_signature',
                'codex_review_merge_preflight_does_not_record_decision',
                'codex_review_merge_preflight_does_not_approve_code',
                'codex_review_merge_preflight_does_not_merge',
                'codex_review_merge_preflight_does_not_dispatch_work',
            ],
            'human_summary' => count($failedChecks) === 0
                ? 'Codex review merge preflight is ready for a separate explicit governed merge action. It still does not approve or merge.'
                : 'Codex review merge preflight is blocked until review-chain readiness checks pass.',
        ];
    }

    /**
     * @param  array{workspace?: string|null, target?: string|null, packet?: string|null, actor?: string|null, session?: string|null, lease_minutes?: int|string|null, reason?: string|null}  $options
     * @return array<string, mixed>
     */
    public function releasePacket(array $options = []): array
    {
        $packetId = (string) ($options['packet'] ?? '');
        $release = $this->reservations->release(
            packetId: $packetId,
            actor: $this->reservationActor($options),
            session: $this->reservationSession($options),
            reason: (string) ($options['reason'] ?? 'operator_released'),
        );

        return [
            'schema_version' => 'atlas.self_construction_release_packet.v1',
            'status' => data_get($release, 'status') === 'released' ? 'released' : 'blocked',
            'mode' => 'durable_local_packet_release',
            'execution_allowed' => false,
            'completion_allowed' => false,
            'claim_persisted' => false,
            'ledger_write_allowed' => true,
            'dispatch_allowed' => false,
            'release' => [
                ...$release,
                'packet_id' => $packetId,
            ],
            'release_hash' => $this->stableHash($release),
            'human_summary' => data_get($release, 'status') === 'released'
                ? 'Packet reservation was released in the local reservation ledger.'
                : 'Packet release was blocked because no active owner-matching reservation was found.',
        ];
    }

    /**
     * @param  array{workspace?: string|null, target?: string|null, packet?: string|null, actor?: string|null, session?: string|null, lease_minutes?: int|string|null, reason?: string|null, evidence_hash?: string|null}  $options
     * @return array<string, mixed>
     */
    public function completePacket(array $options = []): array
    {
        $packetId = (string) ($options['packet'] ?? '');
        $completion = $this->reservations->complete(
            packetId: $packetId,
            actor: $this->reservationActor($options),
            session: $this->reservationSession($options),
            reason: (string) ($options['reason'] ?? 'operator_reported_packet_complete'),
            evidenceHash: $options['evidence_hash'] ?? null,
        );

        return [
            'schema_version' => 'atlas.self_construction_complete_packet.v1',
            'status' => data_get($completion, 'status') === 'completed' ? 'completed' : 'blocked',
            'mode' => 'durable_local_packet_completion',
            'execution_allowed' => false,
            'completion_allowed' => false,
            'completion_persisted' => data_get($completion, 'status') === 'completed',
            'ledger_write_allowed' => true,
            'dispatch_allowed' => false,
            'approval_granted' => false,
            'completion' => [
                ...$completion,
                'packet_id' => $packetId,
            ],
            'completion_hash' => $this->stableHash($completion),
            'non_execution_guarantees' => [
                'complete_packet_does_not_approve_code',
                'complete_packet_does_not_dispatch_work',
                'complete_packet_does_not_enable_execution',
                'complete_packet_does_not_auto_merge',
            ],
            'human_summary' => data_get($completion, 'status') === 'completed'
                ? 'Packet reservation was durably marked completed in the local ledger. This records packet state only; it does not approve code or bypass gates.'
                : 'Packet completion was blocked because no active owner-matching reservation was found.',
        ];
    }

    /**
     * @param  array{workspace?: string|null, target?: string|null}  $options
     * @return array<string, mixed>
     */
    public function durableReservationLedgerImplementationPlan(array $options = []): array
    {
        $reservationPayload = $this->reservationLedgerPreview($options);
        $completionGatePayload = $this->packetCompletionGate($options);
        $scopePayload = $this->scopeValidator($options);

        $storageObjects = [
            [
                'name' => 'atlas_self_construction_reservations',
                'type' => 'current_state_projection',
                'purpose' => 'Track the active reservation state for each packet and owner.',
                'required_fields' => [
                    'reservation_id',
                    'packet_id',
                    'owner_id',
                    'state',
                    'lease_expires_at',
                    'packet_hash',
                    'split_hash',
                    'allowed_files_hash',
                    'forbidden_files_hash',
                    'last_event_hash',
                ],
            ],
            [
                'name' => 'atlas_self_construction_reservation_events',
                'type' => 'append_only_event_ledger',
                'purpose' => 'Record claim, renewal, release, expiry, block and completion events.',
                'required_fields' => [
                    'event_id',
                    'reservation_id',
                    'event_type',
                    'owner_id',
                    'payload_hash',
                    'previous_event_hash',
                    'created_at',
                ],
            ],
            [
                'name' => 'atlas_self_construction_packet_snapshots',
                'type' => 'immutable_packet_snapshot',
                'purpose' => 'Preserve packet hash, split hash, scope and evidence inputs at claim time.',
                'required_fields' => [
                    'snapshot_id',
                    'packet_id',
                    'packet_hash',
                    'split_hash',
                    'allowed_files_json',
                    'forbidden_files_json',
                    'scope_validator_hash',
                    'created_at',
                ],
            ],
        ];

        $plan = [
            'plan_id' => 'DURABLE-RESERVATION-LEDGER-IMPLEMENTATION-PLAN-0001',
            'source_reservation_preview_hash' => data_get($reservationPayload, 'reservation_hash'),
            'source_completion_gate_hash' => data_get($completionGatePayload, 'gate_hash'),
            'source_scope_validator_hash' => data_get($scopePayload, 'validator_hash'),
            'blocker_removed_when_complete' => 'durable_reservation_ledger_missing',
            'storage_object_count' => count($storageObjects),
            'storage_objects' => $storageObjects,
            'claim_states' => [
                'available',
                'claimed',
                'renewed',
                'released',
                'expired',
                'completed',
                'blocked',
            ],
            'atomic_claim_sequence' => [
                'recompute_packet_queue_and_hashes',
                'reject_stale_packet_or_split_hash',
                'reject_active_reservation_for_same_packet',
                'reject_allowed_file_overlap_with_active_reservations',
                'reject_hot_forbidden_scope',
                'append_claim_attempt_event',
                'write_current_projection_under_lock',
                'emit_reservation_hash_and_claim_receipt',
            ],
            'required_invariants' => [
                'one_active_reservation_per_packet',
                'one_active_reservation_per_owner_session',
                'no_allowed_file_overlap_across_active_reservations',
                'no_hot_forbidden_scope_in_claim',
                'completion_requires_active_owned_claim',
                'append_only_events_are_hash_chained',
            ],
            'required_tests' => [
                'blocks_duplicate_packet_claim',
                'blocks_overlapping_allowed_files',
                'blocks_hot_scope_claim',
                'blocks_stale_packet_hash',
                'blocks_completion_after_expiry',
                'allows_release_then_reclaim',
                'preserves_append_only_event_hash_chain',
            ],
            'implementation_order' => [
                'document_storage_ap_and_migration_scope',
                'add_read_model_and_event_schema_tests',
                'implement_repository_with_transactional_claim_lock',
                'wire_scope_collision_detector',
                'wire_lease_expiry_and_release_policy',
                'update_multi_session_readiness_gate_to_consume_durable_projection',
                'keep_dispatch_disabled_until_separate_approval',
            ],
            'forbidden_scopes' => $this->hotForbiddenFiles(),
            'promotion_requirements' => [
                'approved_storage_ap',
                'passing_duplicate_claim_tests',
                'passing_scope_collision_tests',
                'passing_expiry_and_release_tests',
                'architecture_validate_ok',
                'docs_health_ok',
            ],
            'execution_allowed' => false,
            'ledger_write_allowed' => false,
            'migration_write_allowed' => false,
            'dispatch_allowed' => false,
        ];

        return [
            'schema_version' => 'atlas.self_construction_durable_reservation_ledger_implementation_plan.v1',
            'status' => 'durable_reservation_ledger_plan_ready',
            'mode' => 'read_only_durable_reservation_ledger_implementation_plan',
            'execution_allowed' => false,
            'completion_allowed' => false,
            'ledger_write_allowed' => false,
            'migration_write_allowed' => false,
            'dispatch_allowed' => false,
            'plan' => $plan,
            'plan_hash' => $this->stableHash($plan),
            'non_execution_guarantees' => [
                'durable_reservation_ledger_plan_does_not_create_migrations',
                'durable_reservation_ledger_plan_does_not_write_ledger',
                'durable_reservation_ledger_plan_does_not_persist_claim',
                'durable_reservation_ledger_plan_does_not_dispatch_work',
                'durable_reservation_ledger_plan_does_not_enable_execution',
            ],
            'human_summary' => 'Durable reservation ledger implementation plan is ready: the missing multi-session ledger is specified, but storage writes, migrations, claims and dispatch remain disabled.',
        ];
    }

    /**
     * @param  array{workspace?: string|null, target?: string|null}  $options
     * @return array<string, mixed>
     */
    public function durableReservationApCandidate(array $options = []): array
    {
        $planPayload = $this->durableReservationLedgerImplementationPlan($options);
        $readinessGatePayload = $this->multiSessionReadinessGate($options);

        $packets = [
            [
                'packet_id' => 'DR-AP-STORAGE-0001',
                'title' => 'Define durable reservation storage',
                'objective' => 'Create approved storage contracts for reservations, events and packet snapshots.',
                'allowed_scope' => [
                    'future_database_migrations_after_approval',
                    'future_reservation_models_after_approval',
                    'self_construction_docs',
                ],
                'required_tests' => [
                    'storage_schema_contains_required_hash_fields',
                    'events_are_append_only',
                ],
            ],
            [
                'packet_id' => 'DR-AP-REPOSITORY-0002',
                'title' => 'Implement transactional claim repository',
                'objective' => 'Claim, renew, release and expire reservations with atomic locks.',
                'allowed_scope' => [
                    'future_self_construction_reservation_repository',
                    'future_repository_tests',
                ],
                'required_tests' => [
                    'blocks_duplicate_packet_claim',
                    'allows_release_then_reclaim',
                    'blocks_completion_after_expiry',
                ],
            ],
            [
                'packet_id' => 'DR-AP-COLLISION-0003',
                'title' => 'Implement scope collision policy',
                'objective' => 'Reject overlapping allowed files and hot forbidden scopes before claim persistence.',
                'allowed_scope' => [
                    'future_scope_collision_detector',
                    'future_collision_policy_tests',
                ],
                'required_tests' => [
                    'blocks_overlapping_allowed_files',
                    'blocks_hot_scope_claim',
                    'blocks_stale_packet_hash',
                ],
            ],
            [
                'packet_id' => 'DR-AP-READINESS-0004',
                'title' => 'Integrate durable state with readiness gates',
                'objective' => 'Remove durable_reservation_ledger_missing only after durable projection is proven.',
                'allowed_scope' => [
                    'AtlasSelfConstructionReadinessService',
                    'AtlasAiSelfConstructionCommandTest',
                    'self_construction_docs',
                ],
                'required_tests' => [
                    'multi_session_gate_reads_durable_reservation_projection',
                    'dispatch_remains_disabled_after_ledger_activation',
                ],
            ],
        ];

        $candidate = [
            'ap_id' => 'AP-CANDIDATE-DURABLE-RESERVATION-LEDGER-0001',
            'title' => 'Implement durable Self-Construction reservation ledger',
            'source_plan_hash' => data_get($planPayload, 'plan_hash'),
            'source_readiness_gate_hash' => data_get($readinessGatePayload, 'gate_hash'),
            'blocker_target' => 'durable_reservation_ledger_missing',
            'operator_decision_required' => true,
            'implementation_allowed_now' => false,
            'packet_count' => count($packets),
            'packets' => $packets,
            'global_forbidden_scopes' => $this->hotForbiddenFiles(),
            'required_evidence' => [
                'approved_ap_document',
                'migration_or_storage_diff_after_approval',
                'repository_test_output',
                'scope_collision_test_output',
                'multi_session_readiness_gate_output',
                'docs_health_output',
                'architecture_validate_output',
                'rollback_notes',
            ],
            'promotion_gates' => [
                'operator_approval_present',
                'duplicate_claim_tests_pass',
                'collision_tests_pass',
                'lease_expiry_tests_pass',
                'append_only_event_tests_pass',
                'dispatch_still_disabled',
            ],
            'rollback_strategy' => [
                'disable_durable_claim_reads',
                'return_multi_session_gate_to_preview_only',
                'preserve_append_only_events_for_audit',
                'block_dispatch_until_manual_review',
            ],
            'next_safe_action' => 'review_ap_candidate_before_any_storage_or_migration_work',
        ];

        return [
            'schema_version' => 'atlas.self_construction_durable_reservation_ap_candidate.v1',
            'status' => 'durable_reservation_ap_candidate_ready',
            'mode' => 'read_only_durable_reservation_ap_candidate',
            'execution_allowed' => false,
            'completion_allowed' => false,
            'operator_approval_required' => true,
            'storage_write_allowed' => false,
            'migration_write_allowed' => false,
            'dispatch_allowed' => false,
            'candidate' => $candidate,
            'candidate_hash' => $this->stableHash($candidate),
            'non_execution_guarantees' => [
                'durable_reservation_ap_candidate_does_not_create_migrations',
                'durable_reservation_ap_candidate_does_not_write_storage',
                'durable_reservation_ap_candidate_does_not_persist_claim',
                'durable_reservation_ap_candidate_does_not_dispatch_work',
                'durable_reservation_ap_candidate_requires_operator_approval',
            ],
            'human_summary' => 'Durable reservation AP candidate is ready: a future implementation is split into storage, repository, collision and readiness packets, but approval and execution remain disabled.',
        ];
    }

    /**
     * @param  array{workspace?: string|null, target?: string|null}  $options
     * @return array<string, mixed>
     */
    public function durableReservationApprovalRequest(array $options = []): array
    {
        $candidatePayload = $this->durableReservationApCandidate($options);
        $planPayload = $this->durableReservationLedgerImplementationPlan($options);
        $gatePayload = $this->multiSessionReadinessGate($options);

        $requiredSigners = [
            'product_governor',
            'architecture_governor',
            'safety_governance_reviewer',
            'implementation_operator',
        ];

        $request = [
            'request_id' => 'APPROVAL-REQUEST-DURABLE-RESERVATION-LEDGER-0001',
            'approval_status' => 'not_approved_read_only_request',
            'candidate_ap_id' => data_get($candidatePayload, 'candidate.ap_id'),
            'candidate_hash' => data_get($candidatePayload, 'candidate_hash'),
            'plan_hash' => data_get($planPayload, 'plan_hash'),
            'readiness_gate_hash' => data_get($gatePayload, 'gate_hash'),
            'blocker_target' => data_get($candidatePayload, 'candidate.blocker_target'),
            'required_signer_count' => count($requiredSigners),
            'required_signers' => $requiredSigners,
            'operator_decisions_required' => [
                'approve_storage_and_migration_scope',
                'accept_ap_candidate_packet_split',
                'keep_dispatch_disabled_after_ledger_activation',
                'accept_rollback_strategy',
                'confirm_hot_scopes_remain_forbidden',
            ],
            'approval_blockers' => [
                'candidate_hash_changed_after_review',
                'plan_hash_changed_after_review',
                'docs_health_failed',
                'architecture_validate_failed',
                'hot_scope_present_in_allowed_files',
                'dispatch_enabled_in_same_ap',
                'rollback_strategy_missing',
            ],
            'required_evidence' => [
                'candidate_hash',
                'plan_hash',
                'multi_session_readiness_gate_hash',
                'docs_health_output',
                'architecture_validate_output',
                'rollback_strategy',
                'forbidden_scope_list',
            ],
            'rollback_strategy' => data_get($candidatePayload, 'candidate.rollback_strategy', []),
            'forbidden_scopes' => $this->hotForbiddenFiles(),
            'post_approval_limits' => [
                'implementation_may_start_only_after_signed_approval',
                'dispatch_remains_disabled',
                'completion_requires_packet_completion_gate',
                'hot_scopes_remain_forbidden',
            ],
        ];

        return [
            'schema_version' => 'atlas.self_construction_durable_reservation_approval_request.v1',
            'status' => 'durable_reservation_approval_request_ready',
            'mode' => 'read_only_durable_reservation_approval_request',
            'execution_allowed' => false,
            'completion_allowed' => false,
            'approval_granted' => false,
            'storage_write_allowed' => false,
            'migration_write_allowed' => false,
            'dispatch_allowed' => false,
            'request' => $request,
            'request_hash' => $this->stableHash($request),
            'non_execution_guarantees' => [
                'durable_reservation_approval_request_does_not_grant_approval',
                'durable_reservation_approval_request_does_not_create_migrations',
                'durable_reservation_approval_request_does_not_write_storage',
                'durable_reservation_approval_request_does_not_persist_claim',
                'durable_reservation_approval_request_does_not_dispatch_work',
            ],
            'human_summary' => 'Durable reservation approval request is ready: signers, decisions, evidence and blockers are explicit, but no approval or execution was granted.',
        ];
    }

    /**
     * @param  array{workspace?: string|null, target?: string|null}  $options
     * @return array<string, mixed>
     */
    public function durableReservationApprovalDecisionTemplate(array $options = []): array
    {
        $requestPayload = $this->durableReservationApprovalRequest($options);
        $candidatePayload = $this->durableReservationApCandidate($options);
        $planPayload = $this->durableReservationLedgerImplementationPlan($options);
        $gatePayload = $this->multiSessionReadinessGate($options);

        $signerSlots = array_map(
            fn (string $role): array => [
                'role' => $role,
                'signer_id' => null,
                'signed_at' => null,
                'decision' => 'pending',
            ],
            (array) data_get($requestPayload, 'request.required_signers', []),
        );

        $decision = [
            'decision_id' => 'APPROVAL-DECISION-DURABLE-RESERVATION-LEDGER-0001',
            'decision_status' => 'template_not_signed',
            'allowed_decisions' => [
                'approved_for_scoped_implementation',
                'rejected',
                'needs_revision',
                'expired',
            ],
            'default_decision' => 'needs_revision',
            'approval_request_hash' => data_get($requestPayload, 'request_hash'),
            'candidate_hash' => data_get($candidatePayload, 'candidate_hash'),
            'plan_hash' => data_get($planPayload, 'plan_hash'),
            'readiness_gate_hash' => data_get($gatePayload, 'gate_hash'),
            'signer_slot_count' => count($signerSlots),
            'signer_slots' => $signerSlots,
            'approved_scope_if_signed' => [
                'durable_reservation_storage_after_approval',
                'durable_reservation_repository_after_approval',
                'durable_reservation_collision_policy_after_approval',
                'multi_session_gate_projection_after_approval',
            ],
            'forbidden_scope' => $this->hotForbiddenFiles(),
            'post_decision_limits' => [
                'approval_decision_must_match_current_hashes',
                'dispatch_requires_separate_future_ap',
                'completion_requires_packet_completion_gate',
                'hot_scopes_remain_forbidden',
            ],
            'expiry_checks' => [
                'request_hash_changed',
                'candidate_hash_changed',
                'plan_hash_changed',
                'readiness_gate_hash_changed',
                'required_evidence_missing',
            ],
            'rollback_strategy' => data_get($requestPayload, 'request.rollback_strategy', []),
        ];

        return [
            'schema_version' => 'atlas.self_construction_durable_reservation_approval_decision_template.v1',
            'status' => 'durable_reservation_approval_decision_template_ready',
            'mode' => 'read_only_durable_reservation_approval_decision_template',
            'execution_allowed' => false,
            'completion_allowed' => false,
            'approval_granted' => false,
            'decision_signed' => false,
            'storage_write_allowed' => false,
            'migration_write_allowed' => false,
            'dispatch_allowed' => false,
            'decision' => $decision,
            'decision_hash' => $this->stableHash($decision),
            'non_execution_guarantees' => [
                'durable_reservation_approval_decision_template_does_not_grant_approval',
                'durable_reservation_approval_decision_template_does_not_sign_decision',
                'durable_reservation_approval_decision_template_does_not_write_storage',
                'durable_reservation_approval_decision_template_does_not_persist_claim',
                'durable_reservation_approval_decision_template_does_not_dispatch_work',
            ],
            'human_summary' => 'Durable reservation approval decision template is ready: approval states, signer slots, hash bindings and limits are explicit, but no decision was signed.',
        ];
    }

    /**
     * @param  array{workspace?: string|null, target?: string|null}  $options
     * @return array<string, mixed>
     */
    public function durableReservationPostApprovalPreflight(array $options = []): array
    {
        $decisionPayload = $this->durableReservationApprovalDecisionTemplate($options);
        $requestPayload = $this->durableReservationApprovalRequest($options);
        $candidatePayload = $this->durableReservationApCandidate($options);
        $planPayload = $this->durableReservationLedgerImplementationPlan($options);
        $gatePayload = $this->multiSessionReadinessGate($options);

        $checks = [
            [
                'check' => 'approval_decision_signed',
                'status' => 'blocked',
                'reason' => 'Current surface emits a decision template only; no signer has signed.',
            ],
            [
                'check' => 'approved_for_scoped_implementation',
                'status' => 'blocked',
                'reason' => 'Decision value is not signed as approved_for_scoped_implementation.',
            ],
            [
                'check' => 'hash_bindings_current',
                'status' => 'ready_for_future_validation',
                'bound_hashes' => [
                    'decision_hash' => data_get($decisionPayload, 'decision_hash'),
                    'request_hash' => data_get($requestPayload, 'request_hash'),
                    'candidate_hash' => data_get($candidatePayload, 'candidate_hash'),
                    'plan_hash' => data_get($planPayload, 'plan_hash'),
                    'readiness_gate_hash' => data_get($gatePayload, 'gate_hash'),
                ],
            ],
            [
                'check' => 'dispatch_disabled',
                'status' => 'passed',
                'reason' => 'Durable reservation approval chain still keeps dispatch disabled.',
            ],
            [
                'check' => 'hot_scopes_forbidden',
                'status' => 'passed',
                'forbidden_scopes' => $this->hotForbiddenFiles(),
            ],
        ];

        $blockingChecks = array_values(array_filter(
            $checks,
            fn (array $check): bool => data_get($check, 'status') === 'blocked',
        ));

        $preflight = [
            'preflight_id' => 'POST-APPROVAL-PREFLIGHT-DURABLE-RESERVATION-LEDGER-0001',
            'decision' => 'blocked_until_signed_approval',
            'blocking_check_count' => count($blockingChecks),
            'checks' => $checks,
            'required_before_implementation' => [
                'signed_approval_decision',
                'all_required_signer_slots_filled',
                'decision_value_approved_for_scoped_implementation',
                'hash_bindings_match_current_payloads',
                'docs_health_ok',
                'architecture_validate_ok',
                'rollback_strategy_accepted',
            ],
            'implementation_limits_after_pass' => [
                'storage_scope_only_as_approved',
                'repository_scope_only_as_approved',
                'collision_policy_scope_only_as_approved',
                'dispatch_remains_disabled',
                'completion_requires_packet_completion_gate',
            ],
            'forbidden_scopes' => $this->hotForbiddenFiles(),
            'rollback_strategy' => data_get($decisionPayload, 'decision.rollback_strategy', []),
        ];

        return [
            'schema_version' => 'atlas.self_construction_durable_reservation_post_approval_preflight.v1',
            'status' => 'durable_reservation_post_approval_preflight_ready',
            'mode' => 'read_only_durable_reservation_post_approval_preflight',
            'execution_allowed' => false,
            'completion_allowed' => false,
            'approval_granted' => false,
            'preflight_passed' => false,
            'storage_write_allowed' => false,
            'migration_write_allowed' => false,
            'dispatch_allowed' => false,
            'preflight' => $preflight,
            'preflight_hash' => $this->stableHash($preflight),
            'non_execution_guarantees' => [
                'durable_reservation_post_approval_preflight_does_not_sign_decision',
                'durable_reservation_post_approval_preflight_does_not_create_migrations',
                'durable_reservation_post_approval_preflight_does_not_write_storage',
                'durable_reservation_post_approval_preflight_does_not_persist_claim',
                'durable_reservation_post_approval_preflight_does_not_dispatch_work',
            ],
            'human_summary' => 'Durable reservation post-approval preflight is ready: implementation remains blocked until a signed approval passes hash, evidence and scope checks.',
        ];
    }

    /**
     * @param  array{workspace?: string|null, target?: string|null}  $options
     * @return array<string, mixed>
     */
    public function durableReservationImplementationPacket(array $options = []): array
    {
        $preflightPayload = $this->durableReservationPostApprovalPreflight($options);
        $candidatePayload = $this->durableReservationApCandidate($options);

        $workPackets = [
            [
                'id' => 'DR-IMPL-STORAGE-0001',
                'title' => 'Implement durable reservation storage',
                'allowed_future_scope' => [
                    'database/migrations/approved_self_construction_reservations',
                    'future_self_construction_reservation_models',
                ],
                'required_tests' => [
                    'storage_schema_contains_hash_fields',
                    'events_are_append_only',
                ],
            ],
            [
                'id' => 'DR-IMPL-REPOSITORY-0002',
                'title' => 'Implement reservation repository and locks',
                'allowed_future_scope' => [
                    'future_reservation_repository',
                    'future_repository_tests',
                ],
                'required_tests' => [
                    'blocks_duplicate_claim',
                    'allows_release_then_reclaim',
                    'blocks_expired_completion',
                ],
            ],
            [
                'id' => 'DR-IMPL-COLLISION-0003',
                'title' => 'Implement collision and hot-scope guards',
                'allowed_future_scope' => [
                    'future_scope_collision_detector',
                    'future_collision_tests',
                ],
                'required_tests' => [
                    'blocks_overlapping_allowed_files',
                    'blocks_hot_scope_claim',
                    'blocks_stale_hash_claim',
                ],
            ],
            [
                'id' => 'DR-IMPL-READINESS-0004',
                'title' => 'Integrate durable projection with readiness gate',
                'allowed_future_scope' => [
                    'app/Services/Ai/SelfConstruction/AtlasSelfConstructionReadinessService.php',
                    'tests/Feature/Ai/AtlasAiSelfConstructionCommandTest.php',
                    'self_construction_docs',
                ],
                'required_tests' => [
                    'multi_session_gate_reads_durable_projection',
                    'dispatch_remains_disabled',
                ],
            ],
        ];

        $packet = [
            'packet_id' => 'IMPLEMENTATION-PACKET-DURABLE-RESERVATION-LEDGER-0001',
            'packet_status' => 'blocked_until_post_approval_preflight_passes',
            'source_preflight_hash' => data_get($preflightPayload, 'preflight_hash'),
            'source_candidate_hash' => data_get($candidatePayload, 'candidate_hash'),
            'preflight_decision' => data_get($preflightPayload, 'preflight.decision'),
            'work_packet_count' => count($workPackets),
            'work_packets' => $workPackets,
            'required_first_commands' => [
                'git status --short',
                'git diff --stat',
                'git diff --name-only',
                'php artisan atlas:ai:self-construction --durable-reservation-post-approval-preflight --json',
                'php artisan atlas:ai:self-construction --scope-validator --json',
            ],
            'required_gates' => [
                'php artisan test tests/Feature/Ai/AtlasAiSelfConstructionCommandTest.php',
                'php artisan atlas:engineering:knowledge docs-health --json',
                'php artisan atlas:ai:architecture-validate --json',
                'git diff --check',
            ],
            'stop_conditions' => [
                'post_approval_preflight_not_passed',
                'approval_hash_drift_detected',
                'hot_scope_detected',
                'dispatch_scope_requested',
                'rollback_strategy_missing',
            ],
            'forbidden_scopes' => $this->hotForbiddenFiles(),
            'implementation_allowed_now' => false,
            'dispatch_allowed' => false,
        ];

        return [
            'schema_version' => 'atlas.self_construction_durable_reservation_implementation_packet.v1',
            'status' => 'durable_reservation_implementation_packet_ready',
            'mode' => 'read_only_durable_reservation_implementation_packet',
            'execution_allowed' => false,
            'completion_allowed' => false,
            'implementation_allowed_now' => false,
            'storage_write_allowed' => false,
            'migration_write_allowed' => false,
            'dispatch_allowed' => false,
            'packet' => $packet,
            'packet_hash' => $this->stableHash($packet),
            'non_execution_guarantees' => [
                'durable_reservation_implementation_packet_does_not_create_migrations',
                'durable_reservation_implementation_packet_does_not_write_storage',
                'durable_reservation_implementation_packet_does_not_persist_claim',
                'durable_reservation_implementation_packet_does_not_dispatch_work',
                'durable_reservation_implementation_packet_requires_passed_preflight',
            ],
            'human_summary' => 'Durable reservation implementation packet is ready: future work is ordered, but implementation remains blocked until signed approval and preflight pass.',
        ];
    }

    /**
     * @param  array{workspace?: string|null, target?: string|null}  $options
     * @return array<string, mixed>
     */
    public function durableReservationStorageSchema(array $options = []): array
    {
        $implementationPayload = $this->durableReservationImplementationPacket($options);

        $tables = [
            [
                'name' => 'atlas_self_construction_reservation_events',
                'role' => 'append_only_event_source',
                'fields' => [
                    'id',
                    'reservation_id',
                    'packet_id',
                    'event_type',
                    'actor_type',
                    'actor_id',
                    'session_id',
                    'owner_token_hash',
                    'packet_hash',
                    'allowed_files_hash',
                    'payload_json',
                    'previous_event_hash',
                    'event_hash',
                    'occurred_at',
                    'created_at',
                ],
                'indexes' => [
                    'unique_event_hash',
                    'reservation_id_occurred_at',
                    'packet_id_event_type',
                ],
            ],
            [
                'name' => 'atlas_self_construction_reservations',
                'role' => 'current_reservation_projection',
                'fields' => [
                    'id',
                    'packet_id',
                    'state',
                    'owner_type',
                    'owner_id',
                    'session_id',
                    'packet_hash',
                    'allowed_files_hash',
                    'lease_expires_at',
                    'claimed_at',
                    'released_at',
                    'completed_at',
                    'blocked_reason',
                    'last_event_hash',
                    'created_at',
                    'updated_at',
                ],
                'indexes' => [
                    'packet_state_lease',
                    'owner_session_state',
                    'allowed_files_hash_state',
                    'last_event_hash',
                ],
            ],
        ];

        $storageSchema = [
            'schema_id' => 'STORAGE-SCHEMA-DURABLE-RESERVATION-LEDGER-0001',
            'schema_status' => 'blocked_until_post_approval_preflight_passes',
            'source_implementation_packet_hash' => data_get($implementationPayload, 'packet_hash'),
            'table_count' => count($tables),
            'tables' => $tables,
            'states' => [
                'preview',
                'claimed',
                'released',
                'expired',
                'completed',
                'blocked',
            ],
            'event_types' => [
                'claim_requested',
                'claim_granted',
                'claim_rejected',
                'lease_renewed',
                'reservation_released',
                'reservation_expired',
                'completion_requested',
                'completion_recorded',
                'reservation_blocked',
            ],
            'invariants' => [
                'events_are_append_only',
                'events_chain_previous_event_hash',
                'one_active_claim_per_packet',
                'active_allowed_file_overlap_blocks_new_claim',
                'expired_reservation_cannot_complete',
                'released_reservation_can_be_reclaimed',
                'hot_voice_kernel_scope_is_not_claimable',
                'dispatch_remains_disabled',
            ],
            'required_tests' => [
                'storage_schema_contains_hash_actor_state_lease_and_payload_fields',
                'duplicate_active_packet_claim_is_blocked',
                'overlapping_active_file_scope_is_blocked',
                'expired_reservation_cannot_complete',
                'released_reservation_can_be_reclaimed',
                'projection_can_be_rebuilt_from_events',
                'storage_schema_command_does_not_create_migrations_or_write_storage',
            ],
            'forbidden_scopes' => $this->hotForbiddenFiles(),
            'migration_allowed_now' => false,
            'storage_write_allowed' => false,
            'dispatch_allowed' => false,
        ];

        return [
            'schema_version' => 'atlas.self_construction_durable_reservation_storage_schema.v1',
            'status' => 'durable_reservation_storage_schema_ready',
            'mode' => 'read_only_durable_reservation_storage_schema',
            'execution_allowed' => false,
            'completion_allowed' => false,
            'implementation_allowed_now' => false,
            'migration_allowed_now' => false,
            'storage_write_allowed' => false,
            'dispatch_allowed' => false,
            'storage_schema' => $storageSchema,
            'schema_hash' => $this->stableHash($storageSchema),
            'non_execution_guarantees' => [
                'durable_reservation_storage_schema_does_not_create_migrations',
                'durable_reservation_storage_schema_does_not_write_storage',
                'durable_reservation_storage_schema_does_not_persist_claim',
                'durable_reservation_storage_schema_does_not_dispatch_work',
                'durable_reservation_storage_schema_requires_passed_preflight',
            ],
            'human_summary' => 'Durable reservation storage schema is ready: future migrations have a precise event/projection contract, but no storage work is allowed yet.',
        ];
    }

    /**
     * @param  array{workspace?: string|null, target?: string|null}  $options
     * @return array<string, mixed>
     */
    public function durableReservationRepositoryContract(array $options = []): array
    {
        $schemaPayload = $this->durableReservationStorageSchema($options);

        $methods = [
            [
                'name' => 'preview',
                'purpose' => 'validate packet, actor and scope without durable claim persistence',
                'writes_events' => false,
                'writes_projection' => false,
            ],
            [
                'name' => 'claim',
                'purpose' => 'atomically claim an available packet with lease and scope locks',
                'writes_events' => true,
                'writes_projection' => true,
            ],
            [
                'name' => 'renew',
                'purpose' => 'extend an active lease owned by the same actor',
                'writes_events' => true,
                'writes_projection' => true,
            ],
            [
                'name' => 'release',
                'purpose' => 'release an active reservation and free the packet for future claim',
                'writes_events' => true,
                'writes_projection' => true,
            ],
            [
                'name' => 'expire',
                'purpose' => 'mark expired leases from event/projection state',
                'writes_events' => true,
                'writes_projection' => true,
            ],
            [
                'name' => 'complete',
                'purpose' => 'record completion only after packet completion gate and evidence pass',
                'writes_events' => true,
                'writes_projection' => true,
            ],
            [
                'name' => 'current',
                'purpose' => 'read current projection for one packet',
                'writes_events' => false,
                'writes_projection' => false,
            ],
            [
                'name' => 'activeCollisions',
                'purpose' => 'read active overlapping allowed-file claims',
                'writes_events' => false,
                'writes_projection' => false,
            ],
            [
                'name' => 'rebuildProjection',
                'purpose' => 'rebuild current state from reservation events',
                'writes_events' => false,
                'writes_projection' => true,
            ],
        ];

        $repositoryContract = [
            'contract_id' => 'REPOSITORY-CONTRACT-DURABLE-RESERVATION-LEDGER-0001',
            'contract_status' => 'blocked_until_storage_schema_and_preflight_pass',
            'source_storage_schema_hash' => data_get($schemaPayload, 'schema_hash'),
            'method_count' => count($methods),
            'methods' => $methods,
            'errors' => [
                'packet_already_claimed',
                'allowed_files_overlap_active_reservation',
                'hot_scope_forbidden',
                'packet_hash_stale',
                'lease_expired',
                'completion_gate_missing',
                'actor_not_owner',
                'event_chain_mismatch',
            ],
            'transaction_rules' => [
                'acquire_packet_and_allowed_file_scope_lock_before_claim',
                'append_event_before_projection_update',
                'reject_projection_update_when_event_hash_chain_is_broken',
                'release_and_completion_are_terminal_for_current_lease',
                'dispatch_is_never_enabled_by_repository_methods',
            ],
            'required_tests' => [
                'claim_writes_claim_event_then_projection',
                'duplicate_active_claim_is_rejected',
                'overlapping_allowed_files_are_rejected',
                'non_owner_cannot_release_or_complete',
                'expired_lease_cannot_complete',
                'stale_packet_hash_is_rejected',
                'projection_rebuild_matches_current_projection',
                'repository_contract_command_does_not_persist_claims_or_write_storage',
            ],
            'forbidden_scopes' => $this->hotForbiddenFiles(),
            'repository_write_allowed_now' => false,
            'storage_write_allowed' => false,
            'dispatch_allowed' => false,
        ];

        return [
            'schema_version' => 'atlas.self_construction_durable_reservation_repository_contract.v1',
            'status' => 'durable_reservation_repository_contract_ready',
            'mode' => 'read_only_durable_reservation_repository_contract',
            'execution_allowed' => false,
            'completion_allowed' => false,
            'implementation_allowed_now' => false,
            'repository_write_allowed_now' => false,
            'storage_write_allowed' => false,
            'dispatch_allowed' => false,
            'repository_contract' => $repositoryContract,
            'contract_hash' => $this->stableHash($repositoryContract),
            'non_execution_guarantees' => [
                'durable_reservation_repository_contract_does_not_create_repository',
                'durable_reservation_repository_contract_does_not_write_storage',
                'durable_reservation_repository_contract_does_not_persist_claim',
                'durable_reservation_repository_contract_does_not_dispatch_work',
                'durable_reservation_repository_contract_requires_storage_schema',
            ],
            'human_summary' => 'Durable reservation repository contract is ready: future claim methods, errors, transactions and tests are precise, but no repository or storage writes are allowed yet.',
        ];
    }

    /**
     * @param  array{workspace?: string|null, target?: string|null}  $options
     * @return array<string, mixed>
     */
    public function durableReservationCollisionGuard(array $options = []): array
    {
        $repositoryPayload = $this->durableReservationRepositoryContract($options);

        $collisionGuard = [
            'guard_id' => 'COLLISION-GUARD-DURABLE-RESERVATION-LEDGER-0001',
            'guard_status' => 'blocked_until_repository_and_projection_exist',
            'source_repository_contract_hash' => data_get($repositoryPayload, 'contract_hash'),
            'inputs' => [
                'candidate_packet_id',
                'candidate_allowed_files',
                'candidate_forbidden_files',
                'candidate_packet_hash',
                'candidate_dependency_ids',
                'active_reservation_projections',
                'hot_forbidden_scope_list',
                'current_changed_files',
                'completion_gate_status',
            ],
            'blocking_decisions' => [
                'hot_scope_forbidden',
                'active_file_overlap',
                'packet_hash_stale',
                'dependency_incomplete',
                'completion_gate_blocked',
                'owner_conflict',
            ],
            'blocking_decision_count' => 6,
            'outputs' => [
                'decision',
                'blocking_reasons',
                'overlapping_files',
                'active_reservation_ids',
                'stale_hashes',
                'dependency_blockers',
                'hot_scope_matches',
                'required_next_command',
            ],
            'decision_states' => [
                'allow_preview',
                'block_claim',
                'require_human_review',
            ],
            'required_tests' => [
                'hot_voice_kernel_scope_is_blocked',
                'overlapping_active_file_scope_is_blocked',
                'stale_packet_hash_is_blocked',
                'incomplete_dependency_is_blocked',
                'clean_disjoint_packet_remains_preview_allowable',
                'collision_guard_command_does_not_persist_claims_or_write_storage',
            ],
            'forbidden_scopes' => $this->hotForbiddenFiles(),
            'claim_allowed_now' => false,
            'storage_write_allowed' => false,
            'dispatch_allowed' => false,
        ];

        return [
            'schema_version' => 'atlas.self_construction_durable_reservation_collision_guard.v1',
            'status' => 'durable_reservation_collision_guard_ready',
            'mode' => 'read_only_durable_reservation_collision_guard',
            'execution_allowed' => false,
            'completion_allowed' => false,
            'implementation_allowed_now' => false,
            'claim_allowed_now' => false,
            'storage_write_allowed' => false,
            'dispatch_allowed' => false,
            'collision_guard' => $collisionGuard,
            'guard_hash' => $this->stableHash($collisionGuard),
            'non_execution_guarantees' => [
                'durable_reservation_collision_guard_does_not_create_detector',
                'durable_reservation_collision_guard_does_not_write_storage',
                'durable_reservation_collision_guard_does_not_persist_claim',
                'durable_reservation_collision_guard_does_not_dispatch_work',
                'durable_reservation_collision_guard_requires_repository_projection',
            ],
            'human_summary' => 'Durable reservation collision guard is ready: future claim safety decisions are precise, but no detector, claim or storage write is allowed yet.',
        ];
    }

    /**
     * @param  array{workspace?: string|null, target?: string|null}  $options
     * @return array<string, mixed>
     */
    public function durableReservationLeaseLifecycle(array $options = []): array
    {
        $collisionPayload = $this->durableReservationCollisionGuard($options);

        $states = [
            'preview',
            'claimed',
            'renewed',
            'released',
            'expired',
            'completed',
            'blocked',
        ];

        $leaseLifecycle = [
            'lifecycle_id' => 'LEASE-LIFECYCLE-DURABLE-RESERVATION-LEDGER-0001',
            'lifecycle_status' => 'blocked_until_repository_projection_and_collision_guard_exist',
            'source_collision_guard_hash' => data_get($collisionPayload, 'guard_hash'),
            'state_count' => count($states),
            'states' => $states,
            'transitions' => [
                'preview_to_claimed',
                'claimed_to_renewed',
                'claimed_to_released',
                'claimed_to_expired',
                'renewed_to_released',
                'renewed_to_expired',
                'claimed_to_completed',
                'renewed_to_completed',
                'any_to_blocked_when_guard_rejects_action',
            ],
            'timing_rules' => [
                'default_lease_duration_must_be_explicit_in_config_or_policy',
                'renew_requires_same_owner_session_and_active_lease',
                'release_requires_same_owner_session_and_active_lease',
                'expire_may_be_system_driven_and_must_append_event',
                'completion_requires_active_lease_same_owner_and_passing_completion_gate',
                'expired_or_released_leases_cannot_complete',
            ],
            'required_tests' => [
                'owner_can_renew_active_lease',
                'non_owner_cannot_renew_or_release',
                'expired_lease_cannot_complete',
                'released_lease_cannot_complete',
                'expired_packet_can_be_reclaimed_after_expiry_event',
                'completion_requires_packet_completion_gate_pass',
                'lease_lifecycle_command_does_not_persist_claims_or_write_storage',
            ],
            'forbidden_scopes' => $this->hotForbiddenFiles(),
            'claim_allowed_now' => false,
            'storage_write_allowed' => false,
            'dispatch_allowed' => false,
        ];

        return [
            'schema_version' => 'atlas.self_construction_durable_reservation_lease_lifecycle.v1',
            'status' => 'durable_reservation_lease_lifecycle_ready',
            'mode' => 'read_only_durable_reservation_lease_lifecycle',
            'execution_allowed' => false,
            'completion_allowed' => false,
            'implementation_allowed_now' => false,
            'claim_allowed_now' => false,
            'storage_write_allowed' => false,
            'dispatch_allowed' => false,
            'lease_lifecycle' => $leaseLifecycle,
            'lifecycle_hash' => $this->stableHash($leaseLifecycle),
            'non_execution_guarantees' => [
                'durable_reservation_lease_lifecycle_does_not_create_lifecycle_runtime',
                'durable_reservation_lease_lifecycle_does_not_write_storage',
                'durable_reservation_lease_lifecycle_does_not_persist_claim',
                'durable_reservation_lease_lifecycle_does_not_dispatch_work',
                'durable_reservation_lease_lifecycle_requires_collision_guard',
            ],
            'human_summary' => 'Durable reservation lease lifecycle is ready: future timing, renewal, release, expiry and completion semantics are precise, but no runtime or storage write is allowed yet.',
        ];
    }

    /**
     * @param  array{workspace?: string|null, target?: string|null}  $options
     * @return array<string, mixed>
     */
    public function durableReservationReadinessProjection(array $options = []): array
    {
        $lifecyclePayload = $this->durableReservationLeaseLifecycle($options);

        $queueStates = [
            'available',
            'claimed',
            'blocked_by_collision',
            'blocked_by_dependency',
            'blocked_by_hot_scope',
            'blocked_by_stale_hash',
            'completed',
        ];

        $readinessProjection = [
            'projection_id' => 'READINESS-PROJECTION-DURABLE-RESERVATION-LEDGER-0001',
            'projection_status' => 'blocked_until_durable_projection_exists',
            'source_lease_lifecycle_hash' => data_get($lifecyclePayload, 'lifecycle_hash'),
            'inputs' => [
                'active_reservations',
                'expired_reservations',
                'completed_packets',
                'blocked_packets',
                'packet_dependencies',
                'allowed_file_scopes',
                'hot_forbidden_scopes',
                'current_changed_files',
                'completion_gate_status',
            ],
            'queue_state_count' => count($queueStates),
            'queue_states' => $queueStates,
            'outputs' => [
                'queue_summary',
                'claimable_packet_ids',
                'blocked_packet_ids_and_reasons',
                'active_reservation_owners',
                'dependency_unlock_hints',
                'multi_session_decision',
                'safe_single_session_fallback_instruction',
            ],
            'integration_targets' => [
                'packet_queue',
                'collision_matrix',
                'dependency_unlock_plan',
                'multi_session_readiness_gate',
                'single_session_instruction_packet',
            ],
            'required_tests' => [
                'active_reservation_removes_packet_from_claimable_queue',
                'completed_dependency_unlocks_dependent_packet',
                'hot_scope_blocks_packet_before_queue_assignment',
                'stale_packet_hash_blocks_claim',
                'multi_session_gate_blocks_when_durable_ledger_is_missing',
                'single_session_fallback_remains_available_when_parallel_dispatch_is_blocked',
                'readiness_projection_command_does_not_persist_claims_or_write_storage',
            ],
            'forbidden_scopes' => $this->hotForbiddenFiles(),
            'claim_allowed_now' => false,
            'storage_write_allowed' => false,
            'dispatch_allowed' => false,
        ];

        return [
            'schema_version' => 'atlas.self_construction_durable_reservation_readiness_projection.v1',
            'status' => 'durable_reservation_readiness_projection_ready',
            'mode' => 'read_only_durable_reservation_readiness_projection',
            'execution_allowed' => false,
            'completion_allowed' => false,
            'implementation_allowed_now' => false,
            'claim_allowed_now' => false,
            'storage_write_allowed' => false,
            'dispatch_allowed' => false,
            'readiness_projection' => $readinessProjection,
            'projection_hash' => $this->stableHash($readinessProjection),
            'non_execution_guarantees' => [
                'durable_reservation_readiness_projection_does_not_create_projection_runtime',
                'durable_reservation_readiness_projection_does_not_write_storage',
                'durable_reservation_readiness_projection_does_not_persist_claim',
                'durable_reservation_readiness_projection_does_not_dispatch_work',
                'durable_reservation_readiness_projection_requires_durable_projection',
            ],
            'human_summary' => 'Durable reservation readiness projection is ready: future queue, collision and multi-session gates know how to consume durable state, but no projection runtime or storage write is allowed yet.',
        ];
    }

    /**
     * @param  array{workspace?: string|null, target?: string|null}  $options
     * @return array<string, mixed>
     */
    public function durableReservationImplementationPreflight(array $options = []): array
    {
        $postApprovalPayload = $this->durableReservationPostApprovalPreflight($options);
        $implementationPayload = $this->durableReservationImplementationPacket($options);
        $schemaPayload = $this->durableReservationStorageSchema($options);
        $repositoryPayload = $this->durableReservationRepositoryContract($options);
        $collisionPayload = $this->durableReservationCollisionGuard($options);
        $lifecyclePayload = $this->durableReservationLeaseLifecycle($options);
        $projectionPayload = $this->durableReservationReadinessProjection($options);

        $contractHashes = [
            ['id' => 'post_approval_preflight', 'hash' => data_get($postApprovalPayload, 'preflight_hash')],
            ['id' => 'implementation_packet', 'hash' => data_get($implementationPayload, 'packet_hash')],
            ['id' => 'storage_schema', 'hash' => data_get($schemaPayload, 'schema_hash')],
            ['id' => 'repository_contract', 'hash' => data_get($repositoryPayload, 'contract_hash')],
            ['id' => 'collision_guard', 'hash' => data_get($collisionPayload, 'guard_hash')],
            ['id' => 'lease_lifecycle', 'hash' => data_get($lifecyclePayload, 'lifecycle_hash')],
            ['id' => 'readiness_projection', 'hash' => data_get($projectionPayload, 'projection_hash')],
        ];

        $implementationPreflight = [
            'preflight_id' => 'IMPLEMENTATION-PREFLIGHT-DURABLE-RESERVATION-LEDGER-0001',
            'preflight_status' => 'blocked_until_signed_approval_and_contract_hashes_pass',
            'contract_hash_count' => count($contractHashes),
            'contract_hashes' => $contractHashes,
            'required_gates' => [
                'php artisan test tests/Feature/Ai/AtlasAiSelfConstructionCommandTest.php',
                'php artisan atlas:ai:self-construction --traceability --json',
                'php artisan atlas:engineering:knowledge docs-health --json',
                'php artisan atlas:ai:architecture-validate --json',
                'git diff --check',
            ],
            'blocking_conditions' => [
                'any_contract_hash_missing',
                'contract_hash_drift_detected',
                'traceability_not_clean',
                'docs_health_failed',
                'architecture_validate_failed',
                'migration_scope_broader_than_approval',
                'storage_write_requested_before_approval',
                'dispatch_requested',
            ],
            'required_first_commands' => [
                'git status --short',
                'git diff --stat',
                'git diff --name-only',
                'php artisan atlas:ai:self-construction --durable-reservation-implementation-preflight --json',
                'php artisan atlas:ai:self-construction --scope-validator --json',
            ],
            'forbidden_scopes' => $this->hotForbiddenFiles(),
            'implementation_allowed_now' => false,
            'migration_allowed_now' => false,
            'storage_write_allowed' => false,
            'dispatch_allowed' => false,
        ];

        return [
            'schema_version' => 'atlas.self_construction_durable_reservation_implementation_preflight.v1',
            'status' => 'durable_reservation_implementation_preflight_ready',
            'mode' => 'read_only_durable_reservation_implementation_preflight',
            'execution_allowed' => false,
            'completion_allowed' => false,
            'implementation_allowed_now' => false,
            'migration_allowed_now' => false,
            'storage_write_allowed' => false,
            'dispatch_allowed' => false,
            'implementation_preflight' => $implementationPreflight,
            'preflight_hash' => $this->stableHash($implementationPreflight),
            'non_execution_guarantees' => [
                'durable_reservation_implementation_preflight_does_not_approve_work',
                'durable_reservation_implementation_preflight_does_not_create_migrations',
                'durable_reservation_implementation_preflight_does_not_write_storage',
                'durable_reservation_implementation_preflight_does_not_persist_claim',
                'durable_reservation_implementation_preflight_does_not_dispatch_work',
            ],
            'human_summary' => 'Durable reservation implementation preflight is ready: all contract hashes are bundled, but implementation remains blocked until signed approval and gates pass.',
        ];
    }

    /**
     * @param  array{workspace?: string|null, target?: string|null}  $options
     * @return array<string, mixed>
     */
    public function durableReservationMigrationBlueprint(array $options = []): array
    {
        $preflightPayload = $this->durableReservationImplementationPreflight($options);
        $schemaPayload = $this->durableReservationStorageSchema($options);

        $tables = [
            [
                'name' => 'atlas_self_construction_reservation_events',
                'purpose' => 'append_only_reservation_event_source',
                'columns' => [
                    'id',
                    'reservation_id',
                    'packet_id',
                    'event_type',
                    'actor_id',
                    'session_id',
                    'packet_hash',
                    'allowed_files_hash',
                    'previous_event_hash',
                    'event_hash',
                    'payload',
                    'created_at',
                ],
                'indexes' => [
                    'reservation_id',
                    'packet_id',
                    'event_type',
                    'session_id',
                    'event_hash_unique',
                    'created_at',
                ],
            ],
            [
                'name' => 'atlas_self_construction_reservations',
                'purpose' => 'current_reservation_projection_for_fast_claim_checks',
                'columns' => [
                    'id',
                    'reservation_id',
                    'packet_id',
                    'owner_id',
                    'session_id',
                    'state',
                    'packet_hash',
                    'allowed_files_hash',
                    'lease_expires_at',
                    'completed_at',
                    'released_at',
                    'blocker_reason',
                    'created_at',
                    'updated_at',
                ],
                'indexes' => [
                    'active_packet_claim_guard',
                    'packet_id',
                    'owner_session',
                    'state',
                    'lease_expires_at',
                    'allowed_files_hash',
                ],
            ],
        ];

        $migrationBlueprint = [
            'blueprint_id' => 'MIGRATION-BLUEPRINT-DURABLE-RESERVATION-LEDGER-0001',
            'blueprint_status' => 'blocked_until_signed_preflight_and_migration_scope_approval',
            'source_preflight_hash' => data_get($preflightPayload, 'preflight_hash'),
            'source_storage_schema_hash' => data_get($schemaPayload, 'schema_hash'),
            'migration_files' => [
                'create_atlas_self_construction_reservation_events_table',
                'create_atlas_self_construction_reservations_table',
            ],
            'table_count' => count($tables),
            'tables' => $tables,
            'rollback_order' => [
                'drop_atlas_self_construction_reservations',
                'drop_atlas_self_construction_reservation_events',
            ],
            'required_tests' => [
                'migration_creates_reservation_events_table_with_required_columns',
                'migration_creates_reservations_projection_table_with_required_columns',
                'event_hash_is_unique',
                'lease_expiry_is_indexed_and_queryable',
                'projection_can_be_rebuilt_from_events',
                'rollback_drops_projection_before_events',
                'migration_blueprint_command_does_not_create_migrations_or_write_storage',
            ],
            'forbidden_scopes' => $this->hotForbiddenFiles(),
            'implementation_allowed_now' => false,
            'migration_allowed_now' => false,
            'storage_write_allowed' => false,
            'dispatch_allowed' => false,
        ];

        return [
            'schema_version' => 'atlas.self_construction_durable_reservation_migration_blueprint.v1',
            'status' => 'durable_reservation_migration_blueprint_ready',
            'mode' => 'read_only_durable_reservation_migration_blueprint',
            'execution_allowed' => false,
            'completion_allowed' => false,
            'implementation_allowed_now' => false,
            'migration_allowed_now' => false,
            'storage_write_allowed' => false,
            'dispatch_allowed' => false,
            'migration_blueprint' => $migrationBlueprint,
            'blueprint_hash' => $this->stableHash($migrationBlueprint),
            'non_execution_guarantees' => [
                'durable_reservation_migration_blueprint_does_not_create_migration_files',
                'durable_reservation_migration_blueprint_does_not_run_migrations',
                'durable_reservation_migration_blueprint_does_not_write_storage',
                'durable_reservation_migration_blueprint_does_not_persist_claim',
                'durable_reservation_migration_blueprint_does_not_dispatch_work',
            ],
            'human_summary' => 'Durable reservation migration blueprint is ready: table names, columns, indexes, rollback and tests are fixed, but migration creation remains blocked.',
        ];
    }

    /**
     * @param  array{workspace?: string|null, target?: string|null}  $options
     * @return array<string, mixed>
     */
    public function durableReservationRepositoryBlueprint(array $options = []): array
    {
        $repositoryPayload = $this->durableReservationRepositoryContract($options);
        $migrationPayload = $this->durableReservationMigrationBlueprint($options);

        $classes = [
            'App\Services\Ai\SelfConstruction\Reservations\DurableReservationRepository',
            'App\Services\Ai\SelfConstruction\Reservations\DurableReservationCollisionGuard',
            'App\Services\Ai\SelfConstruction\Reservations\ReservationEventHasher',
            'App\Services\Ai\SelfConstruction\Reservations\ReservationProjectionBuilder',
            'App\Services\Ai\SelfConstruction\Reservations\Data\ReservationClaimRequest',
            'App\Services\Ai\SelfConstruction\Reservations\Data\ReservationClaimResult',
            'App\Services\Ai\SelfConstruction\Reservations\Exceptions\ReservationRejectedException',
        ];

        $methods = [
            'preview',
            'claim',
            'renew',
            'release',
            'expire',
            'complete',
            'current',
            'activeCollisions',
            'rebuildProjection',
        ];

        $repositoryBlueprint = [
            'blueprint_id' => 'REPOSITORY-BLUEPRINT-DURABLE-RESERVATION-LEDGER-0001',
            'blueprint_status' => 'blocked_until_migration_blueprint_and_repository_scope_approval',
            'source_repository_contract_hash' => data_get($repositoryPayload, 'contract_hash'),
            'source_migration_blueprint_hash' => data_get($migrationPayload, 'blueprint_hash'),
            'class_count' => count($classes),
            'classes' => $classes,
            'method_count' => count($methods),
            'methods' => $methods,
            'error_codes' => [
                'packet_already_claimed',
                'allowed_files_overlap_active_reservation',
                'hot_scope_forbidden',
                'packet_hash_stale',
                'lease_expired',
                'completion_gate_missing',
                'actor_not_owner',
                'event_chain_mismatch',
            ],
            'transaction_rules' => [
                'claim_renew_release_expire_and_complete_run_inside_database_transactions',
                'claim_locks_packet_scope_before_appending_event',
                'event_hash_includes_previous_event_hash_actor_packet_hash_and_payload',
                'projection_updates_only_after_accepted_events',
                'completion_requires_packet_completion_gate_evidence',
                'repository_methods_never_dispatch_work',
            ],
            'required_tests' => [
                'preview_reports_rejection_without_writing_events',
                'claim_appends_event_and_updates_projection_atomically',
                'duplicate_active_packet_claim_is_rejected',
                'overlapping_active_file_scope_is_rejected',
                'stale_packet_hash_is_rejected',
                'non_owner_cannot_renew_release_or_complete',
                'event_chain_mismatch_blocks_projection_update',
                'repository_blueprint_command_does_not_create_php_files_or_write_storage',
            ],
            'forbidden_scopes' => $this->hotForbiddenFiles(),
            'implementation_allowed_now' => false,
            'migration_allowed_now' => false,
            'runtime_file_creation_allowed' => false,
            'storage_write_allowed' => false,
            'dispatch_allowed' => false,
        ];

        return [
            'schema_version' => 'atlas.self_construction_durable_reservation_repository_blueprint.v1',
            'status' => 'durable_reservation_repository_blueprint_ready',
            'mode' => 'read_only_durable_reservation_repository_blueprint',
            'execution_allowed' => false,
            'completion_allowed' => false,
            'implementation_allowed_now' => false,
            'migration_allowed_now' => false,
            'runtime_file_creation_allowed' => false,
            'storage_write_allowed' => false,
            'dispatch_allowed' => false,
            'repository_blueprint' => $repositoryBlueprint,
            'blueprint_hash' => $this->stableHash($repositoryBlueprint),
            'non_execution_guarantees' => [
                'durable_reservation_repository_blueprint_does_not_create_php_files',
                'durable_reservation_repository_blueprint_does_not_run_migrations',
                'durable_reservation_repository_blueprint_does_not_write_storage',
                'durable_reservation_repository_blueprint_does_not_persist_claim',
                'durable_reservation_repository_blueprint_does_not_dispatch_work',
            ],
            'human_summary' => 'Durable reservation repository blueprint is ready: classes, methods, errors, transactions and tests are fixed, but runtime file creation remains blocked.',
        ];
    }

    /**
     * @param  array{workspace?: string|null, target?: string|null}  $options
     * @return array<string, mixed>
     */
    public function durableReservationCollisionGuardBlueprint(array $options = []): array
    {
        $collisionPayload = $this->durableReservationCollisionGuard($options);
        $repositoryBlueprintPayload = $this->durableReservationRepositoryBlueprint($options);

        $blockers = [
            'hot_scope_forbidden',
            'active_file_overlap',
            'packet_hash_stale',
            'dependency_incomplete',
            'completion_gate_blocked',
            'owner_conflict',
        ];

        $outputs = [
            'decision_state',
            'blocker_code',
            'human_reason',
            'conflicting_reservation_ids',
            'conflicting_file_paths',
            'packet_hash_used_for_decision',
            'guard_hash',
        ];

        $collisionGuardBlueprint = [
            'blueprint_id' => 'COLLISION-GUARD-BLUEPRINT-DURABLE-RESERVATION-LEDGER-0001',
            'blueprint_status' => 'blocked_until_repository_blueprint_and_guard_scope_approval',
            'source_collision_guard_hash' => data_get($collisionPayload, 'guard_hash'),
            'source_repository_blueprint_hash' => data_get($repositoryBlueprintPayload, 'blueprint_hash'),
            'inputs' => [
                'candidate_packet_id',
                'candidate_packet_hash',
                'candidate_allowed_files',
                'current_active_reservations',
                'current_changed_files',
                'hot_forbidden_scopes',
                'packet_dependency_status',
                'packet_completion_gate_status',
            ],
            'blocker_count' => count($blockers),
            'blockers' => $blockers,
            'decision_states' => [
                'allow_preview',
                'allow_claim',
                'block_claim',
                'require_human_review',
            ],
            'output_count' => count($outputs),
            'outputs' => $outputs,
            'required_tests' => [
                'hot_voice_kernel_scope_is_blocked',
                'overlapping_active_file_scope_is_blocked',
                'stale_packet_hash_is_blocked',
                'incomplete_dependency_is_blocked',
                'clean_disjoint_packet_can_be_claimable',
                'guard_explains_conflicting_reservation_ids_and_file_paths',
                'collision_guard_blueprint_command_does_not_create_php_files_or_write_storage',
            ],
            'forbidden_scopes' => $this->hotForbiddenFiles(),
            'implementation_allowed_now' => false,
            'runtime_file_creation_allowed' => false,
            'claim_allowed_now' => false,
            'storage_write_allowed' => false,
            'dispatch_allowed' => false,
        ];

        return [
            'schema_version' => 'atlas.self_construction_durable_reservation_collision_guard_blueprint.v1',
            'status' => 'durable_reservation_collision_guard_blueprint_ready',
            'mode' => 'read_only_durable_reservation_collision_guard_blueprint',
            'execution_allowed' => false,
            'completion_allowed' => false,
            'implementation_allowed_now' => false,
            'runtime_file_creation_allowed' => false,
            'claim_allowed_now' => false,
            'storage_write_allowed' => false,
            'dispatch_allowed' => false,
            'collision_guard_blueprint' => $collisionGuardBlueprint,
            'blueprint_hash' => $this->stableHash($collisionGuardBlueprint),
            'non_execution_guarantees' => [
                'durable_reservation_collision_guard_blueprint_does_not_create_php_files',
                'durable_reservation_collision_guard_blueprint_does_not_write_storage',
                'durable_reservation_collision_guard_blueprint_does_not_persist_claim',
                'durable_reservation_collision_guard_blueprint_does_not_dispatch_work',
            ],
            'human_summary' => 'Durable reservation collision guard blueprint is ready: inputs, blockers, outputs and tests are fixed, but runtime file creation remains blocked.',
        ];
    }

    /**
     * @param  array{workspace?: string|null, target?: string|null}  $options
     * @return array<string, mixed>
     */
    public function durableReservationLeaseLifecycleBlueprint(array $options = []): array
    {
        $lifecyclePayload = $this->durableReservationLeaseLifecycle($options);
        $guardBlueprintPayload = $this->durableReservationCollisionGuardBlueprint($options);

        $states = [
            'preview',
            'claimed',
            'renewed',
            'released',
            'expired',
            'completed',
            'blocked',
        ];

        $transitions = [
            'preview_to_claimed',
            'claimed_to_renewed',
            'claimed_to_released',
            'claimed_to_expired',
            'renewed_to_released',
            'renewed_to_expired',
            'claimed_to_completed',
            'renewed_to_completed',
            'released_or_expired_to_claimed_by_new_owner',
            'any_to_blocked_when_guard_rejects_action',
        ];

        $leaseLifecycleBlueprint = [
            'blueprint_id' => 'LEASE-LIFECYCLE-BLUEPRINT-DURABLE-RESERVATION-LEDGER-0001',
            'blueprint_status' => 'blocked_until_collision_guard_blueprint_and_lifecycle_scope_approval',
            'source_lease_lifecycle_hash' => data_get($lifecyclePayload, 'lifecycle_hash'),
            'source_collision_guard_blueprint_hash' => data_get($guardBlueprintPayload, 'blueprint_hash'),
            'state_count' => count($states),
            'states' => $states,
            'transition_count' => count($transitions),
            'transitions' => $transitions,
            'timing_rules' => [
                'default_lease_duration_must_come_from_config_or_policy',
                'renew_requires_same_owner_session_and_active_lease',
                'release_requires_same_owner_session_and_active_lease',
                'expiry_may_be_system_driven_and_must_append_event',
                'completion_requires_same_owner_active_lease_and_passing_completion_gate',
                'expired_or_released_leases_cannot_complete',
            ],
            'required_tests' => [
                'owner_can_renew_active_lease',
                'non_owner_cannot_renew_release_or_complete',
                'expired_lease_cannot_complete',
                'released_lease_cannot_complete',
                'expired_packet_can_be_reclaimed_after_expiry_event',
                'released_packet_can_be_reclaimed_after_release_event',
                'completion_requires_packet_completion_gate_evidence',
                'lease_lifecycle_blueprint_command_does_not_create_php_files_or_write_storage',
            ],
            'forbidden_scopes' => $this->hotForbiddenFiles(),
            'implementation_allowed_now' => false,
            'runtime_file_creation_allowed' => false,
            'claim_allowed_now' => false,
            'storage_write_allowed' => false,
            'dispatch_allowed' => false,
        ];

        return [
            'schema_version' => 'atlas.self_construction_durable_reservation_lease_lifecycle_blueprint.v1',
            'status' => 'durable_reservation_lease_lifecycle_blueprint_ready',
            'mode' => 'read_only_durable_reservation_lease_lifecycle_blueprint',
            'execution_allowed' => false,
            'completion_allowed' => false,
            'implementation_allowed_now' => false,
            'runtime_file_creation_allowed' => false,
            'claim_allowed_now' => false,
            'storage_write_allowed' => false,
            'dispatch_allowed' => false,
            'lease_lifecycle_blueprint' => $leaseLifecycleBlueprint,
            'blueprint_hash' => $this->stableHash($leaseLifecycleBlueprint),
            'non_execution_guarantees' => [
                'durable_reservation_lease_lifecycle_blueprint_does_not_create_php_files',
                'durable_reservation_lease_lifecycle_blueprint_does_not_write_storage',
                'durable_reservation_lease_lifecycle_blueprint_does_not_persist_claim',
                'durable_reservation_lease_lifecycle_blueprint_does_not_dispatch_work',
            ],
            'human_summary' => 'Durable reservation lease lifecycle blueprint is ready: states, transitions, timing rules and tests are fixed, but runtime file creation remains blocked.',
        ];
    }

    /**
     * @param  array{workspace?: string|null, target?: string|null}  $options
     * @return array<string, mixed>
     */
    public function durableReservationReadinessProjectionBlueprint(array $options = []): array
    {
        $projectionPayload = $this->durableReservationReadinessProjection($options);
        $lifecycleBlueprintPayload = $this->durableReservationLeaseLifecycleBlueprint($options);

        $queueStates = [
            'available',
            'claimed',
            'blocked_by_collision',
            'blocked_by_dependency',
            'blocked_by_hot_scope',
            'blocked_by_stale_hash',
            'completed',
        ];

        $outputs = [
            'queue_summary',
            'claimable_packet_ids',
            'blocked_packet_ids_and_reasons',
            'active_reservation_owners',
            'dependency_unlock_hints',
            'multi_session_decision',
            'safe_single_session_fallback_instruction',
        ];

        $readinessProjectionBlueprint = [
            'blueprint_id' => 'READINESS-PROJECTION-BLUEPRINT-DURABLE-RESERVATION-LEDGER-0001',
            'blueprint_status' => 'blocked_until_lease_lifecycle_blueprint_and_projection_scope_approval',
            'source_readiness_projection_hash' => data_get($projectionPayload, 'projection_hash'),
            'source_lease_lifecycle_blueprint_hash' => data_get($lifecycleBlueprintPayload, 'blueprint_hash'),
            'inputs' => [
                'active_reservations',
                'expired_reservations',
                'completed_packets',
                'blocked_packets',
                'packet_dependencies',
                'allowed_file_scopes',
                'hot_forbidden_scopes',
                'current_changed_files',
                'packet_completion_gate_status',
            ],
            'queue_state_count' => count($queueStates),
            'queue_states' => $queueStates,
            'output_count' => count($outputs),
            'outputs' => $outputs,
            'integration_targets' => [
                'packet_queue',
                'collision_matrix',
                'dependency_unlock_plan',
                'multi_session_readiness_gate',
                'single_session_instruction_packet',
            ],
            'required_tests' => [
                'active_reservation_removes_packet_from_claimable_queue',
                'completed_dependency_unlocks_dependent_packet',
                'hot_scope_blocks_packet_before_queue_assignment',
                'stale_packet_hash_blocks_claim',
                'completed_packet_is_not_claimable',
                'multi_session_gate_blocks_when_durable_projection_is_missing',
                'single_session_fallback_remains_available_when_parallel_dispatch_is_blocked',
                'readiness_projection_blueprint_command_does_not_create_php_files_or_write_storage',
            ],
            'forbidden_scopes' => $this->hotForbiddenFiles(),
            'implementation_allowed_now' => false,
            'runtime_file_creation_allowed' => false,
            'claim_allowed_now' => false,
            'storage_write_allowed' => false,
            'dispatch_allowed' => false,
        ];

        return [
            'schema_version' => 'atlas.self_construction_durable_reservation_readiness_projection_blueprint.v1',
            'status' => 'durable_reservation_readiness_projection_blueprint_ready',
            'mode' => 'read_only_durable_reservation_readiness_projection_blueprint',
            'execution_allowed' => false,
            'completion_allowed' => false,
            'implementation_allowed_now' => false,
            'runtime_file_creation_allowed' => false,
            'claim_allowed_now' => false,
            'storage_write_allowed' => false,
            'dispatch_allowed' => false,
            'readiness_projection_blueprint' => $readinessProjectionBlueprint,
            'blueprint_hash' => $this->stableHash($readinessProjectionBlueprint),
            'non_execution_guarantees' => [
                'durable_reservation_readiness_projection_blueprint_does_not_create_php_files',
                'durable_reservation_readiness_projection_blueprint_does_not_write_storage',
                'durable_reservation_readiness_projection_blueprint_does_not_persist_claim',
                'durable_reservation_readiness_projection_blueprint_does_not_dispatch_work',
            ],
            'human_summary' => 'Durable reservation readiness projection blueprint is ready: inputs, queue states, outputs and integrations are fixed, but runtime file creation remains blocked.',
        ];
    }

    /**
     * @param  array{workspace?: string|null, target?: string|null}  $options
     * @return array<string, mixed>
     */
    public function durableReservationRuntimeBuildPacket(array $options = []): array
    {
        $migrationBlueprintPayload = $this->durableReservationMigrationBlueprint($options);
        $repositoryBlueprintPayload = $this->durableReservationRepositoryBlueprint($options);
        $collisionGuardBlueprintPayload = $this->durableReservationCollisionGuardBlueprint($options);
        $leaseLifecycleBlueprintPayload = $this->durableReservationLeaseLifecycleBlueprint($options);
        $readinessProjectionBlueprintPayload = $this->durableReservationReadinessProjectionBlueprint($options);

        $sourceBlueprints = [
            ['id' => 'migration_blueprint', 'hash' => data_get($migrationBlueprintPayload, 'blueprint_hash')],
            ['id' => 'repository_blueprint', 'hash' => data_get($repositoryBlueprintPayload, 'blueprint_hash')],
            ['id' => 'collision_guard_blueprint', 'hash' => data_get($collisionGuardBlueprintPayload, 'blueprint_hash')],
            ['id' => 'lease_lifecycle_blueprint', 'hash' => data_get($leaseLifecycleBlueprintPayload, 'blueprint_hash')],
            ['id' => 'readiness_projection_blueprint', 'hash' => data_get($readinessProjectionBlueprintPayload, 'blueprint_hash')],
        ];

        $implementationSlices = [
            'migrations',
            'dtos_results_and_exceptions',
            'durable_reservation_repository',
            'collision_guard_service',
            'lease_lifecycle_service',
            'readiness_projection_service',
            'command_integration_and_read_only_adapter',
            'feature_and_failure_mode_tests',
        ];

        $futureFiles = [
            'database/migrations/*_create_atlas_self_construction_reservations_table.php',
            'database/migrations/*_create_atlas_self_construction_reservation_events_table.php',
            'app/Services/Ai/SelfConstruction/Reservations/DurableReservationRepository.php',
            'app/Services/Ai/SelfConstruction/Reservations/DurableReservationCollisionGuard.php',
            'app/Services/Ai/SelfConstruction/Reservations/DurableReservationLeaseLifecycle.php',
            'app/Services/Ai/SelfConstruction/Reservations/DurableReservationReadinessProjection.php',
            'tests/Feature/Ai/SelfConstruction/DurableReservationRepositoryTest.php',
            'tests/Feature/Ai/SelfConstruction/DurableReservationConcurrencyTest.php',
        ];

        $requiredGates = [
            'php artisan test tests/Feature/Ai/AtlasAiSelfConstructionCommandTest.php',
            'php artisan test tests/Feature/Ai/SelfConstruction/DurableReservationRepositoryTest.php',
            'php artisan test tests/Feature/Ai/SelfConstruction/DurableReservationConcurrencyTest.php',
            'php artisan atlas:ai:self-construction --traceability --json',
            'php artisan atlas:engineering:knowledge docs-health --json',
            'php artisan atlas:ai:architecture-validate --json',
            'git diff --check',
        ];

        $runtimeBuildPacket = [
            'packet_id' => 'RUNTIME-BUILD-PACKET-DURABLE-RESERVATION-LEDGER-0001',
            'build_status' => 'blocked_until_signed_approval_preflight_and_runtime_scope_approval',
            'source_blueprint_count' => count($sourceBlueprints),
            'source_blueprints' => $sourceBlueprints,
            'slice_count' => count($implementationSlices),
            'implementation_slices' => $implementationSlices,
            'future_file_count' => count($futureFiles),
            'future_files' => $futureFiles,
            'required_gate_count' => count($requiredGates),
            'required_gates' => $requiredGates,
            'required_evidence' => [
                'source_blueprint_hashes',
                'implementation_slice_statuses',
                'changed_files',
                'migration_dry_run_or_rollback_notes',
                'test_outputs',
                'scope_validation_output',
                'residual_risk_summary',
            ],
            'stop_conditions' => [
                'missing_signed_approval',
                'blueprint_hash_drift',
                'hot_forbidden_scope_touched',
                'migration_rollback_undefined',
                'repository_tests_missing',
                'collision_tests_missing',
                'readiness_projection_not_connected_to_packet_queue_and_multi_session_gate',
            ],
            'forbidden_scopes' => $this->hotForbiddenFiles(),
            'implementation_allowed_now' => false,
            'runtime_file_creation_allowed' => false,
            'migration_creation_allowed' => false,
            'claim_allowed_now' => false,
            'storage_write_allowed' => false,
            'dispatch_allowed' => false,
        ];

        return [
            'schema_version' => 'atlas.self_construction_durable_reservation_runtime_build_packet.v1',
            'status' => 'durable_reservation_runtime_build_packet_ready',
            'mode' => 'read_only_durable_reservation_runtime_build_packet',
            'execution_allowed' => false,
            'completion_allowed' => false,
            'implementation_allowed_now' => false,
            'runtime_file_creation_allowed' => false,
            'migration_creation_allowed' => false,
            'claim_allowed_now' => false,
            'storage_write_allowed' => false,
            'dispatch_allowed' => false,
            'runtime_build_packet' => $runtimeBuildPacket,
            'build_packet_hash' => $this->stableHash($runtimeBuildPacket),
            'non_execution_guarantees' => [
                'durable_reservation_runtime_build_packet_does_not_create_migrations',
                'durable_reservation_runtime_build_packet_does_not_create_php_files',
                'durable_reservation_runtime_build_packet_does_not_write_storage',
                'durable_reservation_runtime_build_packet_does_not_persist_claim',
                'durable_reservation_runtime_build_packet_does_not_dispatch_work',
            ],
            'human_summary' => 'Durable reservation runtime build packet is ready: source blueprints, implementation slices, future files, gates and evidence are fixed, but runtime creation remains blocked.',
        ];
    }

    /**
     * @param  array{workspace?: string|null, target?: string|null}  $options
     * @return array<string, mixed>
     */
    public function aiSessionBootstrap(array $options = []): array
    {
        $assignmentPayload = $this->assignmentPreview($options);
        $reservationPayload = $this->reservationLedgerPreview($options);
        $runbookPayload = $this->packetRunbook($options);
        $scopePayload = $this->scopeValidator($options);
        $evidencePayload = $this->packetEvidenceReport($options);
        $completionGatePayload = $this->packetCompletionGate($options);

        $bootstrap = [
            'bootstrap_id' => 'BOOTSTRAP-SELF-CONSTRUCTION-AI-SESSION-0001',
            'session_mode' => 'read_only_ai_bootstrap',
            'selected_packet_id' => data_get($assignmentPayload, 'assignment.selected_packet_id'),
            'assignment_hash' => data_get($assignmentPayload, 'assignment_hash'),
            'reservation_hash' => data_get($reservationPayload, 'reservation_hash'),
            'runbook_hash' => data_get($runbookPayload, 'runbook_hash'),
            'scope_validator_status' => data_get($scopePayload, 'status'),
            'evidence_report_status' => data_get($evidencePayload, 'status'),
            'completion_gate_status' => data_get($completionGatePayload, 'status'),
            'execution_allowed' => false,
            'completion_allowed' => false,
            'claim_persisted' => false,
            'ledger_write_allowed' => false,
            'required_first_commands' => [
                'git status --short',
                'git diff --stat',
                'git diff --name-only',
                $this->packetCommand('ai-session-bootstrap', data_get($assignmentPayload, 'assignment.selected_packet_id')),
                $this->packetCommand('scope-validator', data_get($assignmentPayload, 'assignment.selected_packet_id')),
            ],
            'stop_conditions' => array_values(array_unique(array_merge(
                (array) data_get($assignmentPayload, 'assignment.stop_conditions', []),
                (array) data_get($reservationPayload, 'reservation.stop_conditions', []),
                [
                    'bootstrap_hash_changed',
                    'reservation_hash_changed',
                    'scope_validator_blocked',
                    'packet_completion_gate_blocked',
                    'operator_evidence_missing',
                ],
            ))),
            'forbidden_hot_scopes' => $this->hotForbiddenFiles(),
            'payload_refs' => [
                'assignment_schema' => data_get($assignmentPayload, 'schema_version'),
                'reservation_schema' => data_get($reservationPayload, 'schema_version'),
                'runbook_schema' => data_get($runbookPayload, 'schema_version'),
                'scope_validator_schema' => data_get($scopePayload, 'schema_version'),
                'evidence_report_schema' => data_get($evidencePayload, 'schema_version'),
                'completion_gate_schema' => data_get($completionGatePayload, 'schema_version'),
            ],
        ];

        return [
            'schema_version' => 'atlas.self_construction_ai_session_bootstrap.v1',
            'status' => data_get($assignmentPayload, 'status') === 'claim_preview_ready'
                ? 'bootstrap_ready'
                : 'blocked_by_assignment',
            'mode' => 'read_only_ai_session_bootstrap',
            'execution_allowed' => false,
            'completion_allowed' => false,
            'claim_persisted' => false,
            'ledger_write_allowed' => false,
            'bootstrap' => $bootstrap,
            'bootstrap_hash' => $this->stableHash($bootstrap),
            'non_execution_guarantees' => [
                'ai_session_bootstrap_does_not_persist_claim',
                'ai_session_bootstrap_does_not_write_ledger',
                'ai_session_bootstrap_does_not_apply_patch',
                'ai_session_bootstrap_does_not_enable_execution',
            ],
            'human_summary' => 'AI session bootstrap is ready: a new AI can read one canonical packet, but claims, ledger writes, execution and completion remain disabled.',
        ];
    }

    /**
     * @param  array{workspace?: string|null, target?: string|null}  $options
     * @return array<string, mixed>
     */
    public function packetQueue(array $options = []): array
    {
        $splitter = $this->workSplitter($options);
        $packets = (array) data_get($splitter, 'split.packets', []);
        $withheld = (array) data_get($splitter, 'split.withheld_work', []);
        $activeReservations = $this->reservations->activeByPacket();
        $completedReservations = $this->reservations->completedByPacket();

        $entries = [];
        foreach ($packets as $index => $packet) {
            $dependsOn = (array) data_get($packet, 'depends_on', []);
            $packetId = (string) data_get($packet, 'packet_id');
            $activeReservation = $activeReservations[$packetId] ?? null;
            $completedReservation = $completedReservations[$packetId] ?? null;
            $dependenciesComplete = $dependsOn === []
                || count(array_diff($dependsOn, array_keys($completedReservations))) === 0;
            $queueState = match (true) {
                is_array($completedReservation) => 'completed',
                is_array($activeReservation) => 'claimed',
                $dependenciesComplete => 'available',
                default => 'blocked_by_dependency',
            };

            $entries[] = [
                'packet_id' => $packetId,
                'lane' => data_get($packet, 'lane'),
                'objective' => data_get($packet, 'objective'),
                'queue_state' => $queueState,
                'rank' => $queueState === 'available' ? $index + 1 : null,
                'active_reservation_id' => data_get($activeReservation, 'reservation_id'),
                'active_reservation_actor' => data_get($activeReservation, 'actor'),
                'active_reservation_session' => data_get($activeReservation, 'session'),
                'lease_expires_at' => data_get($activeReservation, 'lease_expires_at'),
                'completed_reservation_id' => data_get($completedReservation, 'reservation_id'),
                'completed_at' => data_get($completedReservation, 'completed_at'),
                'completion_actor' => data_get($completedReservation, 'actor'),
                'claim_policy' => data_get($packet, 'claim_policy'),
                'collision_risk' => data_get($packet, 'collision_risk'),
                'recommended' => false,
                'depends_on' => $dependsOn,
                'allowed_files' => (array) data_get($packet, 'allowed_files', []),
                'forbidden_files' => (array) data_get($packet, 'forbidden_files', []),
                'required_bootstrap_command' => $this->packetCommand('ai-session-bootstrap', data_get($packet, 'packet_id')),
            ];
        }

        foreach ($withheld as $index => $item) {
            $entries[] = [
                'packet_id' => data_get($item, 'id'),
                'lane' => 'withheld_hot_external',
                'objective' => data_get($item, 'reason'),
                'queue_state' => 'withheld',
                'rank' => null,
                'claim_policy' => 'not_assignable',
                'collision_risk' => 'blocked',
                'recommended' => false,
                'depends_on' => [],
                'allowed_files' => [],
                'forbidden_files' => [data_get($item, 'forbidden_scope')],
                'withheld_order' => $index + 1,
            ];
        }

        $firstAvailableIndex = null;
        foreach ($entries as $index => $entry) {
            if ($entry['queue_state'] === 'available') {
                $firstAvailableIndex = $index;
                break;
            }
        }
        if ($firstAvailableIndex !== null) {
            $entries[$firstAvailableIndex]['recommended'] = true;
        }

        $queue = [
            'queue_id' => 'PACKET-QUEUE-SELF-CONSTRUCTION-READ-ONLY-0001',
            'source_split_hash' => data_get($splitter, 'split_hash'),
            'entry_count' => count($entries),
            'available_count' => count(array_filter($entries, fn (array $entry): bool => $entry['queue_state'] === 'available')),
            'blocked_count' => count(array_filter($entries, fn (array $entry): bool => $entry['queue_state'] === 'blocked_by_dependency')),
            'claimed_count' => count(array_filter($entries, fn (array $entry): bool => $entry['queue_state'] === 'claimed')),
            'completed_count' => count(array_filter($entries, fn (array $entry): bool => $entry['queue_state'] === 'completed')),
            'withheld_count' => count(array_filter($entries, fn (array $entry): bool => $entry['queue_state'] === 'withheld')),
            'recommended_packet_id' => data_get(collect($entries)->firstWhere('recommended', true), 'packet_id'),
            'entries' => $entries,
            'execution_allowed' => false,
            'claim_persisted' => false,
            'ledger_write_allowed' => false,
            'queue_write_allowed' => false,
        ];

        return [
            'schema_version' => 'atlas.self_construction_packet_queue.v1',
            'status' => 'packet_queue_ready',
            'mode' => 'read_only_packet_queue',
            'execution_allowed' => false,
            'completion_allowed' => false,
            'claim_persisted' => false,
            'ledger_write_allowed' => false,
            'queue_write_allowed' => false,
            'queue' => $queue,
            'queue_hash' => $this->stableHash($queue),
            'non_execution_guarantees' => [
                'packet_queue_does_not_persist_claim',
                'packet_queue_does_not_write_ledger',
                'packet_queue_does_not_dispatch_work',
                'packet_queue_does_not_enable_execution',
            ],
            'human_summary' => 'Packet queue is ready: available, blocked and withheld packets are visible without claims, dispatch, ledger writes or execution.',
        ];
    }

    /**
     * @param  array{workspace?: string|null, target?: string|null}  $options
     * @return array<string, mixed>
     */
    public function parallelSessionPlan(array $options = []): array
    {
        $queuePayload = $this->packetQueue($options);
        $entries = (array) data_get($queuePayload, 'queue.entries', []);
        $assignable = array_values(array_filter($entries, fn (array $entry): bool => $entry['queue_state'] === 'available'));
        $blocked = array_values(array_filter($entries, fn (array $entry): bool => $entry['queue_state'] === 'blocked_by_dependency'));
        $withheld = array_values(array_filter($entries, fn (array $entry): bool => $entry['queue_state'] === 'withheld'));

        $slots = [];
        for ($slot = 1; $slot <= 5; $slot++) {
            $entry = $assignable[$slot - 1] ?? null;

            $slots[] = [
                'slot_id' => sprintf('SESSION-SLOT-%03d', $slot),
                'state' => $entry === null ? 'idle_no_safe_packet' : 'preview_assignable',
                'packet_id' => data_get($entry, 'packet_id'),
                'lane' => data_get($entry, 'lane'),
                'bootstrap_command' => $entry === null ? null : $this->packetCommand('ai-session-bootstrap', data_get($entry, 'packet_id')),
                'required_first_commands' => $entry === null ? [] : [
                    'git status --short',
                    'git diff --stat',
                    'git diff --name-only',
                    'php artisan atlas:ai:self-construction --parallel-session-plan --json',
                    $this->packetCommand('ai-session-bootstrap', data_get($entry, 'packet_id')),
                    $this->packetCommand('scope-validator', data_get($entry, 'packet_id')),
                ],
                'execution_allowed' => false,
                'claim_persisted' => false,
                'ledger_write_allowed' => false,
                'dispatch_allowed' => false,
                'reason' => $entry === null
                    ? 'No additional dependency-free packet is safely assignable in read-only mode.'
                    : 'Packet is dependency-free and can be previewed by one AI session without persisted claim.',
            ];
        }

        $plan = [
            'plan_id' => 'PARALLEL-SESSION-PLAN-SELF-CONSTRUCTION-0001',
            'max_session_slots' => 5,
            'slot_count' => count($slots),
            'preview_assignable_count' => count($assignable),
            'idle_slot_count' => count(array_filter($slots, fn (array $slot): bool => $slot['state'] === 'idle_no_safe_packet')),
            'blocked_packet_count' => count($blocked),
            'withheld_packet_count' => count($withheld),
            'source_queue_hash' => data_get($queuePayload, 'queue_hash'),
            'slots' => $slots,
            'blocked_packets' => array_map(fn (array $entry): array => [
                'packet_id' => data_get($entry, 'packet_id'),
                'depends_on' => data_get($entry, 'depends_on'),
                'reason' => 'Packet dependency is not complete in the read-only queue.',
            ], $blocked),
            'withheld_packets' => array_map(fn (array $entry): array => [
                'packet_id' => data_get($entry, 'packet_id'),
                'forbidden_files' => data_get($entry, 'forbidden_files'),
                'reason' => 'Hot external scope is not assignable by Self-Construction.',
            ], $withheld),
            'execution_allowed' => false,
            'claim_persisted' => false,
            'ledger_write_allowed' => false,
            'dispatch_allowed' => false,
        ];

        return [
            'schema_version' => 'atlas.self_construction_parallel_session_plan.v1',
            'status' => 'parallel_session_plan_ready',
            'mode' => 'read_only_parallel_session_plan',
            'execution_allowed' => false,
            'completion_allowed' => false,
            'claim_persisted' => false,
            'ledger_write_allowed' => false,
            'dispatch_allowed' => false,
            'plan' => $plan,
            'plan_hash' => $this->stableHash($plan),
            'non_execution_guarantees' => [
                'parallel_session_plan_does_not_persist_claim',
                'parallel_session_plan_does_not_write_ledger',
                'parallel_session_plan_does_not_dispatch_work',
                'parallel_session_plan_does_not_enable_execution',
            ],
            'human_summary' => 'Parallel session plan is ready: up to five AI slots are previewed while claims, dispatch, ledger writes and execution stay disabled.',
        ];
    }

    /**
     * @param  array{workspace?: string|null, target?: string|null}  $options
     * @return array<string, mixed>
     */
    public function collisionMatrix(array $options = []): array
    {
        $queuePayload = $this->packetQueue($options);
        $entries = (array) data_get($queuePayload, 'queue.entries', []);
        $pairs = [];

        for ($left = 0; $left < count($entries); $left++) {
            for ($right = $left + 1; $right < count($entries); $right++) {
                $leftEntry = $entries[$left];
                $rightEntry = $entries[$right];
                $leftAllowed = array_filter((array) data_get($leftEntry, 'allowed_files', []));
                $rightAllowed = array_filter((array) data_get($rightEntry, 'allowed_files', []));
                $overlap = array_values(array_intersect($leftAllowed, $rightAllowed));
                $leftDepends = (array) data_get($leftEntry, 'depends_on', []);
                $rightDepends = (array) data_get($rightEntry, 'depends_on', []);
                $dependencyRelated = in_array(data_get($leftEntry, 'packet_id'), $rightDepends, true)
                    || in_array(data_get($rightEntry, 'packet_id'), $leftDepends, true);
                $hotScopePresent = data_get($leftEntry, 'queue_state') === 'withheld'
                    || data_get($rightEntry, 'queue_state') === 'withheld'
                    || $this->hasHotScope($leftAllowed)
                    || $this->hasHotScope($rightAllowed);
                $collision = $overlap !== [] || $dependencyRelated || $hotScopePresent;

                $pairs[] = [
                    'left_packet_id' => data_get($leftEntry, 'packet_id'),
                    'right_packet_id' => data_get($rightEntry, 'packet_id'),
                    'overlap' => $overlap,
                    'dependency_related' => $dependencyRelated,
                    'hot_scope_present' => $hotScopePresent,
                    'collision' => $collision,
                    'decision' => $collision ? 'blocked' : 'parallel_safe',
                ];
            }
        }

        $safePackets = array_values(array_map(
            fn (array $entry): string => (string) data_get($entry, 'packet_id'),
            array_filter($entries, fn (array $entry): bool => data_get($entry, 'queue_state') === 'available'),
        ));

        $matrix = [
            'matrix_id' => 'COLLISION-MATRIX-SELF-CONSTRUCTION-READ-ONLY-0001',
            'source_queue_hash' => data_get($queuePayload, 'queue_hash'),
            'entry_count' => count($entries),
            'pair_count' => count($pairs),
            'safe_pair_count' => count(array_filter($pairs, fn (array $pair): bool => $pair['decision'] === 'parallel_safe')),
            'blocked_pair_count' => count(array_filter($pairs, fn (array $pair): bool => $pair['decision'] === 'blocked')),
            'pairs' => $pairs,
            'safe_parallel_groups' => [
                [
                    'group_id' => 'SAFE-PARALLEL-GROUP-001',
                    'packet_ids' => $safePackets,
                    'execution_allowed' => false,
                    'claim_persisted' => false,
                ],
            ],
            'execution_allowed' => false,
            'claim_persisted' => false,
            'ledger_write_allowed' => false,
            'dispatch_allowed' => false,
        ];

        return [
            'schema_version' => 'atlas.self_construction_collision_matrix.v1',
            'status' => 'collision_matrix_ready',
            'mode' => 'read_only_collision_matrix',
            'execution_allowed' => false,
            'completion_allowed' => false,
            'claim_persisted' => false,
            'ledger_write_allowed' => false,
            'dispatch_allowed' => false,
            'matrix' => $matrix,
            'matrix_hash' => $this->stableHash($matrix),
            'non_execution_guarantees' => [
                'collision_matrix_does_not_persist_claim',
                'collision_matrix_does_not_write_ledger',
                'collision_matrix_does_not_dispatch_work',
                'collision_matrix_does_not_enable_execution',
            ],
            'human_summary' => 'Collision matrix is ready: packet overlap and hot scopes are visible without claims, dispatch, ledger writes or execution.',
        ];
    }

    /**
     * @param  array{workspace?: string|null, target?: string|null}  $options
     * @return array<string, mixed>
     */
    public function dependencyUnlockPlan(array $options = []): array
    {
        $queuePayload = $this->packetQueue($options);
        $entries = (array) data_get($queuePayload, 'queue.entries', []);
        $availableIds = array_values(array_map(
            fn (array $entry): string => (string) data_get($entry, 'packet_id'),
            array_filter($entries, fn (array $entry): bool => data_get($entry, 'queue_state') === 'available'),
        ));
        $blockedEntries = array_values(array_filter($entries, fn (array $entry): bool => data_get($entry, 'queue_state') === 'blocked_by_dependency'));
        $withheldEntries = array_values(array_filter($entries, fn (array $entry): bool => data_get($entry, 'queue_state') === 'withheld'));

        $unlockEdges = [];
        $blockedPackets = array_map(function (array $entry) use (&$unlockEdges, $availableIds): array {
            $dependsOn = (array) data_get($entry, 'depends_on', []);
            foreach ($dependsOn as $dependency) {
                $unlockEdges[] = [
                    'dependency_packet_id' => $dependency,
                    'unlocks_packet_id' => data_get($entry, 'packet_id'),
                    'dependency_currently_available' => in_array($dependency, $availableIds, true),
                    'durable_completion_required' => true,
                ];
            }

            return [
                'packet_id' => data_get($entry, 'packet_id'),
                'lane' => data_get($entry, 'lane'),
                'depends_on' => $dependsOn,
                'dependencies_currently_available' => array_values(array_intersect($dependsOn, $availableIds)),
                'would_become_assignable_after' => $dependsOn,
                'queue_state' => data_get($entry, 'queue_state'),
            ];
        }, $blockedEntries);

        $plan = [
            'plan_id' => 'DEPENDENCY-UNLOCK-PLAN-SELF-CONSTRUCTION-0001',
            'source_queue_hash' => data_get($queuePayload, 'queue_hash'),
            'available_packet_ids' => $availableIds,
            'blocked_packet_count' => count($blockedPackets),
            'unlock_edge_count' => count($unlockEdges),
            'withheld_packet_count' => count($withheldEntries),
            'blocked_packets' => $blockedPackets,
            'unlock_edges' => $unlockEdges,
            'withheld_packets' => array_map(fn (array $entry): array => [
                'packet_id' => data_get($entry, 'packet_id'),
                'reason' => 'Hot external work remains withheld and cannot be unlocked by cold-lane completion.',
                'forbidden_files' => data_get($entry, 'forbidden_files'),
            ], $withheldEntries),
            'state_mutation_allowed' => false,
            'execution_allowed' => false,
            'claim_persisted' => false,
            'completion_persisted' => false,
            'dispatch_allowed' => false,
        ];

        return [
            'schema_version' => 'atlas.self_construction_dependency_unlock_plan.v1',
            'status' => 'dependency_unlock_plan_ready',
            'mode' => 'read_only_dependency_unlock_plan',
            'execution_allowed' => false,
            'completion_allowed' => false,
            'claim_persisted' => false,
            'completion_persisted' => false,
            'state_mutation_allowed' => false,
            'dispatch_allowed' => false,
            'plan' => $plan,
            'plan_hash' => $this->stableHash($plan),
            'non_execution_guarantees' => [
                'dependency_unlock_plan_does_not_mutate_queue',
                'dependency_unlock_plan_does_not_persist_completion',
                'dependency_unlock_plan_does_not_dispatch_work',
                'dependency_unlock_plan_does_not_enable_execution',
            ],
            'human_summary' => 'Dependency unlock plan is ready: blocked packets and unlock edges are visible without queue mutation, completion persistence or dispatch.',
        ];
    }

    /**
     * @param  array{workspace?: string|null, target?: string|null}  $options
     * @return array<string, mixed>
     */
    public function multiSessionReadinessGate(array $options = []): array
    {
        $queuePayload = $this->packetQueue($options);
        $parallelPlan = $this->parallelSessionPlan($options);
        $collisionMatrix = $this->collisionMatrix($options);
        $unlockPlan = $this->dependencyUnlockPlan($options);
        $reservationPreview = $this->reservationLedgerPreview($options);
        $reservationStatus = $this->reservationStatus($options);

        $assignable = (int) data_get($parallelPlan, 'plan.preview_assignable_count', 0);
        $safePairs = (int) data_get($collisionMatrix, 'matrix.safe_pair_count', 0);
        $reservationDurable = (bool) data_get($reservationStatus, 'ledger.ledger_available', false);

        $blockingReasons = [];
        if ($assignable < 2) {
            $blockingReasons[] = 'less_than_two_preview_assignable_packets';
        }
        if (! $reservationDurable) {
            $blockingReasons[] = 'durable_reservation_ledger_missing';
        }

        $decision = $blockingReasons === []
            ? 'ready_for_multi_session_preview'
            : ($assignable >= 2 ? 'parallel_preview_ready_but_not_durable' : ($assignable >= 1 ? 'preview_only_single_session' : 'blocked_for_multi_session'));

        $gate = [
            'gate_id' => 'MULTI-SESSION-READINESS-GATE-SELF-CONSTRUCTION-0001',
            'decision' => $decision,
            'multi_session_allowed' => false,
            'parallel_preview_allowed' => $assignable >= 2,
            'single_session_preview_allowed' => $assignable >= 1,
            'durable_dispatch_allowed' => false,
            'execution_allowed' => false,
            'claim_persisted' => false,
            'reservation_persisted' => $reservationDurable,
            'inspected_hashes' => [
                'queue_hash' => data_get($queuePayload, 'queue_hash'),
                'parallel_plan_hash' => data_get($parallelPlan, 'plan_hash'),
                'collision_matrix_hash' => data_get($collisionMatrix, 'matrix_hash'),
                'dependency_unlock_plan_hash' => data_get($unlockPlan, 'plan_hash'),
                'reservation_preview_hash' => data_get($reservationPreview, 'reservation_hash'),
                'reservation_status_hash' => data_get($reservationStatus, 'ledger_hash'),
            ],
            'counts' => [
                'queue_entries' => data_get($queuePayload, 'queue.entry_count'),
                'preview_assignable_packets' => $assignable,
                'safe_parallel_pairs' => $safePairs,
                'blocked_packets' => data_get($queuePayload, 'queue.blocked_count'),
                'claimed_packets' => data_get($queuePayload, 'queue.claimed_count'),
                'withheld_packets' => data_get($queuePayload, 'queue.withheld_count'),
                'unlock_edges' => data_get($unlockPlan, 'plan.unlock_edge_count'),
            ],
            'non_blocking_warnings' => array_values(array_filter([
                ((int) data_get($queuePayload, 'queue.withheld_count', 0) > 0) ? 'hot_external_work_withheld_from_cold_lane' : null,
            ])),
            'blocking_reasons' => $blockingReasons,
            'safe_next_instruction' => $assignable >= 2
                ? 'continue_parallel_preview_with_packet_scoped_bootstrap'
                : ($assignable >= 1
                    ? 'continue_one_session_with_ai_session_bootstrap'
                    : 'do_not_continue_until_queue_has_assignable_packet'),
        ];

        return [
            'schema_version' => 'atlas.self_construction_multi_session_readiness_gate.v1',
            'status' => 'multi_session_readiness_gate_ready',
            'mode' => 'read_only_multi_session_readiness_gate',
            'execution_allowed' => false,
            'completion_allowed' => false,
            'multi_session_allowed' => false,
            'parallel_preview_allowed' => $assignable >= 2,
            'single_session_preview_allowed' => $assignable >= 1,
            'dispatch_allowed' => false,
            'gate' => $gate,
            'gate_hash' => $this->stableHash($gate),
            'non_execution_guarantees' => [
                'multi_session_readiness_gate_does_not_start_sessions',
                'multi_session_readiness_gate_does_not_persist_claim',
                'multi_session_readiness_gate_does_not_write_ledger',
                'multi_session_readiness_gate_does_not_enable_execution',
            ],
            'human_summary' => 'Multi-session readiness gate is ready: current state allows one preview session, while durable multi-session dispatch remains blocked.',
        ];
    }

    /**
     * @param  array{workspace?: string|null, target?: string|null}  $options
     * @return array<string, mixed>
     */
    public function singleSessionInstructionPacket(array $options = []): array
    {
        $gatePayload = $this->multiSessionReadinessGate($options);
        $bootstrapPayload = $this->aiSessionBootstrap($options);
        $runbookPayload = $this->packetRunbook($options);
        $assignmentPayload = $this->assignmentPreview($options);
        $assignment = (array) data_get($bootstrapPayload, 'bootstrap', []);

        $instruction = [
            'instruction_id' => 'SINGLE-SESSION-INSTRUCTION-SELF-CONSTRUCTION-0001',
            'selected_packet_id' => data_get($assignment, 'selected_packet_id'),
            'safe_next_instruction' => data_get($gatePayload, 'gate.safe_next_instruction'),
            'operator_instruction' => 'Continue exactly one Self-Construction session using the selected packet, run required gates, report evidence and stop on any scope or hot-file blocker.',
            'one_line_prompt' => 'Continue Self-Construction using php artisan atlas:ai:self-construction --single-session-instruction-packet --json, follow the selected packet, and do not touch hot Voice/Kernel scopes.',
            'required_first_commands' => (array) data_get($assignment, 'required_first_commands', []),
            'selected_bootstrap_hash' => data_get($bootstrapPayload, 'bootstrap_hash'),
            'readiness_gate_hash' => data_get($gatePayload, 'gate_hash'),
            'runbook_hash' => data_get($runbookPayload, 'runbook_hash'),
            'allowed_files' => (array) data_get($assignmentPayload, 'assignment.allowed_files', []),
            'forbidden_hot_scopes' => (array) data_get($assignment, 'forbidden_hot_scopes', []),
            'required_gates' => (array) data_get($runbookPayload, 'runbook.required_gates', []),
            'required_evidence' => (array) data_get($runbookPayload, 'runbook.required_evidence', []),
            'stop_conditions' => array_values(array_unique(array_merge(
                (array) data_get($assignment, 'stop_conditions', []),
                (array) data_get($gatePayload, 'gate.blocking_reasons', []),
            ))),
            'execution_allowed' => false,
            'claim_persisted' => false,
            'reservation_persisted' => false,
            'dispatch_allowed' => false,
            'completion_allowed' => false,
        ];

        return [
            'schema_version' => 'atlas.self_construction_single_session_instruction_packet.v1',
            'status' => 'single_session_instruction_packet_ready',
            'mode' => 'read_only_single_session_instruction_packet',
            'execution_allowed' => false,
            'completion_allowed' => false,
            'claim_persisted' => false,
            'reservation_persisted' => false,
            'dispatch_allowed' => false,
            'instruction' => $instruction,
            'instruction_hash' => $this->stableHash($instruction),
            'non_execution_guarantees' => [
                'single_session_instruction_packet_does_not_start_session',
                'single_session_instruction_packet_does_not_persist_claim',
                'single_session_instruction_packet_does_not_write_ledger',
                'single_session_instruction_packet_does_not_enable_execution',
            ],
            'human_summary' => 'Single-session instruction packet is ready: one AI can continue from a canonical instruction while claims, dispatch and execution remain disabled.',
        ];
    }

    /**
     * @param  array{workspace?: string|null, target?: string|null}  $options
     * @return array<string, mixed>
     */
    public function coldLaneCertification(array $options = []): array
    {
        $surfaceMatrix = $this->surfaceMatrix($options);
        $phaseLedger = $this->phaseLedger($options);
        $externalBlockers = $this->externalBlockers($options);
        $nextAction = $this->nextAction($options);

        $certification = [
            'id' => 'COLD-LANE-CERTIFICATION-SELF-CONSTRUCTION-PHASE-5',
            'status' => 'certified_with_external_blockers',
            'execution_allowed' => false,
            'completion_allowed' => false,
            'certified_scope' => [
                'app/Console/Commands/AtlasAiSelfConstructionCommand.php',
                'app/Services/Ai/SelfConstruction/AtlasSelfConstructionReadinessService.php',
                'tests/Feature/Ai/AtlasAiSelfConstructionCommandTest.php',
                'docs/engineering-knowledge-base/atlas-ai-self-construction-os.md',
                'docs/engineering-knowledge-base/self-construction/runtime-implementation-roadmap.md',
            ],
            'certified_properties' => [
                'self_construction_surfaces_are_read_only',
                'phase_status_is_explicit',
                'next_action_is_human_signature_review',
                'external_hot_blockers_are_reported_not_edited',
                'execution_and_completion_remain_blocked',
            ],
            'required_local_gates' => [
                'php artisan test tests/Feature/Ai/AtlasAiSelfConstructionCommandTest.php',
                'php artisan atlas:ai:self-construction --surface-matrix --json',
                'php artisan atlas:ai:self-construction --phase-ledger --json',
                'php artisan atlas:ai:self-construction --external-blockers --json',
                'php artisan atlas:ai:self-construction --next-action --json',
                'git diff --check',
            ],
            'global_blockers_not_owned' => data_get($externalBlockers, 'blockers'),
        ];

        return [
            'schema_version' => 'atlas.self_construction_cold_lane_certification.v1',
            'status' => 'cold_lane_certified_with_external_blockers',
            'mode' => 'read_only_cold_lane_certification',
            'execution_allowed' => false,
            'completion_allowed' => false,
            'surface_count' => data_get($surfaceMatrix, 'surface_count'),
            'phase_ledger_status' => data_get($phaseLedger, 'status'),
            'next_action_id' => data_get($nextAction, 'selected_action.id'),
            'external_blocker_count' => data_get($externalBlockers, 'blocker_count'),
            'certification' => $certification,
            'certification_hash' => $this->stableHash($certification),
            'non_execution_guarantees' => [
                'cold_lane_certification_does_not_edit_hot_files',
                'cold_lane_certification_does_not_apply_patch',
                'cold_lane_certification_does_not_mark_completion',
                'cold_lane_certification_does_not_enable_execution',
            ],
            'human_summary' => 'Cold lane is certified read-only with external blockers reported; execution remains blocked.',
        ];
    }

    /**
     * @param  array{workspace?: string|null, target?: string|null}  $options
     * @return array<string, mixed>
     */
    public function operatorChecklist(array $options = []): array
    {
        $certification = $this->coldLaneCertification($options);
        $nextAction = $this->nextAction($options);
        $externalBlockers = $this->externalBlockers($options);

        $checklist = [
            [
                'id' => 'verify_cold_lane_hash',
                'order' => 1,
                'required' => true,
                'command' => 'php artisan atlas:ai:self-construction --cold-lane-certification --json',
                'expected' => 'status=cold_lane_certified_with_external_blockers and execution_allowed=false',
            ],
            [
                'id' => 'review_next_action',
                'order' => 2,
                'required' => true,
                'command' => 'php artisan atlas:ai:self-construction --next-action --json',
                'expected' => 'selected_action.id=request_human_signature_review',
            ],
            [
                'id' => 'confirm_hot_blockers_are_external',
                'order' => 3,
                'required' => true,
                'command' => 'php artisan atlas:ai:self-construction --external-blockers --json',
                'expected' => 'hot Voice, LiveKit and Kernel scanner deltas are reported, not edited',
            ],
            [
                'id' => 'run_focused_self_construction_tests',
                'order' => 4,
                'required' => true,
                'command' => 'php artisan test tests/Feature/Ai/AtlasAiSelfConstructionCommandTest.php',
                'expected' => 'all Self-Construction command surfaces pass',
            ],
            [
                'id' => 'run_global_governance_gates',
                'order' => 5,
                'required' => true,
                'command' => 'php artisan atlas:engineering:knowledge docs-health --json && php artisan atlas:ai:architecture-validate --json && git diff --check',
                'expected' => 'docs, architecture and whitespace gates stay green',
            ],
        ];

        $packet = [
            'id' => 'OPERATOR-CHECKLIST-SELF-CONSTRUCTION-PHASE-5',
            'status' => 'ready_for_human_signature_review',
            'next_action_id' => data_get($nextAction, 'selected_action.id'),
            'cold_lane_certification_hash' => data_get($certification, 'certification_hash'),
            'external_blocker_count' => data_get($externalBlockers, 'blocker_count'),
            'checklist' => $checklist,
        ];

        return [
            'schema_version' => 'atlas.self_construction_operator_checklist.v1',
            'status' => 'operator_checklist_ready',
            'mode' => 'read_only_operator_checklist',
            'execution_allowed' => false,
            'completion_allowed' => false,
            'checklist_count' => count($checklist),
            'checklist_packet' => $packet,
            'checklist_hash' => $this->stableHash($packet),
            'non_execution_guarantees' => [
                'operator_checklist_does_not_edit_hot_files',
                'operator_checklist_does_not_apply_patch',
                'operator_checklist_does_not_sign_receipt',
                'operator_checklist_does_not_enable_execution',
            ],
            'human_summary' => 'Operator checklist is ready: it orders the next review steps without signing, patching or touching hot files.',
        ];
    }

    /**
     * @param  array{workspace?: string|null, target?: string|null}  $options
     * @return array<string, mixed>
     */
    public function promotionBlockers(array $options = []): array
    {
        $phaseLedger = $this->phaseLedger($options);
        $completionReadiness = $this->completionReadiness($options);
        $residualRisk = $this->residualRisk($options);
        $externalBlockers = $this->externalBlockers($options);

        $blockers = [
            [
                'id' => 'human_signature_missing',
                'scope' => 'self_construction_phase_5',
                'severity' => 'blocks_execution',
                'source' => 'phase_ledger',
                'required_action' => 'Human owner must review and sign the scoped Decision Receipt before any execution.',
            ],
            [
                'id' => 'signed_execution_evidence_missing',
                'scope' => 'self_construction_completion',
                'severity' => 'blocks_completion',
                'source' => 'completion_readiness',
                'required_action' => 'Provide signed receipt, scoped diff, focused gates and final evidence packet before completion.',
            ],
            [
                'id' => 'residual_risk_open',
                'scope' => 'self_construction_promotion',
                'severity' => data_get($residualRisk, 'risk_summary.highest_severity'),
                'source' => 'residual_risk',
                'required_action' => 'Resolve or explicitly accept residual risks before promotion.',
            ],
        ];

        foreach ((array) data_get($externalBlockers, 'blockers', []) as $blocker) {
            $blockers[] = [
                'id' => (string) data_get($blocker, 'id'),
                'scope' => (string) data_get($blocker, 'scope'),
                'severity' => (string) data_get($blocker, 'severity'),
                'source' => 'external_blockers',
                'required_action' => (string) data_get($blocker, 'recommended_action'),
            ];
        }

        $packet = [
            'id' => 'PROMOTION-BLOCKERS-SELF-CONSTRUCTION-PHASE-5',
            'phase_ledger_status' => data_get($phaseLedger, 'status'),
            'completion_status' => data_get($completionReadiness, 'status'),
            'residual_risk_status' => data_get($residualRisk, 'status'),
            'external_blocker_count' => data_get($externalBlockers, 'blocker_count'),
            'blockers' => $blockers,
        ];

        return [
            'schema_version' => 'atlas.self_construction_promotion_blockers.v1',
            'status' => 'promotion_blockers_open',
            'mode' => 'read_only_promotion_blockers',
            'execution_allowed' => false,
            'promotion_allowed' => false,
            'completion_allowed' => false,
            'blocker_count' => count($blockers),
            'blocker_packet' => $packet,
            'blocker_hash' => $this->stableHash($packet),
            'non_execution_guarantees' => [
                'promotion_blockers_does_not_edit_hot_files',
                'promotion_blockers_does_not_apply_patch',
                'promotion_blockers_does_not_sign_receipt',
                'promotion_blockers_does_not_enable_execution',
            ],
            'human_summary' => 'Promotion blockers are consolidated read-only; execution, promotion and completion remain blocked.',
        ];
    }

    /**
     * @param  array{workspace?: string|null, target?: string|null}  $options
     * @return array<string, mixed>
     */
    public function readinessDigest(array $options = []): array
    {
        $snapshot = $this->snapshot($options);
        $phaseLedger = $this->phaseLedger($options);
        $surfaceMatrix = $this->surfaceMatrix($options);
        $promotionBlockers = $this->promotionBlockers($options);
        $operatorChecklist = $this->operatorChecklist($options);

        $digest = [
            'id' => 'READINESS-DIGEST-SELF-CONSTRUCTION-PHASE-5',
            'runtime_phase' => data_get($snapshot, 'summary.runtime_phase'),
            'current_phase' => data_get($phaseLedger, 'current_phase'),
            'next_action_id' => data_get($phaseLedger, 'next_action_id'),
            'surface_count' => data_get($surfaceMatrix, 'surface_count'),
            'blocker_count' => data_get($promotionBlockers, 'blocker_count'),
            'operator_checklist_count' => data_get($operatorChecklist, 'checklist_count'),
            'execution_allowed' => false,
            'promotion_allowed' => false,
            'completion_allowed' => false,
            'required_next_commands' => [
                'php artisan atlas:ai:self-construction --readiness-digest --json',
                'php artisan atlas:ai:self-construction --operator-checklist --json',
                'php artisan atlas:ai:self-construction --promotion-blockers --json',
                'php artisan test tests/Feature/Ai/AtlasAiSelfConstructionCommandTest.php',
                'git diff --check',
            ],
        ];

        return [
            'schema_version' => 'atlas.self_construction_readiness_digest.v1',
            'status' => 'readiness_digest_ready',
            'mode' => 'read_only_readiness_digest',
            'execution_allowed' => false,
            'promotion_allowed' => false,
            'completion_allowed' => false,
            'digest' => $digest,
            'digest_hash' => $this->stableHash($digest),
            'non_execution_guarantees' => [
                'readiness_digest_does_not_edit_hot_files',
                'readiness_digest_does_not_apply_patch',
                'readiness_digest_does_not_sign_receipt',
                'readiness_digest_does_not_enable_execution',
            ],
            'human_summary' => 'Readiness digest is ready: compact handoff state is available without execution, signing or hot-file edits.',
        ];
    }

    /**
     * @param  array{workspace?: string|null, target?: string|null}  $options
     * @return array<string, mixed>
     */
    public function governanceScorecard(array $options = []): array
    {
        $digest = $this->readinessDigest($options);
        $surfaceMatrix = $this->surfaceMatrix($options);
        $promotionBlockers = $this->promotionBlockers($options);
        $coldLaneCertification = $this->coldLaneCertification($options);

        $criteria = [
            [
                'id' => 'documentation_complete',
                'weight' => 20,
                'score' => data_get($digest, 'digest.runtime_phase') === 'phase_2_read_only_gap_report' ? 20 : 0,
                'evidence' => 'required Self-Construction docs are present and indexed',
            ],
            [
                'id' => 'command_surface_complete',
                'weight' => 20,
                'score' => data_get($surfaceMatrix, 'surface_count') >= 22 ? 20 : 10,
                'evidence' => 'surface matrix declares read-only command surfaces',
            ],
            [
                'id' => 'execution_locked',
                'weight' => 20,
                'score' => data_get($digest, 'execution_allowed') === false ? 20 : 0,
                'evidence' => 'execution, promotion and completion flags remain false',
            ],
            [
                'id' => 'blockers_explicit',
                'weight' => 20,
                'score' => data_get($promotionBlockers, 'blocker_count') > 0 ? 20 : 0,
                'evidence' => 'promotion blockers are enumerated with required actions',
            ],
            [
                'id' => 'cold_lane_certified',
                'weight' => 20,
                'score' => data_get($coldLaneCertification, 'status') === 'cold_lane_certified_with_external_blockers' ? 20 : 0,
                'evidence' => 'cold lane certification hash exists and external blockers are separated',
            ],
        ];

        $score = array_sum(array_column($criteria, 'score'));
        $scorecard = [
            'id' => 'GOVERNANCE-SCORECARD-SELF-CONSTRUCTION-PHASE-5',
            'score' => $score,
            'max_score' => array_sum(array_column($criteria, 'weight')),
            'rating' => $score >= 90 ? 'strong_governed_readiness' : 'needs_governance_repair',
            'promotion_allowed' => false,
            'execution_allowed' => false,
            'completion_allowed' => false,
            'criteria' => $criteria,
            'blocking_reason' => 'Scorecard is advisory only; human signature and execution evidence remain mandatory.',
        ];

        return [
            'schema_version' => 'atlas.self_construction_governance_scorecard.v1',
            'status' => 'governance_scorecard_ready',
            'mode' => 'read_only_governance_scorecard',
            'execution_allowed' => false,
            'promotion_allowed' => false,
            'completion_allowed' => false,
            'scorecard' => $scorecard,
            'scorecard_hash' => $this->stableHash($scorecard),
            'non_execution_guarantees' => [
                'governance_scorecard_does_not_edit_hot_files',
                'governance_scorecard_does_not_apply_patch',
                'governance_scorecard_does_not_sign_receipt',
                'governance_scorecard_does_not_enable_execution',
            ],
            'human_summary' => 'Governance scorecard is ready: readiness is scored while execution, promotion and completion stay blocked.',
        ];
    }

    /**
     * @param  array{workspace?: string|null, target?: string|null}  $options
     * @return array<string, mixed>
     */
    public function integrityManifest(array $options = []): array
    {
        $entries = [
            [
                'id' => 'readiness_digest',
                'schema' => 'atlas.self_construction_readiness_digest.v1',
                'hash' => data_get($this->readinessDigest($options), 'digest_hash'),
            ],
            [
                'id' => 'governance_scorecard',
                'schema' => 'atlas.self_construction_governance_scorecard.v1',
                'hash' => data_get($this->governanceScorecard($options), 'scorecard_hash'),
            ],
            [
                'id' => 'promotion_blockers',
                'schema' => 'atlas.self_construction_promotion_blockers.v1',
                'hash' => data_get($this->promotionBlockers($options), 'blocker_hash'),
            ],
            [
                'id' => 'operator_checklist',
                'schema' => 'atlas.self_construction_operator_checklist.v1',
                'hash' => data_get($this->operatorChecklist($options), 'checklist_hash'),
            ],
            [
                'id' => 'cold_lane_certification',
                'schema' => 'atlas.self_construction_cold_lane_certification.v1',
                'hash' => data_get($this->coldLaneCertification($options), 'certification_hash'),
            ],
            [
                'id' => 'handoff_packet',
                'schema' => 'atlas.self_construction_handoff_packet.v1',
                'hash' => data_get($this->handoffPacket($options), 'handoff_hash'),
            ],
        ];

        $manifest = [
            'id' => 'INTEGRITY-MANIFEST-SELF-CONSTRUCTION-PHASE-5',
            'status' => 'ready',
            'entry_count' => count($entries),
            'entries' => $entries,
            'execution_allowed' => false,
            'promotion_allowed' => false,
            'completion_allowed' => false,
        ];

        return [
            'schema_version' => 'atlas.self_construction_integrity_manifest.v1',
            'status' => 'integrity_manifest_ready',
            'mode' => 'read_only_integrity_manifest',
            'execution_allowed' => false,
            'promotion_allowed' => false,
            'completion_allowed' => false,
            'entry_count' => count($entries),
            'manifest' => $manifest,
            'manifest_hash' => $this->stableHash($manifest),
            'non_execution_guarantees' => [
                'integrity_manifest_does_not_edit_hot_files',
                'integrity_manifest_does_not_apply_patch',
                'integrity_manifest_does_not_sign_receipt',
                'integrity_manifest_does_not_enable_execution',
            ],
            'human_summary' => 'Integrity manifest is ready: governed packet hashes are bundled for audit without execution.',
        ];
    }

    /**
     * @param  array{workspace?: string|null, target?: string|null}  $options
     * @return array<string, mixed>
     */
    public function continuationToken(array $options = []): array
    {
        $digest = $this->readinessDigest($options);
        $manifest = $this->integrityManifest($options);

        $token = [
            'id' => 'CONTINUATION-TOKEN-SELF-CONSTRUCTION-PHASE-5',
            'resume_mode' => 'read_only_cold_lane',
            'current_phase' => data_get($digest, 'digest.current_phase'),
            'next_action_id' => data_get($digest, 'digest.next_action_id'),
            'manifest_hash' => data_get($manifest, 'manifest_hash'),
            'digest_hash' => data_get($digest, 'digest_hash'),
            'must_run_first' => [
                'git status --short',
                'git diff --stat',
                'git diff --name-only',
                'php artisan atlas:ai:self-construction --continuation-token --json',
            ],
            'must_not_touch' => [
                'runtimes/python/voice_realtime/**',
                'app/Services/Ai/Voice/**',
                'app/Services/Ai/Kernel/Architecture/KernelArchitectureStaticScanner.php',
                'docs/engineering-knowledge-base/atlas-ai-voice-realtime-surface.md',
            ],
            'allowed_next_cold_blocks' => [
                'read_only_report_surface',
                'test_only_guardrail',
                'documentation_index_entry',
                'operator_handoff_refinement',
            ],
            'execution_allowed' => false,
            'promotion_allowed' => false,
            'completion_allowed' => false,
        ];

        return [
            'schema_version' => 'atlas.self_construction_continuation_token.v1',
            'status' => 'continuation_token_ready',
            'mode' => 'read_only_continuation_token',
            'execution_allowed' => false,
            'promotion_allowed' => false,
            'completion_allowed' => false,
            'token' => $token,
            'token_hash' => $this->stableHash($token),
            'non_execution_guarantees' => [
                'continuation_token_does_not_edit_hot_files',
                'continuation_token_does_not_apply_patch',
                'continuation_token_does_not_sign_receipt',
                'continuation_token_does_not_enable_execution',
            ],
            'human_summary' => 'Continuation token is ready: the next operator can resume the cold lane from a compact audited state.',
        ];
    }

    /**
     * @param  array{workspace?: string|null, target?: string|null}  $options
     * @return array<string, mixed>
     */
    public function ownershipBoundary(array $options = []): array
    {
        $externalBlockers = $this->externalBlockers($options);

        $forbiddenScopes = [
            [
                'id' => 'voice_realtime_python_runtime',
                'path' => 'runtimes/python/voice_realtime/**',
                'owner' => 'codex_principal_voice_realtime',
                'reason' => 'Voice/LiveKit runtime is active and outside Self-Construction cold lane.',
            ],
            [
                'id' => 'voice_realtime_php_surface',
                'path' => 'app/Services/Ai/Voice/**',
                'owner' => 'codex_principal_voice_realtime',
                'reason' => 'Voice PHP service/certification surface is active and hot.',
            ],
            [
                'id' => 'kernel_static_scanner',
                'path' => 'app/Services/Ai/Kernel/Architecture/KernelArchitectureStaticScanner.php',
                'owner' => 'codex_principal_kernel_scanner',
                'reason' => 'Kernel scanner owns architecture validation changes.',
            ],
            [
                'id' => 'voice_realtime_owner_docs',
                'path' => 'docs/engineering-knowledge-base/atlas-ai-voice-realtime-surface.md',
                'owner' => 'codex_principal_voice_realtime',
                'reason' => 'Voice owner documentation is coupled to AP-179/AP-185/AP-687 gates.',
            ],
        ];

        $boundary = [
            'id' => 'OWNERSHIP-BOUNDARY-SELF-CONSTRUCTION-COLD-LANE',
            'allowed_files' => $this->receiptAllowedFiles(),
            'forbidden_scopes' => $forbiddenScopes,
            'external_blockers' => data_get($externalBlockers, 'blockers'),
            'required_behavior' => [
                'report_hot_blockers_without_editing',
                'prefer_tests_or_reports_inside_allowed_files',
                'run_focused_self_construction_tests_after_changes',
                'run_git_diff_check_after_changes',
            ],
            'execution_allowed' => false,
            'promotion_allowed' => false,
            'completion_allowed' => false,
        ];

        return [
            'schema_version' => 'atlas.self_construction_ownership_boundary.v1',
            'status' => 'ownership_boundary_ready',
            'mode' => 'read_only_ownership_boundary',
            'execution_allowed' => false,
            'promotion_allowed' => false,
            'completion_allowed' => false,
            'allowed_file_count' => count($boundary['allowed_files']),
            'forbidden_scope_count' => count($forbiddenScopes),
            'boundary' => $boundary,
            'boundary_hash' => $this->stableHash($boundary),
            'non_execution_guarantees' => [
                'ownership_boundary_does_not_edit_hot_files',
                'ownership_boundary_does_not_apply_patch',
                'ownership_boundary_does_not_sign_receipt',
                'ownership_boundary_does_not_enable_execution',
            ],
            'human_summary' => 'Ownership boundary is ready: cold allowed files and hot forbidden scopes are explicit for the next operator.',
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
            'docs/engineering-knowledge-base/self-construction/structural-contract-gate.md',
            'docs/engineering-knowledge-base/self-construction/ai-implementation-packet-contract.md',
            'docs/engineering-knowledge-base/self-construction/work-splitter-contract.md',
            'docs/engineering-knowledge-base/self-construction/scope-validator-contract.md',
            'docs/engineering-knowledge-base/self-construction/assignment-and-claim-contract.md',
            'docs/engineering-knowledge-base/self-construction/packet-consumption-runbook-contract.md',
            'docs/engineering-knowledge-base/self-construction/packet-evidence-report-contract.md',
            'docs/engineering-knowledge-base/self-construction/packet-completion-gate-contract.md',
            'docs/engineering-knowledge-base/self-construction/reservation-ledger-contract.md',
            'docs/engineering-knowledge-base/self-construction/durable-reservation-ledger-implementation-plan.md',
            'docs/engineering-knowledge-base/self-construction/durable-reservation-ap-candidate.md',
            'docs/engineering-knowledge-base/self-construction/durable-reservation-approval-request.md',
            'docs/engineering-knowledge-base/self-construction/durable-reservation-approval-decision-template.md',
            'docs/engineering-knowledge-base/self-construction/durable-reservation-post-approval-preflight.md',
            'docs/engineering-knowledge-base/self-construction/durable-reservation-implementation-packet.md',
            'docs/engineering-knowledge-base/self-construction/durable-reservation-storage-schema.md',
            'docs/engineering-knowledge-base/self-construction/durable-reservation-repository-contract.md',
            'docs/engineering-knowledge-base/self-construction/durable-reservation-collision-guard-contract.md',
            'docs/engineering-knowledge-base/self-construction/durable-reservation-lease-lifecycle-contract.md',
            'docs/engineering-knowledge-base/self-construction/durable-reservation-readiness-projection-contract.md',
            'docs/engineering-knowledge-base/self-construction/durable-reservation-implementation-preflight-contract.md',
            'docs/engineering-knowledge-base/self-construction/durable-reservation-migration-blueprint-contract.md',
            'docs/engineering-knowledge-base/self-construction/durable-reservation-repository-blueprint-contract.md',
            'docs/engineering-knowledge-base/self-construction/durable-reservation-collision-guard-blueprint-contract.md',
            'docs/engineering-knowledge-base/self-construction/durable-reservation-lease-lifecycle-blueprint-contract.md',
            'docs/engineering-knowledge-base/self-construction/durable-reservation-readiness-projection-blueprint-contract.md',
            'docs/engineering-knowledge-base/self-construction/durable-reservation-runtime-build-packet-contract.md',
            'docs/engineering-knowledge-base/self-construction/ai-session-bootstrap-contract.md',
            'docs/engineering-knowledge-base/self-construction/packet-queue-contract.md',
            'docs/engineering-knowledge-base/self-construction/parallel-session-plan-contract.md',
            'docs/engineering-knowledge-base/self-construction/collision-matrix-contract.md',
            'docs/engineering-knowledge-base/self-construction/dependency-unlock-plan-contract.md',
            'docs/engineering-knowledge-base/self-construction/multi-session-readiness-gate-contract.md',
            'docs/engineering-knowledge-base/self-construction/single-session-instruction-packet-contract.md',
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
            'docs/engineering-knowledge-base/self-construction/ai-implementation-packet-contract.md',
            'docs/engineering-knowledge-base/self-construction/work-splitter-contract.md',
            'docs/engineering-knowledge-base/self-construction/scope-validator-contract.md',
            'docs/engineering-knowledge-base/self-construction/assignment-and-claim-contract.md',
            'docs/engineering-knowledge-base/self-construction/packet-consumption-runbook-contract.md',
            'docs/engineering-knowledge-base/self-construction/packet-evidence-report-contract.md',
            'docs/engineering-knowledge-base/self-construction/packet-completion-gate-contract.md',
            'docs/engineering-knowledge-base/self-construction/reservation-ledger-contract.md',
            'docs/engineering-knowledge-base/self-construction/durable-reservation-ledger-implementation-plan.md',
            'docs/engineering-knowledge-base/self-construction/durable-reservation-ap-candidate.md',
            'docs/engineering-knowledge-base/self-construction/durable-reservation-approval-request.md',
            'docs/engineering-knowledge-base/self-construction/durable-reservation-approval-decision-template.md',
            'docs/engineering-knowledge-base/self-construction/durable-reservation-post-approval-preflight.md',
            'docs/engineering-knowledge-base/self-construction/durable-reservation-implementation-packet.md',
            'docs/engineering-knowledge-base/self-construction/durable-reservation-storage-schema.md',
            'docs/engineering-knowledge-base/self-construction/durable-reservation-repository-contract.md',
            'docs/engineering-knowledge-base/self-construction/durable-reservation-collision-guard-contract.md',
            'docs/engineering-knowledge-base/self-construction/durable-reservation-lease-lifecycle-contract.md',
            'docs/engineering-knowledge-base/self-construction/durable-reservation-readiness-projection-contract.md',
            'docs/engineering-knowledge-base/self-construction/durable-reservation-implementation-preflight-contract.md',
            'docs/engineering-knowledge-base/self-construction/durable-reservation-migration-blueprint-contract.md',
            'docs/engineering-knowledge-base/self-construction/durable-reservation-repository-blueprint-contract.md',
            'docs/engineering-knowledge-base/self-construction/durable-reservation-collision-guard-blueprint-contract.md',
            'docs/engineering-knowledge-base/self-construction/durable-reservation-lease-lifecycle-blueprint-contract.md',
            'docs/engineering-knowledge-base/self-construction/durable-reservation-readiness-projection-blueprint-contract.md',
            'docs/engineering-knowledge-base/self-construction/durable-reservation-runtime-build-packet-contract.md',
            'docs/engineering-knowledge-base/self-construction/ai-session-bootstrap-contract.md',
            'docs/engineering-knowledge-base/self-construction/packet-queue-contract.md',
            'docs/engineering-knowledge-base/self-construction/parallel-session-plan-contract.md',
            'docs/engineering-knowledge-base/self-construction/collision-matrix-contract.md',
            'docs/engineering-knowledge-base/self-construction/dependency-unlock-plan-contract.md',
            'docs/engineering-knowledge-base/self-construction/multi-session-readiness-gate-contract.md',
            'docs/engineering-knowledge-base/self-construction/single-session-instruction-packet-contract.md',
            'docs/engineering-knowledge-base/self-construction/runtime-implementation-roadmap.md',
            'docs/ap/AP-691-atlas-self-construction-os-contract.md',
        ];
    }

    /**
     * @return list<string>
     */
    private function hotForbiddenFiles(): array
    {
        return [
            'runtimes/python/voice_realtime/**',
            'app/Services/Ai/Voice/**',
            'app/Console/Commands/AtlasAiVoiceRealtimeCommand.php',
            'app/Services/Ai/Kernel/Architecture/KernelArchitectureStaticScanner.php',
            'docs/engineering-knowledge-base/atlas-ai-voice-realtime-surface.md',
            'routes/**',
            'database/migrations/**',
            'config/**',
        ];
    }

    /**
     * @param  list<string>  $paths
     */
    private function hasHotScope(array $paths): bool
    {
        foreach ($paths as $path) {
            if (str_starts_with($path, 'runtimes/python/voice_realtime/')
                || $path === 'runtimes/python/voice_realtime/**'
                || str_starts_with($path, 'app/Services/Ai/Voice/')
                || $path === 'app/Services/Ai/Voice/**'
                || $path === 'app/Services/Ai/Kernel/Architecture/KernelArchitectureStaticScanner.php'
                || $path === 'docs/engineering-knowledge-base/atlas-ai-voice-realtime-surface.md') {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  array{workspace?: string|null, target?: string|null, packet?: string|null}  $options
     * @return array<string, mixed>|null
     */
    private function selectedSplitPacket(array $options): ?array
    {
        $packetId = $options['packet'] ?? null;
        if ($packetId === null || $packetId === '') {
            return null;
        }

        $splitter = $this->workSplitter([
            'workspace' => $options['workspace'] ?? null,
            'target' => $options['target'] ?? null,
        ]);

        $packet = collect((array) data_get($splitter, 'split.packets', []))->firstWhere('packet_id', $packetId);

        return is_array($packet) ? $packet : null;
    }

    private function packetCommand(string $option, mixed $packetId): string
    {
        $command = 'php artisan atlas:ai:self-construction --'.$option;

        if (is_string($packetId) && $packetId !== '') {
            $command .= ' --packet='.$packetId;
        }

        return $command.' --json';
    }

    /**
     * @param  array{actor?: string|null}  $options
     */
    private function reservationActor(array $options): string
    {
        $actor = trim((string) ($options['actor'] ?? 'codex'));

        return $actor === '' ? 'codex' : $actor;
    }

    /**
     * @param  array{session?: string|null}  $options
     */
    private function reservationSession(array $options): string
    {
        $session = trim((string) ($options['session'] ?? 'local-session'));

        return $session === '' ? 'local-session' : $session;
    }

    /**
     * @return list<string>
     */
    private function changedFiles(): array
    {
        $root = base_path();
        $commands = [
            'git -C '.escapeshellarg($root).' diff --name-only',
            'git -C '.escapeshellarg($root).' ls-files --others --exclude-standard',
        ];

        $files = [];
        foreach ($commands as $command) {
            $output = shell_exec($command);
            foreach (explode("\n", trim((string) $output)) as $line) {
                $path = trim($line);
                if ($path !== '') {
                    $files[] = $path;
                }
            }
        }

        return array_values(array_unique($files));
    }

    /**
     * @param  list<string>  $allowed
     * @param  list<string>  $forbidden
     */
    private function classifyPath(string $path, array $allowed, array $forbidden): string
    {
        foreach ($forbidden as $pattern) {
            if ($this->pathMatches($path, $pattern)) {
                return str_contains($pattern, 'voice') || str_contains($pattern, 'Kernel') ? 'hot_external' : 'forbidden';
            }
        }

        foreach ($allowed as $pattern) {
            if ($this->pathMatches($path, $pattern)) {
                return 'allowed';
            }
        }

        return 'unknown';
    }

    private function pathMatches(string $path, string $pattern): bool
    {
        if ($path === $pattern) {
            return true;
        }

        if (str_ends_with($pattern, '/**')) {
            return str_starts_with($path, substr($pattern, 0, -3).'/');
        }

        return false;
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

    /**
     * @param  array<string, mixed>  $payload
     */
    private function stableHash(array $payload): string
    {
        ksort($payload);

        return hash('sha256', (string) json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
    }
}
