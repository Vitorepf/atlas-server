<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\EngineeringKernel;

use App\Services\Ai\EngineeringKernel\Adapters\TaskLaneMergeActuatorAdapter;
use App\Services\Ai\EngineeringKernel\Adapters\TaskServingWorkcellExecutorAdapter;
use App\Services\Ai\EngineeringKernel\MergeActuator;
use App\Services\Ai\EngineeringKernel\WorkcellExecutor;
use App\Services\Ai\SelfConstruction\ControlPlane\AgentControlPlaneClaimLeaseRepository;
use App\Services\Ai\SelfConstruction\ControlPlane\AgentControlPlaneContinuationSummaryBuilder;
use App\Services\Ai\SelfConstruction\ControlPlane\AgentControlPlaneEvidenceLedgerDryRun;
use App\Services\Ai\SelfConstruction\ControlPlane\AgentControlPlaneScopeLockRuntimeValidator;
use App\Services\Ai\SelfConstruction\ControlPlane\AgentControlPlaneTaskPacketBuilder;
use App\Services\Ai\SelfConstruction\ControlPlane\AgentControlPlaneTaskPacketQueueRepository;
use App\Services\Ai\SelfConstruction\AgentControlPlaneTaskQueueOrchestrator;
use App\Services\Ai\SelfConstruction\AtlasTaskServingService;
use App\Services\Ai\SelfConstruction\Governance\AtlasTaskMergeActuator;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\File;
use Symfony\Component\Process\Process;
use Tests\TestCase;

/**
 * Proves both kernel adapters are pure delegation — zero behavior change — with real
 * (stubbed-environment) underlying instances: a throwaway temp-dir git repo backs the
 * actuator, and an isolated temp queue disk backs the serving service.
 */
final class KernelMergeActuatorWorkcellAdaptersTest extends TestCase
{
    private string $repo = '';

    private string $diskName = '';

    private string $diskRoot = '';

    protected function setUp(): void
    {
        parent::setUp();
        $this->repo = sys_get_temp_dir().'/atlas-kernel-actuator-'.bin2hex(random_bytes(5));
        @mkdir($this->repo, 0775, true);
        $this->git(['init', '-q']);
        $this->git(['config', 'user.email', 'test@atlas.local']);
        $this->git(['config', 'user.name', 'Atlas Test']);
        $this->writeFile('README.md', "seed\n");
        $this->git(['add', 'README.md']);
        $this->git(['commit', '-q', '-m', 'seed']);

        $this->diskName = 'atlas_kernel_test_'.bin2hex(random_bytes(4));
        $this->diskRoot = sys_get_temp_dir().'/atlas-kernel-queue-'.bin2hex(random_bytes(5));
        @mkdir($this->diskRoot, 0775, true);
        Config::set('filesystems.disks.'.$this->diskName, [
            'driver' => 'local',
            'root' => $this->diskRoot,
            'throw' => false,
        ]);
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->repo);
        File::deleteDirectory($this->diskRoot);
        parent::tearDown();
    }

    // ── (a) TaskLaneMergeActuatorAdapter::revert forwards args, returns envelope untouched ──

    public function test_merge_actuator_adapter_implements_kernel_interface(): void
    {
        $adapter = new TaskLaneMergeActuatorAdapter(new AtlasTaskMergeActuator(null, $this->repo));

        $this->assertInstanceOf(MergeActuator::class, $adapter);
    }

    public function test_merge_actuator_adapter_forwards_task_id_and_dry_run_and_returns_envelope_untouched(): void
    {
        $actuator = new AtlasTaskMergeActuator(null, $this->repo);
        $adapter = new TaskLaneMergeActuatorAdapter($actuator);

        $direct = $actuator->revert('no-such-task', true);
        $viaAdapter = $adapter->revert('no-such-task', true);

        $this->assertSame($direct, $viaAdapter, 'adapter must return the actuator envelope byte-for-byte untouched');
        $this->assertSame('no-such-task', $viaAdapter['task_packet_id']);
        $this->assertTrue($viaAdapter['dry_run']);
    }

    public function test_merge_actuator_adapter_forwards_live_dry_run_flag(): void
    {
        $actuator = new AtlasTaskMergeActuator(null, $this->repo);
        $adapter = new TaskLaneMergeActuatorAdapter($actuator);

        $viaAdapter = $adapter->revert('no-such-task', false);

        $this->assertFalse($viaAdapter['dry_run']);
    }

    // ── (b) TaskServingWorkcellExecutorAdapter routes next/report, rejects unknown action ──

    public function test_workcell_executor_adapter_implements_kernel_interface(): void
    {
        $adapter = new TaskServingWorkcellExecutorAdapter($this->servingService());

        $this->assertInstanceOf(WorkcellExecutor::class, $adapter);
    }

    public function test_next_workcell_routes_to_next_and_returns_envelope_schema(): void
    {
        $service = $this->servingService();
        $adapter = new TaskServingWorkcellExecutorAdapter($service);

        $direct = $service->next('client-x', []);
        $viaAdapter = $adapter->execute(['action' => 'next', 'client_id' => 'client-x']);

        $this->assertSame(AtlasTaskServingService::ENVELOPE_SCHEMA, $viaAdapter['schema']);
        $this->assertSame($direct['status'], $viaAdapter['status']);
        $this->assertSame('client-x', $viaAdapter['client_id']);
    }

    public function test_report_workcell_routes_to_report_and_returns_report_schema(): void
    {
        $service = $this->servingService();
        $adapter = new TaskServingWorkcellExecutorAdapter($service);

        $direct = $service->report('client-y', 'task-1', 'lease-1', ['outcome' => 'success']);
        $viaAdapter = $adapter->execute([
            'action' => 'report',
            'client_id' => 'client-y',
            'task_packet_id' => 'task-1',
            'lease_id' => 'lease-1',
            'payload' => ['outcome' => 'success'],
        ]);

        $this->assertSame(AtlasTaskServingService::REPORT_SCHEMA, $viaAdapter['schema']);
        $this->assertSame($direct['status'], $viaAdapter['status']);
        $this->assertSame('client-y', $viaAdapter['client_id']);
    }

    public function test_unknown_action_is_rejected_with_a_distinct_reason_and_never_calls_serving(): void
    {
        $adapter = new TaskServingWorkcellExecutorAdapter($this->servingService());

        $r = $adapter->execute(['action' => 'delete_everything', 'client_id' => 'client-z']);

        $this->assertFalse($r['executed']);
        $this->assertSame('unknown_workcell_action', $r['reason']);
        $this->assertNotSame(AtlasTaskServingService::ENVELOPE_SCHEMA, $r['schema']);
        $this->assertNotSame(AtlasTaskServingService::REPORT_SCHEMA, $r['schema']);
    }

    private function servingService(): AtlasTaskServingService
    {
        $orchestrator = new AgentControlPlaneTaskQueueOrchestrator(
            new AgentControlPlaneTaskPacketBuilder,
            new AgentControlPlaneScopeLockRuntimeValidator,
            new AgentControlPlaneTaskPacketQueueRepository($this->diskName),
            new AgentControlPlaneClaimLeaseRepository($this->diskName),
            new AgentControlPlaneEvidenceLedgerDryRun,
            new AgentControlPlaneContinuationSummaryBuilder,
        );

        return new AtlasTaskServingService($orchestrator);
    }

    private function writeFile(string $rel, string $content): void
    {
        $path = $this->repo.'/'.$rel;
        @mkdir(\dirname($path), 0775, true);
        @file_put_contents($path, $content);
    }

    /** @return array{code:int,out:string,err:string} */
    private function git(array $args): array
    {
        $p = new Process(array_merge(['git'], $args), $this->repo);
        $p->run();

        return ['code' => (int) $p->getExitCode(), 'out' => $p->getOutput(), 'err' => $p->getErrorOutput()];
    }

}
