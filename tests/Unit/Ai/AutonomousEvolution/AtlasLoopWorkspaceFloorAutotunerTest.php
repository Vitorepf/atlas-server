<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\AutonomousEvolution;

use App\Console\Commands\AtlasLoopKeepaliveCommand;
use App\Services\Ai\AutonomousEvolution\AtlasLoopWorkspaceFloorAutotuner;
use Illuminate\Support\Facades\Schema;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;
use Tests\TestCase;

/**
 * Proves the workspace-floor autotuner: the 4 deterministic reason paths of recommend() (injected disk-stat),
 * and the ADVISORY-ONLY invariant through AtlasLoopKeepaliveCommand::handle — config + the workspaces tree are
 * unchanged across a keepalive tick; only the autotune JSON appears.
 */
final class AtlasLoopWorkspaceFloorAutotunerTest extends TestCase
{
    private function autotuner(int $freeMb): AtlasLoopWorkspaceFloorAutotuner
    {
        return new AtlasLoopWorkspaceFloorAutotuner(fn (): int => $freeMb * 1048576);
    }

    public function test_no_history_reason(): void
    {
        $out = $this->autotuner(1000)->recommend(50, ['concurrent_campaigns' => 2]); // no footprint

        $this->assertSame('no_history', $out['reason']);
        $this->assertSame('atlas.loop.workspace_floor.v1', $out['schema']);
        $this->assertGreaterThanOrEqual(1, $out['recommended_floor_mb']);
    }

    public function test_near_starvation_reason(): void
    {
        // recommended = 100*1 = 100; free 50 < 100 ⇒ near_starvation.
        $out = $this->autotuner(50)->recommend(10, ['footprint_p95_mb' => 100, 'history_count' => 3, 'concurrent_campaigns' => 1]);

        $this->assertSame('near_starvation', $out['reason']);
        $this->assertSame(100, $out['recommended_floor_mb']);
        $this->assertSame(50, $out['free_mb']);
        $this->assertSame(-50, $out['headroom_mb']);
    }

    public function test_tight_headroom_reason(): void
    {
        // recommended 100; free 150 (100<=150<200) ⇒ tight.
        $out = $this->autotuner(150)->recommend(10, ['footprint_p95_mb' => 100, 'history_count' => 3]);

        $this->assertSame('tight_headroom', $out['reason']);
    }

    public function test_ample_headroom_reason(): void
    {
        // recommended 100; free 300 (>=200) ⇒ ample.
        $out = $this->autotuner(300)->recommend(10, ['footprint_p95_mb' => 100, 'history_count' => 3]);

        $this->assertSame('ample_headroom', $out['reason']);
        $this->assertSame(200, $out['headroom_mb']);
    }

    public function test_flag_off_write_is_null_no_op(): void
    {
        config(['atlas.loop.workspace_floor_autotuner_enabled' => false]);

        $this->assertNull($this->autotuner(100)->writeAdvisorySnapshot(10, ['footprint_p95_mb' => 5, 'history_count' => 1]));
    }

    public function test_advisory_only_through_keepalive_handle(): void
    {
        $tmpDir = sys_get_temp_dir().'/atlas-floor-'.bin2hex(random_bytes(6));
        config([
            'atlas.loop.workspace_floor_autotuner_enabled' => true,
            'atlas.loop.workspace_floor_mb' => 123,
            'atlas.loop.morning_digest.keepalive_event_log_enabled' => false,
        ]);

        if (! Schema::hasTable('atlas_loop_campaigns')) {
            (require base_path('database/migrations/2026_06_02_000100_create_atlas_loop_runtime_tables.php'))->up();
        }

        $workspacesDir = storage_path('app/atlas/loop/workspaces');
        $workspacesExistedBefore = is_dir($workspacesDir);
        $floorBefore = config('atlas.loop.workspace_floor_mb');

        $autotuner = new AtlasLoopWorkspaceFloorAutotuner(fn (): int => 500 * 1048576, $tmpDir);
        $cmd = new class($autotuner) extends AtlasLoopKeepaliveCommand
        {
            public function __construct(AtlasLoopWorkspaceFloorAutotuner $at)
            {
                parent::__construct(null, null, null, $at);
            }

            protected function masterSwitchEnabled(): bool
            {
                return true; // arm without touching the real .env
            }

            protected function selfDeadlineAvailable(): bool
            {
                return false; // no pcntl alarm in the test
            }
        };
        $cmd->setLaravel($this->app);

        $exit = $cmd->run(new ArrayInput([]), new BufferedOutput());

        $this->assertSame(0, $exit);
        // ADVISORY-ONLY: config unchanged, workspaces tree untouched, only the autotune JSON written.
        $this->assertSame($floorBefore, config('atlas.loop.workspace_floor_mb'), 'config floor unchanged');
        $this->assertSame($workspacesExistedBefore, is_dir($workspacesDir), 'workspaces tree not created/touched');
        $this->assertFileExists($tmpDir.'/latest.json');
        $snapshot = json_decode((string) file_get_contents($tmpDir.'/latest.json'), true);
        $this->assertSame('atlas.loop.workspace_floor.v1', $snapshot['schema']);
        $this->assertSame(123, $snapshot['current_floor_mb']);

        @unlink($tmpDir.'/latest.json');
        @rmdir($tmpDir);
    }
}
