<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\AutonomousEvolution\LiveCycle;

use App\Services\Ai\AutonomousEvolution\AtlasLoopMasterSwitch;
use App\Services\Ai\AutonomousEvolution\LiveCycle\Integration\AtlasLoopLiveCycleOrchestrator;
use RuntimeException;
use Tests\TestCase;

final class AtlasLoopLiveCycleOrchestratorTest extends TestCase
{
    private string $envPath = '';

    protected function setUp(): void
    {
        parent::setUp();
        $this->envPath = sys_get_temp_dir().'/atlas-orch-env-'.bin2hex(random_bytes(6));
        file_put_contents($this->envPath, "ATLAS_LOOP_MASTER_ENABLED=true\n");
        AtlasLoopMasterSwitch::$envPathOverride = $this->envPath;
    }

    protected function tearDown(): void
    {
        AtlasLoopMasterSwitch::$envPathOverride = null;
        @unlink($this->envPath);
        parent::tearDown();
    }

    public function test_runs_all_eight_phases_in_canonical_order_with_monotonic_phase_index(): void
    {
        $orch = new AtlasLoopLiveCycleOrchestrator;
        $callOrder = [];
        $executors = [];
        foreach (AtlasLoopLiveCycleOrchestrator::PHASES as $phase) {
            $executors[$phase] = function (array $ctx) use (&$callOrder, $phase): void {
                $this->assertSame($phase, $ctx['phase_id']);
                $callOrder[] = $phase;
            };
        }
        $clock = (function (): callable {
            $t = 1700000000;

            return function () use (&$t): int {
                return $t++;
            };
        })();
        $factsEmitted = [];

        $result = $orch->run('cycle-A', $executors, $clock, function (array $f) use (&$factsEmitted): void {
            $factsEmitted[] = $f;
        });

        $this->assertSame(AtlasLoopLiveCycleOrchestrator::STATUS_OK, $result['status']);
        $this->assertSame(AtlasLoopLiveCycleOrchestrator::PHASES, $callOrder);
        $this->assertCount(8, $result['facts']);
        $this->assertSame(range(1, 8), array_column($result['facts'], 'phase_index'));
        $this->assertSame($factsEmitted, $result['facts'], 'sink receives the same FACTs the result carries');
        $this->assertSame('close-on-main', $result['final_phase']);
        $this->assertSame(8, $result['phases_completed']);
    }

    public function test_each_phase_receipt_carries_byte_verifiable_chain_recomputable_from_fact_log(): void
    {
        $orch = new AtlasLoopLiveCycleOrchestrator;
        $executors = [];
        foreach (AtlasLoopLiveCycleOrchestrator::PHASES as $phase) {
            $executors[$phase] = static fn (array $ctx) => null;
        }

        $clock = (function (): callable {
            $t = 1700000000;

            return function () use (&$t): int {
                return $t++;
            };
        })();
        $result = $orch->run('cycle-B', $executors, $clock);

        $facts = $result['facts'];
        $expectedChain = AtlasLoopLiveCycleOrchestrator::recomputeChain($facts);
        $actualChain = array_column($facts, 'prev_receipt_hash');

        $this->assertSame($expectedChain, $actualChain, 'on-chain prev_receipt_hash must reproduce from FACT log alone');
        $this->assertSame(
            '0000000000000000000000000000000000000000000000000000000000000000',
            $facts[0]['prev_receipt_hash'],
            'genesis prev_receipt_hash is 64 zero hex digits',
        );

        // The second receipt's prev_receipt_hash MUST equal sha256 of canonical-JSON of the first receipt.
        $this->assertSame(
            AtlasLoopLiveCycleOrchestrator::hashFact($facts[0]),
            $facts[1]['prev_receipt_hash'],
        );
    }

    public function test_master_switch_off_makes_run_a_byte_identical_no_op(): void
    {
        file_put_contents($this->envPath, "ATLAS_LOOP_MASTER_ENABLED=false\n");

        $orch = new AtlasLoopLiveCycleOrchestrator;
        $invocations = 0;
        $factsEmitted = 0;
        $executors = [];
        foreach (AtlasLoopLiveCycleOrchestrator::PHASES as $phase) {
            $executors[$phase] = function (array $ctx) use (&$invocations): void {
                $invocations++;
            };
        }

        $result = $orch->run('cycle-off', $executors, fn (): int => 0, function () use (&$factsEmitted): void {
            $factsEmitted++;
        });

        $this->assertSame(AtlasLoopLiveCycleOrchestrator::STATUS_OFF, $result['status']);
        $this->assertSame(0, $invocations, 'no phase executor invoked when master OFF');
        $this->assertSame(0, $factsEmitted, 'zero FACTs emitted when master OFF');
        $this->assertSame([], $result['facts']);
        $this->assertSame(0, $result['phases_completed']);
        $this->assertNull($result['final_phase']);

        $result2 = $orch->run('different-cycle-id', [], fn (): int => 99999);
        unset($result['cycle_id'], $result2['cycle_id']);
        $this->assertSame($result, $result2, 'OFF envelope is byte-identical regardless of inputs');
    }

    public function test_phase_failure_halts_cycle_and_persists_last_good_phase_index(): void
    {
        $orch = new AtlasLoopLiveCycleOrchestrator;
        $executors = [];
        foreach (AtlasLoopLiveCycleOrchestrator::PHASES as $phase) {
            $executors[$phase] = static fn (array $ctx) => null;
        }
        // architect (phase 4) blows up.
        $executors['architect'] = static function (array $ctx): void {
            throw new RuntimeException('architect-stub-failure');
        };
        $clock = (function (): callable {
            $t = 1700000000;

            return function () use (&$t): int {
                return $t++;
            };
        })();

        $result = $orch->run('cycle-fail', $executors, $clock);

        $this->assertSame(AtlasLoopLiveCycleOrchestrator::STATUS_FAIL, $result['status']);
        $this->assertSame(3, $result['last_good_phase_index'], 'comprehend (3) was the last good phase before architect failed');
        $this->assertSame('architect', $result['final_phase']);
        $this->assertSame('architect-stub-failure', $result['error']);
        $this->assertCount(4, $result['facts'], '3 ok facts + 1 fail fact');
        $this->assertSame('fail', $result['facts'][3]['status']);
    }
}
