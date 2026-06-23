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
 * PART 2 · axis 8 — a COLD client only ever receives an IMPLEMENTABLE packet. A packet that the builder/validator
 * pass but that has no acceptance criteria / no required evidence is served today (verified) and strands the
 * client. The serving path must quarantine such packets and serve the next implementable one instead.
 */
final class AtlasTaskServingPacketQualityTest extends TestCase
{
    private string $envFile = '';

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        $this->envFile = sys_get_temp_dir().'/atlas-pq-env-'.bin2hex(random_bytes(5)).'.env';
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

    public function test_a_deficient_packet_is_quarantined_and_the_good_one_is_served(): void
    {
        $orch = $this->orchestrator();
        // A packet the validator passes but a cold client cannot prove: NO acceptance, NO required evidence.
        $orch->prepareAndEnqueue(['task_packet' => $this->input('deficient', acceptance: [], evidence: [])]);
        $orch->prepareAndEnqueue(['task_packet' => $this->input('good')]);
        $serving = new AtlasTaskServingService($orch);

        $res = $serving->next('client-cold');

        $this->assertSame('served', $res['status']);
        $this->assertSame('good', $res['task']['task_packet_id'], 'the deficient packet is skipped, the implementable one is served');
        $this->assertTrue($res['task']['packet_quality']['self_sufficient']);

        // The deficient packet is now blocked (quarantined) — never claimable again, so no client is stranded.
        $blocked = (new AgentControlPlaneTaskPacketQueueRepository)->get('deficient');
        $this->assertSame('blocked', (string) ($blocked['status'] ?? ''), 'the doomed packet was quarantined out of the pool');
    }

    public function test_when_only_deficient_packets_exist_next_is_honestly_empty(): void
    {
        $orch = $this->orchestrator();
        $orch->prepareAndEnqueue(['task_packet' => $this->input('only-deficient', acceptance: [], evidence: [])]);
        $serving = new AtlasTaskServingService($orch);

        $res = $serving->next('client-cold');

        $this->assertSame('no_self_sufficient_task', $res['status'], 'a queue of only doomed packets is honest, not an error');
        $this->assertContains('missing_acceptance_criteria', $res['blocking_deficiencies']);
        $this->assertNull($res['task']);
        $this->assertSame('needs_brain_origination', $res['escalation']);
    }

    public function test_a_self_sufficient_packet_is_served_with_quality_facts(): void
    {
        $orch = $this->orchestrator();
        $orch->prepareAndEnqueue(['task_packet' => $this->input('healthy')]);
        $serving = new AtlasTaskServingService($orch);

        $res = $serving->next('client-cold');

        $this->assertSame('served', $res['status']);
        $this->assertSame('healthy', $res['task']['task_packet_id']);
        $this->assertArrayHasKey('packet_quality', $res['task']);
        $this->assertSame([], $res['task']['packet_quality']['blocking_deficiencies']);
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
            'objective' => 'serve quality test '.$id,
            'operator_id' => 'tester',
            'allowed_files' => ['app/Services/Ai/SelfConstruction/'.$id.'.php'],
            'scope_in' => ['app/Services/Ai/SelfConstruction/'.$id.'.php'],
            'acceptance_criteria' => $acceptance ?? ['ok'],
            'required_evidence' => $evidence ?? ['task_packet_created'],
        ];
    }
}
