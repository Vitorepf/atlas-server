<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\AutonomousEvolution\Brain;

use App\Services\Ai\AutonomousEvolution\AtlasLoopHarnessGuard;
use App\Services\Ai\AutonomousEvolution\Brain\AtlasBrainCascadeRuleOutcomeAnalyzer;
use App\Services\Ai\AutonomousEvolution\Brain\AtlasBrainDoneSetLedger;
use App\Services\Ai\AutonomousEvolution\Brain\AtlasBrainReflectionStream;
use Tests\TestCase;

/**
 * FROZEN proof of the cascade-rule outcome analyzer — per-action_hint served/refused counts joined from
 * the reflection stream + done-set ledger via snapshot_id === cycle_id. Pétreo organ.
 */
final class AtlasBrainCascadeRuleOutcomeAnalyzerTest extends TestCase
{
    private string $streamPath;

    private string $doneRoot;

    protected function setUp(): void
    {
        parent::setUp();
        $base = sys_get_temp_dir().'/atlas-brain-outcome-'.bin2hex(random_bytes(6));
        @mkdir($base, 0o775, true);
        $this->streamPath = $base.'/reflection.ndjson';
        $this->doneRoot = $base.'/done-set';
        @mkdir($this->doneRoot, 0o775, true);
        config()->set('atlas.brain.reflection_enabled', true);
    }

    private function writeReflection(string $cycleId, string $hint): void
    {
        $row = [
            'schema' => 'x',
            'scope' => 'loop',
            'cycle_id' => $cycleId,
            'result_kind' => 'note',
            'reflection' => "leverage_brief: {$hint} — testing",
            'signals' => ['action_hint' => $hint],
            'recorded_at' => 0,
        ];
        file_put_contents($this->streamPath, json_encode($row).PHP_EOL, FILE_APPEND);
    }

    public function test_analyze_joins_hint_to_outcome_and_computes_served_rate(): void
    {
        // 3 cycles for use_drafted_candidate: 2 served, 1 refused ⇒ 67%.
        // 1 cycle for rotate_path: refused ⇒ 0%.
        $this->writeReflection('snap-A', 'use_drafted_candidate');
        $this->writeReflection('snap-B', 'use_drafted_candidate');
        $this->writeReflection('snap-C', 'use_drafted_candidate');
        $this->writeReflection('snap-D', 'rotate_path');

        $ledger = new AtlasBrainDoneSetLedger('loop', $this->doneRoot);
        $ledger->record(['snapshot_id' => 'snap-A', 'status' => 'served', 'produced' => true]);
        $ledger->record(['snapshot_id' => 'snap-B', 'status' => 'served', 'produced' => true]);
        $ledger->record(['snapshot_id' => 'snap-C', 'status' => 'refused', 'produced' => false]);
        $ledger->record(['snapshot_id' => 'snap-D', 'status' => 'abstain', 'produced' => false]);

        $stream = new AtlasBrainReflectionStream($this->streamPath);
        $report = (new AtlasBrainCascadeRuleOutcomeAnalyzer)->analyze('loop', $stream, $ledger);

        self::assertSame(4, $report['joined_cycles']);
        // Sorted served_rate desc, then total desc, then hint asc.
        self::assertSame('use_drafted_candidate', $report['by_hint'][0]['hint']);
        self::assertSame(67, $report['by_hint'][0]['served_rate_pct']);
        self::assertSame(3, $report['by_hint'][0]['total']);
        self::assertSame('rotate_path', $report['by_hint'][1]['hint']);
        self::assertSame(0, $report['by_hint'][1]['served_rate_pct']);
    }

    public function test_analyze_ignores_cycles_with_no_matching_reflection(): void
    {
        $ledger = new AtlasBrainDoneSetLedger('loop', $this->doneRoot);
        $ledger->record(['snapshot_id' => 'orphan-X', 'status' => 'served']);

        $stream = new AtlasBrainReflectionStream($this->streamPath);
        $report = (new AtlasBrainCascadeRuleOutcomeAnalyzer)->analyze('loop', $stream, $ledger);

        self::assertSame(0, $report['joined_cycles']);
        self::assertSame([], $report['by_hint']);
    }

    public function test_analyzer_organ_is_a_petreo_forbidden_self_target(): void
    {
        $verdict = app(AtlasLoopHarnessGuard::class)->admit(
            'app/Services/Ai/AutonomousEvolution/Brain/AtlasBrainCascadeRuleOutcomeAnalyzer.php',
            true
        );

        self::assertSame('forbidden', $verdict);
    }
}
