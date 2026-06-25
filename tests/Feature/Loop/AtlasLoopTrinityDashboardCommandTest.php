<?php

declare(strict_types=1);

namespace Tests\Feature\Loop;

use App\Console\Commands\AtlasLoopTrinityDashboardCommand;
use App\Services\Ai\AutonomousEvolution\AtlasLoopMasterSwitch;
use Illuminate\Contracts\Console\Kernel as ConsoleKernel;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

final class AtlasLoopTrinityDashboardCommandTest extends TestCase
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
        $this->envFile = sys_get_temp_dir().'/atlas-tdash-master-'.bin2hex(random_bytes(5)).'.env';
        file_put_contents($this->envFile, 'ATLAS_LOOP_MASTER_ENABLED='.($on ? 'true' : 'false')."\n");
        AtlasLoopMasterSwitch::$envPathOverride = $this->envFile;
    }

    private function frozenSnapshot(): array
    {
        return [
            'loop' => ['emit_rate_60s' => 12, 'consume_rate_60s' => 10, 'emit_minus_consume' => 2, 'last_emit_age_s' => 5, 'last_consume_age_s' => 7],
            'cortex' => ['emit_rate_60s' => 4, 'consume_rate_60s' => 4, 'emit_minus_consume' => 0, 'last_emit_age_s' => 22, 'last_consume_age_s' => 19],
            'maestro' => ['emit_rate_60s' => 1, 'consume_rate_60s' => 3, 'emit_minus_consume' => -2, 'last_emit_age_s' => 90, 'last_consume_age_s' => 12],
        ];
    }

    private function runCmd(): array
    {
        $kernel = $this->app->make(ConsoleKernel::class);
        $exit = $kernel->call('atlas:loop:dashboard:trinity');

        return [$exit, $kernel->output()];
    }

    public function test_renders_one_row_per_organ_with_exact_fact_columns_and_is_byte_identical(): void
    {
        $this->pinMasterSwitch(true);
        $this->app->instance(AtlasLoopTrinityDashboardCommand::SNAPSHOT_SOURCE_KEY, fn (): array => $this->frozenSnapshot());

        [, $first] = $this->runCmd();
        [$exit, $second] = $this->runCmd();

        $this->assertSame(0, $exit);
        $this->assertSame($first, $second, 'two renders over the same snapshot must be byte-identical');

        foreach (AtlasLoopTrinityDashboardCommand::ORGANS as $organ) {
            $this->assertStringContainsString($organ, $first);
        }
        foreach (AtlasLoopTrinityDashboardCommand::COLUMNS as $column) {
            $this->assertStringContainsString($column, $first);
        }
        // Raw integers: loop's emit_minus_consume=2 appears, maestro's -2 appears.
        $this->assertMatchesRegularExpression('/loop\s*\|\s*12\s*\|\s*10\s*\|\s*2\s*\|\s*5\s*\|\s*7/', $first);
        $this->assertMatchesRegularExpression('/maestro\s*\|\s*1\s*\|\s*3\s*\|\s*-2\s*\|\s*90\s*\|\s*12/', $first);
    }

    public function test_carries_no_score_or_rating_field_and_performs_zero_db_writes(): void
    {
        $this->pinMasterSwitch(true);
        $this->app->instance(AtlasLoopTrinityDashboardCommand::SNAPSHOT_SOURCE_KEY, fn (): array => $this->frozenSnapshot());

        $writeCount = 0;
        DB::listen(function ($query) use (&$writeCount): void {
            $sql = strtolower((string) $query->sql);
            if (str_starts_with($sql, 'insert') || str_starts_with($sql, 'update') || str_starts_with($sql, 'delete')) {
                $writeCount++;
            }
        });

        [, $out] = $this->runCmd();

        $this->assertSame(0, $writeCount);
        $low = strtolower($out);
        $this->assertStringNotContainsString('score', $low);
        $this->assertStringNotContainsString('rating', $low);
        $this->assertStringNotContainsString('coupling_score', $low);
    }

    public function test_missing_snapshot_renders_no_data_marker_and_exits_zero(): void
    {
        $this->pinMasterSwitch(true);
        // No snapshot source bound ⇒ command must render '(no data)' and exit 0.

        [$exit, $out] = $this->runCmd();

        $this->assertSame(0, $exit);
        $this->assertSame(AtlasLoopTrinityDashboardCommand::NO_DATA_MARKER."\n", $out);
    }

    public function test_master_switch_off_emits_banner_only_and_does_not_call_snapshot_source(): void
    {
        $this->pinMasterSwitch(false);
        $this->app->instance(AtlasLoopTrinityDashboardCommand::SNAPSHOT_SOURCE_KEY, function (): array {
            throw new \RuntimeException('snapshot source must NOT be called when master switch is off');
        });

        [$exit, $out] = $this->runCmd();

        $this->assertSame(0, $exit);
        $this->assertSame(AtlasLoopTrinityDashboardCommand::MASTER_OFF_BANNER."\n", $out);
    }
}
