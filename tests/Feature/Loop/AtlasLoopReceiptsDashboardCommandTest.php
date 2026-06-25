<?php

declare(strict_types=1);

namespace Tests\Feature\Loop;

use App\Console\Commands\AtlasLoopReceiptsDashboardCommand;
use App\Services\Ai\AutonomousEvolution\AtlasLoopMasterSwitch;
use Illuminate\Contracts\Console\Kernel as ConsoleKernel;
use Tests\TestCase;

final class AtlasLoopReceiptsDashboardCommandTest extends TestCase
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
        $this->envFile = sys_get_temp_dir().'/atlas-rdash-master-'.bin2hex(random_bytes(5)).'.env';
        file_put_contents($this->envFile, 'ATLAS_LOOP_MASTER_ENABLED='.($on ? 'true' : 'false')."\n");
        AtlasLoopMasterSwitch::$envPathOverride = $this->envFile;
    }

    private function bindReader(string $key, array $rows, ?callable $spy = null): void
    {
        $this->app->instance($key, function (int $tail) use ($rows, $spy): array {
            if ($spy !== null) {
                $spy($tail);
            }

            return $rows;
        });
    }

    private function runCmd(array $args = []): array
    {
        $kernel = $this->app->make(ConsoleKernel::class);
        $exit = $kernel->call('atlas:loop:dashboard:receipts', $args);

        return [$exit, $kernel->output()];
    }

    public function test_linkage_resolves_for_known_3_step_chain_in_canonical_desc_order(): void
    {
        $this->pinMasterSwitch(true);
        $this->bindReader(AtlasLoopReceiptsDashboardCommand::ATTEMPT_READER_KEY, [
            ['id' => 'attempt-A', 'at' => '2026-06-24T12:00:00Z', 'parent_id' => '', 'summary' => 'first try'],
            ['id' => 'attempt-B', 'at' => '2026-06-24T12:05:00Z', 'parent_id' => '', 'summary' => 'second try'],
        ]);
        $this->bindReader(AtlasLoopReceiptsDashboardCommand::IMPACT_READER_KEY, [
            ['id' => 'impact-X', 'at' => '2026-06-24T12:01:00Z', 'parent_id' => 'attempt-A', 'summary' => 'impact for A'],
        ]);
        $this->bindReader(AtlasLoopReceiptsDashboardCommand::OUTCOME_READER_KEY, [
            ['id' => 'outcome-Y', 'at' => '2026-06-24T12:02:00Z', 'parent_id' => 'impact-X', 'summary' => 'outcome for X'],
        ]);

        [$exit, $out] = $this->runCmd(['--tail' => 10]);

        $this->assertSame(0, $exit, $out);
        $this->assertStringContainsString('attempt-A -> impact-X -> outcome-Y', $out);
        // attempt-B has no impact chain ⇒ shows `-` legs.
        $this->assertStringContainsString('attempt-B -> - -> -', $out);
        // DESC order: attempt-B (12:05) renders BEFORE attempt-A (12:00).
        $posB = strpos($out, 'attempt-B');
        $posA = strpos($out, 'attempt-A');
        $this->assertLessThan($posA, $posB);
    }

    public function test_tail_value_is_clamped_to_one_and_fifty(): void
    {
        $this->pinMasterSwitch(true);
        $tails = [];
        $this->bindReader(AtlasLoopReceiptsDashboardCommand::ATTEMPT_READER_KEY, [], function (int $t) use (&$tails): void {
            $tails[] = $t;
        });
        $this->bindReader(AtlasLoopReceiptsDashboardCommand::IMPACT_READER_KEY, []);
        $this->bindReader(AtlasLoopReceiptsDashboardCommand::OUTCOME_READER_KEY, []);

        $this->runCmd(['--tail' => 999]);
        $this->runCmd(['--tail' => 0]);

        $this->assertSame(AtlasLoopReceiptsDashboardCommand::MAX_TAIL, $tails[0]);
        $this->assertSame(1, $tails[1]);
    }

    public function test_master_switch_off_never_invokes_the_ledger_readers(): void
    {
        $this->pinMasterSwitch(false);

        $reads = ['attempt' => 0, 'impact' => 0, 'outcome' => 0];
        $this->bindReader(AtlasLoopReceiptsDashboardCommand::ATTEMPT_READER_KEY, [], function () use (&$reads): void {
            $reads['attempt']++;
        });
        $this->bindReader(AtlasLoopReceiptsDashboardCommand::IMPACT_READER_KEY, [], function () use (&$reads): void {
            $reads['impact']++;
        });
        $this->bindReader(AtlasLoopReceiptsDashboardCommand::OUTCOME_READER_KEY, [], function () use (&$reads): void {
            $reads['outcome']++;
        });

        [$exit, $out] = $this->runCmd();

        $this->assertSame(0, $exit);
        $this->assertSame(AtlasLoopReceiptsDashboardCommand::MASTER_OFF_BANNER."\n", $out);
        $this->assertSame(['attempt' => 0, 'impact' => 0, 'outcome' => 0], $reads, 'no reader may run when master switch is off');
    }
}
