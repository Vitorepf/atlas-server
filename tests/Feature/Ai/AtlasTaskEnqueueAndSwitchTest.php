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
use App\Services\Ai\SelfConstruction\AtlasTaskServingSwitch;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * PART 2 — the operator's manual front door (atlas:task:enqueue) + the dedicated serving switch.
 */
final class AtlasTaskEnqueueAndSwitchTest extends TestCase
{
    private string $envFile = '';

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        $this->envFile = sys_get_temp_dir().'/atlas-enq-env-'.bin2hex(random_bytes(5)).'.env';
        file_put_contents($this->envFile, "ATLAS_LOOP_MASTER_ENABLED=true\n");
        AtlasLoopMasterSwitch::$envPathOverride = $this->envFile;
    }

    protected function tearDown(): void
    {
        AtlasLoopMasterSwitch::$envPathOverride = null;
        AtlasTaskServingSwitch::$envPathOverride = null;
        @unlink($this->envFile);
        parent::tearDown();
    }

    public function test_enqueue_a_well_specified_task_then_serve_it(): void
    {
        $exit = Artisan::call('atlas:task:enqueue', [
            '--objective' => 'wire the FooBar into the registry',
            '--allow' => ['app/Services/Foo/Bar.php'],
            '--accept' => ['the FooBar resolves from the container'],
            '--evidence' => ['tests_or_gates_result'],
            '--id' => 'op-demo-1',
            '--json' => true,
        ]);
        $this->assertSame(0, $exit, 'a well-specified task enqueues');

        // The container-resolved serving surface (same faked disk) now serves it.
        $serving = new AtlasTaskServingService($this->orchestrator());
        $res = $serving->next('some-ai');
        $this->assertSame('served', $res['status']);
        $this->assertSame('op-demo-1', $res['task']['task_packet_id']);
        $this->assertSame(['app/Services/Foo/Bar.php'], $res['task']['allowed_files']);
    }

    public function test_enqueue_rejects_an_underspecified_task(): void
    {
        // No acceptance, no evidence ⇒ a cold AI could not implement/prove it ⇒ rejected at the door.
        $exit = Artisan::call('atlas:task:enqueue', [
            '--objective' => 'do something vague',
            '--allow' => ['app/Services/Foo/Bar.php'],
            '--json' => true,
        ]);
        $out = Artisan::output();

        $this->assertSame(1, $exit, 'an underspecified task is rejected');
        $this->assertStringContainsString('not_self_sufficient', $out);
        $this->assertStringContainsString('missing_acceptance_criteria', $out);
    }

    public function test_serving_switch_independently_gates_next(): void
    {
        // Decouple from the master switch to prove the dedicated flag governs serving.
        AtlasLoopMasterSwitch::$envPathOverride = $this->envFile;
        file_put_contents($this->envFile, "ATLAS_LOOP_MASTER_ENABLED=false\n");
        $servingEnv = sys_get_temp_dir().'/atlas-srv-'.bin2hex(random_bytes(5)).'.env';
        AtlasTaskServingSwitch::$envPathOverride = $servingEnv;

        // Off by default (fail-closed): serving is disabled.
        file_put_contents($servingEnv, "ATLAS_TASK_SERVING_ENABLED=false\n");
        $serving = new AtlasTaskServingService($this->orchestrator());
        $this->assertSame('disabled', $serving->next('ai')['status']);

        // Operator turns serving ON (without the autonomous master switch).
        AtlasTaskServingSwitch::on();
        $this->assertTrue(AtlasTaskServingSwitch::enabled());
        $this->assertSame('no_claimable_task', $serving->next('ai')['status'], 'serving is live (empty queue is honest)');

        @unlink($servingEnv);
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
