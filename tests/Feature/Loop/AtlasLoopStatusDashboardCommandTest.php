<?php

declare(strict_types=1);

namespace Tests\Feature\Loop;

use App\Console\Commands\AtlasLoopStatusDashboardCommand;
use App\Services\Ai\AutonomousEvolution\AtlasLoopMasterSwitch;
use Illuminate\Contracts\Console\Kernel as ConsoleKernel;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

final class AtlasLoopStatusDashboardCommandTest extends TestCase
{
    private ?string $envFile = null;

    protected function tearDown(): void
    {
        AtlasLoopMasterSwitch::$envPathOverride = null;
        if ($this->envFile !== null) {
            @unlink($this->envFile);
        }
        parent::tearDown();
    }

    private function pinMasterSwitch(bool $on): void
    {
        $this->envFile = sys_get_temp_dir().'/atlas-dash-master-'.bin2hex(random_bytes(5)).'.env';
        file_put_contents($this->envFile, 'ATLAS_LOOP_MASTER_ENABLED='.($on ? 'true' : 'false')."\n");
        AtlasLoopMasterSwitch::$envPathOverride = $this->envFile;
    }

    private function frozenSnapshot(): array
    {
        return [
            'queue_depth' => 7,
            'in_flight' => 2,
            'recent_completion_rate' => '12/13',
            'last_10_facts' => [
                ['at' => '2026-06-24T12:00:00Z', 'kind' => 'enqueued', 'detail' => 'pkt-a'],
                ['at' => '2026-06-24T12:01:00Z', 'kind' => 'resolved', 'detail' => 'pkt-b'],
                ['at' => '2026-06-24T12:02:00Z', 'kind' => 'gave_back', 'detail' => 'pkt-c'],
            ],
        ];
    }

    private function runCmd(): array
    {
        $kernel = $this->app->make(ConsoleKernel::class);
        $exit = $kernel->call('atlas:loop:dashboard:status');

        return [$exit, $kernel->output()];
    }

    public function test_renders_a_single_frame_in_under_250ms_with_all_four_sections(): void
    {
        $this->pinMasterSwitch(true);
        $this->app->instance(AtlasLoopStatusDashboardCommand::SNAPSHOT_SOURCE_KEY, fn (): array => $this->frozenSnapshot());

        $start = microtime(true);
        [$exit, $out] = $this->runCmd();
        $elapsedMs = (microtime(true) - $start) * 1000;

        $this->assertSame(0, $exit);
        $this->assertLessThan(250.0, $elapsedMs, 'frame must render under 250ms');
        $this->assertStringContainsString('queue_depth: 7', $out);
        $this->assertStringContainsString('in_flight: 2', $out);
        $this->assertStringContainsString('recent_completion_rate: 12/13', $out);
        $this->assertStringContainsString('last 10 FACTs', $out);
        $this->assertStringContainsString('pkt-a', $out);
    }

    public function test_frame_is_byte_identical_given_a_frozen_snapshot_and_carries_no_score_field(): void
    {
        $this->pinMasterSwitch(true);
        $this->app->instance(AtlasLoopStatusDashboardCommand::SNAPSHOT_SOURCE_KEY, fn (): array => $this->frozenSnapshot());

        [, $first] = $this->runCmd();
        [, $second] = $this->runCmd();

        $this->assertSame($first, $second, 'two renders over the same snapshot must be byte-identical');
        $this->assertStringNotContainsString('score', strtolower($first), 'no scalar score may appear in the rendered frame');
        $this->assertStringNotContainsString('rank', strtolower($first));
    }

    public function test_render_performs_zero_writes_during_a_transaction(): void
    {
        $this->pinMasterSwitch(true);
        $this->app->instance(AtlasLoopStatusDashboardCommand::SNAPSHOT_SOURCE_KEY, fn (): array => $this->frozenSnapshot());

        // Use the same DB to detect any write: count statements; on a read-only command, the inserts/updates
        // are zero.
        $writeCount = 0;
        DB::listen(function ($query) use (&$writeCount): void {
            $sql = strtolower((string) $query->sql);
            if (str_starts_with($sql, 'insert') || str_starts_with($sql, 'update') || str_starts_with($sql, 'delete')) {
                $writeCount++;
            }
        });

        [$exit] = $this->runCmd();

        $this->assertSame(0, $exit);
        $this->assertSame(0, $writeCount, 'dashboard render must perform zero DB writes');
    }

    public function test_master_switch_off_emits_only_the_banner_and_exits_zero(): void
    {
        $this->pinMasterSwitch(false);

        // A snapshot source that would BLOW UP if read — proves the off-path never reaches the loader.
        $this->app->instance(AtlasLoopStatusDashboardCommand::SNAPSHOT_SOURCE_KEY, function (): array {
            throw new \RuntimeException('snapshot source must NOT be called when master switch is off');
        });

        [$exit, $out] = $this->runCmd();

        $this->assertSame(0, $exit);
        $this->assertSame(AtlasLoopStatusDashboardCommand::MASTER_OFF_BANNER."\n", $out);
    }
}
