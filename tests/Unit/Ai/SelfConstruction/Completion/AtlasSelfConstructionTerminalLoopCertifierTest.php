<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\SelfConstruction\Completion;

use App\Services\Ai\SelfConstruction\AtlasSelfConstructionReadinessService;
use App\Services\Ai\SelfConstruction\Completion\AtlasSelfConstructionTerminalLoopCertifier;
use Tests\TestCase;

class AtlasSelfConstructionTerminalLoopCertifierTest extends TestCase
{
    private function readiness(): AtlasSelfConstructionReadinessService
    {
        return new AtlasSelfConstructionReadinessService(new \App\Services\Ai\SelfConstruction\AtlasSelfConstructionReservationRepository);
    }

    private function makeModules(): array
    {
        return [
            [
                'id' => 'mod_a',
                'label' => 'Module A',
                'readiness_method' => 'nonexistentMethod',
                'service_class' => self::class,
                'doc_anchor' => 'doc-anchor-a',
                'cli_option' => '--cli-opt-a',
            ],
        ];
    }

    public function test_terminal_loop_invariant_expectation_always_true(): void
    {
        self::assertTrue(AtlasSelfConstructionTerminalLoopCertifier::terminalLoopInvariantExpectation('any_name'));
    }

    public function test_evaluate_terminal_loop_invariants_returns_all_keys(): void
    {
        $result = AtlasSelfConstructionTerminalLoopCertifier::evaluateTerminalLoopInvariants([]);

        self::assertArrayHasKey('runtime_safety_all_false', $result);
        self::assertArrayHasKey('queue_transition_policy_enforced', $result);
        self::assertArrayHasKey('safe_for_parallel_terminal_loop', $result);
    }

    public function test_evaluate_terminal_loop_invariants_structural_defaults(): void
    {
        $result = AtlasSelfConstructionTerminalLoopCertifier::evaluateTerminalLoopInvariants([]);

        self::assertTrue($result['runtime_safety_all_false']);
        self::assertTrue($result['completion_does_not_mark_real_os_completion']);
    }

    public function test_evaluate_terminal_loop_invariants_apply_overrides(): void
    {
        $overrides = ['no_duplicate_claims' => false];

        $result = AtlasSelfConstructionTerminalLoopCertifier::evaluateTerminalLoopInvariants($overrides);

        self::assertFalse($result['no_duplicate_claims']);
    }

    public function test_evaluate_terminal_loop_invariants_ignore_unknown_keys(): void
    {
        $overrides = ['unknown_key' => true];

        $result = AtlasSelfConstructionTerminalLoopCertifier::evaluateTerminalLoopInvariants($overrides);

        self::assertArrayNotHasKey('unknown_key', $result);
    }

    public function test_evaluate_terminal_loop_invariants_apply_multi_agent_matrix(): void
    {
        $overrides = [
            'multi_agent_loop_certification' => [
                'canonical_invariant_matrix' => [
                    'invariants' => [
                        'no_duplicate_claims' => ['value' => true],
                    ],
                ],
            ],
        ];

        $result = AtlasSelfConstructionTerminalLoopCertifier::evaluateTerminalLoopInvariants($overrides);

        self::assertTrue($result['no_duplicate_claims']);
    }

    public function test_terminal_loop_contract_doc_uses_path_override(): void
    {
        $tmpPath = tempnam(sys_get_temp_dir(), 'doc_');
        file_put_contents($tmpPath, 'CONTRACT_CONTENT');
        try {
            $result = AtlasSelfConstructionTerminalLoopCertifier::terminalLoopContractDoc(['contract_doc_path' => $tmpPath]);
            self::assertSame('CONTRACT_CONTENT', $result);
        } finally {
            @unlink($tmpPath);
        }
    }

    public function test_terminal_loop_command_surface_uses_path_override(): void
    {
        $tmpPath = tempnam(sys_get_temp_dir(), 'cmd_');
        file_put_contents($tmpPath, 'CMD_CONTENT');
        try {
            $result = AtlasSelfConstructionTerminalLoopCertifier::terminalLoopCommandSurface(['command_file_path' => $tmpPath]);
            self::assertSame('CMD_CONTENT', $result);
        } finally {
            @unlink($tmpPath);
        }
    }

    public function test_certify_passes_when_overrides_all_provide_artifacts(): void
    {
        $modules = [
            [
                'id' => 'mod_a',
                'label' => 'Module A',
                'readiness_method' => 'fakeMethodA',
                'service_class' => self::class,
                'doc_anchor' => 'DOC_A',
                'cli_option' => 'CLI_A',
            ],
        ];
        $options = [
            'module_overrides' => [
                'mod_a' => [
                    'readiness_method_available' => true,
                    'service_class_exists' => true,
                    'doc_bullet_exists' => true,
                    'cli_surface_exists' => true,
                ],
            ],
        ];

        $payload = AtlasSelfConstructionTerminalLoopCertifier::certifyAgentControlPlaneTerminalLoop(
            $options,
            $modules,
            $this->readiness(),
            'test.v1',
        );

        self::assertTrue($payload['passed']);
        self::assertSame('available', $payload['status']);
        self::assertSame(1, $payload['modules_passed']);
        self::assertSame(0, $payload['modules_blocked']);
    }

    public function test_certify_blocks_when_doc_bullet_missing(): void
    {
        $modules = $this->makeModules();
        $options = [
            'module_overrides' => [
                'mod_a' => [
                    'readiness_method_available' => true,
                    'service_class_exists' => true,
                    'doc_bullet_exists' => false,
                    'cli_surface_exists' => true,
                ],
            ],
        ];

        $payload = AtlasSelfConstructionTerminalLoopCertifier::certifyAgentControlPlaneTerminalLoop(
            $options,
            $modules,
            $this->readiness(),
            'test.v1',
        );

        self::assertFalse($payload['passed']);
        self::assertContains('doc_bullet', $payload['modules'][0]['missing_artifacts']);
    }

    public function test_certify_includes_runtime_safety_and_guarantees(): void
    {
        $options = [
            'module_overrides' => [
                'mod_a' => [
                    'readiness_method_available' => true,
                    'service_class_exists' => true,
                    'doc_bullet_exists' => true,
                    'cli_surface_exists' => true,
                ],
            ],
        ];

        $payload = AtlasSelfConstructionTerminalLoopCertifier::certifyAgentControlPlaneTerminalLoop(
            $options,
            $this->makeModules(),
            $this->readiness(),
            'test.v1',
        );

        self::assertArrayHasKey('runtime_safety', $payload);
        self::assertFalse($payload['runtime_safety']['execution_allowed']);
        self::assertContains('terminal_loop_certification_does_not_complete_a_packet', $payload['non_execution_guarantees']);
    }

    public function test_terminal_loop_operational_proof_evidence_not_supplied(): void
    {
        $result = AtlasSelfConstructionTerminalLoopCertifier::terminalLoopOperationalProofEvidence([]);

        self::assertSame('not_supplied_to_read_only_audit', $result['status']);
        self::assertFalse($result['supplied']);
        self::assertFalse($result['passed']);
    }

    public function test_terminal_loop_operational_proof_evidence_passes_when_all_good(): void
    {
        $proof = [
            'status' => 'passed',
            'terminal_loop_operational_proof_hash' => str_repeat('a', 64),
            'invariants_all_true' => true,
            'operational_readiness_matrix' => ['all_true' => true],
            'completion_real_allowed' => false,
            'provider_call_allowed' => false,
            'token_spend_allowed' => false,
            'dispatch_allowed' => false,
            'adapter_execution_allowed' => false,
            'self_programming_allowed' => false,
            'post_cycle_cycle_supervisor' => [
                'status' => 'cycle_evidence_review_ready',
                'cycle_state' => 'review_evidence',
                'next_command_purpose' => 'review_completed_dry_run_evidence_and_rerun_digest',
                'hash' => str_repeat('b', 64),
            ],
            'post_cycle_end_to_end_contract' => [
                'status' => 'terminal_loop_end_to_end_contract_available',
                'all_required_surfaces_present' => true,
                'covered_capabilities' => [
                    'auto_replenishment', 'validation', 'leases', 'evidence',
                    'retomada', 'lane_isolation', 'cycle_supervision', 'operator_handoff',
                ],
                'failed_check_ids' => [],
                'hash' => str_repeat('c', 64),
            ],
            'post_cycle_cleanup_state' => [
                'claimed_task_count' => 0,
                'active_lease_count' => 0,
                'recoverable_lease_count' => 0,
            ],
        ];

        $result = AtlasSelfConstructionTerminalLoopCertifier::terminalLoopOperationalProofEvidence($proof);

        self::assertTrue($result['supplied']);
        self::assertTrue($result['passed']);
        self::assertSame([], $result['validation_violations']);
    }

    public function test_terminal_loop_operational_proof_evidence_flags_status_not_passed(): void
    {
        $proof = [
            'status' => 'failed',
            'terminal_loop_operational_proof_hash' => str_repeat('a', 64),
            'invariants_all_true' => true,
            'operational_readiness_matrix' => ['all_true' => true],
        ];

        $result = AtlasSelfConstructionTerminalLoopCertifier::terminalLoopOperationalProofEvidence($proof);

        self::assertContains('status_not_passed', $result['validation_violations']);
    }

    public function test_terminal_loop_operational_proof_evidence_flags_invalid_hash(): void
    {
        $proof = [
            'status' => 'passed',
            'terminal_loop_operational_proof_hash' => 'not-hex',
            'invariants_all_true' => true,
        ];

        $result = AtlasSelfConstructionTerminalLoopCertifier::terminalLoopOperationalProofEvidence($proof);

        self::assertContains('invalid_or_missing_operational_proof_hash', $result['validation_violations']);
    }

    public function test_terminal_loop_operational_proof_evidence_unwraps_nested(): void
    {
        $proof = [
            'proof_payload' => [
                'status' => 'passed',
                'terminal_loop_operational_proof_hash' => str_repeat('a', 64),
                'invariants_all_true' => true,
            ],
        ];

        $result = AtlasSelfConstructionTerminalLoopCertifier::terminalLoopOperationalProofEvidence($proof);

        self::assertTrue($result['supplied']);
    }

    public function test_trailing_newline_hash_is_rejected_as_invalid(): void
    {
        $proof = [
            'status' => 'passed',
            'terminal_loop_operational_proof_hash' => str_repeat('a', 64)."\n",
            'invariants_all_true' => true,
        ];

        $result = AtlasSelfConstructionTerminalLoopCertifier::terminalLoopOperationalProofEvidence($proof);

        self::assertContains('invalid_or_missing_operational_proof_hash', $result['validation_violations']);
    }

    public function test_certify_final_brain_loop_refuses_when_recovery_receipts_absent(): void
    {
        $result = AtlasSelfConstructionTerminalLoopCertifier::certifyFinalBrainLoop([
            'no_stale_heartbeat' => true,
            'no_queue_jam' => true,
            'balanced_lane_generation' => true,
            'muscle_feedback_success' => true,
            // recovery_receipts intentionally absent
        ]);

        $this->assertFalse($result['certified']);
        $this->assertContains('missing_or_false:recovery_receipts', $result['blockers']);
    }

    public function test_certify_final_brain_loop_refuses_when_balanced_lane_generation_false(): void
    {
        $result = AtlasSelfConstructionTerminalLoopCertifier::certifyFinalBrainLoop([
            'no_stale_heartbeat' => true,
            'no_queue_jam' => true,
            'balanced_lane_generation' => false,
            'muscle_feedback_success' => true,
            'recovery_receipts' => true,
        ]);

        $this->assertFalse($result['certified']);
        $this->assertContains('missing_or_false:balanced_lane_generation', $result['blockers']);
    }

    public function test_certify_final_brain_loop_accepts_complete_deterministic_evidence_bundle(): void
    {
        $result = AtlasSelfConstructionTerminalLoopCertifier::certifyFinalBrainLoop([
            'no_stale_heartbeat' => true,
            'no_queue_jam' => true,
            'balanced_lane_generation' => true,
            'muscle_feedback_success' => true,
            'recovery_receipts' => true,
        ]);

        $this->assertTrue($result['certified']);
        $this->assertSame([], $result['blockers']);
        $this->assertSame(AtlasSelfConstructionTerminalLoopCertifier::FINAL_BRAIN_LOOP_SCHEMA, $result['schema_version']);
        $this->assertCount(5, $result['evidence_summary']);
        $this->assertTrue(array_reduce($result['evidence_summary'], fn (bool $carry, bool $v): bool => $carry && $v, true));
    }

    public function test_terminal_loop_operational_proof_evidence_flags_post_cycle_not_zero(): void
    {
        $proof = [
            'status' => 'passed',
            'terminal_loop_operational_proof_hash' => str_repeat('a', 64),
            'invariants_all_true' => true,
            'operational_readiness_matrix' => ['all_true' => true],
            'post_cycle_cleanup_state' => [
                'claimed_task_count' => 5,
            ],
        ];

        $result = AtlasSelfConstructionTerminalLoopCertifier::terminalLoopOperationalProofEvidence($proof);

        self::assertContains('post_cycle_claimed_tasks_not_zero', $result['validation_violations']);
    }
}