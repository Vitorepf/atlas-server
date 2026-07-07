<?php

declare(strict_types=1);

namespace Tests\Feature\Ai;

use App\Services\Ai\AutonomousEvolution\AtlasLoopMasterSwitch;
use App\Services\Ai\SelfConstruction\AtlasTaskServingService;
use Illuminate\Support\Facades\Storage;
use Tests\Concerns\MakesAgentControlPlaneTaskQueueOrchestrator;
use Tests\TestCase;

/**
 * C2 (Obra #18) — the served packet carries a FIFTH advisory source,
 * `relevant_memory`: decisions/refutations relevant to its files, via the
 * query-aware recall. The key always travels (list), and the source is
 * fail-open: no memory ⇒ honest empty list, never a crash on serve.
 */
final class AtlasTaskServingRelevantMemoryTest extends TestCase
{
    use MakesAgentControlPlaneTaskQueueOrchestrator;

    private string $envFile = '';

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        $this->envFile = sys_get_temp_dir().'/atlas-c2-env-'.bin2hex(random_bytes(5)).'.env';
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

    public function test_served_packet_carries_relevant_memory_as_a_failopen_list(): void
    {
        $orch = $this->orchestrator();
        $orch->prepareAndEnqueue(['task_packet' => [
            'task_packet_id' => 'c2-relmem-1',
            'objective' => 'wire AtlasFooService for c2',
            'operator_id' => 'tester',
            'allowed_files' => ['app/Services/Ai/SelfConstruction/C2RelMem.php'],
            'scope_in' => ['app/Services/Ai/SelfConstruction/C2RelMem.php'],
            'acceptance_criteria' => ['php artisan test passes'],
            'required_evidence' => ['task_packet_created'],
        ]]);

        $res = (new AtlasTaskServingService($orch))->next('client-c2');

        self::assertSame('served', $res['status']);
        // The fifth advisory source always travels with the served packet …
        self::assertArrayHasKey('relevant_memory', $res['task']);
        // … as a list (each entry a provider-safe "title — summary" string) …
        self::assertIsArray($res['task']['relevant_memory']);
        self::assertSame(array_values($res['task']['relevant_memory']), $res['task']['relevant_memory']);
        // … and never wedges a serve when there is no memory to recall (fail-open).
        foreach ($res['task']['relevant_memory'] as $line) {
            self::assertIsString($line);
        }
    }
}
