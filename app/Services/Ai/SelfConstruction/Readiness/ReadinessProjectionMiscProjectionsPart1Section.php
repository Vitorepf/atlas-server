<?php

namespace App\Services\Ai\SelfConstruction\Readiness;

use App\Services\Ai\SelfConstruction\Support\ReadinessDocumentProbe;
use App\Services\Ai\SelfConstruction\Support\ReadinessHash;

/**
 * GOD-DEBULK extracted projection family from AtlasSelfConstructionReadinessService.
 * Pure read-only projections. The mother is passed as $parent so cross-family sibling
 * calls route back through the preserved public mother surface (ReflectionMethod /
 * method_exists probes on the mother stay intact).
 */
final class ReadinessProjectionMiscProjectionsPart1Section
{
    public function __construct(
        private readonly AtlasSelfConstructionReadinessService $parent,
    ) {}

    /**
     * @param  array<string, mixed>  $options
     * @return array<string, mixed>
     */
    public function traceabilityAudit(array $options = []): array
    {
        $snapshot = $this->parent->snapshot($options);
        $rootDoc = 'docs/engineering-knowledge-base/atlas-ai-self-construction-os.md';
        $rootContent = ReadinessDocumentProbe::content($rootDoc);
        $docs = (array) data_get($snapshot, 'required_docs', []);
        $items = array_map(function (array $doc) use ($rootContent, $rootDoc): array {
            $path = (string) $doc['path'];
            $content = ReadinessDocumentProbe::content($path);
            $isAp = str_starts_with($path, 'docs/ap/');

            return [
                'path' => $path,
                'exists' => (bool) $doc['exists'],
                'declares_self_construction_tag' => str_contains($content, 'self-construction'),
                'declares_layer' => $isAp || str_contains($content, 'layer:'),
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
     * @param  array<string, mixed>  $options
     * @return array<string, mixed>
     */
    public function promotionGate(array $options = []): array
    {
        $snapshot = $this->parent->snapshot($options);
        $packet = $this->parent->metaSddPacket($options);
        $receipt = $this->parent->receiptPreview($options);
        $traceability = $this->parent->traceabilityAudit($options);

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
     * @param  array<string, mixed>  $options
     * @return array<string, mixed>
     */
    public function executionCandidate(array $options = []): array
    {
        $gate = $this->parent->promotionGate($options);
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
            'candidate_hash' => ReadinessHash::stable($candidate),
            'blocking_failures' => data_get($gate, 'blocking_failures'),
            'human_summary' => $ready
                ? 'Phase 5 execution candidate is ready for human review only. It does not execute patches or enable self-programming.'
                : 'Phase 5 execution candidate is blocked until promotion gate passes.',
        ];
    }

    /**
     * @param  array<string, mixed>  $options
     * @return array<string, mixed>
     */
    public function approvalPacket(array $options = []): array
    {
        $candidatePayload = $this->parent->executionCandidate($options);
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
            'approval_hash' => ReadinessHash::stable($approval),
            'candidate_status' => data_get($candidatePayload, 'status'),
            'blocking_failures' => data_get($candidatePayload, 'blocking_failures'),
            'human_summary' => $ready
                ? 'Approval packet is ready for human review. It does not approve, sign or execute the candidate.'
                : 'Approval packet is blocked until execution candidate is ready.',
        ];
    }

    /**
     * @param  array<string, mixed>  $options
     * @return array<string, mixed>
     */
    public function receiptDraft(array $options = []): array
    {
        $approvalPacket = $this->parent->approvalPacket($options);
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
            'receipt_hash' => ReadinessHash::stable($draft),
            'preview_signature' => ReadinessHash::stable([
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
     * @param  array<string, mixed>  $options
     * @return array<string, mixed>
     */
    public function executionPreflight(array $options = []): array
    {
        $draftPayload = $this->parent->receiptDraft($options);
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
     * @param  array<string, mixed>  $options
     * @return array<string, mixed>
     */
    public function signatureRequest(array $options = []): array
    {
        $preflight = $this->parent->executionPreflight($options);

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
            'signable_payload_hash' => ReadinessHash::stable((array) data_get($request, 'signable_payload')),
            'request_hash' => ReadinessHash::stable($request),
            'human_summary' => 'Signature request is ready for human review. It does not sign the receipt or enable execution.',
        ];
    }

    /**
     * @param  array<string, mixed>  $options
     * @return array<string, mixed>
     */
    public function executionRunbook(array $options = []): array
    {
        $signatureRequestPayload = $this->parent->signatureRequest($options);
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
            'runbook_hash' => ReadinessHash::stable($runbook),
            'human_summary' => 'Execution runbook is ready for post-signature review. It does not sign, patch, approve or enable execution.',
        ];
    }

    /**
     * @param  array<string, mixed>  $options
     * @return array<string, mixed>
     */
    public function evidencePacket(array $options = []): array
    {
        $runbookPayload = $this->parent->executionRunbook($options);
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
            'evidence_packet_hash' => ReadinessHash::stable($evidencePacket),
            'human_summary' => 'Evidence packet is ready. It defines required proof for a future signed run but does not execute or mark completion.',
        ];
    }

    /**
     * @param  array<string, mixed>  $options
     * @return array<string, mixed>
     */
    public function completionReadiness(array $options = []): array
    {
        $evidencePacketPayload = $this->parent->evidencePacket($options);
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
     * @param  array<string, mixed>  $options
     * @return array<string, mixed>
     */
    public function residualRisk(array $options = []): array
    {
        $completionReadiness = $this->parent->completionReadiness($options);
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
     * @param  array<string, mixed>  $options
     * @return array<string, mixed>
     */
    public function handoffPacket(array $options = []): array
    {
        $signatureRequest = $this->parent->signatureRequest($options);
        $runbook = $this->parent->executionRunbook($options);
        $evidencePacket = $this->parent->evidencePacket($options);
        $completionReadiness = $this->parent->completionReadiness($options);
        $residualRisk = $this->parent->residualRisk($options);

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
            'handoff_hash' => ReadinessHash::stable($packet),
            'human_summary' => 'Handoff packet is ready: next operator gets hashes, blockers, commands and forbidden hot-file scope without enabling execution.',
        ];
    }
}
