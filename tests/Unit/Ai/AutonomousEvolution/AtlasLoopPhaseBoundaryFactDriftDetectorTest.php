<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\AutonomousEvolution;

use App\Services\Ai\AutonomousEvolution\LiveCycle\FactPassing\AtlasLoopPhaseBoundaryFactDriftDetector;
use App\Services\Ai\AutonomousEvolution\LiveCycle\FactPassing\AtlasLoopPhaseBoundaryFactReceiptLedger;
use App\Services\Ai\AutonomousEvolution\LiveCycle\FactPassing\AtlasLoopPhaseBoundaryFactValidator;
use Tests\TestCase;

final class AtlasLoopPhaseBoundaryFactDriftDetectorTest extends TestCase
{
    private string $path = '';

    protected function setUp(): void
    {
        parent::setUp();
        $this->path = sys_get_temp_dir().'/atlas-fact-drift-'.bin2hex(random_bytes(6)).'.jsonl';
    }

    protected function tearDown(): void
    {
        @unlink($this->path);
        parent::tearDown();
    }

    private function ledger(): AtlasLoopPhaseBoundaryFactReceiptLedger
    {
        return new AtlasLoopPhaseBoundaryFactReceiptLedger(new AtlasLoopPhaseBoundaryFactValidator(), $this->path);
    }

    private function validFact(string $cycleId = 'cyc-1'): array
    {
        return [
            'scope_snapshot' => ['root' => 'app/Demo'],
            'surface_inventory' => ['Foo'],
            'target_signal' => 'compile_clean',
            'emitted_by_phase' => 'orient',
            'emitted_at' => '2026-06-25T12:00:00Z',
            'cycle_id' => $cycleId,
        ];
    }

    public function test_detect_returns_per_boundary_counts_and_top_keys(): void
    {
        $ledger = $this->ledger();
        // Boundary 1: orient->comprehend
        //   3 valid receipts, 2 invalid (missing target_signal).
        for ($i = 0; $i < 3; $i++) {
            $ledger->record('cyc-'.$i, 'orient->comprehend', $this->validFact('cyc-'.$i));
        }
        for ($i = 0; $i < 2; $i++) {
            $f = $this->validFact('cyc-bad-'.$i);
            unset($f['target_signal']);
            $ledger->record('cyc-bad-'.$i, 'orient->comprehend', $f);
        }
        // Boundary 2: comprehend->decide-leverage, 1 receipt invalid with two missing.
        $invalid = ['emitted_by_phase' => 'comprehend', 'emitted_at' => '2026-06-25T12:00:00Z', 'cycle_id' => 'cyc-x'];
        $ledger->record('cyc-x', 'comprehend->decide-leverage', $invalid);
        // Boundary 3: decide-leverage->architect, 1 valid receipt.
        $ok = [
            'selected_lever' => 'foo',
            'selection_rationale' => ['why' => 'because'],
            'guardrails' => ['no_pet'],
            'emitted_by_phase' => 'decide-leverage',
            'emitted_at' => '2026-06-25T12:00:00Z',
            'cycle_id' => 'cyc-y',
        ];
        $ledger->record('cyc-y', 'decide-leverage->architect', $ok);

        $detector = new AtlasLoopPhaseBoundaryFactDriftDetector($ledger);
        $report = $detector->detect(0);

        $orient = $report['boundaries']['orient->comprehend'];
        $this->assertSame(5, $orient['total_count']);
        $this->assertSame(2, $orient['invalid_count']);
        $this->assertNotEmpty($orient['top_missing_keys']);
        $this->assertSame('target_signal', $orient['top_missing_keys'][0]['key']);
        $this->assertSame(2, $orient['top_missing_keys'][0]['count']);
        $this->assertSame('cyc-bad-0', $orient['first_divergence_cycle_id']);

        $comprehend = $report['boundaries']['comprehend->decide-leverage'];
        $this->assertSame(1, $comprehend['total_count']);
        $this->assertSame(1, $comprehend['invalid_count']);
        $this->assertGreaterThanOrEqual(2, count($comprehend['top_missing_keys']));

        $decide = $report['boundaries']['decide-leverage->architect'];
        $this->assertSame(1, $decide['total_count']);
        $this->assertSame(0, $decide['invalid_count']);
        $this->assertNull($decide['first_divergence_cycle_id']);
    }

    public function test_report_contains_no_score_keys(): void
    {
        $report = (new AtlasLoopPhaseBoundaryFactDriftDetector($this->ledger()))->detect(0);
        $flat = (string) json_encode($report);
        // No top-level *_score field anywhere.
        $this->assertDoesNotMatchRegularExpression('/"[A-Za-z_]*_score"\s*:/', $flat);
        $this->assertDoesNotMatchRegularExpression('/"[A-Za-z_]*score"\s*:/', $flat);
    }
}
