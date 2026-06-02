<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\Aaeos;

use App\Services\Ai\Aaeos\AtlasAaeosDepartmentMaturityBandClassifier;
use PHPUnit\Framework\TestCase;

final class AtlasAaeosDepartmentMaturityBandClassifierTest extends TestCase
{
    private AtlasAaeosDepartmentMaturityBandClassifier $classifier;

    protected function setUp(): void
    {
        $this->classifier = new AtlasAaeosDepartmentMaturityBandClassifier();
    }

    /**
     * Canonical Forge maturity ladder from atlas-aaeos-department-quality-bar-matrix.md.
     *
     * @return list<array{band: string, rank: int, thresholds: list<array{metric: string, comparator: string, value: float}>}>
     */
    private function canonicalForgeLadder(): array
    {
        return [
            [
                'band' => 'L0-L2',
                'rank' => 0,
                'thresholds' => [
                    ['metric' => 'obra_completion_rate', 'comparator' => '>=', 'value' => 0.7],
                    ['metric' => 'cert_pass_rate', 'comparator' => '>=', 'value' => 0.85],
                    ['metric' => 'rollback_rate', 'comparator' => '<=', 'value' => 0.10],
                    ['metric' => 'multi_agent_collision_rate', 'comparator' => '<=', 'value' => 0.05],
                ],
            ],
            [
                'band' => 'L3',
                'rank' => 3,
                'thresholds' => [
                    ['metric' => 'obra_completion_rate', 'comparator' => '>=', 'value' => 0.85],
                    ['metric' => 'cert_pass_rate', 'comparator' => '>=', 'value' => 0.93],
                    ['metric' => 'rollback_rate', 'comparator' => '<=', 'value' => 0.04],
                    ['metric' => 'multi_agent_collision_rate', 'comparator' => '<=', 'value' => 0.02],
                ],
            ],
            [
                'band' => 'L4',
                'rank' => 4,
                'thresholds' => [
                    ['metric' => 'obra_completion_rate', 'comparator' => '>=', 'value' => 0.93],
                    ['metric' => 'cert_pass_rate', 'comparator' => '>=', 'value' => 0.97],
                    ['metric' => 'rollback_rate', 'comparator' => '<=', 'value' => 0.02],
                    ['metric' => 'multi_agent_collision_rate', 'comparator' => '<=', 'value' => 0.01],
                ],
            ],
            [
                'band' => 'L5+',
                'rank' => 5,
                'thresholds' => [
                    ['metric' => 'obra_completion_rate', 'comparator' => '>=', 'value' => 0.97],
                    ['metric' => 'cert_pass_rate', 'comparator' => '>=', 'value' => 0.99],
                    ['metric' => 'rollback_rate', 'comparator' => '<=', 'value' => 0.005],
                    ['metric' => 'multi_agent_collision_rate', 'comparator' => '<=', 'value' => 0.0],
                ],
            ],
        ];
    }

    public function testSchemaVersionIsStable(): void
    {
        $result = $this->classifier->classify($this->canonicalForgeLadder(), [
            'obra_completion_rate' => 0.91,
            'cert_pass_rate' => 0.98,
            'rollback_rate' => 0.015,
            'multi_agent_collision_rate' => 0.005,
        ]);

        $this->assertSame('atlas.aaeos.department_maturity_band.v1', $result['schema_version']);
    }

    public function testCanonicalForgeSnapshotQualifiesL3AndBlocksAtL4(): void
    {
        $result = $this->classifier->classify($this->canonicalForgeLadder(), [
            'obra_completion_rate' => 0.91,
            'cert_pass_rate' => 0.98,
            'rollback_rate' => 0.015,
            'multi_agent_collision_rate' => 0.005,
        ]);

        $this->assertSame('L3', $result['qualified_band']);
        $this->assertSame(3, $result['qualified_rank']);
        $this->assertFalse($result['all_bands_breached']);
        $this->assertSame(['band' => 'L4', 'rank' => 4], $result['next_band']);
        $this->assertTrue($result['promotion_blocked']);
    }

    public function testCanonicalForgeNextBandBreachesAreExactlyObraCompletion(): void
    {
        $result = $this->classifier->classify($this->canonicalForgeLadder(), [
            'obra_completion_rate' => 0.91,
            'cert_pass_rate' => 0.98,
            'rollback_rate' => 0.015,
            'multi_agent_collision_rate' => 0.005,
        ]);

        $this->assertSame([
            [
                'metric' => 'obra_completion_rate',
                'comparator' => '>=',
                'threshold' => 0.93,
                'observed' => 0.91,
                'missing' => false,
            ],
        ], $result['next_band_breaches']);

        $this->assertCount(1, $result['next_band_breaches']);
        $this->assertSame('obra_completion_rate', $result['next_band_breaches'][0]['metric']);
        $this->assertSame(0.91, $result['next_band_breaches'][0]['observed']);
        $this->assertSame(0.93, $result['next_band_breaches'][0]['threshold']);
        $this->assertFalse($result['next_band_breaches'][0]['missing']);
    }

    public function testCanonicalForgePerBandQualifiesUpToL3OnlyAndEvaluatesEveryBand(): void
    {
        $result = $this->classifier->classify($this->canonicalForgeLadder(), [
            'obra_completion_rate' => 0.91,
            'cert_pass_rate' => 0.98,
            'rollback_rate' => 0.015,
            'multi_agent_collision_rate' => 0.005,
        ]);

        $this->assertCount(4, $result['per_band']);

        $qualifiesByBand = [];
        foreach ($result['per_band'] as $row) {
            $qualifiesByBand[$row['band']] = $row['qualifies'];
        }

        $this->assertSame([
            'L0-L2' => true,
            'L3' => true,
            'L4' => false,
            'L5+' => false,
        ], $qualifiesByBand);

        // The L4 per_band row breach must hold exactly the obra_completion_rate failure.
        $this->assertSame('L4', $result['per_band'][2]['band']);
        $this->assertSame([
            [
                'metric' => 'obra_completion_rate',
                'comparator' => '>=',
                'threshold' => 0.93,
                'observed' => 0.91,
                'missing' => false,
            ],
        ], $result['per_band'][2]['breaches']);
    }

    public function testSnapshotMeetingEveryL5FloorReachesTopBandWithNoPromotionBlock(): void
    {
        $result = $this->classifier->classify($this->canonicalForgeLadder(), [
            'obra_completion_rate' => 0.99,
            'cert_pass_rate' => 0.995,
            'rollback_rate' => 0.001,
            'multi_agent_collision_rate' => 0.0,
        ]);

        $this->assertSame('L5+', $result['qualified_band']);
        $this->assertSame(5, $result['qualified_rank']);
        $this->assertFalse($result['all_bands_breached']);
        $this->assertNull($result['next_band']);
        $this->assertSame([], $result['next_band_breaches']);
        $this->assertFalse($result['promotion_blocked']);
    }

    public function testMissingMetricFlagsMissingTrueAndFailsThatBand(): void
    {
        // cert_pass_rate is absent from the snapshot entirely; L0-L2 already needs it.
        $result = $this->classifier->classify($this->canonicalForgeLadder(), [
            'obra_completion_rate' => 0.99,
            'rollback_rate' => 0.001,
            'multi_agent_collision_rate' => 0.0,
        ]);

        $lowestBand = $result['per_band'][0];
        $this->assertSame('L0-L2', $lowestBand['band']);
        $this->assertFalse($lowestBand['qualifies']);

        $missingBreaches = array_values(array_filter(
            $lowestBand['breaches'],
            static fn (array $breach): bool => $breach['metric'] === 'cert_pass_rate',
        ));

        $this->assertCount(1, $missingBreaches);
        $this->assertTrue($missingBreaches[0]['missing']);
        $this->assertNull($missingBreaches[0]['observed']);
        $this->assertSame('cert_pass_rate', $missingBreaches[0]['metric']);

        // Every band depends on cert_pass_rate, so nothing qualifies.
        $this->assertNull($result['qualified_band']);
        $this->assertSame(-1, $result['qualified_rank']);
        $this->assertTrue($result['all_bands_breached']);
        $this->assertSame(['band' => 'L0-L2', 'rank' => 0], $result['next_band']);
        $this->assertTrue($result['promotion_blocked']);
    }

    public function testGeneralisesOnSyntheticLadderNotPresentInCanonicalSet(): void
    {
        // Fresh ladder/metrics the canonical cases never use, proving real rules.
        $ladder = [
            [
                'band' => 'bronze',
                'rank' => 1,
                'thresholds' => [
                    ['metric' => 'coverage', 'comparator' => '>=', 'value' => 0.50],
                    ['metric' => 'flake_rate', 'comparator' => '<=', 'value' => 0.20],
                ],
            ],
            [
                'band' => 'silver',
                'rank' => 2,
                'thresholds' => [
                    ['metric' => 'coverage', 'comparator' => '>=', 'value' => 0.80],
                    ['metric' => 'flake_rate', 'comparator' => '<=', 'value' => 0.05],
                ],
            ],
            [
                'band' => 'gold',
                'rank' => 3,
                'thresholds' => [
                    ['metric' => 'coverage', 'comparator' => '>=', 'value' => 0.95],
                    ['metric' => 'flake_rate', 'comparator' => '<=', 'value' => 0.01],
                ],
            ],
        ];

        // coverage 0.82 clears silver but not gold; flake 0.03 clears silver but not gold.
        $result = $this->classifier->classify($ladder, [
            'coverage' => 0.82,
            'flake_rate' => 0.03,
        ]);

        $this->assertSame('silver', $result['qualified_band']);
        $this->assertSame(2, $result['qualified_rank']);
        $this->assertFalse($result['all_bands_breached']);
        $this->assertSame(['band' => 'gold', 'rank' => 3], $result['next_band']);
        $this->assertTrue($result['promotion_blocked']);

        // Gold fails on BOTH metrics; next_band_breaches must list exactly those two.
        $this->assertSame([
            [
                'metric' => 'coverage',
                'comparator' => '>=',
                'threshold' => 0.95,
                'observed' => 0.82,
                'missing' => false,
            ],
            [
                'metric' => 'flake_rate',
                'comparator' => '<=',
                'threshold' => 0.01,
                'observed' => 0.03,
                'missing' => false,
            ],
        ], $result['next_band_breaches']);
    }

    public function testBoundaryEqualityQualifiesUnderEpsilonComparator(): void
    {
        $ladder = [
            [
                'band' => 'floor',
                'rank' => 1,
                'thresholds' => [
                    ['metric' => 'ratio', 'comparator' => '>=', 'value' => 0.90],
                    ['metric' => 'cap', 'comparator' => '<=', 'value' => 0.10],
                ],
            ],
        ];

        // Exact boundary values must satisfy >= and <= (epsilon-tolerant).
        $result = $this->classifier->classify($ladder, [
            'ratio' => 0.90,
            'cap' => 0.10,
        ]);

        $this->assertSame('floor', $result['qualified_band']);
        $this->assertSame(1, $result['qualified_rank']);
        $this->assertTrue($result['per_band'][0]['qualifies']);
        $this->assertSame([], $result['per_band'][0]['breaches']);
        $this->assertNull($result['next_band']);
        $this->assertFalse($result['promotion_blocked']);
    }

    public function testClassifyDepartmentsMapsEachDepartmentToItsOwnClassification(): void
    {
        $result = $this->classifier->classifyDepartments(
            [
                'forge' => $this->canonicalForgeLadder(),
                'dev' => $this->canonicalForgeLadder(),
            ],
            [
                'forge' => [
                    'obra_completion_rate' => 0.91,
                    'cert_pass_rate' => 0.98,
                    'rollback_rate' => 0.015,
                    'multi_agent_collision_rate' => 0.005,
                ],
                'dev' => [
                    'obra_completion_rate' => 0.99,
                    'cert_pass_rate' => 0.995,
                    'rollback_rate' => 0.001,
                    'multi_agent_collision_rate' => 0.0,
                ],
            ],
        );

        $this->assertSame('atlas.aaeos.department_maturity_band.v1', $result['schema_version']);
        $this->assertSame(['forge', 'dev'], array_keys($result['departments']));

        $this->assertSame('L3', $result['departments']['forge']['qualified_band']);
        $this->assertTrue($result['departments']['forge']['promotion_blocked']);
        $this->assertSame(['band' => 'L4', 'rank' => 4], $result['departments']['forge']['next_band']);

        $this->assertSame('L5+', $result['departments']['dev']['qualified_band']);
        $this->assertFalse($result['departments']['dev']['promotion_blocked']);
        $this->assertNull($result['departments']['dev']['next_band']);
    }
}
