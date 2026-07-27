<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\SelfConstruction;

use App\Services\Ai\AutonomousEvolution\AtlasLoopMasterSwitch;
use App\Services\Ai\SelfConstruction\ControlPlane\AgentControlPlaneClaimLeaseRepository;
use App\Services\Ai\SelfConstruction\ControlPlane\AgentControlPlaneTaskPacketBuilder;
use App\Services\Ai\SelfConstruction\ControlPlane\AgentControlPlaneTaskPacketQueueRepository;
use App\Services\Ai\SelfConstruction\TaskServing\AtlasTaskCoordinationHealthService;
use App\Services\Ai\SelfConstruction\AtlasTaskServingSentinel;
use App\Services\Ai\SelfConstruction\AtlasTaskServingService;
use Illuminate\Support\Facades\Storage;
use Tests\Concerns\MakesAgentControlPlaneTaskQueueOrchestrator;
use Tests\TestCase;

final class AtlasTaskCoordinationHealthServiceDrainFallbackTest extends TestCase
{
    use MakesAgentControlPlaneTaskQueueOrchestrator;
    private string $envFile = '';

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        $this->envFile = sys_get_temp_dir().'/atlas-health-drain-env-'.bin2hex(random_bytes(5)).'.env';
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

    public function test_zero_serve_total_with_queue_transitions_reports_estimated_fallback(): void
    {
        $queue = new AgentControlPlaneTaskPacketQueueRepository;
        $packet = (new AgentControlPlaneTaskPacketBuilder)->build($this->input('a'));
        $queue->enqueue($packet);
        // Mark the packet's queue transition complete WITHOUT ever serving it — the sentinel
        // never records a serve, so serve_total stays 0 while the queue itself shows progress.
        $queue->updateStatus('a', 'claimed', ['lease_id' => 'lease-1', 'agent_id' => 'agent-1']);
        $queue->updateStatus('a', 'completed_dry_run');

        // Isolated sentinel log — without it the health service reads the LIVE
        // serving-sentinel.jsonl and serve_total reflects real production serves.
        $sentinel = new AtlasTaskServingSentinel;
        $sentinel->setLogPathForTesting(sys_get_temp_dir().'/atlas-drain-sentinel-'.bin2hex(random_bytes(5)).'.jsonl');

        $health = new AtlasTaskCoordinationHealthService($queue, new AgentControlPlaneClaimLeaseRepository, null, $sentinel);
        $snap = $health->snapshot();

        $this->assertSame(0, $snap['serving']['serve_total']);
        $this->assertSame('estimated', $snap['worker_drain_forecast']['drain_telemetry_confidence']);
        $this->assertSame('queue_transitions', $snap['worker_drain_forecast']['fallback_source']);
    }

    public function test_normal_serve_total_reports_direct_confidence_and_preserves_health_flags(): void
    {
        $sentinel = new AtlasTaskServingSentinel;
        $sentinel->setLogPathForTesting(sys_get_temp_dir().'/atlas-drain-sentinel-'.bin2hex(random_bytes(5)).'.jsonl');
        $orch = $this->orchestrator();
        $orch->prepareAndEnqueue(['task_packet' => $this->input('b')]);
        $serving = new AtlasTaskServingService($orch, $sentinel);

        $served = $serving->next('client-1');
        $this->assertSame('served', $served['status']);

        $health = new AtlasTaskCoordinationHealthService(
            new AgentControlPlaneTaskPacketQueueRepository,
            new AgentControlPlaneClaimLeaseRepository,
            null,
            $sentinel,
        );
        $snap = $health->snapshot();

        $this->assertGreaterThan(0, $snap['serving']['serve_total']);
        $this->assertSame('direct', $snap['worker_drain_forecast']['drain_telemetry_confidence']);
        $this->assertNull($snap['worker_drain_forecast']['fallback_source']);
        $this->assertFalse($snap['health_flags']['lease_leak_detected']);
        $this->assertTrue($snap['healthy']);
    }

    /** @return array<string, mixed> */
    private function input(string $id): array
    {
        return [
            'task_packet_id' => $id,
            'objective' => 'Drain-fallback fixture '.$id.': implement app/Services/Ai/SelfConstruction/'.$id.'.php deterministically and prove it.',
            'operator_id' => 'tester',
            'allowed_files' => ['app/Services/Ai/SelfConstruction/'.$id.'.php'],
            'scope_in' => ['app/Services/Ai/SelfConstruction/'.$id.'.php'],
            'acceptance_criteria' => ['php artisan test asserts '.$id.' behaves correctly'],
            'required_evidence' => ['task_packet_created'],
        ];
    }
}
