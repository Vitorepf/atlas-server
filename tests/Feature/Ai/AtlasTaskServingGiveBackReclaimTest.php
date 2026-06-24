<?php

declare(strict_types=1);

namespace Tests\Feature\Ai;

use App\Services\Ai\AutonomousEvolution\AtlasLoopMasterSwitch;
use App\Services\Ai\SelfConstruction\AgentControlPlaneClaimLeaseRepository;
use App\Services\Ai\SelfConstruction\AgentControlPlaneContinuationSummaryBuilder;
use App\Services\Ai\SelfConstruction\AgentControlPlaneEvidenceLedgerDryRun;
use App\Services\Ai\SelfConstruction\AgentControlPlaneScopeLockRuntimeValidator;
use App\Services\Ai\SelfConstruction\AgentControlPlaneTaskPacketBuilder;
use App\Services\Ai\SelfConstruction\AgentControlPlaneTaskPacketQueueRepository;
use App\Services\Ai\SelfConstruction\AgentControlPlaneTaskQueueOrchestrator;
use App\Services\Ai\SelfConstruction\AtlasTaskServingService;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * PART 2 · R1/R2 — a client give-back must RETURN the task to the servable pool, not strand it.
 *
 * THE GAP (verified): `report give_back/failed` releases the lease and moves the queue record to `released`,
 * but the live serving reclaim path (claimNext pre-sweep + the scheduled reaper) only recovered EXPIRED and
 * ORPHANED tasks — never RELEASED ones. So every give-back silently drained a task out of the queue forever
 * (it only came back if a certification probe happened to run `recoverReleasedTasks`, which serving never does).
 * That violates "the queue never dries" (R1) and "serving never fails to deliver" (R2).
 */
final class AtlasTaskServingGiveBackReclaimTest extends TestCase
{
    private string $envFile = '';

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        $this->envFile = sys_get_temp_dir().'/atlas-gb-env-'.bin2hex(random_bytes(5)).'.env';
        file_put_contents($this->envFile, "ATLAS_LOOP_MASTER_ENABLED=true\n");
        AtlasLoopMasterSwitch::$envPathOverride = $this->envFile;
    }

    protected function tearDown(): void
    {
        AtlasLoopMasterSwitch::$envPathOverride = null;
        if ($this->envFile !== '') {
            @unlink($this->envFile);
        }
        parent::tearDown();
    }

    public function test_given_back_task_returns_to_the_servable_pool_for_another_client(): void
    {
        $orch = $this->orchestrator();
        $orch->prepareAndEnqueue(['task_packet' => $this->input('gb-1')]);
        $serving = new AtlasTaskServingService($orch);

        // Client A claims, then gives it back (transient — "I couldn't finish this").
        $a = $serving->next('client-a');
        $this->assertSame('served', $a['status']);
        $taskId = $a['task']['task_packet_id'];
        $lease = $a['task']['lease_id'];

        $report = $serving->report('client-a', $taskId, $lease, ['outcome' => 'give_back']);
        $this->assertSame('reported', $report['status']);
        $this->assertTrue($report['lease_released']);

        // Client B must be able to reclaim the SAME task — a give-back returns it to the pool, never strands it.
        $b = $serving->next('client-b');
        $this->assertSame('served', $b['status'], 'a given-back task returns to the servable pool (R1/R2)');
        $this->assertSame($taskId, $b['task']['task_packet_id']);
        $this->assertNotEmpty($b['task']['lease_id']);
        $this->assertNotSame($lease, $b['task']['lease_id'], 'the reclaim issues a fresh lease');
    }

    public function test_a_worker_never_re_pulls_its_own_give_back(): void
    {
        // THE BUG: a give-back returned the task to claimable and the SAME worker pulled it again instantly,
        // gave it back again, forever. Now the giver is skipped — it gets the next task (or honest empty).
        $orch = $this->orchestrator();
        $orch->prepareAndEnqueue(['task_packet' => $this->input('loop-1')]);
        $serving = new AtlasTaskServingService($orch);

        $a = $serving->next('worker-x');
        $this->assertSame('served', $a['status']);
        $serving->report('worker-x', $a['task']['task_packet_id'], $a['task']['lease_id'], ['outcome' => 'give_back']);

        // The SAME worker pulls again — it must NOT get its own give-back back (no infinite loop).
        $b = $serving->next('worker-x');
        $this->assertSame('no_claimable_task', $b['status'], 'the worker does not get its own give-back re-served');

        // But ANOTHER worker still can reclaim it.
        $c = $serving->next('worker-y');
        $this->assertSame('served', $c['status']);
        $this->assertSame('loop-1', $c['task']['task_packet_id']);
    }

    public function test_a_task_given_back_too_many_times_is_quarantined(): void
    {
        $orch = $this->orchestrator();
        $orch->prepareAndEnqueue(['task_packet' => $this->input('doomed-1')]);
        $serving = new AtlasTaskServingService($orch);

        // Bounce it across distinct workers until it crosses MAX_GIVE_BACKS → quarantined (no longer served).
        for ($i = 0; $i < 12; $i++) {
            $res = $serving->next('w'.$i);
            if ($res['status'] !== 'served') {
                break;
            }
            $serving->report('w'.$i, $res['task']['task_packet_id'], $res['task']['lease_id'], ['outcome' => 'give_back']);
        }

        $blocked = (new AgentControlPlaneTaskPacketQueueRepository)->get('doomed-1');
        $this->assertSame('blocked', (string) ($blocked['status'] ?? ''), 'a perpetually-given-back task is quarantined, never cycles forever');
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

    /** @return array<string, mixed> */
    private function input(string $id): array
    {
        return [
            'task_packet_id' => $id,
            'objective' => 'give-back test '.$id,
            'operator_id' => 'tester',
            'allowed_files' => ['app/Services/Ai/SelfConstruction/'.$id.'.php'],
            'scope_in' => ['app/Services/Ai/SelfConstruction/'.$id.'.php'],
            'acceptance_criteria' => ['ok'],
            'required_evidence' => ['task_packet_created'],
        ];
    }
}
