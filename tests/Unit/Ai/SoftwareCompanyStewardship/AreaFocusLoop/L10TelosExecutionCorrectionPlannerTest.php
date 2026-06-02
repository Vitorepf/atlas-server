<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\SoftwareCompanyStewardship\AreaFocusLoop;

use App\Services\Ai\SoftwareCompanyStewardship\AreaFocusLoop\L10TelosExecutionCorrectionPlanner;
use PHPUnit\Framework\TestCase;

final class L10TelosExecutionCorrectionPlannerTest extends TestCase
{
    private L10TelosExecutionCorrectionPlanner $planner;

    protected function setUp(): void
    {
        $this->planner = new L10TelosExecutionCorrectionPlanner();
    }

    /**
     * @return array{0: array<string, mixed>, 1: array<string, mixed>}
     */
    private function curatedTelosWithMixedOutcomes(): array
    {
        $telos = [
            'curated_telos_id' => 'telos_engineering_2030',
            'operator_curated' => true,
            'final_ends' => ['operator_sovereign_engineering', 'local_first'],
            'target_outcomes' => [
                ['target_id' => 'merge_throughput', 'goal' => 0.90],
                ['target_id' => 'defect_escape_rate', 'goal' => 0.95],
                ['target_id' => 'cycle_latency', 'goal' => 0.80],
                ['target_id' => 'review_depth', 'goal' => 0.70],
            ],
        ];

        $outcomes = [
            ['target_id' => 'merge_throughput', 'measured' => 0.60],
            ['target_id' => 'defect_escape_rate', 'measured' => 0.40],
            ['target_id' => 'cycle_latency', 'measured' => 0.85],
            // review_depth has no measurement -> counts as drifted.
        ];

        return [$telos, $outcomes];
    }

    public function testHealthyTelosReturnsAllFourFieldsWithComputedDriftAndPackets(): void
    {
        [$telos, $outcomes] = $this->curatedTelosWithMixedOutcomes();

        $result = $this->planner->plan($telos, $outcomes);

        $this->assertArrayHasKey('correction_packets', $result);
        $this->assertArrayHasKey('drift_from_telos', $result);
        $this->assertArrayHasKey('measured_or_reverted_required', $result);
        $this->assertArrayHasKey('blockers', $result);

        $this->assertSame('atlas.aaeos.l10.telos_execution_correction_plan.v1', $result['schema_version']);
        $this->assertSame([], $result['blockers']);

        // 3 of 4 targets are below goal (merge_throughput, defect_escape_rate, review_depth-missing).
        $this->assertEqualsWithDelta(0.75, $result['drift_from_telos'], 0.0001);

        // One packet per drifting target, in declared order.
        $this->assertCount(3, $result['correction_packets']);
        $this->assertSame(
            ['merge_throughput', 'defect_escape_rate', 'review_depth'],
            array_column($result['correction_packets'], 'target_id')
        );
    }

    public function testCorrectionPacketCarriesComputedGapAndMeasureOrRevertContract(): void
    {
        [$telos, $outcomes] = $this->curatedTelosWithMixedOutcomes();

        $result = $this->planner->plan($telos, $outcomes);

        $first = $result['correction_packets'][0];
        $this->assertSame('merge_throughput', $first['target_id']);
        $this->assertEqualsWithDelta(0.90, $first['goal'], 0.0001);
        $this->assertEqualsWithDelta(0.60, $first['measured'], 0.0001);
        $this->assertEqualsWithDelta(0.30, $first['gap'], 0.0001);
        $this->assertSame('correct_path_toward_goal', $first['action']);

        // A correction never changes ends and always carries the measure-or-revert duty.
        $this->assertFalse($first['changes_final_ends']);
        $this->assertTrue($first['measured_or_reverted_required']);
    }

    public function testMissingMeasurementTargetIsTreatedAsFullyDriftedWithZeroMeasured(): void
    {
        [$telos, $outcomes] = $this->curatedTelosWithMixedOutcomes();

        $result = $this->planner->plan($telos, $outcomes);

        $missing = null;
        foreach ($result['correction_packets'] as $packet) {
            if ($packet['target_id'] === 'review_depth') {
                $missing = $packet;
            }
        }

        $this->assertNotNull($missing);
        $this->assertEqualsWithDelta(0.70, $missing['goal'], 0.0001);
        $this->assertEqualsWithDelta(0.0, $missing['measured'], 0.0001);
        $this->assertEqualsWithDelta(0.70, $missing['gap'], 0.0001);
    }

    public function testOnGoalTargetsProduceNoPacketsAndZeroDrift(): void
    {
        $telos = [
            'curated_telos_id' => 'telos_on_track',
            'operator_curated' => true,
            'final_ends' => ['operator_sovereign_engineering'],
            'target_outcomes' => [
                ['target_id' => 'merge_throughput', 'goal' => 0.50],
                ['target_id' => 'cycle_latency', 'goal' => 0.50],
            ],
        ];

        $outcomes = [
            ['target_id' => 'merge_throughput', 'measured' => 0.50], // exactly on goal -> not below.
            ['target_id' => 'cycle_latency', 'measured' => 0.92],    // above goal -> not below.
        ];

        $result = $this->planner->plan($telos, $outcomes);

        $this->assertSame([], $result['blockers']);
        $this->assertSame([], $result['correction_packets']);
        $this->assertEqualsWithDelta(0.0, $result['drift_from_telos'], 0.0001);
        $this->assertTrue($result['measured_or_reverted_required']);
    }

    public function testMissingCuratedTelosBlocksAndProducesNoPackets(): void
    {
        // No curated_telos_id at all.
        $result = $this->planner->plan(
            [
                'target_outcomes' => [
                    ['target_id' => 'merge_throughput', 'goal' => 0.90],
                ],
            ],
            [
                ['target_id' => 'merge_throughput', 'measured' => 0.10],
            ]
        );

        $this->assertContains('curated_telos_missing', $result['blockers']);
        $this->assertSame([], $result['correction_packets']);
    }

    public function testCuratedTelosIdPresentButNotCuratedStillBlocks(): void
    {
        // Has an id but the operator curation flag is absent/false.
        $result = $this->planner->plan(
            [
                'curated_telos_id' => 'telos_draft',
                'operator_curated' => false,
                'target_outcomes' => [
                    ['target_id' => 'merge_throughput', 'goal' => 0.90],
                ],
            ],
            [
                ['target_id' => 'merge_throughput', 'measured' => 0.10],
            ]
        );

        $this->assertContains('curated_telos_missing', $result['blockers']);
        $this->assertSame([], $result['correction_packets']);
    }

    public function testCorrectionThatChangesFinalEndsBlocks(): void
    {
        $telos = [
            'curated_telos_id' => 'telos_engineering_2030',
            'operator_curated' => true,
            'final_ends' => ['operator_sovereign_engineering', 'local_first'],
            'target_outcomes' => [
                ['target_id' => 'merge_throughput', 'goal' => 0.90],
            ],
        ];

        // An outcome proposes a DIFFERENT final-ends set -> tampering with values.
        $outcomes = [
            [
                'target_id' => 'merge_throughput',
                'measured' => 0.10,
                'proposed_final_ends' => ['operator_sovereign_engineering', 'maximize_growth'],
            ],
        ];

        $result = $this->planner->plan($telos, $outcomes);

        $this->assertContains('correction_changes_final_ends', $result['blockers']);
        $this->assertSame([], $result['correction_packets']);
    }

    public function testRestatingTheSameFinalEndsIsNotAValueChange(): void
    {
        $telos = [
            'curated_telos_id' => 'telos_engineering_2030',
            'operator_curated' => true,
            'final_ends' => ['operator_sovereign_engineering', 'local_first'],
            'target_outcomes' => [
                ['target_id' => 'merge_throughput', 'goal' => 0.90],
            ],
        ];

        // Same ends, different order -> NOT a change; path correction still allowed.
        $outcomes = [
            [
                'target_id' => 'merge_throughput',
                'measured' => 0.10,
                'proposed_final_ends' => ['local_first', 'operator_sovereign_engineering'],
            ],
        ];

        $result = $this->planner->plan($telos, $outcomes);

        $this->assertNotContains('correction_changes_final_ends', $result['blockers']);
        $this->assertSame([], $result['blockers']);
        $this->assertCount(1, $result['correction_packets']);
    }

    public function testExplicitEndsMutationFlagBlocks(): void
    {
        $telos = [
            'curated_telos_id' => 'telos_engineering_2030',
            'operator_curated' => true,
            'final_ends' => ['operator_sovereign_engineering'],
            'target_outcomes' => [
                ['target_id' => 'merge_throughput', 'goal' => 0.90],
            ],
        ];

        $outcomes = [
            ['target_id' => 'merge_throughput', 'measured' => 0.10, 'changes_final_ends' => true],
        ];

        $result = $this->planner->plan($telos, $outcomes);

        $this->assertContains('correction_changes_final_ends', $result['blockers']);
        $this->assertSame([], $result['correction_packets']);
    }

    public function testExecutionSideEffectRequestBlocks(): void
    {
        $telos = [
            'curated_telos_id' => 'telos_engineering_2030',
            'operator_curated' => true,
            'final_ends' => ['operator_sovereign_engineering'],
            'target_outcomes' => [
                ['target_id' => 'merge_throughput', 'goal' => 0.90],
            ],
        ];

        // Asking the planner to EXECUTE the correction is a forbidden side effect.
        $outcomes = [
            'execute_corrections' => true,
            'records' => [
                ['target_id' => 'merge_throughput', 'measured' => 0.10],
            ],
        ];

        $result = $this->planner->plan($telos, $outcomes);

        $this->assertContains('execution_side_effect_requested', $result['blockers']);
        $this->assertSame([], $result['correction_packets']);
    }

    public function testPerRecordExecuteModeBlocks(): void
    {
        $telos = [
            'curated_telos_id' => 'telos_engineering_2030',
            'operator_curated' => true,
            'final_ends' => ['operator_sovereign_engineering'],
            'target_outcomes' => [
                ['target_id' => 'merge_throughput', 'goal' => 0.90],
            ],
        ];

        $outcomes = [
            ['target_id' => 'merge_throughput', 'measured' => 0.10, 'mode' => 'execute'],
        ];

        $result = $this->planner->plan($telos, $outcomes);

        $this->assertContains('execution_side_effect_requested', $result['blockers']);
        $this->assertSame([], $result['correction_packets']);
    }

    public function testDriftIsAlwaysWithinUnitIntervalForFullyDriftedTelos(): void
    {
        // Every target below goal, novel ids the test class never reuses for packets.
        $telos = [
            'curated_telos_id' => 'telos_stress',
            'operator_curated' => true,
            'final_ends' => ['operator_sovereign_engineering'],
            'target_outcomes' => [
                ['target_id' => 'alpha', 'goal' => 1.0],
                ['target_id' => 'beta', 'goal' => 1.0],
                ['target_id' => 'gamma', 'goal' => 1.0],
            ],
        ];

        $outcomes = [
            ['target_id' => 'alpha', 'measured' => 0.0],
            ['target_id' => 'beta', 'measured' => 0.10],
            ['target_id' => 'gamma', 'measured' => 0.99],
        ];

        $result = $this->planner->plan($telos, $outcomes);

        $this->assertEqualsWithDelta(1.0, $result['drift_from_telos'], 0.0001);
        $this->assertLessThanOrEqual(1.0, $result['drift_from_telos']);
        $this->assertGreaterThanOrEqual(0.0, $result['drift_from_telos']);
        $this->assertCount(3, $result['correction_packets']);
    }

    public function testNoTargetsYieldZeroDriftNoPacketsNoBlockers(): void
    {
        $telos = [
            'curated_telos_id' => 'telos_empty',
            'operator_curated' => true,
            'final_ends' => ['operator_sovereign_engineering'],
            'target_outcomes' => [],
        ];

        $result = $this->planner->plan($telos, []);

        $this->assertSame([], $result['blockers']);
        $this->assertEqualsWithDelta(0.0, $result['drift_from_telos'], 0.0001);
        $this->assertSame([], $result['correction_packets']);
    }

    public function testCorrectionPacketsAreListOfStringTargetIdsNotIntCoerced(): void
    {
        // target_outcomes keyed map form: keys must surface as string target ids.
        $telos = [
            'curated_telos_id' => 'telos_map_form',
            'operator_curated' => true,
            'final_ends' => ['operator_sovereign_engineering'],
            'target_outcomes' => [
                'merge_throughput' => 0.90,
                'cycle_latency' => 0.80,
            ],
        ];

        $outcomes = [
            ['target_id' => 'merge_throughput', 'measured' => 0.20],
            ['target_id' => 'cycle_latency', 'measured' => 0.20],
        ];

        $result = $this->planner->plan($telos, $outcomes);

        // Keys are list-indexed 0..n (real list), and every target_id is a string.
        $packets = $result['correction_packets'];
        $this->assertSame([0, 1], array_keys($packets));
        foreach ($packets as $packet) {
            $this->assertIsString($packet['target_id']);
        }
        $this->assertSame(['merge_throughput', 'cycle_latency'], array_column($packets, 'target_id'));
    }

    public function testBlockersIsListOfStringsAndMeasureOrRevertHoldsEvenWhenBlocked(): void
    {
        // Two blockers at once: missing curation AND ends tampering.
        $result = $this->planner->plan(
            [
                'final_ends' => ['operator_sovereign_engineering'],
                'target_outcomes' => [
                    ['target_id' => 'merge_throughput', 'goal' => 0.90],
                ],
            ],
            [
                ['target_id' => 'merge_throughput', 'measured' => 0.10, 'changes_final_ends' => true],
            ]
        );

        $blockers = $result['blockers'];
        $this->assertSame(array_values($blockers), $blockers);
        $this->assertContains('curated_telos_missing', $blockers);
        $this->assertContains('correction_changes_final_ends', $blockers);
        foreach ($blockers as $blocker) {
            $this->assertIsString($blocker);
        }

        // The measure-or-revert duty is an invariant, true even on the blocked path.
        $this->assertTrue($result['measured_or_reverted_required']);
        $this->assertSame([], $result['correction_packets']);
    }
}
