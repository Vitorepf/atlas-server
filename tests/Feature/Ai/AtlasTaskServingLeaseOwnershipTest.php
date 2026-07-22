<?php

declare(strict_types=1);

namespace Tests\Feature\Ai;

use App\Services\Ai\AutonomousEvolution\AtlasLoopMasterSwitch;
use App\Services\Ai\SelfConstruction\AtlasTaskServingService;
use Illuminate\Support\Facades\Storage;
use Tests\Concerns\MakesAgentControlPlaneTaskQueueOrchestrator;
use Tests\TestCase;

/**
 * SECURITY · A1-SC-0133 — the report mutation boundary must AUTHENTICATE the caller against the lease
 * BEFORE it reads scope, runs gates, settles a dry-run, releases a give-back, or lands a scoped commit.
 *
 * THE GAP (verified): `report` validated only non-empty strings, then acted; the only ownership comparison
 * (`markResolved`) runs AFTER the git commit already landed, and the dry-run/give-back paths never bound the
 * caller at all. So a foreign, expired, or revoked authority that merely knows the task and lease ids could
 * release another worker's lease, settle its task, or trigger work on shared main before the late failure.
 *
 * These tests PROVE the fail-open: a client that owns no active lease for this exact task+lease pair must be
 * refused before any side effect. The true owner's report must still succeed (the guard is not over-tight).
 */
final class AtlasTaskServingLeaseOwnershipTest extends TestCase
{
    use MakesAgentControlPlaneTaskQueueOrchestrator;

    private string $envFile = '';

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        $this->envFile = sys_get_temp_dir().'/atlas-lease-owner-env-'.bin2hex(random_bytes(5)).'.env';
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

    public function test_a_foreign_client_cannot_give_back_a_lease_it_does_not_own(): void
    {
        $orch = $this->orchestrator();
        $orch->prepareAndEnqueue(['task_packet' => $this->input('owned-task')]);
        $serving = new AtlasTaskServingService($orch);

        $owner = $serving->next('owner');
        $this->assertSame('served', $owner['status']);
        $taskId = $owner['task']['task_packet_id'];
        $lease = $owner['task']['lease_id'];

        // A foreign client that owns NO lease tries to release the owner's task by knowing its ids.
        $attack = $serving->report('attacker', $taskId, $lease, ['outcome' => 'give_back']);

        $this->assertNotSame('reported', $attack['status'], 'a non-owner give-back must be refused, not accepted');
        $this->assertNotTrue($attack['lease_released'] ?? false, 'a non-owner must never release another worker\'s lease');

        // The owner still holds the lease: the attacker\'s call had no effect on the queue.
        $stillOwned = $serving->resume('owner');
        $this->assertNotNull($stillOwned, 'the true owner keeps its active lease after a rejected foreign report');
        $this->assertSame($lease, $stillOwned['lease_id']);
    }

    public function test_a_foreign_client_cannot_settle_a_dry_run_it_does_not_own(): void
    {
        $orch = $this->orchestrator();
        $orch->prepareAndEnqueue(['task_packet' => $this->input('dryrun-task')]);
        $serving = new AtlasTaskServingService($orch);

        $owner = $serving->next('owner');
        $this->assertSame('served', $owner['status']);
        $taskId = $owner['task']['task_packet_id'];
        $lease = $owner['task']['lease_id'];

        $attack = $serving->report('attacker', $taskId, $lease, ['outcome' => 'success']);

        $this->assertNotSame('reported', $attack['status'], 'a non-owner dry-run success must be refused');
        $this->assertNotTrue($attack['lease_closed'] ?? false, 'a non-owner must never settle another worker\'s task');

        $stillOwned = $serving->resume('owner');
        $this->assertNotNull($stillOwned, 'the owner\'s lease survives a rejected foreign dry-run report');
    }

    public function test_the_true_owner_can_still_report_give_back(): void
    {
        // Positive control: the guard must not break the legitimate flow — the claimant can still report.
        $orch = $this->orchestrator();
        $orch->prepareAndEnqueue(['task_packet' => $this->input('legit-task')]);
        $serving = new AtlasTaskServingService($orch);

        $owner = $serving->next('owner');
        $this->assertSame('served', $owner['status']);

        $report = $serving->report('owner', $owner['task']['task_packet_id'], $owner['task']['lease_id'], ['outcome' => 'give_back']);
        $this->assertSame('reported', $report['status'], 'the true owner\'s report is accepted');
        $this->assertTrue($report['lease_released'], 'the true owner releases its own lease');
    }

    /** @return array<string, mixed> */
    private function input(string $id): array
    {
        return [
            'task_packet_id' => $id,
            'objective' => 'lease ownership test '.$id,
            'operator_id' => 'tester',
            'allowed_files' => ['app/Services/Ai/SelfConstruction/'.$id.'.php'],
            'scope_in' => ['app/Services/Ai/SelfConstruction/'.$id.'.php'],
            'acceptance_criteria' => ['ok'],
            'required_evidence' => ['task_packet_created'],
        ];
    }
}
