<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\SoftwareCompanyStewardship\AreaFocusLoop\AtomicBacklog;

use App\Services\Ai\SoftwareCompanyStewardship\AreaFocusLoop\AtomicBacklog\CycleOutcomeSelectionSignalScorer;
use PHPUnit\Framework\TestCase;

final class CycleOutcomeSelectionSignalScorerTest extends TestCase
{
    private CycleOutcomeSelectionSignalScorer $scorer;

    protected function setUp(): void
    {
        $this->scorer = new CycleOutcomeSelectionSignalScorer();
    }

    public function testNineMergesOneBlockYieldsNinetyPercentMergeRateAndPositiveDelta(): void
    {
        $result = $this->scorer->score([
            'flaky_test' => $this->cycles(9, ['merged' => true, 'outcome_met' => true])
                + $this->offsetCycles(9, 1, ['blocked' => true]),
        ]);

        $this->assertSame('atlas.loop.cycle_outcome_selection_signal.v1', $result['schema_version']);

        $signal = $result['signals_by_gap_kind']['flaky_test'];
        $this->assertSame(10, $signal['cycles']);
        $this->assertSame(0.9, $signal['merge_rate']);
        $this->assertSame(0.1, $signal['blocker_rate']);
        $this->assertSame(0.9, $signal['outcome_met_rate']);
        $this->assertSame(0.0, $signal['repair_exhausted_rate']);
        $this->assertSame(82, $signal['priority_delta']);
        $this->assertGreaterThan(0, $signal['priority_delta']);
        $this->assertSame(1.0, $signal['confidence']);

        $this->assertSame(82, $result['priority_deltas']['flaky_test']);
        $this->assertSame('flaky_test', $result['best_gap_kind']);
        $this->assertSame('flaky_test', $result['worst_gap_kind']);
    }

    public function testBlockerRateAboveSixtyPercentYieldsNegativeDelta(): void
    {
        $result = $this->scorer->score([
            'doc_drift' => $this->cycles(7, ['blocked' => true])
                + $this->offsetCycles(7, 3, ['merged' => true, 'outcome_met' => true]),
        ]);

        $signal = $result['signals_by_gap_kind']['doc_drift'];
        $this->assertSame(0.7, $signal['blocker_rate']);
        $this->assertGreaterThan(0.6, $signal['blocker_rate']);
        $this->assertSame(0.3, $signal['merge_rate']);
        $this->assertSame(-26, $signal['priority_delta']);
        $this->assertLessThan(0, $signal['priority_delta']);
    }

    public function testRepairExhaustedOverThresholdPenalizesDelta(): void
    {
        $withoutRepair = [
            'merged' => true,
            'outcome_met' => true,
        ];
        $withRepair = [
            'merged' => true,
            'outcome_met' => true,
            'repair_exhausted' => true,
        ];

        $result = $this->scorer->score([
            'clean' => $this->cycles(10, $withoutRepair),
            'thrash' => $this->cycles(6, $withRepair)
                + $this->offsetCycles(6, 4, $withoutRepair),
        ]);

        $clean = $result['signals_by_gap_kind']['clean'];
        $thrash = $result['signals_by_gap_kind']['thrash'];

        $this->assertSame(0.0, $clean['repair_exhausted_rate']);
        $this->assertSame(0.6, $thrash['repair_exhausted_rate']);
        $this->assertGreaterThan(self::repairThreshold(), $thrash['repair_exhausted_rate']);

        // clean: 60*1 + 40*1 = 100
        $this->assertSame(100, $clean['priority_delta']);
        // thrash: 60*1 + 40*1 - 40*0.6 - 20(penalty over threshold) = 100 - 24 - 20 = 56
        $this->assertSame(56, $thrash['priority_delta']);
        $this->assertLessThan($clean['priority_delta'], $thrash['priority_delta']);
    }

    public function testRepairExhaustedHeavyHistoryDrivesDeltaNegative(): void
    {
        $result = $this->scorer->score([
            'stuck' => $this->cycles(9, ['blocked' => true, 'repair_exhausted' => true])
                + $this->offsetCycles(9, 1, ['merged' => true, 'outcome_met' => true]),
        ]);

        $signal = $result['signals_by_gap_kind']['stuck'];
        $this->assertSame(0.9, $signal['repair_exhausted_rate']);
        // 60*0.1 + 40*0.1 - 80*0.9 - 40*0.9 - 20 = 6 + 4 - 72 - 36 - 20 = -118 -> clamped -100
        $this->assertSame(-100, $signal['priority_delta']);
        $this->assertLessThan(0, $signal['priority_delta']);
    }

    public function testZeroHistoryYieldsZeroDeltaAndZeroConfidence(): void
    {
        $result = $this->scorer->score([
            'untouched' => [],
        ]);

        $signal = $result['signals_by_gap_kind']['untouched'];
        $this->assertSame(0, $signal['cycles']);
        $this->assertSame(0, $signal['priority_delta']);
        $this->assertSame(0.0, $signal['confidence']);
        $this->assertSame(0.0, $signal['merge_rate']);
        $this->assertSame(0.0, $signal['blocker_rate']);
        $this->assertSame(0.0, $signal['outcome_met_rate']);
        $this->assertSame(0.0, $signal['repair_exhausted_rate']);
        $this->assertSame(0, $result['priority_deltas']['untouched']);
    }

    public function testTiedDeltasResolveDeterministicallyByGapKind(): void
    {
        $identical = $this->cycles(10, ['merged' => true, 'outcome_met' => true]);

        $result = $this->scorer->score([
            'zeta_gap' => $identical,
            'alpha_gap' => $identical,
            'mid_gap' => $identical,
        ]);

        $this->assertSame(100, $result['signals_by_gap_kind']['alpha_gap']['priority_delta']);
        $this->assertSame(100, $result['signals_by_gap_kind']['zeta_gap']['priority_delta']);
        $this->assertSame(100, $result['signals_by_gap_kind']['mid_gap']['priority_delta']);

        // All deltas tie at 100 -> best and worst both resolve to the
        // alphabetically smallest gap_kind, deterministically.
        $this->assertSame('alpha_gap', $result['best_gap_kind']);
        $this->assertSame('alpha_gap', $result['worst_gap_kind']);

        $this->assertSame(['alpha_gap', 'mid_gap', 'zeta_gap'], array_keys($result['priority_deltas']));
    }

    public function testBestAndWorstSeparateWhenDeltasDiffer(): void
    {
        $result = $this->scorer->score([
            'green_gap' => $this->cycles(10, ['merged' => true, 'outcome_met' => true]),
            'red_gap' => $this->cycles(10, ['blocked' => true]),
        ]);

        $this->assertSame(100, $result['signals_by_gap_kind']['green_gap']['priority_delta']);
        $this->assertSame(-80, $result['signals_by_gap_kind']['red_gap']['priority_delta']);
        $this->assertSame('green_gap', $result['best_gap_kind']);
        $this->assertSame('red_gap', $result['worst_gap_kind']);
    }

    public function testIdenticalInputIsDeterministic(): void
    {
        $history = [
            'flaky_test' => $this->cycles(9, ['merged' => true, 'outcome_met' => true])
                + $this->offsetCycles(9, 1, ['blocked' => true]),
            'doc_drift' => $this->cycles(4, ['blocked' => true, 'repair_exhausted' => true]),
        ];

        $first = $this->scorer->score($history);
        $second = $this->scorer->score($history);

        $this->assertSame($first, $second);
    }

    /**
     * @param  array<string, mixed>  $template
     * @return list<array<string, mixed>>
     */
    private function cycles(int $count, array $template): array
    {
        $cycles = [];
        for ($i = 0; $i < $count; $i++) {
            $cycles[] = $template;
        }

        return $cycles;
    }

    /**
     * @param  array<string, mixed>  $template
     * @return array<int, array<string, mixed>>
     */
    private function offsetCycles(int $offset, int $count, array $template): array
    {
        $cycles = [];
        for ($i = 0; $i < $count; $i++) {
            $cycles[$offset + $i] = $template;
        }

        return $cycles;
    }

    private static function repairThreshold(): float
    {
        return 0.5;
    }
}
