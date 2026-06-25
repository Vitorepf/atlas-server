<?php

declare(strict_types=1);

namespace Tests\Feature\Loop;

use App\Console\Commands\AtlasLoopCycleCommand;
use App\Console\Commands\UnifiedReceiptChainReader;
use App\Services\Ai\AutonomousEvolution\AtlasLoopMasterSwitch;
use App\Services\Ai\AutonomousEvolution\LiveCycle\AtlasLoopFullCycleConductor;
use App\Services\Ai\AutonomousEvolution\LiveCycle\AtlasLoopPhaseRunner;
use App\Services\Ai\AutonomousEvolution\LiveCycle\UnifiedReceiptChain;
use Illuminate\Contracts\Console\Kernel as ConsoleKernel;
use Tests\TestCase;

final class AtlasLoopCycleCommandTest extends TestCase
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
        $this->envFile = sys_get_temp_dir().'/atlas-cycle-cli-'.bin2hex(random_bytes(5)).'.env';
        file_put_contents($this->envFile, 'ATLAS_LOOP_MASTER_ENABLED='.($on ? 'true' : 'false')."\n");
        AtlasLoopMasterSwitch::$envPathOverride = $this->envFile;
    }

    private function happyRunner(?string $mergedSha = null): AtlasLoopPhaseRunner
    {
        return new class($mergedSha) implements AtlasLoopPhaseRunner
        {
            public function __construct(private readonly ?string $mergedSha) {}

            public function run(array $scope): array
            {
                return $this->mergedSha === null ? ['status' => 'ok'] : ['status' => 'ok', 'merged_sha' => $this->mergedSha];
            }
        };
    }

    private function bindFakeConductor(): void
    {
        $runners = [];
        foreach (AtlasLoopFullCycleConductor::PHASES as $phase) {
            $runners[$phase] = $this->happyRunner($phase === 'close' ? 'sha-feature' : null);
        }
        $chain = new class implements UnifiedReceiptChain
        {
            public function record(string $cycleId, string $phase, array $receipt): void {}
        };
        $this->app->instance(AtlasLoopFullCycleConductor::class, new AtlasLoopFullCycleConductor($runners, $chain));
    }

    private function bindReader(array $forCycle, array $history): void
    {
        $this->app->instance(AtlasLoopCycleCommand::READER_KEY, new class($forCycle, $history) implements UnifiedReceiptChainReader
        {
            public function __construct(private readonly array $forCycleMap, private readonly array $historyRows) {}

            public function forCycle(string $cycleId): array
            {
                return $this->forCycleMap[$cycleId] ?? [];
            }

            public function history(int $limit): array
            {
                return array_slice($this->historyRows, 0, $limit);
            }
        });
    }

    private function runCmd(array $args): array
    {
        $kernel = $this->app->make(ConsoleKernel::class);
        $exit = $kernel->call('atlas:loop:cycle', $args);

        return [$exit, $kernel->output()];
    }

    public function test_run_with_master_switch_on_emits_json_with_final_status(): void
    {
        $this->pinMasterSwitch(true);
        $this->bindFakeConductor();

        [$exit, $out] = $this->runCmd(['action' => 'run', '--scope' => 'test', '--json' => true]);

        $this->assertSame(AtlasLoopCycleCommand::EXIT_OK, $exit, $out);
        $decoded = json_decode(trim($out), true);
        $this->assertArrayHasKey('final_status', $decoded);
        $this->assertSame('completed', $decoded['final_status']);
    }

    public function test_run_with_master_switch_off_refuses_with_exit_one(): void
    {
        $this->pinMasterSwitch(false);
        $this->bindFakeConductor();

        [$exit, $out] = $this->runCmd(['action' => 'run', '--scope' => 'test', '--json' => true]);

        $this->assertSame(AtlasLoopCycleCommand::EXIT_REFUSED, $exit);
        $this->assertStringContainsString('master_switch_off', $out);
    }

    public function test_history_is_available_even_when_master_switch_off(): void
    {
        $this->pinMasterSwitch(false);
        $this->bindFakeConductor(); // required so the container can resolve handle()'s ctor param
        $this->bindReader([], [['cycle_id' => 'c-1', 'final_status' => 'completed']]);

        [$exit, $out] = $this->runCmd(['action' => 'history', '--json' => true]);

        $this->assertSame(AtlasLoopCycleCommand::EXIT_OK, $exit);
        $decoded = json_decode(trim($out), true);
        $this->assertIsArray($decoded);
    }

    public function test_inspect_prints_all_eight_phase_receipts_in_canonical_order(): void
    {
        $this->bindFakeConductor();
        $perPhase = [];
        foreach (AtlasLoopFullCycleConductor::PHASES as $phase) {
            $perPhase[$phase] = ['status' => 'ok', 'phase' => $phase];
        }
        $this->bindReader(['c-1' => $perPhase], []);

        [$exit, $out] = $this->runCmd(['action' => 'inspect', '--cycle-id' => 'c-1', '--json' => true]);

        $this->assertSame(AtlasLoopCycleCommand::EXIT_OK, $exit);
        $decoded = json_decode(trim($out), true);
        $this->assertSame(AtlasLoopFullCycleConductor::PHASES, array_keys($decoded['phases']));
        foreach (AtlasLoopFullCycleConductor::PHASES as $phase) {
            $this->assertNotNull($decoded['phases'][$phase], "missing receipt for {$phase}");
        }
    }
}
