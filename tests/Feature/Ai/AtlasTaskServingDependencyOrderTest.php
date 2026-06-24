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
 * PART 2 · ORDER — the version-ladder needs ORDERED delivery: a task is only servable once every depends_on
 * task is COMPLETED. A worker that finds only dependency-gated work must WAIT (the ladder is still advancing),
 * not stop as if the queue were drained. An unknown dependency is fail-open — it never strands a task.
 */
final class AtlasTaskServingDependencyOrderTest extends TestCase
{
    private string $envFile = '';

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        $this->envFile = sys_get_temp_dir().'/atlas-order-env-'.bin2hex(random_bytes(5)).'.env';
        file_put_contents($this->envFile, "ATLAS_LOOP_MASTER_ENABLED=true\n");
        AtlasLoopMasterSwitch::$envPathOverride = $this->envFile;
    }

    protected function tearDown(): void
    {
        AtlasLoopMasterSwitch::$envPathOverride = null;
        @unlink($this->envFile);
        parent::tearDown();
    }

    public function test_a_task_waits_until_its_prerequisite_is_completed(): void
    {
        $orch = $this->orchestrator();
        $orch->prepareAndEnqueue(['task_packet' => $this->input('wave1-A', [], 1)]);
        $orch->prepareAndEnqueue(['task_packet' => $this->input('wave2-B', ['wave1-A'], 2)]);
        $serving = new AtlasTaskServingService($orch);

        // A worker pulls → gets the wave-1 task; NEVER the wave-2 task whose prerequisite is unfinished.
        $a = $serving->next('w1');
        $this->assertSame('served', $a['status']);
        $this->assertSame('wave1-A', $a['task']['task_packet_id'], 'order: the prerequisite is served first');

        // Another worker pulls → the only remaining task is gated by A → WAIT (not drained, not served).
        $b = $serving->next('w2');
        $this->assertSame('waiting_on_dependencies', $b['status'], 'a gated task makes the worker wait, not stop');

        // A completes → B opens for serving.
        (new AgentControlPlaneTaskPacketQueueRepository)->updateStatus('wave1-A', 'completed_dry_run', ['agent_id' => 'w1']);
        $b2 = $serving->next('w2');
        $this->assertSame('served', $b2['status'], 'once the prerequisite is done, the dependent task is servable');
        $this->assertSame('wave2-B', $b2['task']['task_packet_id']);
    }

    public function test_an_unknown_dependency_is_fail_open_and_never_strands(): void
    {
        $orch = $this->orchestrator();
        $orch->prepareAndEnqueue(['task_packet' => $this->input('solo', ['this-dep-was-never-enqueued'], 1)]);
        $serving = new AtlasTaskServingService($orch);

        $r = $serving->next('w1');
        $this->assertSame('served', $r['status'], 'an unknown dependency is treated as satisfied → never strands a task');
        $this->assertSame('solo', $r['task']['task_packet_id']);
    }

    /** @param list<string> $dependsOn @return array<string, mixed> */
    private function input(string $id, array $dependsOn = [], int $wave = 0): array
    {
        return [
            'task_packet_id' => $id,
            'objective' => 'ordered task '.$id,
            'operator_id' => 'tester',
            'allowed_files' => ['app/Services/Ai/SelfConstruction/Generated/'.$id.'.php'],
            'scope_in' => ['app/Services/Ai/SelfConstruction/Generated/'.$id.'.php'],
            'acceptance_criteria' => ['ok'],
            'required_evidence' => ['tests_or_gates_result'],
            'depends_on' => $dependsOn,
            'wave' => $wave,
        ];
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
}
