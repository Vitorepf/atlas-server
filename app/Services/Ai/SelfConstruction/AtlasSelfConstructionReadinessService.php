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

    /**
     * @param  array<string, mixed>  $payload
     */
    private function stableHash(array $payload): string
    {
        ksort($payload);

        return hash('sha256', (string) json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
    }
}
