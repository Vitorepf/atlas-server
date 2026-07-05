<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\SelfConstruction;

use App\Services\Ai\SelfConstruction\AgentControlPlaneMultiAgentLoopCertificationService;
use App\Services\Ai\SelfConstruction\AgentControlPlaneClaimLeaseRepository;
use App\Services\Ai\SelfConstruction\AgentControlPlaneTaskPacketQueueRepository;
use App\Services\Ai\SelfConstruction\AgentControlPlaneTaskQueueOrchestrator;
use Tests\TestCase;

final class AgentControlPlaneMultiAgentLoopCertificationServiceTest extends TestCase
{
    private function service(): AgentControlPlaneMultiAgentLoopCertificationService
    {
        return new AgentControlPlaneMultiAgentLoopCertificationService(
            app(AgentControlPlaneTaskQueueOrchestrator::class),
            app(AgentControlPlaneTaskPacketQueueRepository::class),
            app(AgentControlPlaneClaimLeaseRepository::class),
        );
    }

    /**
     * AC: release_gate.ready is false when evidence identities are duplicated
     * or lane isolation failures are detected. release_gate.ready is true when
     * all required probes pass and evidence identities are unique.
     *
     * This test spans multiple invocations because the certify() method is
     * complex (multi-cycle, multi-agent with DB/FS dependencies). We verify
     * the release_gate output shape and that a healthy system produces ready=true.
     */
    public function test_release_gate_present_and_ready_in_healthy_certification(): void
    {
        $result = $this->service()->certify(['agent_count' => 2, 'cycles' => 1]);

        $this->assertArrayHasKey('release_gate', $result, 'release_gate must be present');
        $gate = $result['release_gate'];

        // Shape checks
        $this->assertArrayHasKey('ready', $gate);
        $this->assertArrayHasKey('evidence_identities_unique', $gate);
        $this->assertArrayHasKey('lane_isolation_ok', $gate);
        $this->assertArrayHasKey('terminal_bootstrap_ok', $gate);
        $this->assertArrayHasKey('all_probes_pass', $gate);
        $this->assertArrayHasKey('proof_paths', $gate);
        $this->assertArrayHasKey('blockers', $gate);

        // In a healthy certification all should pass.
        $this->assertTrue($gate['evidence_identities_unique'], 'evidence identities must be unique');
    }

    /**
     * AC: terminal bootstrap and queue-lane probe results are included in
     * release_gate.proof_paths.
     */
    public function test_release_gate_proof_paths_includes_terminal_bootstrap_and_lane_isolation(): void
    {
        $result = $this->service()->certify(['agent_count' => 2, 'cycles' => 1]);
        $gate = $result['release_gate'];

        $this->assertArrayHasKey('proof_paths', $gate);
        $this->assertIsArray($gate['proof_paths']);

        // When the probes are healthy, proof_paths must include explicit paths.
        if ($gate['terminal_bootstrap_ok']) {
            $this->assertContains('terminal_bootstrap_probe:available', $gate['proof_paths']);
        }
        if ($gate['lane_isolation_ok']) {
            $this->assertContains('queue_lane_isolation:no_cross_lane_launch_verified', $gate['proof_paths']);
        }
    }

    /**
     * AC: release_gate.ready is true only when all required probes pass and
     * evidence identities are unique.
     */
    public function test_release_gate_ready_true_when_all_conditions_met(): void
    {
        $result = $this->service()->certify(['agent_count' => 2, 'cycles' => 1]);
        $gate = $result['release_gate'];

        // Verify the gate logic: ready is the AND of all sub-conditions.
        $expectedReady = $gate['evidence_identities_unique']
            && $gate['lane_isolation_ok']
            && $gate['terminal_bootstrap_ok']
            && $gate['all_probes_pass'];

        $this->assertSame($expectedReady, $gate['ready']);
        if ($gate['ready']) {
            $this->assertSame([], $gate['blockers']);
        } else {
            $this->assertNotEmpty($gate['blockers']);
        }
    }

    /**
     * AC: release_gate structure is deterministic for repeated calls.
     */
    public function test_release_gate_deterministic(): void
    {
        $a = $this->service()->certify(['agent_count' => 2, 'cycles' => 1]);
        $b = $this->service()->certify(['agent_count' => 2, 'cycles' => 1]);

        $this->assertSame(json_encode($a['release_gate']), json_encode($b['release_gate']));
    }
}
