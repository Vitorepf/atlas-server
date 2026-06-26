<?php

declare(strict_types=1);

namespace Tests\Feature\Ai;

use App\Services\Ai\SelfConstruction\AgentControlPlaneClaimLeaseRepository;
use App\Services\Ai\SelfConstruction\AgentControlPlaneMultiAgentLoopCertificationService;
use App\Services\Ai\SelfConstruction\AgentControlPlaneMultiAgentLoopProbeRunner;
use App\Services\Ai\SelfConstruction\AgentControlPlaneTaskPacketQueueRepository;
use App\Services\Ai\SelfConstruction\AgentControlPlaneTaskQueueOrchestrator;
use Closure;
use Tests\TestCase;

/**
 * Locks the contract of AgentControlPlaneMultiAgentLoopProbeRunner — the
 * terminal-loop probe concern extracted from the god-class
 * AgentControlPlaneMultiAgentLoopCertificationService.
 *
 * The runtime service delegates 12 methods to this collaborator; this test
 * pins the wiring so a future refactor cannot silently break the delegation
 * contract.
 */
final class AtlasAiSelfConstructionAgentControlPlaneMultiAgentLoopProbeRunnerTest extends TestCase
{
    public function test_runner_class_is_resolvable_with_required_dependencies(): void
    {
        $runner = $this->makeRunner();

        $this->assertInstanceOf(AgentControlPlaneMultiAgentLoopProbeRunner::class, $runner);
    }

    public function test_synthetic_file_namespace_constant_matches_runtime_service(): void
    {
        // The runner's SYNTHETIC_FILE_NAMESPACE is delegated to the
        // runtime service — pin the wiring so the two cannot drift.
        $this->assertSame(
            AgentControlPlaneMultiAgentLoopCertificationService::SYNTHETIC_FILE_NAMESPACE,
            AgentControlPlaneMultiAgentLoopProbeRunner::SYNTHETIC_FILE_NAMESPACE
        );
    }

    public function test_runtime_service_delegates_all_twelve_methods_to_runner(): void
    {
        // The 12 terminal-loop probe methods are the surgical scope of
        // this extraction. Every one must exist as a delegator on the
        // runtime service AND on the runner — missing one means a future
        // refactor accidentally dropped the wiring.
        $methods = [
            'runTerminalBootstrapProbe',
            'runTerminalFleetLaunchPlanProbe',
            'runTerminalBootstrapPartialSupplyProbe',
            'runTerminalFleetPartialSupplyGateProbe',
            'runTerminalFleetLaneIsolationNegativeProbe',
            'runTerminalFleetResumeRollupProbe',
            'runTerminalFleetMetadataOrphanRecoveryProbe',
            'runTerminalFleetReleasedResumeProbe',
            'runTerminalFleetEvidenceRollupProbe',
            'runTerminalBootstrapPreviewProbe',
            'runTerminalBootstrapInvalidScopeProbe',
            'terminalBootstrapContext',
        ];

        $runtime = app(AgentControlPlaneMultiAgentLoopCertificationService::class);
        $runner = $this->makeRunner();

        foreach ($methods as $method) {
            $this->assertTrue(
                method_exists($runtime, $method),
                "AgentControlPlaneMultiAgentLoopCertificationService::{$method} must exist as a delegator"
            );
            $this->assertTrue(
                method_exists($runner, $method),
                "AgentControlPlaneMultiAgentLoopProbeRunner::{$method} must exist as the implementation"
            );
        }
    }

    public function test_delegator_returns_byte_identical_payload_to_runner(): void
    {
        // When the runtime service delegates to the runner via
        // ->probeRunner(), the wire-up should pass-through identical results.
        // For a minimal proof we exercise the cheapest probe —
        // terminalBootstrapContext — which returns a deterministic envelope.
        $runtime = app(AgentControlPlaneMultiAgentLoopCertificationService::class);
        $runner = $this->resolveRunner($runtime);

        $runtimeResult = $runtime->terminalBootstrapContext('test-run', 2);
        $runnerResult = $runner->terminalBootstrapContext('test-run', 2);

        $this->assertSame($runnerResult, $runtimeResult);
    }

    /**
     * Wire up a runner directly via the container, using in-memory fakes.
     */
    private function makeRunner(): AgentControlPlaneMultiAgentLoopProbeRunner
    {
        $orchestrator = app(AgentControlPlaneTaskQueueOrchestrator::class);
        $queue = app(AgentControlPlaneTaskPacketQueueRepository::class);
        $leases = app(AgentControlPlaneClaimLeaseRepository::class);

        return new AgentControlPlaneMultiAgentLoopProbeRunner(
            $orchestrator,
            $queue,
            $leases,
            fn (array $items): array => [],
            fn (array $writeSets): int => 0,
            fn (array $results): bool => true,
        );
    }

    /**
     * Reach into the runtime service's lazy resolver to obtain the runner
     * it currently uses — proves the wiring path
     * (runtime -> probeRunner() -> runner) is consistent.
     */
    private function resolveRunner(AgentControlPlaneMultiAgentLoopCertificationService $runtime): AgentControlPlaneMultiAgentLoopProbeRunner
    {
        $ref = new \ReflectionMethod($runtime, 'probeRunner');
        $ref->setAccessible(true);

        return $ref->invoke($runtime);
    }
}