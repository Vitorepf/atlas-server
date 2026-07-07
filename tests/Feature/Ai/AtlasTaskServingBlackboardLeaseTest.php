<?php

declare(strict_types=1);

namespace Tests\Feature\Ai;

use App\Services\Ai\AtlasAobgBlackboardService;
use App\Services\Ai\AutonomousEvolution\AtlasLoopMasterSwitch;
use App\Services\Ai\SelfConstruction\AtlasTaskServingService;
use Illuminate\Support\Facades\Storage;
use Tests\Concerns\MakesAgentControlPlaneTaskQueueOrchestrator;
use Tests\TestCase;

/**
 * L2 (Obra #19) — the serving lease is blackboard-aware: a packet whose file
 * ANOTHER engine actively claims is DEFERRED (never served over a live edit), and
 * an unclaimed file serves normally. Fail-open by construction (the blackboard read
 * degrades to "no claims").
 */
final class AtlasTaskServingBlackboardLeaseTest extends TestCase
{
    use MakesAgentControlPlaneTaskQueueOrchestrator;

    private string $envFile = '';

    private object $migration;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        $this->migration = require base_path('database/migrations/2026_06_10_120000_create_atlas_aobg_blackboard_table.php');
        $this->migration->down();
        $this->migration->up();
        $this->envFile = sys_get_temp_dir().'/atlas-l2-env-'.bin2hex(random_bytes(5)).'.env';
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

    public function test_lease_is_deferred_when_another_engine_claims_the_file(): void
    {
        $file = 'app/Services/Ai/SelfConstruction/L2Contended.php';
        // Codex is editing the file right now.
        app(AtlasAobgBlackboardService::class)->claim('codex', 'file', $file);

        $orch = $this->orchestrator();
        $orch->prepareAndEnqueue(['task_packet' => [
            'task_packet_id' => 'l2-contended-1',
            'objective' => 'edit L2Contended',
            'operator_id' => 'tester',
            'allowed_files' => [$file],
            'scope_in' => [$file],
            'acceptance_criteria' => ['ok'],
            'required_evidence' => ['task_packet_created'],
        ]]);

        $res = (new AtlasTaskServingService($orch))->next('worker-l2');

        self::assertSame('lease_deferred', $res['status']);
        self::assertContains($file, $res['contended_files'] ?? []);
    }

    public function test_unclaimed_file_serves_normally(): void
    {
        $orch = $this->orchestrator();
        $orch->prepareAndEnqueue(['task_packet' => [
            'task_packet_id' => 'l2-free-1',
            'objective' => 'edit an unclaimed file',
            'operator_id' => 'tester',
            'allowed_files' => ['app/Services/Ai/SelfConstruction/L2Free.php'],
            'scope_in' => ['app/Services/Ai/SelfConstruction/L2Free.php'],
            'acceptance_criteria' => ['ok'],
            'required_evidence' => ['task_packet_created'],
        ]]);

        $res = (new AtlasTaskServingService($orch))->next('worker-l2');

        self::assertSame('served', $res['status']);
    }
}
