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
use App\Services\Ai\SelfConstruction\AtlasTaskCoordinationHealthService;
use App\Services\Ai\SelfConstruction\AtlasTaskServingService;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * PART 2 · axis 10 — the coordination health panel reports the true serving cross-cut and the integrity flags.
 */
final class AtlasTaskCoordinationHealthTest extends TestCase
{
    private string $envFile = '';

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        $this->envFile = sys_get_temp_dir().'/atlas-health-env-'.bin2hex(random_bytes(5)).'.env';
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

    public function test_snapshot_reports_distribution_leases_and_a_healthy_state(): void
    {
        $orch = $this->orchestrator();
        $orch->prepareAndEnqueue(['task_packet' => $this->input('a')]);
        $orch->prepareAndEnqueue(['task_packet' => $this->input('b')]);
        $serving = new AtlasTaskServingService($orch);

        // Claim 'a' (a held lease + a claimed record); leave 'b' claimable.
        $served = $serving->next('client-1');
        $this->assertSame('served', $served['status']);

        $health = new AtlasTaskCoordinationHealthService(
            new AgentControlPlaneTaskPacketQueueRepository,
            new AgentControlPlaneClaimLeaseRepository,
        );
        $snap = $health->snapshot();

        $this->assertSame(AtlasTaskCoordinationHealthService::SCHEMA, $snap['schema']);
        $this->assertSame(1, $snap['claimable_depth'], 'b is still claimable');
        $this->assertSame(1, $snap['claimed_records'], 'a is claimed');
        $this->assertSame(1, $snap['active_leases'], 'a holds one active lease');
        $this->assertTrue($snap['leases_match_claimed'], 'one active lease ↔ one claimed record (no leak)');
        $this->assertTrue($snap['healthy'], 'no integrity breach');
        $this->assertFalse($snap['health_flags']['lease_leak_detected']);
        $this->assertFalse($snap['health_flags']['dry_queue']);
    }

    public function test_snapshot_flags_a_quarantined_packet_and_a_dry_queue(): void
    {
        $orch = $this->orchestrator();
        // The ONLY packet is deficient (no acceptance/evidence): serving quarantines it ⇒ queue goes dry.
        $orch->prepareAndEnqueue(['task_packet' => $this->input('doomed', acceptance: [], evidence: [])]);
        $serving = new AtlasTaskServingService($orch);

        $res = $serving->next('client-1');
        $this->assertSame('no_self_sufficient_task', $res['status']);

        $snap = (new AtlasTaskCoordinationHealthService(
            new AgentControlPlaneTaskPacketQueueRepository,
            new AgentControlPlaneClaimLeaseRepository,
        ))->snapshot();

        $this->assertSame(1, $snap['quarantined_count'], 'the doomed packet is blocked');
        $this->assertTrue($snap['health_flags']['has_quarantined_packets']);
        $this->assertTrue($snap['health_flags']['dry_queue'], 'nothing claimable remains');
        // A quarantined packet released its lease, so leases still match claimed (both zero) — no leak.
        $this->assertTrue($snap['leases_match_claimed']);
        $this->assertTrue($snap['healthy'], 'a dry queue is operational, not an integrity breach');
    }

    public function test_health_cli_front_door_prints_json(): void
    {
        $this->orchestrator()->prepareAndEnqueue(['task_packet' => $this->input('cli-health')]);

        $exit = \Illuminate\Support\Facades\Artisan::call('atlas:task:health', ['--json' => true]);
        $this->assertSame(0, $exit);
        $out = \Illuminate\Support\Facades\Artisan::output();
        $this->assertStringContainsString('"schema": "'.AtlasTaskCoordinationHealthService::SCHEMA.'"', $out);
        $this->assertStringContainsString('"claimable_depth": 1', $out);
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
    private function input(string $id, ?array $acceptance = null, ?array $evidence = null): array
    {
        return [
            'task_packet_id' => $id,
            'objective' => 'health test '.$id,
            'operator_id' => 'tester',
            'allowed_files' => ['app/Services/Ai/SelfConstruction/'.$id.'.php'],
            'scope_in' => ['app/Services/Ai/SelfConstruction/'.$id.'.php'],
            'acceptance_criteria' => $acceptance ?? ['ok'],
            'required_evidence' => $evidence ?? ['task_packet_created'],
        ];
    }
}
