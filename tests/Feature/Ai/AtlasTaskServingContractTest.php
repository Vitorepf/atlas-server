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
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * PART 2 · A7 — THE CONTRACT end-to-end. The headline proof the operator asked for: two distinct opaque
 * client ids calling `next` receive DISJOINT task packets; `report` closes/releases the lease; the surface is
 * master-switch gated and platform-free.
 */
final class AtlasTaskServingContractTest extends TestCase
{
    private string $envFile = '';

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        // Enable the loop master switch via a temp .env (the documented test seam).
        $this->envFile = sys_get_temp_dir().'/atlas-task-env-'.bin2hex(random_bytes(5)).'.env';
        file_put_contents($this->envFile, "ATLAS_LOOP_MASTER_ENABLED=true\n");
        AtlasLoopMasterSwitch::$envPathOverride = $this->envFile;
        // Isolate the dedicated serving switch from the real .env too (serving = master OR serving-flag).
        \App\Services\Ai\SelfConstruction\AtlasTaskServingSwitch::$envPathOverride = $this->envFile;
    }

    protected function tearDown(): void
    {
        AtlasLoopMasterSwitch::$envPathOverride = null;
        \App\Services\Ai\SelfConstruction\AtlasTaskServingSwitch::$envPathOverride = null;
        if ($this->envFile !== '') {
            @unlink($this->envFile);
        }
        parent::tearDown();
    }

    public function test_two_distinct_clients_receive_disjoint_packets(): void
    {
        $orch = $this->orchestrator();
        $orch->prepareAndEnqueue(['task_packet' => $this->input('serve-1')]);
        $orch->prepareAndEnqueue(['task_packet' => $this->input('serve-2')]);
        $serving = new AtlasTaskServingService($orch);

        $a = $serving->next('client-alpha');
        $b = $serving->next('client-beta');

        $this->assertSame('served', $a['status']);
        $this->assertSame('served', $b['status']);
        $this->assertSame('client-alpha', $a['client_id'], 'client_id is echoed verbatim');
        $this->assertSame('client-beta', $b['client_id']);

        $idA = $a['task']['task_packet_id'];
        $idB = $b['task']['task_packet_id'];
        $this->assertNotSame($idA, $idB, 'two clients NEVER receive the same packet (conflict-free serving)');
        $this->assertEqualsCanonicalizing(['serve-1', 'serve-2'], [$idA, $idB]);

        // The packet is SELF-SUFFICIENT: id + lease + scope + objective.
        $this->assertNotEmpty($a['task']['lease_id']);
        $this->assertNotEmpty($a['task']['allowed_files']);
        $this->assertSame(AtlasTaskServingService::ENVELOPE_SCHEMA, $a['schema']);
    }

    public function test_serving_is_disabled_when_master_switch_off(): void
    {
        file_put_contents($this->envFile, "ATLAS_LOOP_MASTER_ENABLED=false\n");

        $serving = new AtlasTaskServingService($this->orchestrator());
        $res = $serving->next('client-x');

        $this->assertSame('disabled', $res['status'], 'master switch OFF => serving is inert');
        $this->assertNull($res['task']);
    }

    public function test_no_claimable_task_is_honest_with_escalation(): void
    {
        // Empty queue: an honest no_claimable_task with the brain-origination escalation — NOT an error.
        $serving = new AtlasTaskServingService($this->orchestrator());
        $res = $serving->next('client-y');

        $this->assertSame('no_claimable_task', $res['status']);
        $this->assertSame('needs_brain_origination', $res['escalation']);
        $this->assertGreaterThan(0, $res['retry_after_seconds']);
        $this->assertNull($res['task']);
    }

    public function test_report_success_closes_the_lease(): void
    {
        $orch = $this->orchestrator();
        $orch->prepareAndEnqueue(['task_packet' => $this->input('done-1')]);
        $serving = new AtlasTaskServingService($orch);

        $served = $serving->next('client-z');
        $taskId = $served['task']['task_packet_id'];
        $leaseId = $served['task']['lease_id'];

        $evidence = $this->completionEvidenceFor($taskId, $leaseId, 'client-z');
        $report = $serving->report('client-z', $taskId, $leaseId, ['outcome' => 'success', 'evidence' => $evidence]);

        $this->assertSame('reported', $report['status']);
        $this->assertTrue($report['lease_closed'], 'a valid success report finalises the dry-run cycle');
        $this->assertSame('completed_dry_run', $report['orchestrator_event']);
    }

    public function test_report_give_back_releases_the_lease(): void
    {
        $orch = $this->orchestrator();
        $orch->prepareAndEnqueue(['task_packet' => $this->input('give-1')]);
        $serving = new AtlasTaskServingService($orch);

        $served = $serving->next('client-g');
        $report = $serving->report('client-g', $served['task']['task_packet_id'], $served['task']['lease_id'], ['outcome' => 'failed']);

        $this->assertSame('reported', $report['status']);
        $this->assertTrue($report['lease_released'], 'a failed report releases the lease so the task can be recovered');
    }

    public function test_client_id_is_opaque_and_engine_typed_filters_are_ignored(): void
    {
        $orch = $this->orchestrator();
        $orch->prepareAndEnqueue(['task_packet' => $this->input('opaque-1')]);
        $serving = new AtlasTaskServingService($orch);

        // A weird opaque id is echoed verbatim; engine-typed filters are whitelisted away (no platform branch).
        $weird = 'cursor::codex::🤖::v2';
        $res = $serving->next($weird, ['platform' => 'cursor', 'engine' => 'codex', 'provider' => 'claude']);

        $this->assertSame('served', $res['status']);
        $this->assertSame($weird, $res['client_id'], 'client_id is opaque — forwarded and echoed, never interpreted');
        $this->assertSame('opaque-1', $res['task']['task_packet_id']);
    }

    public function test_artisan_front_door_serves_via_the_contract(): void
    {
        // The container-resolved service shares the same faked-disk store as a manual orchestrator.
        $this->orchestrator()->prepareAndEnqueue(['task_packet' => $this->input('cli-1')]);

        $exit = Artisan::call('atlas:task', ['action' => 'next', '--client' => 'cli-client', '--json' => true]);
        $this->assertSame(0, $exit, 'atlas:task next is wired and runs');
        $out = Artisan::output();
        $this->assertStringContainsString('"status": "served"', $out);
        $this->assertStringContainsString('cli-1', $out);
        $this->assertStringContainsString('"client_id": "cli-client"', $out);
    }

    // --- helpers ---------------------------------------------------------------------------------------------

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
            'objective' => 'serve test '.$id,
            'operator_id' => 'tester',
            'allowed_files' => ['app/Services/Ai/SelfConstruction/'.$id.'.php'],
            'scope_in' => ['app/Services/Ai/SelfConstruction/'.$id.'.php'],
            'acceptance_criteria' => ['ok'],
            'required_evidence' => ['task_packet_created'],
        ];
    }

    /** @return array<string, mixed> */
    private function completionEvidenceFor(string $packetId, string $leaseId, string $actor, array $overrides = []): array
    {
        $evidence = array_merge([
            'packet_id' => $packetId,
            'lease_id' => $leaseId,
            'actor' => $actor,
            'files_changed' => ['app/Services/Ai/SelfConstruction/'.$packetId.'.php'],
            'commands_run' => ['php artisan test --filter=ScopedSuite: passed'],
            'tests_or_gates_result' => 'passed',
            'git_status_short' => ' M app/Services/Ai/SelfConstruction/'.$packetId.'.php',
            'git_diff_check_result' => 'clean',
        ], $overrides);
        $evidence['evidence_hash'] = AgentControlPlaneTaskQueueOrchestrator::canonicalCompletionEvidenceHash($evidence);

        return $evidence;
    }
}
