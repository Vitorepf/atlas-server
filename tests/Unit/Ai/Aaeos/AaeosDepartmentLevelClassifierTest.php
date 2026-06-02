<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\Aaeos;

use App\Services\Ai\Aaeos\AaeosDepartmentLevelClassifier;
use PHPUnit\Framework\TestCase;

final class AaeosDepartmentLevelClassifierTest extends TestCase
{
    private AaeosDepartmentLevelClassifier $classifier;

    protected function setUp(): void
    {
        $this->classifier = new AaeosDepartmentLevelClassifier();
    }

    /**
     * Canonical Forge band table (bottom-up ordered), supplied by the caller.
     *
     * @return list<array{level: string, thresholds: list<array{metric: string, comparator: string, value: float}>}>
     */
    private function forgeBandLadder(): array
    {
        return [
            [
                'level' => 'L0-L2',
                'thresholds' => [
                    ['metric' => 'obra_completion_rate', 'comparator' => '>=', 'value' => 0.70],
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

    /**
     * Canonical Dev band table (bottom-up ordered), supplied by the caller.
     *
     * @return list<array{level: string, thresholds: list<array{metric: string, comparator: string, value: float}>}>
     */
    private function devBandLadder(): array
    {
        return [
            [
                'level' => 'L0-L1',
                'thresholds' => [
                    ['metric' => 'latency_p95', 'comparator' => '<=', 'value' => 120.0],
                    ['metric' => 'tests_pass_rate', 'comparator' => '>=', 'value' => 0.85],
                    ['metric' => 'scope_violation_rate', 'comparator' => '<=', 'value' => 0.05],
                    ['metric' => 'repair_loop_avg', 'comparator' => '<=', 'value' => 2.0],
                ],
            ],
            [
                'level' => 'L2',
                'thresholds' => [
                    ['metric' => 'latency_p95', 'comparator' => '<=', 'value' => 90.0],
                    ['metric' => 'tests_pass_rate', 'comparator' => '>=', 'value' => 0.92],
                    ['metric' => 'scope_violation_rate', 'comparator' => '<=', 'value' => 0.02],
                    ['metric' => 'repair_loop_avg', 'comparator' => '<=', 'value' => 1.5],
                ],
            ],
            [
                'level' => 'L3',
                'thresholds' => [
                    ['metric' => 'latency_p95', 'comparator' => '<=', 'value' => 60.0],
                    ['metric' => 'tests_pass_rate', 'comparator' => '>=', 'value' => 0.96],
                    ['metric' => 'scope_violation_rate', 'comparator' => '<=', 'value' => 0.01],
                    ['metric' => 'repair_loop_avg', 'comparator' => '<=', 'value' => 1.0],
                ],
            ],
            [
                'level' => 'L4+',
                'thresholds' => [
                    ['metric' => 'latency_p95', 'comparator' => '<=', 'value' => 45.0],
                    ['metric' => 'tests_pass_rate', 'comparator' => '>=', 'value' => 0.98],
                    ['metric' => 'scope_violation_rate', 'comparator' => '<=', 'value' => 0.0],
                    ['metric' => 'repair_loop_avg', 'comparator' => '<=', 'value' => 0.5],
                ],
            ],
        ];
    }

    public function testSchemaVersionIsCanonical(): void
    {
        $result = $this->classifier->classify('forge', [], $this->forgeBandLadder());

        $this->assertSame('atlas.aaeos.department_level_classification.v1', $result['schema_version']);
    }

    public function testForgeSnapshotEarnsL3AndCapsAtL4ObraCompletionRate(): void
    {
        $result = $this->classifier->classify(
            'forge',
            [
                'obra_completion_rate' => 0.91,
                'cert_pass_rate' => 0.95,
                'rollback_rate' => 0.03,
                'multi_agent_collision_rate' => 0.015,
            ],
            $this->forgeBandLadder(),
        );

        $this->assertSame('L3', $result['earned_level']);
        $this->assertSame(1, $result['earned_level_index']);
        $this->assertFalse($result['all_bands_satisfied']);

        $this->assertSame('L4', $result['capping_metric']['level']);
        $this->assertSame('obra_completion_rate', $result['capping_metric']['metric']);
        $this->assertSame('>=', $result['capping_metric']['comparator']);
        $this->assertSame(0.93, $result['capping_metric']['threshold']);
        $this->assertSame(0.91, $result['capping_metric']['observed']);

        $this->assertSame('L5+', $result['highest_band_offered']);
        $this->assertSame([], $result['missing_metrics']);
    }

    public function testDevSnapshotSatisfyingEveryTopBandRowEarnsL4Plus(): void
    {
        $result = $this->classifier->classify(
            'dev',
            [
                'latency_p95' => 40.0,
                'tests_pass_rate' => 0.99,
                'scope_violation_rate' => 0.0,
                'repair_loop_avg' => 0.4,
            ],
            $this->devBandLadder(),
        );

        $this->assertSame('L4+', $result['earned_level']);
        $this->assertSame(3, $result['earned_level_index']);
        $this->assertTrue($result['all_bands_satisfied']);
        $this->assertNull($result['capping_metric']);
        $this->assertSame('L4+', $result['highest_band_offered']);
    }

    public function testSnapshotFailingLowestBandEarnsNoLevel(): void
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

        $this->assertNull($result['earned_level']);
        $this->assertSame(-1, $result['earned_level_index']);
        $this->assertFalse($result['all_bands_satisfied']);
        $this->assertSame('L0-L2', $result['capping_metric']['level']);
        $this->assertSame('obra_completion_rate', $result['capping_metric']['metric']);
        $this->assertSame(0.40, $result['capping_metric']['observed']);
    }

    public function testMissingRequiredMetricIsListedAndTreatedAsFailedWithNullObserved(): void
    {
        $result = $this->classifier->classify(
            'forge',
            [
                // cert_pass_rate intentionally absent from the snapshot.
                'obra_completion_rate' => 0.91,
                'rollback_rate' => 0.03,
                'multi_agent_collision_rate' => 0.015,
            ],
            $this->forgeBandLadder(),
        );

        $this->assertContains('cert_pass_rate', $result['missing_metrics']);

        $lowestBand = $result['evaluated_bands'][0];
        $this->assertSame('L0-L2', $lowestBand['level']);
        $this->assertFalse($lowestBand['satisfied']);

        $failedMetrics = array_column($lowestBand['failed_thresholds'], 'metric');
        $this->assertContains('cert_pass_rate', $failedMetrics);

        $certThreshold = null;
        foreach ($lowestBand['failed_thresholds'] as $failed) {
            if ($failed['metric'] === 'cert_pass_rate') {
                $certThreshold = $failed;
                break;
            }
        }

        $this->assertNotNull($certThreshold);
        $this->assertNull($certThreshold['observed']);
        $this->assertSame('>=', $certThreshold['comparator']);
        $this->assertSame(0.85, $certThreshold['threshold']);
    }

    public function testEmptyLadderYieldsNoLevelAndNullCapping(): void
    {
        $result = $this->classifier->classify('forge', ['obra_completion_rate' => 0.99], []);

        $this->assertSame('atlas.aaeos.department_level_classification.v1', $result['schema_version']);
        $this->assertNull($result['earned_level']);
        $this->assertSame(-1, $result['earned_level_index']);
        $this->assertFalse($result['all_bands_satisfied']);
        $this->assertNull($result['capping_metric']);
        $this->assertSame('', $result['highest_band_offered']);
        $this->assertSame([], $result['evaluated_bands']);
        $this->assertSame([], $result['missing_metrics']);
    }

    public function testMalformedLadderTreatedAsEmpty(): void
    {
        $result = $this->classifier->classify(
            'forge',
            ['obra_completion_rate' => 0.99],
            [
                ['level' => 'L0', 'thresholds' => 'not-a-list'],
            ],
        );

        $this->assertNull($result['earned_level']);
        $this->assertSame(-1, $result['earned_level_index']);
        $this->assertFalse($result['all_bands_satisfied']);
        $this->assertNull($result['capping_metric']);
    }

    public function testDepartmentIdIsEchoedFromInput(): void
    {
        $result = $this->classifier->classify('security-department-42', [], $this->forgeBandLadder());

        $this->assertSame('security-department-42', $result['department_id']);
    }

    public function testMissingMetricsAreSortedAndUnique(): void
    {
        // Two bands both reference the same absent metrics; snapshot omits beta and alpha.
        $ladder = [
            [
                'level' => 'B0',
                'thresholds' => [
                    ['metric' => 'beta', 'comparator' => '>=', 'value' => 0.5],
                    ['metric' => 'alpha', 'comparator' => '>=', 'value' => 0.5],
                ],
            ],
            [
                'level' => 'B1',
                'thresholds' => [
                    ['metric' => 'beta', 'comparator' => '>=', 'value' => 0.9],
                ],
            ],
        ];

        $result = $this->classifier->classify('x', [], $ladder);

        $this->assertSame(['alpha', 'beta'], $result['missing_metrics']);
    }

    public function testEpsilonBoundaryCountsExactThresholdAsSatisfied(): void
    {
        // observed exactly equal to a >= threshold and to a <= threshold must satisfy.
        $ladder = [
            [
                'level' => 'EXACT',
                'thresholds' => [
                    ['metric' => 'pass_rate', 'comparator' => '>=', 'value' => 0.93],
                    ['metric' => 'error_rate', 'comparator' => '<=', 'value' => 0.02],
                ],
            ],
        ];

        $result = $this->classifier->classify(
            'dev',
            ['pass_rate' => 0.93, 'error_rate' => 0.02],
            $ladder,
        );

        $this->assertSame('EXACT', $result['earned_level']);
        $this->assertSame(0, $result['earned_level_index']);
        $this->assertTrue($result['all_bands_satisfied']);
        $this->assertNull($result['capping_metric']);
    }

    public function testResultKeysAreSortedAlphabetically(): void
    {
        $result = $this->classifier->classify('forge', [], $this->forgeBandLadder());

        $keys = array_keys($result);
        $sorted = $keys;
        sort($sorted);

        $this->assertSame($sorted, $keys);
    }

    public function testClassificationIsDeterministic(): void
    {
        $snapshot = [
            'obra_completion_rate' => 0.91,
            'cert_pass_rate' => 0.95,
            'rollback_rate' => 0.03,
            'multi_agent_collision_rate' => 0.015,
        ];
        $ladder = $this->forgeBandLadder();

        $first = $this->classifier->classify('forge', $snapshot, $ladder);
        $second = $this->classifier->classify('forge', $snapshot, $ladder);

        $this->assertSame($first, $second);
    }
}
