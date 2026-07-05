<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\SelfConstruction\MultiAgentLoopCertification;

use App\Services\Ai\SelfConstruction\MultiAgentLoopCertification\AtlasMultiAgentLoopCertificationRunner;
use Tests\TestCase;

class AtlasMultiAgentLoopCertificationRunnerTest extends TestCase
{
    private AtlasMultiAgentLoopCertificationRunner $runner;

    protected function setUp(): void
    {
        parent::setUp();
        $this->runner = new AtlasMultiAgentLoopCertificationRunner;
    }

    // ── hash-drifted terminal-loop proof fails certification ────────────

    public function test_hash_drifted_terminal_loop_proof_fails_certification(): void
    {
        $proof = [
            'proof_payload' => ['key' => 'value'],
            'expected_proof_hash' => 'the-wrong-hash',
            'health_digest' => [
                'status' => 'running',
                'terminal_loop_health_digest_hash' => 'digest-hash-xyz',
                'runtime_safety' => [
                    'runtime_execution_allowed' => false,
                    'dispatch_allowed' => false,
                    'provider_call_allowed' => false,
                    'token_spend_allowed' => false,
                    'self_programming_allowed' => false,
                ],
            ],
            'invariants' => [],
            'hashes' => [],
            'cycle_evidence' => [],
            'target_min' => 1,
            'cycles' => 1,
        ];

        $result = $this->runner->run($proof);

        self::assertFalse($result['certified'], 'Hash-drifted proof should NOT be certified');
        self::assertContains('proof_hash_drift', $result['reasons']);
        self::assertNotEmpty($result['proof']);
        self::assertIsArray($result['digest']);
        self::assertIsArray($result['readiness_matrix']);
        self::assertNotEmpty($result['certification_hash']);
        self::assertIsArray($result['invariant_matrix']);
        self::assertIsArray($result['health_digest']);
    }

    // ── missing health-digest fails certification ───────────────────────

    public function test_missing_health_digest_fails_certification(): void
    {
        $proof = [
            'proof_payload' => ['foo' => 'bar'],
            'expected_proof_hash' => '',
            'health_digest' => null,
            'invariants' => [],
            'hashes' => [],
            'cycle_evidence' => [],
            'target_min' => 1,
            'cycles' => 1,
        ];

        $result = $this->runner->run($proof);

        self::assertFalse($result['certified'], 'Missing health digest should NOT be certified');
        self::assertContains('health_digest_missing', $result['reasons']);
    }

    // ── canonical proof with present health-digest is certified ─────────

    public function test_canonical_proof_with_present_health_digest_is_certified(): void
    {
        $proof = [
            'proof_payload' => ['canonical' => 'data', 'nested' => ['a' => 1, 'b' => 2]],
            'expected_proof_hash' => '',
            'health_digest' => [
                'status' => 'running',
                'terminal_loop_health_digest_hash' => 'digest-hash-xyz',
                'lease_health' => [
                    'active_lease_count' => 0,
                    'recoverable_lease_count' => 0,
                ],
                'queue_health' => [
                    'claimable_task_count' => 0,
                    'claimed_task_count' => 0,
                ],
                'terminal_loop_fleet_evidence_rollup' => [
                    'status' => 'available',
                    'completed_dry_run_task_count' => 3,
                    'valid_completion_evidence_count' => 0,
                ],
                'terminal_loop_cycle_supervisor' => [
                    'status' => 'idle',
                    'cycle_state' => 'waiting',
                    'next_command_purpose' => 'proof_check',
                ],
                'runtime_safety' => [
                    'runtime_execution_allowed' => false,
                    'dispatch_allowed' => false,
                    'provider_call_allowed' => false,
                    'token_spend_allowed' => false,
                    'self_programming_allowed' => false,
                ],
            ],
            'invariants' => [],
            'hashes' => [],
            'cycle_evidence' => [],
            'target_min' => 1,
            'cycles' => 1,
        ];

        $result = $this->runner->run($proof);

        self::assertTrue($result['certified'], 'Canonical proof with present health digest SHOULD be certified');
        self::assertSame([], $result['reasons']);
        self::assertNotEmpty($result['proof']);
        self::assertIsArray($result['digest']);
        self::assertIsArray($result['readiness_matrix']);
        self::assertNotEmpty($result['certification_hash']);
        self::assertIsArray($result['invariant_matrix']);
        self::assertArrayHasKey('present', $result['health_digest']);
        self::assertTrue($result['health_digest']['present']);
    }
}
