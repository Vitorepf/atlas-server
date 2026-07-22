<?php

declare(strict_types=1);

namespace Tests\Feature\Ai;

use App\Services\Ai\SelfConstruction\AgentControlPlaneTaskQueueOrchestrator;
use App\Services\Ai\SelfConstruction\ControlPlane\AgentControlPlaneClaimLeaseRepository;
use App\Services\Ai\SelfConstruction\ControlPlane\AgentControlPlaneMultiAgentLoopCertificationService;
use App\Services\Ai\SelfConstruction\ControlPlane\AgentControlPlaneMultiAgentLoopProbeRunner;
use App\Services\Ai\SelfConstruction\ControlPlane\AgentControlPlaneTaskLeaseRecoveryService;
use App\Services\Ai\SelfConstruction\ControlPlane\AgentControlPlaneTaskPacketQueueRepository;
use App\Services\Ai\SelfConstruction\ControlPlane\AgentControlPlaneTerminalLoopHealthDigestService;
use App\Services\Ai\SelfConstruction\ControlPlane\AgentControlPlaneTerminalWorkerBootstrapService;
use Closure;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
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
    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');
    }

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

    public function test_direct_released_resume_probe_removes_its_synthetic_queue_and_lease_artifacts(): void
    {
        $runId = 'direct-cleanup';
        $taskPacketId = 'fleet_released_resume_probe_'.$runId;

        $result = $this->makeRunner()->runTerminalFleetReleasedResumeProbe($runId);

        $this->assertSame('claimable', $result['final_queue_status']);
        $this->assertNull(app(AgentControlPlaneTaskPacketQueueRepository::class)->get($taskPacketId));
        $this->assertTrue($result['synthetic_artifacts_cleaned']);
    }

    public function test_every_public_mutating_probe_returns_with_an_empty_synthetic_control_plane(): void
    {
        $runner = $this->makeRunner();
        $bootstrap = app(AgentControlPlaneTerminalWorkerBootstrapService::class);
        $probes = [
            fn (): array => $runner->runTerminalBootstrapProbe('cleanup-bootstrap', 1),
            fn (): array => $runner->runTerminalFleetLaunchPlanProbe('cleanup-launch', 1),
            fn (): array => $runner->runTerminalBootstrapPartialSupplyProbe($bootstrap, 'cleanup-partial'),
            fn (): array => $runner->runTerminalFleetPartialSupplyGateProbe('cleanup-fleet-partial'),
            fn (): array => $runner->runTerminalFleetLaneIsolationNegativeProbe('cleanup-lane'),
            fn (): array => $runner->runTerminalFleetResumeRollupProbe('cleanup-resume'),
            fn (): array => $runner->runTerminalFleetMetadataOrphanRecoveryProbe('cleanup-metadata'),
            fn (): array => $runner->runTerminalFleetReleasedResumeProbe('cleanup-released'),
            fn (): array => $runner->runTerminalFleetEvidenceRollupProbe('cleanup-evidence'),
            fn (): array => $runner->runTerminalBootstrapInvalidScopeProbe($bootstrap, 'cleanup-invalid-scope'),
        ];

        foreach ($probes as $probe) {
            $result = $probe();

            $this->assertTrue($result['synthetic_artifacts_cleaned']);
            $this->assertSame(0, app(AgentControlPlaneTaskPacketQueueRepository::class)->registry()['total_count']);
        }

        $leaseFiles = array_values(array_filter(
            Storage::disk('local')->files(AgentControlPlaneClaimLeaseRepository::STORAGE_PREFIX),
            static fn (string $path): bool => str_starts_with(basename($path), 'lease_'),
        ));

        $this->assertSame([], $leaseFiles);
    }

    public function test_mutating_probe_cleans_its_synthetic_artifacts_when_a_post_enqueue_check_throws(): void
    {
        $runner = $this->makeRunner(
            terminalBootstrapRuntimeSafetyFn: static function (array $results): bool {
                throw new RuntimeException('forced post-enqueue probe failure');
            },
        );

        try {
            $runner->runTerminalBootstrapProbe('cleanup-exception', 1);
            $this->fail('The injected post-enqueue check must abort the probe.');
        } catch (RuntimeException $exception) {
            $this->assertSame('forced post-enqueue probe failure', $exception->getMessage());
        }

        $this->assertSame(0, app(AgentControlPlaneTaskPacketQueueRepository::class)->registry()['total_count']);
    }

    public function test_status_envelopes_fail_closed_when_a_computed_verification_is_false(): void
    {
        $cases = [
            'fleet launch lane isolation' => [
                'corrupt' => static function (array &$digest): void {
                    data_set($digest, 'terminal_loop_fleet_lane_isolation.all_commands_lane_bound', false);
                },
                'invoke' => static fn (AgentControlPlaneMultiAgentLoopProbeRunner $runner): array => $runner->runTerminalFleetLaunchPlanProbe('status-launch', 1),
                'verification_key' => 'lane_bound_commands_verified',
            ],
            'fleet resume operator handoff' => [
                'corrupt' => static function (array &$digest): void {
                    data_set($digest, 'terminal_loop_fleet_operator_handoff.next_operator_action', 'incorrect_recovery_priority');
                },
                'invoke' => static fn (AgentControlPlaneMultiAgentLoopProbeRunner $runner): array => $runner->runTerminalFleetResumeRollupProbe('status-resume'),
                'verification_key' => 'operator_handoff_recovery_priority_verified',
            ],
            'fleet evidence cycle supervisor' => [
                'corrupt' => static function (array &$digest): void {
                    data_set($digest, 'terminal_loop_cycle_supervisor.next_command_purpose', 'incorrect_evidence_review_path');
                },
                'invoke' => static fn (AgentControlPlaneMultiAgentLoopProbeRunner $runner): array => $runner->runTerminalFleetEvidenceRollupProbe('status-evidence'),
                'verification_key' => 'cycle_supervisor_evidence_review_path_verified',
            ],
        ];

        foreach ($cases as $label => $case) {
            $runner = $this->makeRunner(
                terminalLoopHealthDigestFn: function (array $options) use ($case): array {
                    $digest = (new AgentControlPlaneTerminalLoopHealthDigestService(
                        app(AgentControlPlaneTaskPacketQueueRepository::class),
                        new AgentControlPlaneTaskLeaseRecoveryService,
                    ))->digest($options);
                    ($case['corrupt'])($digest);

                    return $digest;
                },
            );

            $result = ($case['invoke'])($runner);

            $this->assertFalse($result[$case['verification_key']], $label);
            $this->assertSame('blocked', $result['status'], $label);
            $this->assertTrue($result['synthetic_artifacts_cleaned'], $label);
        }
    }

    /**
     * Wire up a runner directly via the container, using in-memory fakes.
     */
    private function makeRunner(
        ?Closure $terminalBootstrapRuntimeSafetyFn = null,
        ?Closure $terminalLoopHealthDigestFn = null,
    ): AgentControlPlaneMultiAgentLoopProbeRunner {
        $orchestrator = app(AgentControlPlaneTaskQueueOrchestrator::class);
        $queue = app(AgentControlPlaneTaskPacketQueueRepository::class);
        $leases = app(AgentControlPlaneClaimLeaseRepository::class);

        return new AgentControlPlaneMultiAgentLoopProbeRunner(
            $orchestrator,
            $queue,
            $leases,
            fn (array $items): array => [],
            fn (array $writeSets): int => 0,
            $terminalBootstrapRuntimeSafetyFn ?? fn (array $results): bool => true,
            $terminalLoopHealthDigestFn,
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
