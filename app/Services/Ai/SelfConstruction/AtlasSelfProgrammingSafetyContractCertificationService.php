<?php

namespace App\Services\Ai\SelfConstruction;

use Carbon\CarbonImmutable;

final class AtlasSelfProgrammingSafetyContractCertificationService
{
    public const SCHEMA_VERSION = 'atlas.self_programming.safety_contract_certification.v1';

    public const MODE = 'read_only_self_programming_safety_contract_certification';

    private const SAFETY_CONTRACT_PATH = 'docs/engineering-knowledge-base/self-construction/self-programming-safety-contract.md';

    private const TERMINAL_LOOP_OPERATIONAL_PROOF_CANONICAL_BINDING_PATH = 'storage/app/private/atlas/self-construction/operator-submissions/terminal-loop-operational-proof-binding.json';

    public function __construct(
        private readonly AtlasSelfConstructionReadinessService $readiness,
    ) {}

    /**
     * @param  array<string, mixed>  $options
     * @return array<string, mixed>
     */
    public function certify(array $options = []): array
    {
        $terminalLoopProofJsonReference = $this->terminalLoopOperationalProofJsonReference($options);
        if (
            $terminalLoopProofJsonReference !== ''
            && ! isset($options['agent_control_plane_terminal_loop_operational_proof_json'])
        ) {
            $options['agent_control_plane_terminal_loop_operational_proof_json'] = $terminalLoopProofJsonReference;
        }
        $transition = (array) data_get(
            (new AtlasSelfConstructionFinalCompletionReadinessGateService($this->readiness))->evaluate($options),
            'self_programming_os_transition_readiness',
            [],
        );
        $finalizationGate = $this->finalizationGateStructuralProbe();
        $liveFinalizationGate = $terminalLoopProofJsonReference !== ''
            ? (new AtlasSelfConstructionCompletionFinalizationGateService($this->readiness))->evaluate($options)
            : [];
        $workerTaskEligibility = $this->workerTaskEligibilityProbe();
        $bootstrapPlan = $this->selfProgrammingBootstrapPlan(
            $transition,
            $liveFinalizationGate !== [] ? $liveFinalizationGate : $finalizationGate,
            $workerTaskEligibility,
            $liveFinalizationGate !== [] ? 'live_finalization_gate_with_operator_terminal_loop_binding' : 'structural_finalization_gate_probe',
        );
        $path = self::SAFETY_CONTRACT_PATH;
        $absolutePath = base_path($path);
        $content = is_file($absolutePath) ? (string) file_get_contents($absolutePath) : '';
        $hash = $content !== '' ? hash('sha256', $content) : '';
        $requiredSections = [
            'Required Preconditions',
            'Forbidden Mutations Without Human Gate',
            'Autonomy Shrink Rule',
            'Receipt Scope',
            'Patch Shape',
            'Safety Closeout',
        ];
        $sectionChecks = [];
        foreach ($requiredSections as $section) {
            $sectionChecks[$section] = str_contains($content, '## '.$section);
        }

        $requiredPhrases = [
            'self_programming_scope:',
            'max_files_changed',
            'rollback_strategy',
            'evidence_required',
            'provider/model selection policy',
            'auth/security boundary',
            'self-improvement auto-apply',
        ];
        $phraseChecks = [];
        foreach ($requiredPhrases as $phrase) {
            $phraseChecks[$phrase] = str_contains($content, $phrase);
        }

        $checks = [
            'safety_contract_exists' => $content !== '',
            'safety_contract_hash_valid' => preg_match('/^[a-f0-9]{64}$/', $hash) === 1,
            'required_sections_present' => ! in_array(false, $sectionChecks, true),
            'required_phrases_present' => ! in_array(false, $phraseChecks, true),
            'transition_readiness_available' => (string) data_get($transition, 'schema_version', '') === 'atlas.self_programming.transition_readiness.v1',
            'transition_depends_on_self_construction_completion' => in_array('self_construction_os_not_complete', (array) data_get($transition, 'blockers', []), true)
                || (bool) data_get($transition, 'self_construction_complete', false),
            'runtime_activation_disabled' => (bool) data_get($transition, 'runtime_activation_allowed', true) === false,
            'self_programming_disabled' => (bool) data_get($transition, 'self_programming_allowed', true) === false,
            'provider_call_disabled' => (bool) data_get($transition, 'provider_call_allowed', true) === false,
            'token_spend_disabled' => (bool) data_get($transition, 'token_spend_allowed', true) === false,
            'finalization_gate_available' => (string) data_get($finalizationGate, 'schema_version', '') === AtlasSelfConstructionCompletionFinalizationGateService::SCHEMA_VERSION,
            'finalization_gate_requires_terminal_loop_operational_proof' => (bool) data_get($finalizationGate, 'terminal_loop_operational_proof_required_before_completion_claim', false) === true
                && (string) data_get($finalizationGate, 'terminal_loop_operational_proof_expected_binding_schema', '') === 'atlas.self_construction.agent_control_plane_terminal_loop_operational_proof_audit_binding_packet.v1',
            'finalization_gate_terminal_loop_check_present' => array_key_exists('terminal_loop_green', (array) data_get($finalizationGate, 'checks', [])),
            'finalization_gate_terminal_loop_check_uses_binding_evidence' => (string) data_get($finalizationGate, 'checks.terminal_loop_green.evidence_source', '') === 'completion_audit.criteria.agent_control_plane_terminal_loop_certification_green_must_be_passed_with_terminal_loop_operational_proof_binding'
                && (string) data_get($finalizationGate, 'checks.terminal_loop_green.evidence.expected_binding_schema', '') === 'atlas.self_construction.agent_control_plane_terminal_loop_operational_proof_audit_binding_packet.v1',
            'finalization_gate_blocks_completion_and_next_stage_until_all_checks_pass' => (string) data_get($finalizationGate, 'status', '') === 'passed'
                || (
                    (bool) data_get($finalizationGate, 'completion_claim_allowed', true) === false
                    && (bool) data_get($finalizationGate, 'next_stage_allowed', true) === false
                    && count((array) data_get($finalizationGate, 'next_stage_blockers', [])) > 0
                ),
            'finalization_gate_operator_handoff_available' => (string) data_get($finalizationGate, 'completion_finalization_operator_handoff.schema_version', '') === 'atlas.self_construction.completion_finalization_operator_handoff.v1'
                && preg_match('/^[a-f0-9]{64}$/', (string) data_get($finalizationGate, 'completion_finalization_operator_handoff_hash', '')) === 1,
            'finalization_gate_operator_handoff_is_read_only' => (bool) data_get($finalizationGate, 'completion_finalization_operator_handoff.can_execute_from_handoff', true) === false
                && (bool) data_get($finalizationGate, 'completion_finalization_operator_handoff.can_persist_from_handoff', true) === false
                && (bool) data_get($finalizationGate, 'completion_finalization_operator_handoff.can_sign_from_handoff', true) === false
                && (bool) data_get($finalizationGate, 'completion_finalization_operator_handoff.can_call_provider_from_handoff', true) === false
                && (bool) data_get($finalizationGate, 'completion_finalization_operator_handoff.can_dispatch_from_handoff', true) === false
                && (bool) data_get($finalizationGate, 'completion_finalization_operator_handoff.can_promote_completion_from_handoff', true) === false,
            'finalization_gate_runtime_activation_disabled' => (bool) data_get($finalizationGate, 'execution_allowed', true) === false
                && (bool) data_get($finalizationGate, 'dispatch_allowed', true) === false
                && (bool) data_get($finalizationGate, 'provider_call_allowed', true) === false
                && (bool) data_get($finalizationGate, 'token_spend_allowed', true) === false
                && (bool) data_get($finalizationGate, 'self_programming_allowed', true) === false,
            'worker_task_eligibility_certification_available' => (string) data_get($workerTaskEligibility, 'status', '') === 'available',
            'worker_task_eligibility_checks_all_true' => (bool) data_get($workerTaskEligibility, 'checks_all_true', false) === true
                && (int) data_get($workerTaskEligibility, 'violation_count', 1) === 0,
            'worker_task_eligibility_blocks_operator_only_completion_blockers' => (array) data_get($workerTaskEligibility, 'operator_only_failed_criteria', []) === [
                'runtime_gap_matrix_all_runtime_y',
                'human_signed_os_complete_receipt_present',
                'end_to_end_real_provider_smoke_green',
            ]
                && (array) data_get($workerTaskEligibility, 'missing_operator_handoff_criteria', []) === []
                && (int) data_get($workerTaskEligibility, 'operator_handoff_seed_count', 0) >= 3,
            'self_programming_bootstrap_plan_available' => (string) data_get($bootstrapPlan, 'schema_version', '') === 'atlas.self_programming.bootstrap_plan.v1'
                && preg_match('/^[a-f0-9]{64}$/', (string) data_get($bootstrapPlan, 'bootstrap_plan_hash', '')) === 1,
            'self_programming_bootstrap_plan_blocks_until_self_construction_complete' => (bool) data_get($bootstrapPlan, 'self_construction_complete', true) === true
                || (
                    (string) data_get($bootstrapPlan, 'status', '') === 'blocked_until_self_construction_complete'
                    && in_array('self_construction_os_not_complete', (array) data_get($bootstrapPlan, 'blockers', []), true)
                ),
            'self_programming_bootstrap_plan_is_read_only' => (bool) data_get($bootstrapPlan, 'can_create_mutation_tasks', true) === false
                && (bool) data_get($bootstrapPlan, 'can_apply_patches', true) === false
                && (bool) data_get($bootstrapPlan, 'can_call_provider', true) === false
                && (bool) data_get($bootstrapPlan, 'can_spend_tokens', true) === false
                && (bool) data_get($bootstrapPlan, 'can_dispatch_work', true) === false
                && (bool) data_get($bootstrapPlan, 'can_enable_self_programming_runtime', true) === false,
        ];
        $failedCheckIds = array_keys(array_filter($checks, static fn (bool $passed): bool => ! $passed));
        $nextStageChecklist = $this->nextStagePromptToArtifactChecklist(
            transition: $transition,
            finalizationGate: $liveFinalizationGate !== [] ? $liveFinalizationGate : $finalizationGate,
            workerTaskEligibility: $workerTaskEligibility,
            bootstrapPlan: $bootstrapPlan,
            checks: $checks,
        );
        $payload = [
            'schema_version' => self::SCHEMA_VERSION,
            'mode' => self::MODE,
            'status' => $failedCheckIds === [] ? 'available' : 'blocked',
            'generated_at' => CarbonImmutable::now()->toIso8601String(),
            'safety_contract_path' => $path,
            'safety_contract_hash' => $hash,
            'terminal_loop_operational_proof_canonical_binding_path' => self::TERMINAL_LOOP_OPERATIONAL_PROOF_CANONICAL_BINDING_PATH,
            'transition_readiness_command_with_canonical_terminal_loop_binding' => $this->transitionReadinessCommandWithCanonicalTerminalLoopBinding(),
            'safety_contract_certification_command_with_canonical_terminal_loop_binding' => $this->safetyContractCertificationCommandWithCanonicalTerminalLoopBinding(),
            'completion_audit_command_with_canonical_terminal_loop_binding' => $this->completionAuditCommandWithCanonicalTerminalLoopBinding(),
            'transition_status' => (string) data_get($transition, 'status', ''),
            'transition_blockers' => (array) data_get($transition, 'blockers', []),
            'finalization_gate_status' => (string) data_get($finalizationGate, 'status', ''),
            'finalization_gate_evaluation_mode' => 'structural_probe_no_persistence_no_registry_side_effects',
            'finalization_gate_hash' => (string) data_get($finalizationGate, 'completion_finalization_gate_hash', ''),
            'finalization_gate_failed_check_ids' => (array) data_get($finalizationGate, 'failed_check_ids', []),
            'finalization_gate_terminal_loop_green' => (bool) data_get($finalizationGate, 'terminal_loop_green', false),
            'finalization_gate_terminal_loop_required_before_completion_claim' => (bool) data_get($finalizationGate, 'terminal_loop_operational_proof_required_before_completion_claim', false),
            'finalization_gate_completion_claim_allowed' => (bool) data_get($finalizationGate, 'completion_claim_allowed', false),
            'finalization_gate_next_stage_allowed' => (bool) data_get($finalizationGate, 'next_stage_allowed', false),
            'finalization_gate_next_stage_blockers' => (array) data_get($finalizationGate, 'next_stage_blockers', []),
            'finalization_gate_operator_handoff_status' => (string) data_get($finalizationGate, 'completion_finalization_operator_handoff.status', ''),
            'finalization_gate_operator_handoff_hash' => (string) data_get($finalizationGate, 'completion_finalization_operator_handoff_hash', ''),
            'finalization_gate_current_required_operator_artifact' => (string) data_get($finalizationGate, 'completion_finalization_operator_handoff.current_required_operator_artifact', ''),
            'finalization_gate_operator_evidence_readiness_command' => (string) data_get($finalizationGate, 'completion_finalization_operator_handoff.operator_evidence_readiness_command', ''),
            'finalization_gate_final_verification_sequence' => (array) data_get($finalizationGate, 'completion_finalization_operator_handoff.final_verification_sequence', []),
            'finalization_gate_final_verification_sequence_count' => count((array) data_get($finalizationGate, 'completion_finalization_operator_handoff.final_verification_sequence', [])),
            'terminal_loop_operational_proof_json_reference' => $terminalLoopProofJsonReference,
            'live_finalization_gate_available' => $liveFinalizationGate !== [],
            'live_finalization_gate_status' => (string) data_get($liveFinalizationGate, 'status', ''),
            'live_finalization_gate_hash' => (string) data_get($liveFinalizationGate, 'completion_finalization_gate_hash', ''),
            'live_finalization_gate_terminal_loop_green' => (bool) data_get($liveFinalizationGate, 'terminal_loop_green', false),
            'live_finalization_gate_terminal_loop_proof_status' => (string) data_get($liveFinalizationGate, 'checks.terminal_loop_green.evidence.operational_proof_status', ''),
            'live_finalization_gate_terminal_loop_proof_hash' => (string) data_get($liveFinalizationGate, 'checks.terminal_loop_green.evidence.operational_proof_hash', ''),
            'live_finalization_gate_failed_check_ids' => (array) data_get($liveFinalizationGate, 'failed_check_ids', []),
            'live_finalization_gate_completion_audit_failed_criteria' => (array) data_get($liveFinalizationGate, 'completion_audit_failed_criteria', []),
            'live_finalization_gate_command' => (string) data_get($liveFinalizationGate, 'completion_finalization_operator_handoff.finalization_gate_command', ''),
            'live_finalization_gate_audit_with_binding_command' => (string) data_get($liveFinalizationGate, 'command_to_rerun_audit_with_terminal_loop_operational_proof', ''),
            'live_finalization_gate_operator_evidence_readiness_command' => (string) data_get($liveFinalizationGate, 'completion_finalization_operator_handoff.operator_evidence_readiness_command', ''),
            'live_finalization_gate_final_verification_sequence' => (array) data_get($liveFinalizationGate, 'completion_finalization_operator_handoff.final_verification_sequence', []),
            'live_finalization_gate_final_verification_sequence_count' => count((array) data_get($liveFinalizationGate, 'completion_finalization_operator_handoff.final_verification_sequence', [])),
            'worker_task_eligibility_status' => (string) data_get($workerTaskEligibility, 'status', ''),
            'worker_task_eligibility_certification_hash' => (string) data_get($workerTaskEligibility, 'certification_hash', ''),
            'worker_task_eligibility_violation_count' => (int) data_get($workerTaskEligibility, 'violation_count', 0),
            'worker_task_eligibility_operator_only_failed_criteria' => (array) data_get($workerTaskEligibility, 'operator_only_failed_criteria', []),
            'worker_task_eligibility_missing_operator_handoff_criteria' => (array) data_get($workerTaskEligibility, 'missing_operator_handoff_criteria', []),
            'worker_task_eligibility_operator_handoff_seed_count' => (int) data_get($workerTaskEligibility, 'operator_handoff_seed_count', 0),
            'self_programming_bootstrap_plan' => $bootstrapPlan,
            'self_programming_bootstrap_plan_status' => (string) data_get($bootstrapPlan, 'status', ''),
            'self_programming_bootstrap_plan_phase_count' => count((array) data_get($bootstrapPlan, 'phases', [])),
            'self_programming_bootstrap_plan_hash' => (string) data_get($bootstrapPlan, 'bootstrap_plan_hash', ''),
            'next_stage_prompt_to_artifact_checklist' => $nextStageChecklist,
            'next_stage_prompt_to_artifact_checklist_count' => count($nextStageChecklist),
            'next_stage_prompt_to_artifact_checklist_passed_count' => count(array_filter($nextStageChecklist, static fn (array $row): bool => (string) ($row['evidence_status'] ?? '') === 'passed')),
            'self_construction_complete' => (bool) data_get($transition, 'self_construction_complete', false),
            'contract_design_allowed' => (bool) data_get($transition, 'contract_design_allowed', false),
            'runtime_activation_allowed' => false,
            'self_programming_allowed' => false,
            'provider_call_allowed' => false,
            'token_spend_allowed' => false,
            'dispatch_allowed' => false,
            'section_checks' => $sectionChecks,
            'phrase_checks' => $phraseChecks,
            'checks' => $checks,
            'failed_check_ids' => $failedCheckIds,
            'check_count' => count($checks),
            'passed_check_count' => count($checks) - count($failedCheckIds),
            'checks_all_true' => $failedCheckIds === [],
            'invariants' => $checks,
            'invariants_all_true' => $failedCheckIds === [],
            'violations' => $failedCheckIds,
            'violation_count' => count($failedCheckIds),
            'non_execution_guarantees' => [
                'self_programming_safety_contract_certification_is_read_only',
                'self_programming_safety_contract_certification_does_not_enable_self_programming',
                'self_programming_safety_contract_certification_does_not_activate_runtime',
                'self_programming_safety_contract_certification_does_not_call_provider',
                'self_programming_safety_contract_certification_does_not_spend_tokens',
                'self_programming_safety_contract_certification_does_not_dispatch_work',
                'self_programming_safety_contract_certification_does_not_persist_receipts',
                'self_programming_safety_contract_certification_requires_worker_task_eligibility_before_next_stage',
                'self_programming_safety_contract_certification_uses_canonical_terminal_loop_binding_only_as_evidence',
            ],
        ];
        $payload['certification_hash'] = $this->stableHash($payload);

        return $payload;
    }

    /**
     * @param  array<string, mixed>  $transition
     * @param  array<string, mixed>  $finalizationGate
     * @param  array<string, mixed>  $workerTaskEligibility
     * @param  array<string, mixed>  $bootstrapPlan
     * @param  array<string, bool>  $checks
     * @return list<array<string, mixed>>
     */
    private function nextStagePromptToArtifactChecklist(
        array $transition,
        array $finalizationGate,
        array $workerTaskEligibility,
        array $bootstrapPlan,
        array $checks,
    ): array {
        return [
            [
                'requirement' => 'Self-Construction OS complete before Self-Programming',
                'artifact' => 'atlas.self_construction.completion_finalization_gate.v1',
                'evidence_status' => (bool) data_get($transition, 'self_construction_complete', false) ? 'passed' : 'blocked_until_completion_audit_complete',
                'evidence_hash' => (string) data_get($finalizationGate, 'completion_finalization_gate_hash', ''),
                'blocking_artifacts' => (array) data_get($finalizationGate, 'next_stage_blockers', []),
            ],
            [
                'requirement' => 'terminal loop operational proof bound to finalization',
                'artifact' => 'atlas.self_construction.agent_control_plane_terminal_loop_operational_proof_audit_binding_packet.v1',
                'evidence_status' => (bool) data_get($finalizationGate, 'terminal_loop_green', false) ? 'passed' : 'blocked_until_terminal_loop_operational_proof_binding_green',
                'evidence_hash' => (string) data_get($finalizationGate, 'checks.terminal_loop_green.evidence.operational_proof_hash', ''),
                'command' => $this->completionAuditCommandWithCanonicalTerminalLoopBinding(),
            ],
            [
                'requirement' => 'worker task eligibility blocks final human/provider artifacts from worker claim',
                'artifact' => 'atlas.self_construction.agent_control_plane_worker_task_eligibility_certification.v1',
                'evidence_status' => ((bool) ($checks['worker_task_eligibility_certification_available'] ?? false) && (bool) ($checks['worker_task_eligibility_blocks_operator_only_completion_blockers'] ?? false)) ? 'passed' : 'blocked',
                'evidence_hash' => (string) data_get($workerTaskEligibility, 'certification_hash', ''),
                'operator_only_failed_criteria' => (array) data_get($workerTaskEligibility, 'operator_only_failed_criteria', []),
            ],
            [
                'requirement' => 'Self-Programming bootstrap plan exists but remains read-only',
                'artifact' => 'atlas.self_programming.bootstrap_plan.v1',
                'evidence_status' => ((bool) ($checks['self_programming_bootstrap_plan_available'] ?? false) && (bool) ($checks['self_programming_bootstrap_plan_is_read_only'] ?? false)) ? 'passed' : 'blocked',
                'evidence_hash' => (string) data_get($bootstrapPlan, 'bootstrap_plan_hash', ''),
                'bootstrap_status' => (string) data_get($bootstrapPlan, 'status', ''),
            ],
            [
                'requirement' => 'provider calls, token spend, dispatch and self-programming runtime stay disabled',
                'artifact' => 'runtime safety flags',
                'evidence_status' => (
                    (bool) ($checks['runtime_activation_disabled'] ?? false)
                    && (bool) ($checks['self_programming_disabled'] ?? false)
                    && (bool) ($checks['provider_call_disabled'] ?? false)
                    && (bool) ($checks['token_spend_disabled'] ?? false)
                    && (bool) ($checks['self_programming_bootstrap_plan_is_read_only'] ?? false)
                ) ? 'passed' : 'blocked',
                'evidence_hash' => (string) data_get($bootstrapPlan, 'bootstrap_plan_hash', ''),
            ],
        ];
    }

    /** @return array<string, mixed> */
    private function workerTaskEligibilityProbe(): array
    {
        $status = $this->readiness->agentControlPlaneWorkerTaskEligibilityCertificationStatus([
            'completion_audit' => [
                'status' => 'incomplete',
                'failed_count' => 3,
                'failed_criteria' => [
                    'runtime_gap_matrix_all_runtime_y',
                    'human_signed_os_complete_receipt_present',
                    'end_to_end_real_provider_smoke_green',
                ],
                'context_scope' => 'self_programming_safety_contract_structural_worker_eligibility_probe',
                'worker_task_creation_allowed_for_failed_criteria' => false,
            ],
            'max_new_tasks' => 0,
            'queue_tags' => ['self_programming_safety_contract_probe'],
        ]);

        return (array) data_get($status, 'agent_control_plane_worker_task_eligibility_certification_status', []);
    }

    /** @return array<string, mixed> */
    private function finalizationGateStructuralProbe(): array
    {
        $hash = str_repeat('0', 64);
        $completionAudit = [
            'status' => 'incomplete',
            'failed_count' => 3,
            'failed_criteria' => [
                'runtime_gap_matrix_all_runtime_y',
                'end_to_end_real_provider_smoke_green',
                'human_signed_os_complete_receipt_present',
            ],
            'completion_allowed' => false,
            'completion_claim_allowed' => false,
            'criteria' => [
                [
                    'id' => 'runtime_gap_matrix_all_runtime_y',
                    'passed' => false,
                    'evidence' => [
                        'runtime_gap_matrix_hash' => $hash,
                        'runtime_promotion_receipt_hash' => '',
                    ],
                ],
                [
                    'id' => 'end_to_end_real_provider_smoke_green',
                    'passed' => false,
                    'evidence' => [
                        'smoke_hash' => '',
                    ],
                ],
                [
                    'id' => 'human_signed_os_complete_receipt_present',
                    'passed' => false,
                    'evidence' => [
                        'receipt_hash' => '',
                    ],
                ],
                [
                    'id' => 'agent_control_plane_terminal_loop_certification_green',
                    'passed' => false,
                    'evidence' => [],
                ],
            ],
            'agent_control_plane_terminal_loop_operational_proof_evidence' => [
                'status' => 'not_supplied',
                'supplied' => false,
                'passed' => false,
                'proof_hash' => '',
                'validation_violation_count' => 1,
                'post_cycle_cleanup_state' => [
                    'claimed_task_count' => 0,
                    'active_lease_count' => 0,
                    'recoverable_lease_count' => 0,
                ],
                'dispatch_allowed' => false,
                'adapter_execution_allowed' => false,
                'self_programming_allowed' => false,
            ],
            'blocker_classification' => [
                'human_blocker_count' => 2,
                'real_provider_blocker_count' => 1,
                'technical_blocker_count' => 0,
            ],
            'completion_audit_hash' => $hash,
        ];
        $completionEvidence = [
            'runtime_gap_matrix' => [
                'status' => 'blocked',
                'all_runtime_y' => false,
                'runtime_gap_matrix_hash' => $hash,
                'runtime_promotion_receipt' => [
                    'status' => 'missing',
                    'receipt_hash' => '',
                ],
            ],
            'real_provider_smoke' => [
                'status' => 'missing',
                'smoke_hash' => '',
            ],
            'human_signed_completion_receipt' => [
                'status' => 'missing',
                'completion_claim_allowed' => false,
                'receipt_hash' => '',
            ],
            'completion_evidence_status_hash' => $hash,
        ];

        return (new AtlasSelfConstructionCompletionFinalizationGateService($this->readiness))->evaluate([
            'completion_audit' => $completionAudit,
            'completion_evidence' => $completionEvidence,
        ]);
    }

    /** @param array<string, mixed> $options */
    private function terminalLoopOperationalProofJsonReference(array $options): string
    {
        $reference = trim((string) ($options['agent_control_plane_terminal_loop_operational_proof_json'] ?? ''));
        if (str_starts_with($reference, '@')) {
            return $reference;
        }

        return is_file(base_path(self::TERMINAL_LOOP_OPERATIONAL_PROOF_CANONICAL_BINDING_PATH))
            ? '@'.self::TERMINAL_LOOP_OPERATIONAL_PROOF_CANONICAL_BINDING_PATH
            : '';
    }

    private function transitionReadinessCommandWithCanonicalTerminalLoopBinding(): string
    {
        return 'php artisan atlas:ai:self-construction --atlas-self-programming-os-transition-readiness-status --agent-control-plane-terminal-loop-operational-proof-json=@'.self::TERMINAL_LOOP_OPERATIONAL_PROOF_CANONICAL_BINDING_PATH.' --json';
    }

    private function safetyContractCertificationCommandWithCanonicalTerminalLoopBinding(): string
    {
        return 'php artisan atlas:ai:self-construction --atlas-self-programming-safety-contract-certification-status --agent-control-plane-terminal-loop-operational-proof-json=@'.self::TERMINAL_LOOP_OPERATIONAL_PROOF_CANONICAL_BINDING_PATH.' --json';
    }

    private function completionAuditCommandWithCanonicalTerminalLoopBinding(): string
    {
        return 'php artisan atlas:ai:self-construction --atlas-self-construction-os-completion-audit-status --agent-control-plane-terminal-loop-operational-proof-json=@'.self::TERMINAL_LOOP_OPERATIONAL_PROOF_CANONICAL_BINDING_PATH.' --json';
    }

    /**
     * @param  array<string, mixed>  $transition
     * @param  array<string, mixed>  $finalizationGate
     * @param  array<string, mixed>  $workerTaskEligibility
     * @return array<string, mixed>
     */
    private function selfProgrammingBootstrapPlan(
        array $transition,
        array $finalizationGate,
        array $workerTaskEligibility,
        string $finalizationGateSource,
    ): array {
        $selfConstructionComplete = (bool) data_get($transition, 'self_construction_complete', false);
        $blockers = $selfConstructionComplete
            ? []
            : array_values(array_unique(array_merge(
                ['self_construction_os_not_complete'],
                (array) data_get($transition, 'blockers', []),
                (array) data_get($finalizationGate, 'next_stage_blockers', []),
            )));

        $payload = [
            'schema_version' => 'atlas.self_programming.bootstrap_plan.v1',
            'mode' => 'read_only_self_programming_bootstrap_plan',
            'status' => $selfConstructionComplete ? 'ready_for_operator_review' : 'blocked_until_self_construction_complete',
            'self_construction_complete' => $selfConstructionComplete,
            'blockers' => $blockers,
            'safety_contract_path' => self::SAFETY_CONTRACT_PATH,
            'safety_contract_hash' => (string) data_get($transition, 'safety_contract_hash', ''),
            'terminal_loop_operational_proof_canonical_binding_path' => self::TERMINAL_LOOP_OPERATIONAL_PROOF_CANONICAL_BINDING_PATH,
            'finalization_gate_source' => $finalizationGateSource,
            'finalization_gate_terminal_loop_green' => (bool) data_get($finalizationGate, 'terminal_loop_green', false),
            'finalization_gate_failed_check_ids' => (array) data_get($finalizationGate, 'failed_check_ids', []),
            'finalization_gate_next_stage_blockers' => (array) data_get($finalizationGate, 'next_stage_blockers', []),
            'required_before_first_self_programming_task' => [
                'self_construction_completion_audit_complete',
                'runtime_promotion_receipt_green',
                'real_provider_smoke_green',
                'human_completion_receipt_green',
                'terminal_loop_operational_proof_binding_green',
                'final_completion_readiness_gate_complete',
                'self_programming_safety_contract_certification_available',
                'worker_task_eligibility_certification_available',
            ],
            'prompt_to_artifact_checklist_required' => true,
            'phases' => [
                [
                    'id' => 'safety_contract_design',
                    'allowed_when' => 'self_construction_complete_and_operator_reviewed',
                    'output' => 'signed_self_programming_scope_receipt_template',
                    'runtime_enabled' => false,
                ],
                [
                    'id' => 'mutation_proposal_dry_run',
                    'allowed_when' => 'safety_contract_design_receipt_is_signed',
                    'output' => 'non_executing_mutation_proposal_packet',
                    'runtime_enabled' => false,
                ],
                [
                    'id' => 'patch_plan_evidence_gate',
                    'allowed_when' => 'proposal_packet_passes_policy_and_evidence_checks',
                    'output' => 'patch_plan_with_rollback_and_test_matrix',
                    'runtime_enabled' => false,
                ],
                [
                    'id' => 'operator_signed_apply_gate',
                    'allowed_when' => 'human_operator_signs_specific_patch_scope',
                    'output' => 'bounded_apply_receipt_for_future_runtime',
                    'runtime_enabled' => false,
                ],
            ],
            'commands' => [
                'refresh_transition_readiness' => $this->transitionReadinessCommandWithCanonicalTerminalLoopBinding(),
                'refresh_safety_contract_certification' => $this->safetyContractCertificationCommandWithCanonicalTerminalLoopBinding(),
                'rerun_completion_audit_with_terminal_loop_binding' => $this->completionAuditCommandWithCanonicalTerminalLoopBinding(),
            ],
            'worker_task_eligibility_status' => (string) data_get($workerTaskEligibility, 'status', ''),
            'worker_task_eligibility_certification_hash' => (string) data_get($workerTaskEligibility, 'certification_hash', ''),
            'can_create_mutation_tasks' => false,
            'can_apply_patches' => false,
            'can_call_provider' => false,
            'can_spend_tokens' => false,
            'can_dispatch_work' => false,
            'can_enable_self_programming_runtime' => false,
            'non_execution_guarantees' => [
                'bootstrap_plan_does_not_create_tasks',
                'bootstrap_plan_does_not_apply_patches',
                'bootstrap_plan_does_not_call_provider',
                'bootstrap_plan_does_not_spend_tokens',
                'bootstrap_plan_does_not_dispatch_work',
                'bootstrap_plan_does_not_enable_self_programming_runtime',
            ],
        ];
        $payload['bootstrap_plan_hash'] = $this->stableHash($payload);

        return $payload;
    }

    /** @param array<string, mixed> $payload */
    private function stableHash(array $payload): string
    {
        unset($payload['generated_at'], $payload['certification_hash']);

        return hash('sha256', (string) json_encode($this->ksortRecursive($payload), JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
    }

    /** @param array<string, mixed> $value */
    private function ksortRecursive(array $value): array
    {
        foreach ($value as $key => $entry) {
            if (is_array($entry)) {
                $value[$key] = $this->ksortRecursive($entry);
            }
        }
        if ($value !== [] && array_keys($value) !== range(0, count($value) - 1)) {
            ksort($value);
        }

        return $value;
    }
}
