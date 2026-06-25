<?php

declare(strict_types=1);

namespace Tests\Feature\Ai;

use App\Services\Ai\SelfConstruction\AgentControlPlaneClaimLeaseRepository;
use App\Services\Ai\SelfConstruction\AgentControlPlaneContinuationSummaryBuilder;
use App\Services\Ai\SelfConstruction\AgentControlPlaneEvidenceLedgerDryRun;
use App\Services\Ai\SelfConstruction\AgentControlPlaneScopeLockRuntimeValidator;
use App\Services\Ai\SelfConstruction\AgentControlPlaneTaskPacketBuilder;
use App\Services\Ai\SelfConstruction\AgentControlPlaneTaskPacketQueueRepository;
use App\Services\Ai\SelfConstruction\AgentControlPlaneTaskQueueOrchestrator;
use App\Services\Ai\SelfConstruction\AtlasTaskCoordinationHealthService;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * PART 2 · axis 10 — health must AGREE with serving. Raw `claimable_depth` counted dependency-gated and probe
 * records a worker can never pull, so a queue that looked full (135 claimable) could serve nothing (0). The
 * panel now reports `servable_now` from the orchestrator's OWN claim predicate, and FLAGS a true jam.
 */
final class AtlasTaskServingHealthServabilityTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
    }

    public function test_servable_now_excludes_dependency_gated_and_probe_records(): void
    {
        $orch = $this->orchestrator();
        $orch->prepareAndEnqueue(['task_packet' => $this->input('svc-A', [], 1)]);           // servable
        $orch->prepareAndEnqueue(['task_packet' => $this->input('svc-B', ['svc-A'], 2)]);     // gated by in-flight A
        $orch->prepareAndEnqueue(['task_packet' => $this->input('probe_svc_C', [], 1)]);      // certification probe

        $snap = $this->health()->snapshot();

        $this->assertSame(3, $snap['claimable_depth'], 'raw claimable counts everything');
        $this->assertSame(1, $snap['servable_now'], 'servable_now excludes the in-flight-gated task and the probe');
        $this->assertSame(1, $snap['servability']['waiting_on_inflight_deps']);
        $this->assertSame(1, $snap['servability']['certification_probe_excluded']);
        $this->assertFalse($snap['health_flags']['serving_jammed'], 'not jammed: one task is servable');
        $this->assertTrue($snap['healthy']);
    }

    public function test_servable_now_agrees_with_what_serving_actually_delivers(): void
    {
        $orch = $this->orchestrator();
        $orch->prepareAndEnqueue(['task_packet' => $this->input('svc-A', [], 1)]);
        $orch->prepareAndEnqueue(['task_packet' => $this->input('svc-B', ['svc-A'], 2)]);
        $orch->prepareAndEnqueue(['task_packet' => $this->input('probe_svc_C', [], 1)]);

        // Health says exactly 1 servable; the serving front door delivers exactly that one real task next.
        $this->assertSame(1, $this->health()->snapshot()['servable_now']);
        $served = (new \App\Services\Ai\SelfConstruction\AtlasTaskServingService($orch))->next('w1');
        $this->assertSame('served', $served['status']);
        $this->assertSame('svc-A', $served['task']['task_packet_id']);
    }

    public function test_a_queue_that_looks_full_but_serves_nothing_is_flagged_jammed(): void
    {
        // EXACTLY the failure the old raw claimable_depth hid: claimable>0, servable_now=0, nothing advancing.
        $orch = $this->orchestrator();
        $orch->prepareAndEnqueue(['task_packet' => $this->input('jam-prereq', [], 1)]);
        $orch->prepareAndEnqueue(['task_packet' => $this->input('jam-dependent', ['jam-prereq'], 2)]);
        (new AgentControlPlaneTaskPacketQueueRepository)->updateStatus('jam-prereq', 'blocked', ['reason' => 'quarantined']);

        $snap = $this->health()->snapshot();

        $this->assertSame(1, $snap['claimable_depth'], 'looks like there is work');
        $this->assertSame(0, $snap['servable_now'], 'but none of it is servable');
        $this->assertSame(1, $snap['servability']['blocked_by_dead_prereq']);
        $this->assertTrue($snap['health_flags']['serving_jammed'], 'a full-but-unservable queue is JAMMED, surfaced not hidden');
        $this->assertFalse($snap['healthy'], 'a serving jam is a coordination failure');
    }

    public function test_recoverable_backlog_is_not_flagged_as_a_jam(): void
    {
        // A claimable task gated ONLY by a dead prereq (servable_now=0, nothing advancing) PLUS a task a worker
        // gave back (now `released`, recoverable). The next claim's pre-sweep re-admits the released task, so this
        // is NOT a jam — health must not falsely report DEGRADED (the bug the adversarial pass caught).
        $orch = $this->orchestrator();
        $orch->prepareAndEnqueue(['task_packet' => $this->input('blk-prereq', [], 1)]);
        $orch->prepareAndEnqueue(['task_packet' => $this->input('blk-dependent', ['blk-prereq'], 2)]);
        (new AgentControlPlaneTaskPacketQueueRepository)->updateStatus('blk-prereq', 'blocked', ['reason' => 'quarantined']);

        $serving = new \App\Services\Ai\SelfConstruction\AtlasTaskServingService($orch);
        $orch->prepareAndEnqueue(['task_packet' => $this->input('recoverable-Z', [], 1)]);
        $claim = $serving->next('w-claimer');
        $this->assertSame('served', $claim['status']);
        $this->assertSame('recoverable-Z', $claim['task']['task_packet_id']);
        $serving->report('w-claimer', 'recoverable-Z', $claim['task']['lease_id'], ['outcome' => 'give_back']);

        $snap = $this->health()->snapshot();
        $this->assertSame(0, $snap['servable_now'], 'nothing is servable from the current claimable set');
        $this->assertGreaterThan(0, $snap['recoverable']['total'], 'the given-back task is recoverable');
        $this->assertFalse($snap['health_flags']['serving_jammed'], 'recoverable backlog is not a jam — the next claim re-admits it');
        $this->assertTrue($snap['healthy'], 'a recoverable queue is healthy, not DEGRADED');

        // PROOF the belt flows: the next claim pre-sweeps the released task and serves it.
        $next = $serving->next('w-next');
        $this->assertSame('served', $next['status']);
        $this->assertSame('recoverable-Z', $next['task']['task_packet_id']);
    }

    private function health(): AtlasTaskCoordinationHealthService
    {
        // Default-constructed: it builds its servability orchestrator over the SAME repos it reports on.
        return new AtlasTaskCoordinationHealthService(
            new AgentControlPlaneTaskPacketQueueRepository,
            new AgentControlPlaneClaimLeaseRepository,
        );
    }

    private function orchestrator(): AgentControlPlaneTaskQueueOrchestrator
    {
        return new AgentControlPlaneTaskQueueOrchestrator(
            new AgentControlPlaneTaskPacketBuilder,
            new AgentControlPlaneScopeLockRuntimeValidator,
            new AgentControlPlaneTaskPacketQueueRepository,
            new AgentControlPlaneClaimLeaseRepository,
            new AgentControlPlaneEvidenceLedgerDryRun,
            new AgentControlPlaneContinuationSummaryBuilder,
        );
    }

    /** @param list<string> $dependsOn @return array<string, mixed> */
    private function input(string $id, array $dependsOn = [], int $wave = 0): array
    {
        return [
            'task_packet_id' => $id,
            'objective' => 'health task '.$id,
            'operator_id' => 'tester',
            'allowed_files' => [
                'app/Services/Ai/SelfConstruction/Generated/'.$id.'.php',
                'tests/Unit/Ai/SelfConstruction/Generated/'.$id.'Test.php',
            ],
            'scope_in' => [
                'app/Services/Ai/SelfConstruction/Generated/'.$id.'.php',
                'tests/Unit/Ai/SelfConstruction/Generated/'.$id.'Test.php',
            ],
            'acceptance_criteria' => ['ok'],
            'required_evidence' => ['tests_or_gates_result'],
            'depends_on' => $dependsOn,
            'wave' => $wave,
        ];
    }
}
