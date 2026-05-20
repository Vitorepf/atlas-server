<?php

namespace Tests\Feature\Ai\SelfConstruction;

use App\Services\Ai\SelfConstruction\AtlasSelfConstructionCompletionEvidenceHashService;
use App\Services\Ai\SelfConstruction\AtlasSelfConstructionFinalCompletionHumanGateService;
use App\Services\Ai\SelfConstruction\AtlasSelfConstructionReadinessService;
use App\Services\Ai\SelfConstruction\AtlasSelfConstructionReservationRepository;
use Tests\TestCase;

final class AtlasSelfConstructionFinalCompletionHumanGateTest extends TestCase
{
    public function test_gate_blocks_when_runtime_promotion_missing(): void
    {
        $gate = $this->buildGate(audit: $this->auditWith(runtime: false, smoke: false, human: false), evidence: $this->evidence(runtimeY: false, runtimeReceipt: 'blocked', smoke: 'blocked'));

        $this->assertSame('atlas.self_construction.final_completion_human_gate.v1', $gate['schema_version']);
        $this->assertSame('blocked_runtime_promotion_required', $gate['status']);
        $this->assertFalse((bool) $gate['completion_claim_allowed']);
        $this->assertFalse((bool) data_get($gate, 'prerequisite_matrix.runtime_gap_matrix_all_runtime_y.green'));
        $this->assertFalse((bool) data_get($gate, 'prerequisite_matrix.runtime_promotion_receipt_present.green'));
        $this->assertContains('final_completion_human_gate_does_not_sign_for_operator', (array) $gate['non_execution_guarantees']);
        $this->assertContains('final_completion_human_gate_does_not_persist_receipts', (array) $gate['non_execution_guarantees']);
    }

    public function test_gate_blocks_when_real_provider_smoke_missing(): void
    {
        $gate = $this->buildGate(audit: $this->auditWith(runtime: true, smoke: false, human: false), evidence: $this->evidence(runtimeY: true, runtimeReceipt: 'passed', smoke: 'blocked'));

        $this->assertSame('blocked_real_provider_smoke_required', $gate['status']);
        $this->assertTrue((bool) data_get($gate, 'prerequisite_matrix.runtime_gap_matrix_all_runtime_y.green'));
        $this->assertFalse((bool) data_get($gate, 'prerequisite_matrix.real_provider_smoke_green.green'));
    }

    public function test_gate_blocks_when_human_signature_missing(): void
    {
        $gate = $this->buildGate(audit: $this->auditWith(runtime: true, smoke: true, human: false), evidence: $this->evidence(runtimeY: true, runtimeReceipt: 'passed', smoke: 'passed'));

        $this->assertSame('blocked_human_signature_required', $gate['status']);
        $this->assertTrue((bool) data_get($gate, 'prerequisite_matrix.runtime_gap_matrix_all_runtime_y.green'));
        $this->assertTrue((bool) data_get($gate, 'prerequisite_matrix.real_provider_smoke_green.green'));
        $this->assertFalse((bool) data_get($gate, 'persistence_preflight.can_persist'));
    }

    public function test_gate_advances_to_ready_to_verify_when_receipt_provided_but_invalid(): void
    {
        $receipt = $this->canonicalReceipt();
        $receipt['signed_by'] = 'codex';
        $receipt['receipt_hash'] = (new AtlasSelfConstructionCompletionEvidenceHashService)->humanCompletionReceiptHash($receipt);

        $gate = $this->buildGate(audit: $this->auditWith(runtime: true, smoke: true, human: false), evidence: $this->evidence(runtimeY: true, runtimeReceipt: 'passed', smoke: 'passed'), receipt: $receipt);

        $this->assertSame('ready_to_verify_human_receipt', $gate['status']);
        $this->assertSame('blocked', (string) data_get($gate, 'human_receipt_verification.status'));
    }

    public function test_gate_advances_to_verifier_passed_when_receipt_valid_but_audit_still_incomplete(): void
    {
        $gate = $this->buildGate(audit: $this->auditWith(runtime: true, smoke: true, human: false), evidence: $this->evidence(runtimeY: true, runtimeReceipt: 'passed', smoke: 'passed'), receipt: $this->canonicalReceipt());

        $this->assertSame('verifier_passed_ready_for_explicit_persistence', $gate['status']);
        $this->assertSame('passed', (string) data_get($gate, 'human_receipt_verification.status'));
        $this->assertFalse((bool) $gate['completion_claim_allowed']);
    }

    public function test_gate_flips_to_complete_candidate_only_with_full_test_audit(): void
    {
        $gate = $this->buildGate(audit: $this->auditWith(runtime: true, smoke: true, human: true, complete: true), evidence: $this->evidence(runtimeY: true, runtimeReceipt: 'passed', smoke: 'passed'), receipt: $this->canonicalReceipt());

        $this->assertSame('complete_candidate_after_audit_rerun', $gate['status']);
        $this->assertFalse((bool) $gate['completion_claim_allowed'], 'completion_claim_allowed must always remain false on the gate; only the readiness gate may flip it.');
    }

    public function test_gate_does_not_call_provider_or_promote_completion(): void
    {
        $gate = $this->buildGate();

        $this->assertFalse((bool) $gate['execution_allowed']);
        $this->assertFalse((bool) $gate['dispatch_allowed']);
        $this->assertFalse((bool) $gate['provider_call_allowed']);
        $this->assertFalse((bool) $gate['token_spend_allowed']);
        $this->assertFalse((bool) $gate['adapter_execution_allowed']);
        $this->assertFalse((bool) $gate['self_programming_allowed']);
        $this->assertFalse((bool) $gate['completion_claim_allowed']);
    }

    public function test_gate_anti_cheat_policy_lists_forbidden_signers_and_flags(): void
    {
        $gate = $this->buildGate();
        $signers = (array) data_get($gate, 'anti_cheat_policy.forbidden_signers', []);
        $flags = (array) data_get($gate, 'anti_cheat_policy.forbidden_flags', []);

        $this->assertContains('codex', $signers);
        $this->assertContains('<operator>', $signers);
        $this->assertContains('execution_allowed', $flags);
        $this->assertContains('completion_autopromoted', $flags);
        $this->assertSame(32, (int) data_get($gate, 'anti_cheat_policy.minimum_reason_length'));
    }

    public function test_gate_exposes_exact_commands_and_ordered_operator_steps(): void
    {
        $gate = $this->buildGate();
        $commands = (array) data_get($gate, 'exact_commands', []);
        $steps = array_column((array) data_get($gate, 'ordered_operator_steps', []), 'id');

        $this->assertArrayHasKey('refresh_completion_audit', $commands);
        $this->assertArrayHasKey('refresh_terminal_loop_operational_proof', $commands);
        $this->assertArrayHasKey('persist_terminal_loop_operational_proof_binding', $commands);
        $this->assertArrayHasKey('refresh_completion_audit_with_terminal_loop_operational_proof', $commands);
        $this->assertStringContainsString('terminal-loop-operational-proof-status', (string) $commands['refresh_terminal_loop_operational_proof']);
        $this->assertStringContainsString('--persist-terminal-loop-operational-proof-binding', (string) $commands['persist_terminal_loop_operational_proof_binding']);
        $this->assertStringContainsString('--agent-control-plane-terminal-loop-operational-proof-json=', (string) $commands['refresh_completion_audit_with_terminal_loop_operational_proof']);
        $this->assertSame(
            'php artisan atlas:ai:self-construction --atlas-self-construction-os-completion-evidence-status --runtime-promotion-receipt-json=@/path/to/runtime-promotion.json --persist-runtime-promotion-receipt --json',
            $commands['persist_runtime_promotion_receipt'] ?? null
        );
        $this->assertStringNotContainsString(
            'runtime-promotion-receipt.json',
            (string) ($commands['persist_runtime_promotion_receipt'] ?? '')
        );
        $this->assertArrayHasKey('persist_human_completion_receipt', $commands);
        $this->assertArrayHasKey('final_completion_readiness_gate_status', $commands);
        $this->assertContains('refresh_completion_audit', $steps);
        $this->assertContains('persist_real_provider_smoke', $steps);
        $this->assertContains('run_endgame_verifier', $steps);
        $this->assertContains('refresh_terminal_loop_operational_proof', $steps);
        $this->assertContains('rerun_audit_until_complete', $steps);
        $this->assertTrue((bool) data_get($gate, 'terminal_loop_operational_proof_required_before_final_audit'));
        $this->assertTrue((bool) data_get($gate, 'persistence_preflight.terminal_loop_operational_proof_required_before_final_audit'));
    }

    public function test_gate_hash_is_deterministic(): void
    {
        $first = $this->buildGate();
        $second = $this->buildGate();

        $this->assertSame($first['human_gate_hash'], $second['human_gate_hash']);
        $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/', (string) $first['human_gate_hash']);
    }

    public function test_gate_payload_is_json_serializable(): void
    {
        $gate = $this->buildGate();
        $encoded = json_encode($gate, JSON_THROW_ON_ERROR);
        $this->assertJson($encoded);
    }

    public function test_status_projection_exposes_human_gate_preflight_and_runtime_safety(): void
    {
        $status = (new AtlasSelfConstructionReadinessService(new AtlasSelfConstructionReservationRepository))
            ->atlasSelfConstructionFinalCompletionHumanGateStatus();
        $summary = (array) data_get($status, 'agent_control_plane_atlas_self_construction_final_completion_human_gate_status', []);

        $this->assertSame('blocked_runtime_promotion_required', $summary['final_completion_human_gate_status']);
        $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/', (string) $summary['human_gate_hash']);
        $this->assertSame('runtime_promotion_receipt', $summary['current_required_operator_artifact']);
        $this->assertStringContainsString('--atlas-self-construction-runtime-promotion-receipt-draft-status', (string) $summary['next_required_command']);
        $this->assertStringContainsString('--persist-runtime-promotion-receipt', (string) $summary['next_required_persist_command']);
        $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/', (string) $summary['runtime_gap_matrix_hash']);
        $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/', (string) $summary['expected_runtime_gap_matrix_hash_for_promotion_receipt']);
        $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/', (string) $summary['runtime_promotion_basis_hash']);
        $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/', (string) $summary['runtime_promotion_closure_basis_hash']);
        $this->assertSame('blocked', $summary['human_receipt_verification_status']);
        $this->assertGreaterThan(0, (int) $summary['human_receipt_verification_diagnostic_count']);
        $this->assertContains('prerequisites_not_green', (array) $summary['human_receipt_verification_diagnostic_codes']);
        $this->assertFalse((bool) $summary['human_receipt_verification_can_persist']);
        $this->assertSame('human_completion_receipt_prerequisites_not_green', $summary['human_receipt_verification_persistence_blocker']);
        $this->assertFalse((bool) $summary['persistence_preflight_can_persist']);
        $this->assertContains('prerequisites_not_green', (array) $summary['persistence_preflight_blockers']);
        $this->assertGreaterThan(0, (int) $summary['persistence_preflight_blocker_count']);
        $this->assertStringContainsString('final_completion_human_gate_persistence_blocked_by_', (string) $summary['persistence_preflight_persistence_blocker']);
        $this->assertTrue((bool) $summary['terminal_loop_operational_proof_required_before_final_audit']);
        $this->assertTrue((bool) $summary['terminal_loop_operational_proof_required_before_completion_claim']);
        $this->assertTrue((bool) $summary['completion_audit_without_terminal_loop_operational_proof_is_diagnostic_only']);
        $this->assertStringContainsString('--agent-control-plane-terminal-loop-operational-proof-status', (string) $summary['terminal_loop_operational_proof_command']);
        $this->assertStringContainsString('--persist-terminal-loop-operational-proof-binding', (string) $summary['terminal_loop_operational_proof_binding_persist_command']);
        $this->assertStringContainsString('--agent-control-plane-terminal-loop-operational-proof-json=', (string) $summary['rerun_audit_with_terminal_loop_operational_proof_command']);
        $this->assertFalse((bool) $summary['completion_allowed']);
        $this->assertFalse((bool) $summary['completion_claim_allowed']);
        $this->assertFalse((bool) $summary['ledger_write_allowed']);
        $this->assertFalse((bool) $summary['runtime_write_allowed']);
        $this->assertFalse((bool) $summary['execution_allowed']);
        $this->assertFalse((bool) $summary['dispatch_allowed']);
        $this->assertFalse((bool) $summary['provider_call_allowed']);
        $this->assertFalse((bool) $summary['token_spend_allowed']);
        $this->assertFalse((bool) $summary['adapter_execution_allowed']);
        $this->assertFalse((bool) $summary['self_programming_allowed']);
    }

    private function service(): AtlasSelfConstructionFinalCompletionHumanGateService
    {
        return new AtlasSelfConstructionFinalCompletionHumanGateService(
            new AtlasSelfConstructionReadinessService(new AtlasSelfConstructionReservationRepository),
        );
    }

    /**
     * @param  array<string, mixed>  $audit
     * @param  array<string, mixed>  $evidence
     * @param  array<string, mixed>  $receipt
     */
    private function buildGate(array $audit = [], array $evidence = [], array $receipt = []): array
    {
        return $this->service()->build([
            'completion_audit' => $audit !== [] ? $audit : $this->auditWith(runtime: false, smoke: false, human: false),
            'completion_evidence' => $evidence !== [] ? $evidence : $this->evidence(runtimeY: false, runtimeReceipt: 'blocked', smoke: 'blocked'),
            'completion_receipt' => $receipt,
        ]);
    }

    /** @return array<string, mixed> */
    private function auditWith(bool $runtime, bool $smoke, bool $human, bool $complete = false): array
    {
        $hash = str_repeat('a', 64);
        $criteria = [
            ['id' => 'runtime_gap_matrix_all_runtime_y', 'passed' => $runtime, 'evidence' => ['runtime_gap_matrix_hash' => $hash, 'runtime_promotion_receipt_hash' => $hash]],
            ['id' => 'release_dossier_green', 'passed' => true, 'evidence' => ['hash' => $hash, 'baseline_snapshot_capture_required' => false]],
            ['id' => 'replay_diff_against_completion_snapshot_green', 'passed' => true, 'evidence' => ['diff_hash' => $hash]],
            ['id' => 'promotion_gate_green', 'passed' => true, 'evidence' => []],
            ['id' => 'mutation_guard_green', 'passed' => true, 'evidence' => []],
            ['id' => 'human_signed_os_complete_receipt_present', 'passed' => $human, 'evidence' => []],
            ['id' => 'end_to_end_real_provider_smoke_green', 'passed' => $smoke, 'evidence' => ['smoke_hash' => $hash]],
            ['id' => 'forge_self_improvement_integration_smoke_green', 'passed' => true, 'evidence' => []],
            ['id' => 'certification_status_batch_green', 'passed' => true, 'evidence' => ['hash' => $hash]],
        ];
        $failed = array_values(array_filter(array_map(static fn (array $row): ?string => ($row['passed'] ?? false) === false ? (string) $row['id'] : null, $criteria)));

        return [
            'status' => ($complete && $failed === []) ? 'complete' : 'incomplete',
            'completion_audit_hash' => $hash,
            'failed_criteria' => $failed,
            'failed_count' => count($failed),
            'passed_count' => count($criteria) - count($failed),
            'completion_allowed' => $complete && $failed === [],
            'criteria' => $criteria,
            'operator_action_packet' => [
                'human_completion_receipt_template' => [
                    'runtime_promotion_receipt_hash' => $hash,
                    'real_provider_smoke_hash' => $hash,
                    'certification_status_batch_hash' => $hash,
                ],
            ],
        ];
    }

    /** @return array<string, mixed> */
    private function evidence(bool $runtimeY, string $runtimeReceipt, string $smoke): array
    {
        $hash = str_repeat('a', 64);

        return [
            'status' => $runtimeY && $smoke === 'passed' ? 'ready' : 'blocked',
            'runtime_gap_matrix' => [
                'status' => $runtimeY ? 'passed' : 'blocked',
                'all_runtime_y' => $runtimeY,
                'runtime_gap_matrix_hash' => $hash,
                'runtime_promotion_receipt' => ['status' => $runtimeReceipt, 'receipt_hash' => $hash],
            ],
            'real_provider_smoke' => ['status' => $smoke, 'smoke_hash' => $hash],
            'human_signed_completion_receipt' => ['status' => 'blocked_missing_operator_receipt', 'receipt_hash' => ''],
            'operator_action_packet' => [
                'human_completion_receipt_template' => ['certification_status_batch_hash' => $hash],
            ],
        ];
    }

    /** @return array<string, mixed> */
    private function canonicalReceipt(): array
    {
        $hash = str_repeat('a', 64);
        $receipt = [
            'receipt_id' => 'final-test-receipt-1',
            'signed_by' => 'Vitore Test Operator',
            'reason' => 'Operator reviewed every final completion criterion, runtime promotion receipt and real-provider smoke evidence in this test context.',
            'completion_audit_hash' => $hash,
            'release_dossier_hash' => $hash,
            'replay_diff_hash' => $hash,
            'runtime_gap_matrix_hash' => $hash,
            'runtime_promotion_receipt_hash' => $hash,
            'real_provider_smoke_hash' => $hash,
            'certification_status_batch_hash' => $hash,
            'receipt_hash' => '',
            'os_complete_approved' => true,
            'operator_reviewed_completion_audit' => true,
            'no_autopromotion_acknowledged' => true,
        ];
        $receipt['receipt_hash'] = (new AtlasSelfConstructionCompletionEvidenceHashService)->humanCompletionReceiptHash($receipt);

        return $receipt;
    }
}
