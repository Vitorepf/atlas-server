<?php

declare(strict_types=1);

namespace Tests\Feature\Loop;

use App\Console\Commands\AtlasLoopQuaternityDashboardCommand;
use App\Services\Ai\AutonomousEvolution\AtlasLoopMasterSwitch;
use Illuminate\Contracts\Console\Kernel as ConsoleKernel;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

final class AtlasLoopQuaternityDashboardCommandTest extends TestCase
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
        $this->envFile = sys_get_temp_dir().'/atlas-qdash-master-'.bin2hex(random_bytes(5)).'.env';
        file_put_contents($this->envFile, 'ATLAS_LOOP_MASTER_ENABLED='.($on ? 'true' : 'false')."\n");
        AtlasLoopMasterSwitch::$envPathOverride = $this->envFile;
    }

    private function frozenSnapshot(): array
    {
        return [
            'operator_intent_stream' => [
                ['at' => '2026-06-24T12:02:00Z', 'kind' => 'goal', 'detail' => 'foco no loop'],
                ['at' => '2026-06-24T12:00:00Z', 'kind' => 'chat', 'detail' => 'tira keepalive'],
                ['at' => '2026-06-24T12:01:00Z', 'kind' => 'cli', 'detail' => 'enqueue task X'],
            ],
            'recent_facts' => [
                ['at' => '2026-06-24T12:03:00Z', 'kind' => 'FACT', 'detail' => 'queue=12 in_flight=3'],
            ],
            'cortex_grounding_state' => [
                ['at' => '2026-06-24T12:04:00Z', 'kind' => 'role', 'detail' => 'designer=AtlasLoopFoo'],
            ],
        ];
    }

    private function runCmd(): array
    {
        $kernel = $this->app->make(ConsoleKernel::class);
        $exit = $kernel->call('atlas:loop:dashboard:quaternity');

        return [$exit, $kernel->output()];
    }

    public function test_three_panes_rendered_from_frozen_snapshot_with_canonical_timestamp_order(): void
    {
        $this->pinMasterSwitch(true);
        $this->app->instance(AtlasLoopQuaternityDashboardCommand::SNAPSHOT_SOURCE_KEY, fn (): array => $this->frozenSnapshot());

        [$exit, $out] = $this->runCmd();

        $this->assertSame(0, $exit);
        foreach (['operator_intent_stream', 'recent_facts', 'cortex_grounding_state'] as $pane) {
            $this->assertStringContainsString($pane, $out);
        }

        // Canonical timestamp asc: the chat "tira keepalive" (12:00) must precede goal "foco no loop" (12:02).
        $posChat = strpos($out, 'tira keepalive');
        $posCli = strpos($out, 'enqueue task X');
        $posGoal = strpos($out, 'foco no loop');
        $this->assertLessThan($posCli, $posChat);
        $this->assertLessThan($posGoal, $posCli);
    }

    public function test_performs_zero_db_writes_and_carries_no_score_or_priority_field(): void
    {
        $this->pinMasterSwitch(true);
        $this->app->instance(AtlasLoopQuaternityDashboardCommand::SNAPSHOT_SOURCE_KEY, fn (): array => $this->frozenSnapshot());

        $writeCount = 0;
        DB::listen(function ($query) use (&$writeCount): void {
            $sql = strtolower((string) $query->sql);
            if (str_starts_with($sql, 'insert') || str_starts_with($sql, 'update') || str_starts_with($sql, 'delete')) {
                $writeCount++;
            }
        });

        [, $out] = $this->runCmd();

        $this->assertSame(0, $writeCount, 'render must perform zero DB writes');
        $this->assertStringNotContainsString('score', strtolower($out));
        $this->assertStringNotContainsString('priority', strtolower($out));
        $this->assertStringNotContainsString('quality', strtolower($out));
    }

    public function test_empty_snapshot_renders_no_rows_markers_in_each_pane(): void
    {
        $this->pinMasterSwitch(true);
        $this->app->instance(AtlasLoopQuaternityDashboardCommand::SNAPSHOT_SOURCE_KEY, fn (): array => [
            'operator_intent_stream' => [],
            'recent_facts' => [],
            'cortex_grounding_state' => [],
        ]);

        [$exit, $out] = $this->runCmd();

        $this->assertSame(0, $exit);
        // Three panes ⇒ three (no rows) markers.
        $this->assertSame(3, substr_count($out, AtlasLoopQuaternityDashboardCommand::NO_ROWS_MARKER));
    }

    public function test_master_switch_off_emits_banner_only(): void
    {
        $this->pinMasterSwitch(false);

        $this->app->instance(AtlasLoopQuaternityDashboardCommand::SNAPSHOT_SOURCE_KEY, function (): array {
            throw new \RuntimeException('snapshot source must NOT be called when master switch is off');
        });

        [$exit, $out] = $this->runCmd();

        $this->assertSame(0, $exit);
        $this->assertSame(AtlasLoopQuaternityDashboardCommand::MASTER_OFF_BANNER."\n", $out);
    }
}
