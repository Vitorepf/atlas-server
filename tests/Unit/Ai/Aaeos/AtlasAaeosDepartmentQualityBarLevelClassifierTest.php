<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\Aaeos;

use App\Services\Ai\Aaeos\AtlasAaeosDepartmentQualityBarLevelClassifier;
use PHPUnit\Framework\TestCase;

final class AtlasAaeosDepartmentQualityBarLevelClassifierTest extends TestCase
{
    private AtlasAaeosDepartmentQualityBarLevelClassifier $classifier;

    protected function setUp(): void
    {
        $this->classifier = new AtlasAaeosDepartmentQualityBarLevelClassifier();
    }

    /**
     * Canonical Forge ladder (matrix lines 117-120), lowest-first, supplied by the caller.
     *
     * @return list<array{level: string, thresholds: list<array{metric: string, comparator: string, value: float}>}>
     */
    private function forgeBandLadder(): array
    {
        return [
            [
                'level' => 'L0-L2',
                'thresholds' => [
                    ['metric' => 'obra_completion_rate', 'comparator' => '>=', 'value' => 0.7],
                    ['metric' => 'cert_pass_rate', 'comparator' => '>=', 'value' => 0.85],
                    ['metric' => 'rollback_rate', 'comparator' => '<=', 'value' => 0.10],
                    ['metric' => 'multi_agent_collision_rate', 'comparator' => '<=', 'value' => 0.05],
                ],
            ],
            [
                'level' => 'L3',
                'thresholds' => [
                    ['metric' => 'obra_completion_rate', 'comparator' => '>=', 'value' => 0.85],
                    ['metric' => 'cert_pass_rate', 'comparator' => '>=', 'value' => 0.93],
                    ['metric' => 'rollback_rate', 'comparator' => '<=', 'value' => 0.04],
                    ['metric' => 'multi_agent_collision_rate', 'comparator' => '<=', 'value' => 0.02],
                ],
            ],
            [
                'level' => 'L4',
                'thresholds' => [
                    ['metric' => 'obra_completion_rate', 'comparator' => '>=', 'value' => 0.93],
                    ['metric' => 'cert_pass_rate', 'comparator' => '>=', 'value' => 0.97],
                    ['metric' => 'rollback_rate', 'comparator' => '<=', 'value' => 0.02],
                    ['metric' => 'multi_agent_collision_rate', 'comparator' => '<=', 'value' => 0.01],
                ],
            ],
            [
                'level' => 'L5+',
                'thresholds' => [
                    ['metric' => 'obra_completion_rate', 'comparator' => '>=', 'value' => 0.97],
                    ['metric' => 'cert_pass_rate', 'comparator' => '>=', 'value' => 0.99],
                    ['metric' => 'rollback_rate', 'comparator' => '<=', 'value' => 0.005],
                    ['metric' => 'multi_agent_collision_rate', 'comparator' => '<=', 'value' => 0.0],
                ],
            ],
        ];
    }

    public function testSchemaVersionIsCanonical(): void
    {
        $result = $this->classifier->classify('forge', [], $this->forgeBandLadder());

        $this->assertSame('atlas.aaeos.quality_bar_level.v1', $result['schema_version']);
    }

    public function testForgeMeasuredSnapshotIsBlockedByTheSingleBindingObraBreach(): void
    {
        // Canonical Forge ladder with the row's measured snapshot. obra=0.91 clears L3 in
        // full but fails the first metric of the band above it, so the climb stops there
        // and exactly one binding breach (obra) blocks promotion.
        $result = $this->classifier->classify(
            'forge',
            [
                'obra_completion_rate' => 0.91,
                'cert_pass_rate' => 0.98,
                'rollback_rate' => 0.015,
                'multi_agent_collision_rate' => 0.008,
            ],
            $this->forgeBandLadder(),
        );

        // Highest band whose every threshold (and every band below it) is satisfied.
        $this->assertSame('L3', $result['achieved_level']);
        $this->assertSame(1, $result['achieved_band_index']);
        $this->assertSame('L4', $result['next_level']);
        $this->assertSame('L5+', $result['highest_evaluable_level']);
        $this->assertFalse($result['all_bands_satisfied']);
        $this->assertTrue($result['promotion_blocked']);

        // Exactly one binding breach: obra against the first unmet band's threshold.
        $this->assertCount(1, $result['binding_breaches']);

        $breach = $result['binding_breaches'][0];
        $this->assertSame('L4', $breach['level']);
        $this->assertSame('obra_completion_rate', $breach['metric']);
        $this->assertSame('>=', $breach['comparator']);
        $this->assertSame(0.93, $breach['threshold']);
        $this->assertSame(0.91, $breach['observed']);
        $this->assertFalse($breach['satisfied']);
        $this->assertFalse($breach['missing_metric']);

        $this->assertSame(4, $result['evaluated_bands']);
        $this->assertSame(4, $result['evaluated_metrics']);
    }

    public function testSnapshotSatisfyingEveryBandReachesTheTopWithNoBreaches(): void
    {
        $result = $this->classifier->classify(
            'forge',
            [
                'obra_completion_rate' => 0.99,
                'cert_pass_rate' => 0.995,
                'rollback_rate' => 0.001,
                'multi_agent_collision_rate' => 0.0,
            ],
            $this->forgeBandLadder(),
        );

        $this->assertTrue($result['all_bands_satisfied']);
        $this->assertSame('L5+', $result['achieved_level']);
        $this->assertSame(3, $result['achieved_band_index']);
        $this->assertSame([], $result['binding_breaches']);
        $this->assertNull($result['next_level']);
        $this->assertFalse($result['promotion_blocked']);
    }

    public function testOmittingARequiredMetricFailsThatBandWithAMissingMetricBreach(): void
    {
        // cert_pass_rate is intentionally absent; the lowest band that requires it fails.
        $result = $this->classifier->classify(
            'forge',
            [
                'obra_completion_rate' => 0.99,
                'rollback_rate' => 0.001,
                'multi_agent_collision_rate' => 0.0,
            ],
            $this->forgeBandLadder(),
        );

        $this->assertSame('L0-L2', $result['next_level']);
        $this->assertTrue($result['promotion_blocked']);
        $this->assertCount(1, $result['binding_breaches']);

        $breach = $result['binding_breaches'][0];
        $this->assertSame('L0-L2', $breach['level']);
        $this->assertSame('cert_pass_rate', $breach['metric']);
        $this->assertTrue($breach['missing_metric']);
        $this->assertNull($breach['observed']);
        $this->assertFalse($breach['satisfied']);

        // An absent metric is never silently passed: it does not count toward the climb.
        $this->assertNull($result['achieved_level']);
        $this->assertSame(-1, $result['achieved_band_index']);
    }

    public function testComparatorSatisfiedHonoursEpsilonBoundaries(): void
    {
        $this->assertTrue($this->classifier->comparatorSatisfied('<=', 0.10, 0.10));
        $this->assertFalse($this->classifier->comparatorSatisfied('>=', 0.969, 0.97));
    }

    public function testComparatorSatisfiedSupportsEveryDeclaredOperator(): void
    {
        // Exact-boundary tolerance for the three epsilon-aware comparators.
        $this->assertTrue($this->classifier->comparatorSatisfied('>=', 0.93, 0.93));
        $this->assertTrue($this->classifier->comparatorSatisfied('<=', 0.02, 0.02));
        $this->assertTrue($this->classifier->comparatorSatisfied('==', 0.5, 0.5));

        // Strict comparators reject equality.
        $this->assertFalse($this->classifier->comparatorSatisfied('>', 0.5, 0.5));
        $this->assertTrue($this->classifier->comparatorSatisfied('>', 0.51, 0.5));
        $this->assertFalse($this->classifier->comparatorSatisfied('<', 0.5, 0.5));
        $this->assertTrue($this->classifier->comparatorSatisfied('<', 0.49, 0.5));

        // Unknown comparators are unsatisfied.
        $this->assertFalse($this->classifier->comparatorSatisfied('!=', 0.1, 0.2));
    }

    public function testFailingTheLowestBandEarnsNoLevelAndKeepsTheLadderAsNextTarget(): void
    {
        $result = $this->classifier->classify(
            'forge',
            [
                'obra_completion_rate' => 0.40,
                'cert_pass_rate' => 0.50,
                'rollback_rate' => 0.30,
                'multi_agent_collision_rate' => 0.20,
            ],
            $this->forgeBandLadder(),
        );

        $this->assertNull($result['achieved_level']);
        $this->assertSame(-1, $result['achieved_band_index']);
        $this->assertSame('L0-L2', $result['next_level']);
        $this->assertFalse($result['all_bands_satisfied']);
        $this->assertTrue($result['promotion_blocked']);

        // Every failing threshold of the lowest band is reported.
        $this->assertCount(4, $result['binding_breaches']);
        foreach ($result['binding_breaches'] as $breach) {
            $this->assertSame('L0-L2', $breach['level']);
            $this->assertFalse($breach['satisfied']);
            $this->assertFalse($breach['missing_metric']);
        }
    }

    public function testAchievedLevelGeneralisesToAMidLadderWithADifferentLadderAndMetrics(): void
    {
        // A fresh three-rung ladder unrelated to the test fixtures above proves the rule
        // generalises rather than returning values canned to the Forge snapshot.
        $ladder = [
            [
                'level' => 'bronze',
                'thresholds' => [
                    ['metric' => 'uptime', 'comparator' => '>=', 'value' => 0.90],
                    ['metric' => 'error_rate', 'comparator' => '<', 'value' => 0.10],
                ],
            ],
            [
                'level' => 'silver',
                'thresholds' => [
                    ['metric' => 'uptime', 'comparator' => '>=', 'value' => 0.95],
                    ['metric' => 'error_rate', 'comparator' => '<', 'value' => 0.05],
                ],
            ],
            [
                'level' => 'gold',
                'thresholds' => [
                    ['metric' => 'uptime', 'comparator' => '>=', 'value' => 0.99],
                    ['metric' => 'error_rate', 'comparator' => '<', 'value' => 0.01],
                ],
            ],
        ];

        // uptime clears bronze (0.90) and silver (0.95) but not gold (0.99);
        // error_rate clears every rung, so silver is the binding wall.
        $result = $this->classifier->classify(
            'platform',
            ['uptime' => 0.96, 'error_rate' => 0.004],
            $ladder,
        );

        $this->assertSame('silver', $result['achieved_level']);
        $this->assertSame(1, $result['achieved_band_index']);
        $this->assertSame('gold', $result['next_level']);
        $this->assertSame('gold', $result['highest_evaluable_level']);
        $this->assertCount(1, $result['binding_breaches']);

        $breach = $result['binding_breaches'][0];
        $this->assertSame('gold', $breach['level']);
        $this->assertSame('uptime', $breach['metric']);
        $this->assertSame('>=', $breach['comparator']);
        $this->assertSame(0.99, $breach['threshold']);
        $this->assertSame(0.96, $breach['observed']);

        $this->assertSame(3, $result['evaluated_bands']);
        $this->assertSame(2, $result['evaluated_metrics']);
    }

    public function testEmptyLadderYieldsNoLevelAndNoBreaches(): void
    {
        $result = $this->classifier->classify('forge', ['obra_completion_rate' => 0.99], []);

        $this->assertSame('atlas.aaeos.quality_bar_level.v1', $result['schema_version']);
        $this->assertNull($result['achieved_level']);
        $this->assertSame(-1, $result['achieved_band_index']);
        $this->assertNull($result['highest_evaluable_level']);
        $this->assertFalse($result['all_bands_satisfied']);
        $this->assertNull($result['next_level']);
        $this->assertFalse($result['promotion_blocked']);
        $this->assertSame([], $result['binding_breaches']);
        $this->assertSame(0, $result['evaluated_bands']);
        $this->assertSame(1, $result['evaluated_metrics']);
    }

    public function testDepartmentIdIsEchoedFromInput(): void
    {
        $result = $this->classifier->classify('marketing-department-7', [], $this->forgeBandLadder());

        $this->assertSame('marketing-department-7', $result['department_id']);
    }

    public function testEvaluatedMetricsCountsOnlyNumericMeasurements(): void
    {
        $result = $this->classifier->classify(
            'forge',
            [
                'obra_completion_rate' => 0.91,
                'cert_pass_rate' => 1,
                'note' => 'not-a-number',
            ],
            $this->forgeBandLadder(),
        );

        $this->assertSame(2, $result['evaluated_metrics']);
    }

    public function testClassificationIsDeterministic(): void
    {
        $measured = [
            'obra_completion_rate' => 0.91,
            'cert_pass_rate' => 0.98,
            'rollback_rate' => 0.015,
            'multi_agent_collision_rate' => 0.008,
        ];
        $ladder = $this->forgeBandLadder();

        $first = $this->classifier->classify('forge', $measured, $ladder);
        $second = $this->classifier->classify('forge', $measured, $ladder);

        $this->assertSame($first, $second);
    }
}
