<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\SelfConstruction;

use App\Services\Ai\SelfConstruction\NativeImplementation\AtlasSelfConstructionCompletionFinalizationGateService;
use App\Services\Ai\SelfConstruction\Readiness\AtlasSelfConstructionReadinessService;
use Tests\TestCase;

final class AtlasSelfConstructionCompletionFinalizationGateServiceTest extends TestCase
{
    private AtlasSelfConstructionCompletionFinalizationGateService $gate;

    protected function setUp(): void
    {
        parent::setUp();
        // Use app() resolution which caches the readiness singleton.
        $readiness = app(AtlasSelfConstructionReadinessService::class);
        $this->gate = new AtlasSelfConstructionCompletionFinalizationGateService($readiness);
    }

    /** A fully-passing completion audit payload. */
    private function greenAudit(): array
    {
        return [
            'status' => 'complete',
            'failed_count' => 0,
            'failed_criteria' => [],
            'completion_allowed' => true,
            'completion_claim_allowed' => true,
            'criteria' => [
                ['id' => 'runtime_gap_matrix_all_runtime_y', 'passed' => true],
                ['id' => 'end_to_end_real_provider_smoke_green', 'passed' => true],
                ['id' => 'human_signed_os_complete_receipt_present', 'passed' => true],
                ['id' => 'replay_diff_against_completion_snapshot_green', 'passed' => true],
                ['id' => 'promotion_gate_green', 'passed' => true],
                ['id' => 'release_dossier_green', 'passed' => true],
                ['id' => 'certification_status_batch_green', 'passed' => true],
                ['id' => 'mutation_guard_green', 'passed' => true],
                ['id' => 'forge_self_improvement_integration_smoke_green', 'passed' => true],
                ['id' => 'agent_control_plane_terminal_loop_certification_green', 'passed' => true],
            ],
            'blocker_classification' => ['technical_blocker_count' => 0],
            'completion_audit_hash' => str_repeat('a', 64),
        ];
    }

    /** A fully-passing completion evidence payload. */
    private function greenEvidence(): array
    {
        return [
            'runtime_gap_matrix' => [
                'status' => 'passed',
                'all_runtime_y' => true,
                'runtime_gap_matrix_hash' => str_repeat('b', 64),
                'runtime_promotion_receipt' => [
                    'status' => 'passed',
                    'receipt_hash' => str_repeat('c', 64),
                ],
            ],
            'real_provider_smoke' => [
                'status' => 'passed',
                'smoke_hash' => str_repeat('d', 64),
            ],
            'human_signed_completion_receipt' => [
                'status' => 'passed',
                'completion_claim_allowed' => true,
                'receipt_hash' => str_repeat('e', 64),
            ],
            'completion_evidence_status_hash' => str_repeat('f', 64),
            'closure_artifact_sequence' => [],
            'prompt_to_artifact_checklist' => [],
            'prompt_to_artifact_checklist_passed_count' => 0,
        ];
    }

    /** A passing terminal-loop operational proof binding. */
    private function greenTerminalLoopProof(): array
    {
        return [
            'status' => 'passed',
            'supplied' => true,
            'passed' => true,
            'proof_hash' => str_repeat('1', 64),
            'validation_violation_count' => 0,
            'post_cycle_cycle_supervisor_status' => 'completed',
            'post_cycle_end_to_end_contract_status' => 'terminal_loop_end_to_end_contract_available',
            'post_cycle_end_to_end_contract_all_required_surfaces_present' => true,
            'post_cycle_end_to_end_contract_failed_check_ids' => [],
            'post_cycle_end_to_end_contract_missing_required_capabilities' => [],
            'post_cycle_end_to_end_contract_hash' => str_repeat('2', 64),
            'post_cycle_cleanup_state' => [
                'claimed_task_count' => 0,
                'active_lease_count' => 0,
                'recoverable_lease_count' => 0,
            ],
            'dispatch_allowed' => false,
            'adapter_execution_allowed' => false,
            'self_programming_allowed' => false,
        ];
    }

    private function greenOptions(): array
    {
        $audit = $this->greenAudit();
        $audit['agent_control_plane_terminal_loop_operational_proof_evidence'] = $this->greenTerminalLoopProof();
        // Attach evidence hashes to criteria so evidence_hashes_match_completion_audit passes.
        foreach ($audit['criteria'] as &$criterion) {
            if ($criterion['id'] === 'runtime_gap_matrix_all_runtime_y') {
                $criterion['evidence'] = [
                    'runtime_gap_matrix_hash' => str_repeat('b', 64),
                    'runtime_promotion_receipt_hash' => str_repeat('c', 64),
                ];
            } elseif ($criterion['id'] === 'end_to_end_real_provider_smoke_green') {
                $criterion['evidence'] = ['smoke_hash' => str_repeat('d', 64)];
            } elseif ($criterion['id'] === 'human_signed_os_complete_receipt_present') {
                $criterion['evidence'] = ['receipt_hash' => str_repeat('e', 64)];
            }
        }
        unset($criterion);

        return [
            'completion_audit' => $audit,
            'completion_evidence' => $this->greenEvidence(),
            'agent_control_plane_terminal_loop_operational_proof_json' => '@/path/to/proof.json',
        ];
    }

    // ── AC: blocked when proof binding missing ──────────────────────────────

    public function test_evaluate_blocked_when_audit_not_complete(): void
    {
        $options = $this->greenOptions();
        $options['completion_audit']['status'] = 'incomplete';

        $result = $this->gate->evaluate($options);

        $this->assertSame('blocked', $result['status']);
        $this->assertFalse($result['completion_claim_allowed']);
        $this->assertFalse($result['next_stage_allowed']);
    }

    public function test_evaluate_blocked_when_audit_has_failed_criteria(): void
    {
        $options = $this->greenOptions();
        $options['completion_audit']['failed_count'] = 1;
        $options['completion_audit']['failed_criteria'] = ['runtime_gap_matrix_all_runtime_y'];

        $result = $this->gate->evaluate($options);

        $this->assertSame('blocked', $result['status']);
        $this->assertContains('finalization_gate_blocked_by_completion_audit_complete', $result['next_stage_blockers']);
    }

    public function test_evaluate_blocked_when_runtime_promotion_receipt_missing(): void
    {
        $options = $this->greenOptions();
        $options['completion_evidence']['runtime_gap_matrix']['runtime_promotion_receipt']['status'] = '';

        $result = $this->gate->evaluate($options);

        $this->assertSame('blocked', $result['status']);
        $this->assertFalse($result['runtime_all_y']);
    }

    public function test_evaluate_blocked_when_terminal_loop_proof_missing(): void
    {
        $options = $this->greenOptions();
        $options['completion_audit']['agent_control_plane_terminal_loop_operational_proof_evidence'] = [];

        $result = $this->gate->evaluate($options);

        $this->assertSame('blocked', $result['status']);
        $this->assertFalse($result['terminal_loop_green']);
    }

    public function test_evaluate_blocked_when_terminal_loop_proof_has_violations(): void
    {
        $options = $this->greenOptions();
        $options['completion_audit']['agent_control_plane_terminal_loop_operational_proof_evidence']['validation_violation_count'] = 3;

        $result = $this->gate->evaluate($options);

        $this->assertSame('blocked', $result['status']);
        $this->assertFalse($result['terminal_loop_green']);
    }

    public function test_evaluate_blocked_when_evidence_hashes_do_not_match_audit(): void
    {
        $options = $this->greenOptions();
        $options['completion_evidence']['runtime_gap_matrix']['runtime_gap_matrix_hash'] = str_repeat('x', 64);

        $result = $this->gate->evaluate($options);

        $this->assertSame('blocked', $result['status']);
        $this->assertFalse($result['evidence_hashes_match_completion_audit']);
    }

    // ── AC: blocked when human receipt missing ──────────────────────────────

    public function test_evaluate_blocked_when_human_receipt_not_passed(): void
    {
        $options = $this->greenOptions();
        $options['completion_evidence']['human_signed_completion_receipt']['status'] = 'missing';

        $result = $this->gate->evaluate($options);

        $this->assertSame('blocked', $result['status']);
        $this->assertFalse($result['human_receipt_green']);
    }

    // ── AC: ready only when everything green ────────────────────────────────

    public function test_evaluate_ready_when_all_checks_pass(): void
    {
        $result = $this->gate->evaluate($this->greenOptions());

        $this->assertSame('passed', $result['status']);
        $this->assertTrue($result['completion_claim_allowed']);
        $this->assertTrue($result['next_stage_allowed']);
        $this->assertSame([], $result['failed_check_ids']);
    }

    // ── AC: operator_handoff ────────────────────────────────────────────────

    public function test_operator_handoff_present_when_blocked(): void
    {
        $result = $this->gate->evaluate($this->greenOptions());

        $this->assertArrayHasKey('completion_finalization_operator_handoff', $result);
        // When blocked by tampering with one check:
        $options = $this->greenOptions();
        $options['completion_audit']['failed_count'] = 1;
        $options['completion_audit']['failed_criteria'] = ['runtime_gap_matrix_all_runtime_y'];
        $blockedResult = $this->gate->evaluate($options);
        $this->assertSame('blocked_operator_or_provider_evidence_required', $blockedResult['completion_finalization_operator_handoff']['status']);
    }

    public function test_operator_handoff_ready_for_final_review_when_passed(): void
    {
        $result = $this->gate->evaluate($this->greenOptions());

        $this->assertSame('ready_for_final_operator_review', $result['completion_finalization_operator_handoff']['status']);
    }

    public function test_operator_handoff_names_human_receipt_when_missing(): void
    {
        $options = $this->greenOptions();
        $options['completion_audit']['failed_count'] = 1;
        $options['completion_audit']['failed_criteria'] = ['human_signed_os_complete_receipt_present'];

        $result = $this->gate->evaluate($options);

        $this->assertSame('human_signed_completion_receipt', $result['completion_finalization_operator_handoff']['current_required_operator_artifact']);
    }

    public function test_operator_handoff_names_runtime_receipt_when_runtime_fails(): void
    {
        $options = $this->greenOptions();
        $options['completion_audit']['failed_count'] = 1;
        $options['completion_audit']['failed_criteria'] = ['runtime_gap_matrix_all_runtime_y'];

        $result = $this->gate->evaluate($options);

        $this->assertSame('runtime_promotion_receipt', $result['completion_finalization_operator_handoff']['current_required_operator_artifact']);
    }

    // ── AC: external completion claim policy ────────────────────────────────

    public function test_external_claim_policy_delegates_when_passed(): void
    {
        $result = $this->gate->evaluate($this->greenOptions());

        $this->assertSame('completion_claim_delegated_to_completion_audit', $result['external_completion_claim_policy_status']);
    }

    public function test_external_claim_policy_rejects_when_blocked(): void
    {
        $options = $this->greenOptions();
        $options['completion_audit']['status'] = 'incomplete';

        $result = $this->gate->evaluate($options);

        $this->assertSame('reject_external_completion_claim', $result['external_completion_claim_policy_status']);
        $this->assertFalse($result['external_completion_claim_policy']['external_agent_claim_accepted']);
    }

    // ── AC: execution guarantees ────────────────────────────────────────────

    public function test_gate_never_allows_execution(): void
    {
        $result = $this->gate->evaluate($this->greenOptions());

        $this->assertFalse($result['execution_allowed']);
        $this->assertFalse($result['dispatch_allowed']);
        $this->assertFalse($result['provider_call_allowed']);
        $this->assertFalse($result['token_spend_allowed']);
        $this->assertFalse($result['adapter_execution_allowed']);
        $this->assertFalse($result['self_programming_allowed']);
    }

    public function test_gate_has_non_execution_guarantees(): void
    {
        $result = $this->gate->evaluate($this->greenOptions());

        $this->assertNotEmpty($result['non_execution_guarantees']);
        $this->assertContains('completion_finalization_gate_does_not_promote_completion', $result['non_execution_guarantees']);
    }

    // ── AC: determinism ─────────────────────────────────────────────────────

    public function test_gate_hash_present(): void
    {
        $result = $this->gate->evaluate($this->greenOptions());

        $this->assertArrayHasKey('completion_finalization_gate_hash', $result);
        $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/', $result['completion_finalization_gate_hash']);
    }

    public function test_gate_deterministic_except_timestamp(): void
    {
        $options = $this->greenOptions();
        $a = $this->gate->evaluate($options);
        $b = $this->gate->evaluate($options);

        $this->assertSame($a['completion_finalization_gate_hash'], $b['completion_finalization_gate_hash']);
    }
}
