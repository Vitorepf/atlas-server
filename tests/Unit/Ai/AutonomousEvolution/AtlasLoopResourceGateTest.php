<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\AutonomousEvolution;

use App\Services\Ai\AutonomousEvolution\AtlasLoopResourceGate;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Symfony\Component\Process\Process;
use Tests\TestCase;

final class AtlasLoopResourceGateTest extends TestCase
{
    /** @var list<string> */
    private array $paths = [];

    protected function tearDown(): void
    {
        foreach ($this->paths as $path) {
            (new Process(['rm', '-rf', $path]))->run();
        }

        Schema::dropIfExists('atlas_engineering_code_symbols');

        parent::tearDown();
    }

    public function test_admit_scenario_uses_real_free_space_for_existing_tmp_root(): void
    {
        $tmpRoot = $this->makeTempDir('atlas-loop-resource-gate-');
        mkdir($tmpRoot.'/atlas-loop-scn-one', 0o755, true);
        $expectedFreeMb = (int) floor(((int) disk_free_space($tmpRoot)) / (1024 * 1024));

        $gate = new AtlasLoopResourceGate();

        $result = $gate->admitScenario($tmpRoot, 0, 2);
        $capped = $gate->admitScenario($tmpRoot, 0, 1);

        $this->assertTrue($result['admit']);
        $this->assertSame('ok', $result['reason']);
        $this->assertSame(1, $result['live']);
        $this->assertSame($expectedFreeMb, $result['free_mb']);
        $this->assertGreaterThanOrEqual(0, $result['free_mb']);
        $this->assertNotSame(PHP_INT_MAX, $result['free_mb']);
        $this->assertFalse($capped['admit']);
        $this->assertSame('workspace_cap', $capped['reason']);
        $this->assertSame(1, $capped['live']);
        $this->assertSame($result['free_mb'], $capped['free_mb']);
    }

    public function test_admit_scenario_uses_php_int_max_when_disk_free_space_cannot_be_read(): void
    {
        $tmpRoot = $this->makeTempDir('atlas-loop-resource-gate-').'/missing';

        $result = (new AtlasLoopResourceGate)->admitScenario($tmpRoot, 1, 1);

        $this->assertTrue($result['admit']);
        $this->assertSame('ok', $result['reason']);
        $this->assertSame(0, $result['live']);
        $this->assertSame(PHP_INT_MAX, $result['free_mb']);
    }

    public function test_admit_scenario_applies_disk_floor_only_when_free_space_is_readable(): void
    {
        $readableTmpRoot = $this->makeTempDir('atlas-loop-resource-gate-');
        $requiredFreeMb = ((int) floor(((int) disk_free_space($readableTmpRoot)) / (1024 * 1024))) + 1;

        $gate = new AtlasLoopResourceGate();

        $readableResult = $gate->admitScenario($readableTmpRoot, $requiredFreeMb, 0);
        $missingRootResult = $gate->admitScenario($readableTmpRoot.'/missing', $requiredFreeMb, 0);

        $this->assertFalse($readableResult['admit']);
        $this->assertSame('disk_floor', $readableResult['reason']);
        $this->assertLessThan($requiredFreeMb, $readableResult['free_mb']);

        $this->assertTrue($missingRootResult['admit']);
        $this->assertSame('ok', $missingRootResult['reason']);
        $this->assertSame(PHP_INT_MAX, $missingRootResult['free_mb']);
    }

    public function test_reap_worker_returns_zero_for_empty_or_missing_path_and_reaps_existing_workspace(): void
    {
        $workspace = $this->makeTempDir('atlas-loop-resource-worker-');
        file_put_contents($workspace.'/artifact.txt', 'payload');
        $missingWorkspace = $workspace.'-missing';

        $gate = new AtlasLoopResourceGate();

        $this->assertSame(0, $gate->reapWorker(''));
        $this->assertTrue(is_dir($workspace));

        $this->assertSame(0, $gate->reapWorker($missingWorkspace));
        $this->assertFalse(is_dir($missingWorkspace));

        $this->assertSame(1, $gate->reapWorker($workspace));
        $this->assertFalse(is_dir($workspace));
    }

    public function test_sweep_orphans_reaps_only_stale_supported_loop_workspaces_and_updates_live_count(): void
    {
        $tmpRoot = $this->makeTempDir('atlas-loop-resource-gate-');
        $stale = $tmpRoot.'/atlas-loop-task-stale';
        $fresh = $tmpRoot.'/atlas-loop-docstruct-fresh';
        $ignored = $tmpRoot.'/outside-prefix';

        mkdir($stale, 0o755, true);
        mkdir($fresh, 0o755, true);
        mkdir($ignored, 0o755, true);
        touch($stale, time() - 7200);
        touch($fresh, time());

        $gate = new AtlasLoopResourceGate();

        $this->assertSame(2, $gate->countLiveWorkspaces($tmpRoot));

        $reaped = $gate->sweepOrphans($tmpRoot, 3600);

        $this->assertSame(1, $reaped);
        $this->assertDirectoryDoesNotExist($stale);
        $this->assertDirectoryExists($fresh);
        $this->assertDirectoryExists($ignored);
        $this->assertSame(1, $gate->countLiveWorkspaces($tmpRoot));
    }

    public function test_reap_leaked_code_symbols_batches_old_loop_rows_until_a_zero_delete_batch(): void
    {
        $this->createCodeSymbolsTable();
        $cutoff = now()->subHours(3);

        $this->insertCodeSymbol('atlas-loop-scn-old-a', $cutoff);
        $this->insertCodeSymbol('atlas-loop-scn-old-b', $cutoff);
        $this->insertCodeSymbol('atlas-loop-scn-old-c', $cutoff);
        $this->insertCodeSymbol('atlas-loop-scn-fresh', now());
        $this->insertCodeSymbol('atlas-server', $cutoff);
        $this->insertCodeSymbol('atlas-loop-scn-null-created', null);

        $deleted = (new AtlasLoopResourceGate())->reapLeakedCodeSymbols(7200, 2, 5);

        $this->assertSame(3, $deleted, 'the reaper should keep batching until it reaches a zero-delete pass');
        $this->assertSame(0, DB::table('atlas_engineering_code_symbols')->where('workspace_id', 'like', 'atlas-loop-scn-old-%')->count());
        $this->assertSame(1, DB::table('atlas_engineering_code_symbols')->where('workspace_id', 'atlas-loop-scn-fresh')->count());
        $this->assertSame(1, DB::table('atlas_engineering_code_symbols')->where('workspace_id', 'atlas-server')->count());
        $this->assertSame(1, DB::table('atlas_engineering_code_symbols')->where('workspace_id', 'atlas-loop-scn-null-created')->count());
    }

    public function test_pressure_digest_returns_all_required_keys(): void
    {
        $tmpRoot = $this->makeTempDir('atlas-loop-resource-gate-');
        $digest = (new AtlasLoopResourceGate())->pressureDigest($tmpRoot, 0, 0);

        foreach (['admit', 'pressure_state', 'reasons', 'free_mb', 'live_workspaces', 'stale_workspace_candidates', 'recommended_reap_actions'] as $key) {
            $this->assertArrayHasKey($key, $digest, "pressureDigest must return key: {$key}");
        }
        $this->assertIsBool($digest['admit']);
        $this->assertIsString($digest['pressure_state']);
        $this->assertIsArray($digest['reasons']);
        $this->assertIsArray($digest['recommended_reap_actions']);
    }

    public function test_pressure_digest_missing_tmp_root_is_fail_open(): void
    {
        $missing = $this->makeTempDir('atlas-loop-resource-gate-').'/nonexistent';
        $digest = (new AtlasLoopResourceGate())->pressureDigest($missing, 9999999, 1);

        $this->assertTrue($digest['admit'], 'missing root must be fail-open (never block)');
        $this->assertSame(PHP_INT_MAX, $digest['free_mb']);
        $this->assertSame(0, $digest['live_workspaces']);
        $this->assertSame(0, $digest['stale_workspace_candidates']);
        $this->assertSame('ok', $digest['pressure_state']);
    }

    public function test_pressure_digest_disk_floor_breach_sets_critical_and_reasons(): void
    {
        $tmpRoot = $this->makeTempDir('atlas-loop-resource-gate-');
        $requiredFreeMb = ((int) floor(((int) disk_free_space($tmpRoot)) / (1024 * 1024))) + 1;

        $digest = (new AtlasLoopResourceGate())->pressureDigest($tmpRoot, $requiredFreeMb, 0);

        $this->assertFalse($digest['admit']);
        $this->assertSame('critical', $digest['pressure_state']);
        $this->assertContains('disk_floor', $digest['reasons']);
    }

    public function test_pressure_digest_stale_workspaces_produce_warn_and_sweep_action(): void
    {
        $tmpRoot = $this->makeTempDir('atlas-loop-resource-gate-');
        $stale = $tmpRoot.'/atlas-loop-task-stale';
        mkdir($stale, 0o755, true);
        touch($stale, time() - 7200);

        $digest = (new AtlasLoopResourceGate())->pressureDigest($tmpRoot, 0, 0, 3600);

        $this->assertTrue($digest['admit']);
        $this->assertSame('warn', $digest['pressure_state']);
        $this->assertSame(1, $digest['stale_workspace_candidates']);
        $this->assertContains('sweep_orphans', $digest['recommended_reap_actions']);
        $this->assertDirectoryExists($stale, 'pressureDigest must be read-only — stale dir must still exist');
    }

    private function makeTempDir(string $prefix): string
    {
        $path = sys_get_temp_dir().'/'.$prefix.bin2hex(random_bytes(4));
        mkdir($path, 0o755, true);
        $this->paths[] = $path;

        return $path;
    }

    private function createCodeSymbolsTable(): void
    {
        Schema::dropIfExists('atlas_engineering_code_symbols');
        Schema::create('atlas_engineering_code_symbols', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('workspace_id')->nullable()->index();
            $table->timestamp('created_at')->nullable();
            $table->timestamp('updated_at')->nullable();
        });
    }

    private function insertCodeSymbol(?string $workspaceId, mixed $createdAt): void
    {
        DB::table('atlas_engineering_code_symbols')->insert([
            'id' => (string) Str::uuid(),
            'workspace_id' => $workspaceId,
            'created_at' => $createdAt,
            'updated_at' => $createdAt,
        ]);
    }
}
