<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\SelfConstruction;

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
 * Proves AtlasTaskServingService::report() explicitly whitelists outcome to
 * success|failed|give_back — an unknown outcome (typo or hostile string) returns
 * invalid_report with lease_closed=false and mutates nothing, instead of silently
 * falling through into the give_back path.
 */
final class AtlasTaskServingServiceTest extends TestCase
{
    private string $envFile = '';

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        $this->envFile = sys_get_temp_dir().'/atlas-report-env-'.bin2hex(random_bytes(5)).'.env';
        file_put_contents($this->envFile, "ATLAS_LOOP_MASTER_ENABLED=true\n");
        AtlasLoopMasterSwitch::$envPathOverride = $this->envFile;
        AtlasTaskServingSwitch::on();
    }

    protected function tearDown(): void
    {
        AtlasLoopMasterSwitch::$envPathOverride = null;
        AtlasTaskServingSwitch::$envPathOverride = null;
        @unlink($this->envFile);
        parent::tearDown();
    }

    /** @return array{client:string, task_packet_id:string, lease_id:string} */
    private function servedTask(string $id): array
    {
        $exit = Artisan::call('atlas:task:enqueue', [
            '--objective' => 'wire the FooBar into the registry',
            '--allow' => ['app/Services/Foo/Bar.php'],
            '--accept' => ['the FooBar resolves from the container'],
            '--evidence' => ['tests_or_gates_result'],
            '--id' => $id,
            '--json' => true,
        ]);
        $this->assertSame(0, $exit);

        $serving = new AtlasTaskServingService($this->orchestrator());
        $res = $serving->next('client-'.$id);
        $this->assertSame('served', $res['status']);

        return [
            'client' => 'client-'.$id,
            'task_packet_id' => (string) $res['task']['task_packet_id'],
            'lease_id' => (string) $res['task']['lease_id'],
        ];
    }

    public function test_unknown_outcome_returns_invalid_report_and_does_not_close_lease(): void
    {
        $served = $this->servedTask('op-unknown-outcome');
        $serving = new AtlasTaskServingService($this->orchestrator());

        $result = $serving->report($served['client'], $served['task_packet_id'], $served['lease_id'], [
            'outcome' => 'definitely_not_a_real_outcome',
        ]);

        $this->assertSame('invalid_report', $result['status']);
        $this->assertSame('invalid_outcome', $result['reason']);
        $this->assertFalse($result['lease_closed']);
    }

    public function test_unknown_outcome_does_not_mutate_the_task(): void
    {
        $served = $this->servedTask('op-unknown-outcome-2');
        $serving = new AtlasTaskServingService($this->orchestrator());

        $serving->report($served['client'], $served['task_packet_id'], $served['lease_id'], [
            'outcome' => 'hostile-injection-string',
        ]);

        // The lease is still valid: a SECOND report with a real outcome can still close it cleanly,
        // proving the invalid report above never released, completed, committed, or quarantined anything.
        $result = $serving->report($served['client'], $served['task_packet_id'], $served['lease_id'], [
            'outcome' => 'success',
        ]);

        $this->assertSame('reported', $result['status']);
        $this->assertSame('success', $result['outcome']);
    }

    public function test_success_outcome_still_reports(): void
    {
        $served = $this->servedTask('op-success-path');
        $serving = new AtlasTaskServingService($this->orchestrator());

        $result = $serving->report($served['client'], $served['task_packet_id'], $served['lease_id'], [
            'outcome' => 'success',
        ]);

        $this->assertSame('reported', $result['status']);
        $this->assertSame('success', $result['outcome']);
    }

    public function test_failed_outcome_still_reports_as_give_back_path(): void
    {
        $served = $this->servedTask('op-failed-path');
        $serving = new AtlasTaskServingService($this->orchestrator());

        $result = $serving->report($served['client'], $served['task_packet_id'], $served['lease_id'], [
            'outcome' => 'failed',
        ]);

        $this->assertSame('reported', $result['status']);
        $this->assertSame('failed', $result['outcome']);
    }

    public function test_give_back_outcome_still_reports(): void
    {
        $served = $this->servedTask('op-give-back-path');
        $serving = new AtlasTaskServingService($this->orchestrator());

        $result = $serving->report($served['client'], $served['task_packet_id'], $served['lease_id'], [
            'outcome' => 'give_back',
        ]);

        $this->assertSame('reported', $result['status']);
        $this->assertSame('give_back', $result['outcome']);
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
