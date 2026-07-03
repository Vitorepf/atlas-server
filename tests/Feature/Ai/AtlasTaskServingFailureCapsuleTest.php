<?php

declare(strict_types=1);

namespace Tests\Feature\Ai;

use App\Services\Ai\AutonomousEvolution\AtlasLoopMasterSwitch;
use App\Services\Ai\Programming\AtlasDev\RuntimeIntelligence\DevFailureCapsuleRuntimeService;
use App\Services\Ai\Programming\AtlasDev\RuntimeIntelligence\DevTaskPacketRuntimeService;
use App\Services\Ai\Programming\AtlasDev\Support\WorkspaceOriginIdentity;
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
 * M5 → task serving: o packet servido pelo `atlas:task next` carrega as
 * failure capsules da sua área (known_failure_modes) — a mesma memória que o
 * Dev senior loop, o fast path e o Forge já recebem. Área estrangeira nunca
 * injeta; sem capsule = lista vazia (nunca fabricada).
 */
final class AtlasTaskServingFailureCapsuleTest extends TestCase
{
    private string $envFile = '';

    private object $migration;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        $this->migration = require base_path('database/migrations/2026_05_22_160000_create_atlas_dev_runtime_intelligence_tables.php');
        $this->migration->down();
        $this->migration->up();
        $this->envFile = sys_get_temp_dir().'/atlas-m5serve-env-'.bin2hex(random_bytes(5)).'.env';
        file_put_contents($this->envFile, "ATLAS_LOOP_MASTER_ENABLED=true\n");
        AtlasLoopMasterSwitch::$envPathOverride = $this->envFile;
    }

    protected function tearDown(): void
    {
        $this->migration->down();
        AtlasLoopMasterSwitch::$envPathOverride = null;
        if ($this->envFile !== '') {
            @unlink($this->envFile);
        }
        parent::tearDown();
    }

    public function test_served_packet_carries_failure_capsules_of_its_area(): void
    {
        $area = ['app/Services/Ai/SelfConstruction/capsule-match.php'];
        $this->persistCapsule($area, WorkspaceOriginIdentity::slug(base_path()));
        // Capsule de outra área NÃO pode viajar com este packet.
        $this->persistCapsule(['app/Other/Foreign.php'], WorkspaceOriginIdentity::slug(base_path()));

        $orch = $this->orchestrator();
        $orch->prepareAndEnqueue(['task_packet' => $this->input('capsule-match')]);

        $res = (new AtlasTaskServingService($orch))->next('client-m5');

        self::assertSame('served', $res['status']);
        self::assertArrayHasKey('known_failure_modes', $res['task']);
        self::assertCount(1, $res['task']['known_failure_modes']);
        self::assertStringContainsString('test_failure', $res['task']['known_failure_modes'][0]);
    }

    public function test_area_without_capsules_gets_honest_empty_list(): void
    {
        $orch = $this->orchestrator();
        $orch->prepareAndEnqueue(['task_packet' => $this->input('no-capsules')]);

        $res = (new AtlasTaskServingService($orch))->next('client-m5-empty');

        self::assertSame('served', $res['status']);
        self::assertSame([], $res['task']['known_failure_modes']);
    }

    /** @param list<string> $changedFiles */
    private function persistCapsule(array $changedFiles, string $workspaceSlug): void
    {
        $packet = (new DevTaskPacketRuntimeService)->persist([
            'run_id' => 'm5s-'.bin2hex(random_bytes(2)),
            'task_id' => 'm5s-task-'.bin2hex(random_bytes(2)),
            'objective' => 'M5 serving fixture',
            'risk_band' => 'medium',
            'task_class' => 'feature',
            'expected_files' => $changedFiles,
            'workspace_slug' => $workspaceSlug,
        ]);

        (new DevFailureCapsuleRuntimeService)->persist([
            'run_id' => $packet->run_id,
            'task_id' => $packet->task_id,
            'failing_gate' => 'verification_gate',
            'failure_class' => 'test_failure',
            'error' => 'PHPUnit failed assertion in serving area',
            'changed_files' => $changedFiles,
            'suggested_repair' => 'assert the real contract, not a placeholder',
        ], $packet);
    }

    public function test_worker_reported_failure_with_evidence_becomes_a_capsule_the_next_serve_injects(): void
    {
        // Esteira write side (mirrors the S2 read side): a failed report
        // carrying a REAL error excerpt persists a failure capsule anchored to
        // this repo's workspace slug + the packet's files — the next packet
        // served in the same area receives it as a known_failure_mode.
        $orch = $this->orchestrator();
        $serving = new AtlasTaskServingService($orch);

        $file = 'app/Services/Ai/SelfConstruction/CapsuleTarget.php';
        $orch->prepareAndEnqueue(['task_packet' => [
            'task_packet_id' => 'cap-write-1',
            'objective' => 'fix CapsuleTarget',
            'operator_id' => 'tester',
            'allowed_files' => [$file],
            'scope_in' => [$file],
            'acceptance_criteria' => ['ok'],
            'required_evidence' => ['task_packet_created'],
        ]]);

        $a = $serving->next('worker-a');
        $this->assertSame('served', $a['status']);
        $serving->report('worker-a', 'cap-write-1', $a['task']['lease_id'], [
            'outcome' => 'failed',
            'evidence' => ['error' => 'PHPUnit: CapsuleTargetTest::test_value failed asserting 2 matches expected 3'],
        ]);

        $capsule = \App\Models\AtlasDevFailureCapsule::query()->where('task_id', 'cap-write-1')->first();
        $this->assertNotNull($capsule, 'a failed report with evidence persists a capsule');
        $this->assertSame('worker_report_failed', $capsule->failing_gate);
        $this->assertSame('test_failure', $capsule->failure_class);

        // Next packet in the SAME area gets the memory on serve.
        $orch->prepareAndEnqueue(['task_packet' => [
            'task_packet_id' => 'cap-write-2',
            'objective' => 'retry CapsuleTarget',
            'operator_id' => 'tester',
            'allowed_files' => [$file],
            'scope_in' => [$file],
            'acceptance_criteria' => ['ok'],
            'required_evidence' => ['task_packet_created'],
        ]]);
        $b = $serving->next('worker-b');
        $this->assertSame('served', $b['status']);
        $modes = (array) ($b['task']['known_failure_modes'] ?? []);
        $this->assertNotSame([], $modes, 'the next serve in the area injects the worker-reported capsule');
    }

    public function test_bare_give_back_without_evidence_records_nothing(): void
    {
        $orch = $this->orchestrator();
        $serving = new AtlasTaskServingService($orch);
        $orch->prepareAndEnqueue(['task_packet' => [
            'task_packet_id' => 'cap-bare-1',
            'objective' => 'no evidence',
            'operator_id' => 'tester',
            'allowed_files' => ['app/Services/Ai/SelfConstruction/Bare.php'],
            'scope_in' => ['app/Services/Ai/SelfConstruction/Bare.php'],
            'acceptance_criteria' => ['ok'],
            'required_evidence' => ['task_packet_created'],
        ]]);

        $a = $serving->next('worker-a');
        $serving->report('worker-a', 'cap-bare-1', $a['task']['lease_id'], ['outcome' => 'give_back']);

        $this->assertNull(
            \App\Models\AtlasDevFailureCapsule::query()->where('task_id', 'cap-bare-1')->first(),
            'no evidence => no fabricated lesson',
        );
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
            'objective' => 'wire AtlasFooService into the php artisan kernel for packet '.$id,
            'operator_id' => 'tester',
            'allowed_files' => ['app/Services/Ai/SelfConstruction/'.$id.'.php'],
            'scope_in' => ['app/Services/Ai/SelfConstruction/'.$id.'.php'],
            'acceptance_criteria' => ['php artisan test passes'],
            'required_evidence' => ['task_packet_created'],
        ];
    }
}
