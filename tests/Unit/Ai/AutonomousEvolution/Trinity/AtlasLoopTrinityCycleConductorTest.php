<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\AutonomousEvolution\Trinity;

use App\Services\Ai\AutonomousEvolution\AtlasLoopComprehensionOriginator;
use App\Services\Ai\AutonomousEvolution\Discovery\AtlasLoopScopeComprehensionModel;
use App\Services\Ai\AutonomousEvolution\Trinity\ThreeWay\AtlasLoopTrinityCycleConductor;
use Tests\TestCase;

final class AtlasLoopTrinityCycleConductorTest extends TestCase
{
    public function test_run_one_cycle_links_loop_cortex_and_maestro_receipts_under_one_cycle_id(): void
    {
        $conductor = new AtlasLoopTrinityCycleConductor($this->model(), $this->originator());

        $result = $conductor->runOneCycle();

        $this->assertNotSame('', $result->loopReceiptId);
        $this->assertNotSame('', $result->cortexReceiptId);
        $this->assertNotSame('', $result->maestroReceiptId);
        $this->assertSame($result->cycleId, $conductor->receiptFor($result->loopReceiptId)['cycle_id']);
        $this->assertSame($result->cycleId, $conductor->receiptFor($result->cortexReceiptId)['cycle_id']);
        $this->assertSame($result->cycleId, $conductor->receiptFor($result->maestroReceiptId)['cycle_id']);
        $this->assertSame(3, $result->newFactCount);
        $this->assertTrue($result->fuelGenerated);
    }

    public function test_empty_loop_seed_aborts_fail_closed_and_next_cycle_consumes_abort_fact(): void
    {
        $calls = 0;
        $originator = new AtlasLoopComprehensionOriginator(function (string $prompt) use (&$calls): ?array {
            $calls++;

            return $calls === 1
                ? null
                : ['objective' => 'Recover from prior abort', 'cited_symbols' => ['App\\Domain\\TrinitySeed']];
        });
        $conductor = new AtlasLoopTrinityCycleConductor($this->model(), $originator);

        $aborted = $conductor->runOneCycle();
        $next = $conductor->runOneCycle();
        $loopReceipt = $conductor->receiptFor($next->loopReceiptId);

        $this->assertSame('', $aborted->cortexReceiptId);
        $this->assertSame('', $aborted->maestroReceiptId);
        $this->assertSame('aborted_no_seed', $conductor->getFuelForNextCycle($aborted->cycleId)[0]['fact_kind']);
        $this->assertSame(
            $conductor->getFuelForNextCycle($aborted->cycleId),
            $loopReceipt['payload']['facts'][0]['input_fuel'],
        );
        $this->assertSame(
            'cortex_drift_context',
            $conductor->receiptFor($next->cortexReceiptId)['payload']['facts'][0]['fact_kind'],
        );
    }

    public function test_cycle_facts_are_byte_identical_fuel_for_next_cycle(): void
    {
        $conductor = new AtlasLoopTrinityCycleConductor($this->model(), $this->originator());

        $result = $conductor->runOneCycle();
        $fuel = $conductor->getFuelForNextCycle($result->cycleId);

        $this->assertSame(
            json_encode($fuel, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
            json_encode($conductor->getFuelForNextCycle($result->cycleId), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
        );
        $this->assertSame('loop_seed', $fuel[0]['fact_kind']);
        $this->assertSame('cortex_grounded_symbol', $fuel[1]['fact_kind']);
        $this->assertSame('maestro_structured_task', $fuel[2]['fact_kind']);
    }

    private function originator(): AtlasLoopComprehensionOriginator
    {
        return new AtlasLoopComprehensionOriginator(fn (string $prompt): array => [
            'objective' => 'Wire the Trinity seed through Cortex and Maestro',
            'cited_symbols' => ['App\\Domain\\TrinitySeed'],
        ]);
    }

    private function model(): AtlasLoopScopeComprehensionModel
    {
        return new AtlasLoopScopeComprehensionModel(
            inventory: [[
                'rel_path' => 'app/Domain/TrinitySeed.php',
                'fqcn' => 'App\\Domain\\TrinitySeed',
                'public_methods' => ['handle'],
                'is_orphan' => true,
                'is_forbidden' => false,
                'clone_cluster_id' => null,
            ]],
            edges: ['app/Domain/TrinitySeed.php' => []],
            orphans: ['App\\Domain\\TrinitySeed'],
            cloneClusters: [],
            forbidden: [],
            docPurposes: ['App\\Domain\\TrinitySeed' => 'Seed used by the Trinity conductor test.'],
            docStatedGaps: [],
            snapshotId: 'snapshot-trinity',
        );
    }
}
