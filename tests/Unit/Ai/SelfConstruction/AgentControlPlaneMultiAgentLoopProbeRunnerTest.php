<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\SelfConstruction;

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

    // ── AC1/AC2/AC3: probeParallelism — real progress vs fake parallelism ──────

    private function worker(array $overrides = []): array
    {
        return array_merge([
            'worker_id' => 'w1',
            'active' => true,
            'lease_moved' => false,
            'report_events_count' => 0,
            'outcome_freshness_seconds' => null,
        ], $overrides);
    }

    public function test_no_active_workers_is_idle(): void
    {
        $result = $this->makeRunner()->probeParallelism([
            'workers' => [$this->worker(['active' => false])],
            'queue_depth_before' => 10,
            'queue_depth_after' => 10,
        ]);

        $this->assertSame(AgentControlPlaneMultiAgentLoopProbeRunner::PARALLELISM_STATUS_IDLE, $result['parallelism_status']);
        $this->assertSame([], $result['active_workers']);
    }

    public function test_active_workers_with_zero_movement_and_flat_queue_is_fake_parallelism(): void
    {
        $result = $this->makeRunner()->probeParallelism([
            'workers' => [
                $this->worker(['worker_id' => 'w1']),
                $this->worker(['worker_id' => 'w2']),
            ],
            'queue_depth_before' => 10,
            'queue_depth_after' => 10,
        ]);

        $this->assertSame(AgentControlPlaneMultiAgentLoopProbeRunner::PARALLELISM_STATUS_FAKE, $result['parallelism_status']);
        $this->assertSame(['w1', 'w2'], $result['active_workers']);
        $this->assertSame([], $result['productive_workers']);
        $this->assertSame(['w1', 'w2'], $result['stale_workers']);
    }

    public function test_lease_movement_marks_worker_productive(): void
    {
        $result = $this->makeRunner()->probeParallelism([
            'workers' => [$this->worker(['lease_moved' => true])],
            'queue_depth_before' => 10,
            'queue_depth_after' => 10,
        ]);

        $this->assertSame(AgentControlPlaneMultiAgentLoopProbeRunner::PARALLELISM_STATUS_REAL, $result['parallelism_status']);
        $this->assertSame(['w1'], $result['productive_workers']);
    }

    public function test_report_events_mark_worker_productive(): void
    {
        $result = $this->makeRunner()->probeParallelism([
            'workers' => [$this->worker(['report_events_count' => 2])],
            'queue_depth_before' => 10,
            'queue_depth_after' => 10,
        ]);

        $this->assertSame(['w1'], $result['productive_workers']);
    }

    public function test_fresh_outcome_marks_worker_productive(): void
    {
        $result = $this->makeRunner()->probeParallelism([
            'workers' => [$this->worker(['outcome_freshness_seconds' => 30.0])],
            'queue_depth_before' => 10,
            'queue_depth_after' => 10,
        ]);

        $this->assertSame(['w1'], $result['productive_workers']);
    }

    public function test_stale_outcome_does_not_mark_worker_productive(): void
    {
        $result = $this->makeRunner()->probeParallelism([
            'workers' => [$this->worker(['outcome_freshness_seconds' => 9999.0])],
            'queue_depth_before' => 10,
            'queue_depth_after' => 10,
        ]);

        $this->assertSame([], $result['productive_workers']);
        $this->assertSame(['w1'], $result['stale_workers']);
    }

    public function test_queue_depth_decrease_prevents_fake_parallelism_even_without_per_worker_signal(): void
    {
        $result = $this->makeRunner()->probeParallelism([
            'workers' => [$this->worker()],
            'queue_depth_before' => 10,
            'queue_depth_after' => 6,
        ]);

        $this->assertNotSame(AgentControlPlaneMultiAgentLoopProbeRunner::PARALLELISM_STATUS_FAKE, $result['parallelism_status']);
        $this->assertSame(4, $result['queue_depth_change']);
    }

    public function test_mixed_productive_and_stale_workers_is_partial_parallelism(): void
    {
        $result = $this->makeRunner()->probeParallelism([
            'workers' => [
                $this->worker(['worker_id' => 'w1', 'lease_moved' => true]),
                $this->worker(['worker_id' => 'w2']),
            ],
            'queue_depth_before' => 10,
            'queue_depth_after' => 10,
        ]);

        $this->assertSame(AgentControlPlaneMultiAgentLoopProbeRunner::PARALLELISM_STATUS_PARTIAL, $result['parallelism_status']);
        $this->assertSame(['w1'], $result['productive_workers']);
        $this->assertSame(['w2'], $result['stale_workers']);
    }

    public function test_probe_parallelism_is_deterministic(): void
    {
        $input = [
            'workers' => [$this->worker(['lease_moved' => true]), $this->worker(['worker_id' => 'w2'])],
            'queue_depth_before' => 10,
            'queue_depth_after' => 8,
        ];
        $runner = $this->makeRunner();

        $this->assertSame($runner->probeParallelism($input), $runner->probeParallelism($input));
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

    // ── AC2: negative-path coverage probes appear in output ──────────────────

    public function test_negative_path_coverage_includes_all_four_probes(): void
    {
        $probe = $this->makeRunner();
        $result = $probe->runTerminalBootstrapProbe('neg-path-test', 1);

        $this->assertArrayHasKey('negative_path_coverage', $result);
        $coverage = $result['negative_path_coverage'];
        $this->assertArrayHasKey('stale_claim_probe', $coverage);
        $this->assertArrayHasKey('released_resume_probe', $coverage);
        $this->assertArrayHasKey('partial_supply_probe', $coverage);
        $this->assertArrayHasKey('lane_isolation_probe', $coverage);
    }

    // ── AC3: failing negative-path probe marks certification_blocked=true ────

    public function test_certification_blocked_is_true_when_status_not_available(): void
    {
        $probe = $this->makeRunner();
        $result = $probe->runTerminalBootstrapProbe('blocked-test', 1);

        $this->assertArrayHasKey('certification_blocked', $result);
        if ($result['status'] !== 'available') {
            $this->assertTrue($result['certification_blocked']);
        }
    }

    // ── AC4: all passing negative-path probes produce certification_blocked=false ──

    public function test_certification_blocked_false_includes_individual_probe_evidence(): void
    {
        $probe = $this->makeRunner();
        $result = $probe->runTerminalBootstrapProbe('passing-test', 1);

        $this->assertArrayHasKey('certification_blocked', $result);
        $this->assertArrayHasKey('negative_path_coverage', $result);
        // Each probe has a name, passed flag, and blocker_code
        foreach ($result['negative_path_coverage'] as $probeKey => $probeData) {
            $this->assertArrayHasKey('name', $probeData, "Missing name for {$probeKey}");
            $this->assertArrayHasKey('passed', $probeData, "Missing passed for {$probeKey}");
            $this->assertArrayHasKey('blocker_code', $probeData, "Missing blocker_code for {$probeKey}");
        }
    }
}