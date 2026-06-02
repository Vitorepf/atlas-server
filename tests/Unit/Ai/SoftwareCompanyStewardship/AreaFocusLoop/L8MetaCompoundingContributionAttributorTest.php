<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\SoftwareCompanyStewardship\AreaFocusLoop;

use App\Services\Ai\SoftwareCompanyStewardship\AreaFocusLoop\L8MetaCompoundingContributionAttributor;
use PHPUnit\Framework\TestCase;

final class L8MetaCompoundingContributionAttributorTest extends TestCase
{
    private L8MetaCompoundingContributionAttributor $attributor;

    protected function setUp(): void
    {
        $this->attributor = new L8MetaCompoundingContributionAttributor();
    }

    public function testAttributeReturnsFactorIdContributionConfidenceAndSourceRefs(): void
    {
        $observed = [1, 2, 3, 4, 5, 6];

        $result = $this->attributor->attribute($observed, [
            [
                'factor_id' => 'memory_governance',
                'factor_series' => [1, 2, 3, 4, 5, 6],
                'source_refs' => ['ai_metric_daily_snapshots#w1', 'audit_event#abc'],
            ],
        ]);

        $this->assertSame(
            'atlas.aaeos.l8.meta_compounding.contribution_attribution.v1',
            $result['schema_version'],
        );
        $this->assertSame(1, $result['evaluated_factor_count']);

        $factor = $result['attributions'][0];
        $this->assertSame('memory_governance', $factor['factor_id']);
        $this->assertSame(1.0, $factor['contribution_score']);
        $this->assertSame(1.0, $factor['confidence']);
        $this->assertSame(6, $factor['sample_count']);
        $this->assertFalse($factor['noisy']);
        $this->assertFalse($factor['insufficient_evidence']);
        $this->assertTrue($factor['recommend_adoption']);
        $this->assertSame(
            ['ai_metric_daily_snapshots#w1', 'audit_event#abc'],
            $factor['source_refs'],
        );
        $this->assertSame(['memory_governance'], $result['recommended_factor_ids']);
        $this->assertFalse($result['insufficient_evidence']);
    }

    public function testMeasuredContributionTracksTheObservedSeries(): void
    {
        // DoD: weight changes require measured contribution. A factor whose
        // activity moves opposite to dm_dt earns a measured negative score; a
        // factor that tracks it earns a measured positive score.
        $observed = [1, 2, 3, 4, 5, 6];

        $tracking = $this->attributor->attribute($observed, [
            ['factor_id' => 'tracks', 'factor_series' => [1, 2, 3, 4, 5, 6], 'source_refs' => ['s']],
        ])['attributions'][0];

        $opposing = $this->attributor->attribute($observed, [
            ['factor_id' => 'opposes', 'factor_series' => [6, 5, 4, 3, 2, 1], 'source_refs' => ['s']],
        ])['attributions'][0];

        $this->assertSame(1.0, $tracking['contribution_score']);
        $this->assertSame(-1.0, $opposing['contribution_score']);
        $this->assertGreaterThan($opposing['contribution_score'], $tracking['contribution_score']);
    }

    public function testNegativeContributionDoesNotRecommendAdoptionEvenAtHighConfidence(): void
    {
        $observed = [1, 2, 3, 4, 5, 6];

        $result = $this->attributor->attribute($observed, [
            [
                'factor_id' => 'self_construction',
                'factor_series' => [6, 5, 4, 3, 2, 1],
                'source_refs' => ['evidence#1'],
            ],
        ]);

        $factor = $result['attributions'][0];
        $this->assertSame(-1.0, $factor['contribution_score']);
        $this->assertSame(1.0, $factor['confidence']);
        $this->assertFalse($factor['insufficient_evidence']);
        $this->assertFalse($factor['recommend_adoption']);
        $this->assertSame([], $result['recommended_factor_ids']);
    }

    public function testNoisyFlatFactorDoesNotRecommendAdoption(): void
    {
        $observed = [1, 2, 3, 4, 5, 6];

        $factor = $this->attributor->attribute($observed, [
            [
                'factor_id' => 'flat_signal',
                'factor_series' => [2, 2, 2, 2, 2, 2],
                'source_refs' => ['evidence#flat'],
            ],
        ])['attributions'][0];

        $this->assertTrue($factor['noisy']);
        $this->assertSame(0.0, $factor['contribution_score']);
        $this->assertSame(0.0, $factor['confidence']);
        $this->assertTrue($factor['insufficient_evidence']);
        $this->assertFalse($factor['recommend_adoption']);
    }

    public function testShortSeriesIsNoisyAndInsufficient(): void
    {
        $observed = [1, 2];

        $factor = $this->attributor->attribute($observed, [
            [
                'factor_id' => 'too_short',
                'factor_series' => [1, 2],
                'source_refs' => ['evidence#short'],
            ],
        ])['attributions'][0];

        $this->assertSame(2, $factor['sample_count']);
        $this->assertTrue($factor['noisy']);
        $this->assertSame(0.0, $factor['contribution_score']);
        $this->assertTrue($factor['insufficient_evidence']);
        $this->assertFalse($factor['recommend_adoption']);
    }

    public function testWeakPositiveContributionBelowConfidenceThresholdMarksInsufficientEvidence(): void
    {
        $observed = [1, 2, 3, 4, 5, 6];

        $factor = $this->attributor->attribute($observed, [
            [
                'factor_id' => 'weak_evidence',
                'factor_series' => [2, 3, 1, 4, 2, 3],
                'source_refs' => ['evidence#weak'],
            ],
        ])['attributions'][0];

        // Real, non-noisy correlation but small magnitude -> confidence 0.2548.
        $this->assertFalse($factor['noisy']);
        $this->assertSame(0.2548, $factor['contribution_score']);
        $this->assertSame(0.2548, $factor['confidence']);
        $this->assertTrue($factor['insufficient_evidence']);
        $this->assertFalse($factor['recommend_adoption']);
    }

    public function testFactorsAreRankedByContributionScoreDescending(): void
    {
        $observed = [1, 2, 3, 4, 5, 6];

        $result = $this->attributor->attribute($observed, [
            ['factor_id' => 'negative', 'factor_series' => [6, 5, 4, 3, 2, 1], 'source_refs' => ['s']],
            ['factor_id' => 'strong', 'factor_series' => [1, 2, 3, 4, 5, 6], 'source_refs' => ['s']],
            ['factor_id' => 'weak', 'factor_series' => [2, 3, 1, 4, 2, 3], 'source_refs' => ['s']],
            ['factor_id' => 'flat', 'factor_series' => [2, 2, 2, 2, 2, 2], 'source_refs' => ['s']],
        ]);

        $orderedIds = array_map(
            static fn (array $factor): string => $factor['factor_id'],
            $result['attributions'],
        );

        $this->assertSame(['strong', 'weak', 'flat', 'negative'], $orderedIds);
        $this->assertSame(4, $result['evaluated_factor_count']);
        $this->assertSame(['strong'], $result['recommended_factor_ids']);
    }

    public function testEqualScoreTieBreakOrdersFactorIdsAsStringsNotNumerically(): void
    {
        // All three factors track the observed series identically -> equal score.
        // The tie-break must order factor_id as a string ("10" < "100" < "9"),
        // never via the array spaceship which coerces numeric-string ids to a
        // numeric ordering (which would give 9, 10, 100).
        $observed = [1, 2, 3, 4, 5, 6];

        $result = $this->attributor->attribute($observed, [
            ['factor_id' => '9', 'factor_series' => [1, 2, 3, 4, 5, 6], 'source_refs' => ['s']],
            ['factor_id' => '100', 'factor_series' => [1, 2, 3, 4, 5, 6], 'source_refs' => ['s']],
            ['factor_id' => '10', 'factor_series' => [1, 2, 3, 4, 5, 6], 'source_refs' => ['s']],
        ]);

        $orderedIds = array_column($result['attributions'], 'factor_id');
        $this->assertSame(['10', '100', '9'], $orderedIds);
    }

    public function testEmptyCandidatesMarksInsufficientEvidence(): void
    {
        $result = $this->attributor->attribute([1, 2, 3, 4, 5, 6], []);

        $this->assertSame(0, $result['evaluated_factor_count']);
        $this->assertSame([], $result['attributions']);
        $this->assertSame([], $result['recommended_factor_ids']);
        $this->assertTrue($result['insufficient_evidence']);
    }

    public function testContributionAndConfidenceRespectTheirBoundsUnderExtremeInputs(): void
    {
        // Anti-scaffold: inputs unlike any other case; correlation must stay in
        // [-1,1] and confidence in [0,1] no matter how large the magnitudes.
        $observed = [10, -20, 30, -40, 50];

        $factor = $this->attributor->attribute($observed, [
            [
                'factor_id' => 'extreme',
                'factor_series' => [1000000, -2000000, 3000000, -4000000, 5000000],
                'source_refs' => ['evidence#extreme'],
            ],
        ])['attributions'][0];

        $this->assertSame(1.0, $factor['contribution_score']);
        $this->assertLessThanOrEqual(1.0, $factor['contribution_score']);
        $this->assertGreaterThanOrEqual(-1.0, $factor['contribution_score']);
        $this->assertSame(0.8333, $factor['confidence']);
        $this->assertLessThanOrEqual(1.0, $factor['confidence']);
        $this->assertGreaterThanOrEqual(0.0, $factor['confidence']);
        $this->assertSame(5, $factor['sample_count']);
    }

    public function testOverflowMagnitudeSeriesNeverRecommendsFromGarbageCorrelation(): void
    {
        // Huge magnitudes overflow the variance product to +INF, so the raw
        // correlation ratio collapses to NAN. The clamp() (max/min) propagates NAN
        // rather than taming it, so without a non-finite guard this factor would
        // surface either a NAN contribution (outside [-1,1]) or a spurious perfect
        // 1.0 that wrongly clears the adoption threshold. Overflow is not a measured
        // contribution; it must score zero and never recommend.
        $huge = 1e200;
        $observed = [$huge, -$huge, $huge, -$huge, $huge, -$huge];

        $factor = $this->attributor->attribute($observed, [
            [
                'factor_id' => 'overflow',
                'factor_series' => [$huge, -$huge, $huge, -$huge, $huge, -$huge],
                'source_refs' => ['evidence#overflow'],
            ],
        ])['attributions'][0];

        $this->assertIsFloat($factor['contribution_score']);
        $this->assertFalse(is_nan($factor['contribution_score']));
        $this->assertGreaterThanOrEqual(-1.0, $factor['contribution_score']);
        $this->assertLessThanOrEqual(1.0, $factor['contribution_score']);
        $this->assertSame(0.0, $factor['contribution_score']);
        $this->assertFalse(is_nan($factor['confidence']));
        $this->assertGreaterThanOrEqual(0.0, $factor['confidence']);
        $this->assertLessThanOrEqual(1.0, $factor['confidence']);
        $this->assertFalse($factor['recommend_adoption']);
    }

    public function testFactorSeriesIsAlignedToTheOverlappingObservedWindows(): void
    {
        // Factor series longer than observed: only the overlapping prefix counts.
        $observed = [1, 2, 3, 4];

        $factor = $this->attributor->attribute($observed, [
            [
                'factor_id' => 'aligned',
                'factor_series' => [1, 2, 3, 4, 99, 99, 99],
                'source_refs' => ['evidence#align'],
            ],
        ])['attributions'][0];

        $this->assertSame(4, $factor['sample_count']);
        $this->assertSame(1.0, $factor['contribution_score']);
        $this->assertSame(0.6667, $factor['confidence']);
        $this->assertFalse($factor['insufficient_evidence']);
    }

    public function testSourceRefsAreCoercedToAListOfNonEmptyStrings(): void
    {
        $observed = [1, 2, 3, 4, 5, 6];

        $factor = $this->attributor->attribute($observed, [
            [
                'factor_id' => 'memory_governance',
                'factor_series' => [1, 2, 3, 4, 5, 6],
                'source_refs' => ['keep' => 'evidence#a', 7 => 'evidence#b', 'blank' => '   ', 'num' => 42],
            ],
        ])['attributions'][0];

        // list<string> contract: integer keys dropped, blanks and non-strings removed,
        // reindexed sequentially so json encodes as a JSON array.
        $this->assertSame(['evidence#a', 'evidence#b'], $factor['source_refs']);
        $this->assertSame([0, 1], array_keys($factor['source_refs']));
    }

    public function testNamelessFactorIsNeverRecommendedForAdoption(): void
    {
        // Fail-closed: a candidate whose factor_id is missing, blank, or a
        // non-string resolves to '' and is unidentifiable. Even a perfect
        // measured correlation must not recommend adopting a nameless factor
        // into the N x M equation ("weight changes require measured
        // contribution"), and '' must never pollute recommended_factor_ids.
        $observed = [1, 2, 3, 4, 5, 6];
        $perfect = [1, 2, 3, 4, 5, 6];

        $missingKey = $this->attributor->attribute($observed, [
            ['factor_series' => $perfect, 'source_refs' => ['s']],
        ]);
        $blankId = $this->attributor->attribute($observed, [
            ['factor_id' => '   ', 'factor_series' => $perfect, 'source_refs' => ['s']],
        ]);
        $nonStringId = $this->attributor->attribute($observed, [
            ['factor_id' => 42, 'factor_series' => $perfect, 'source_refs' => ['s']],
        ]);

        foreach ([$missingKey, $blankId, $nonStringId] as $result) {
            $factor = $result['attributions'][0];

            // The contribution is still honestly measured...
            $this->assertSame('', $factor['factor_id']);
            $this->assertSame(1.0, $factor['contribution_score']);
            $this->assertSame(1.0, $factor['confidence']);
            $this->assertFalse($factor['noisy']);
            // ...but a nameless factor is never recommended, and never reaches
            // the recommended list.
            $this->assertFalse($factor['recommend_adoption']);
            $this->assertSame([], $result['recommended_factor_ids']);
        }
    }

    public function testIdenticalInputIsDeterministic(): void
    {
        $observed = [1, 2, 3, 4, 5, 6];
        $candidates = [
            ['factor_id' => 'a', 'factor_series' => [1, 2, 3, 4, 5, 6], 'source_refs' => ['s']],
            ['factor_id' => 'b', 'factor_series' => [2, 3, 1, 4, 2, 3], 'source_refs' => ['s']],
        ];

        $first = $this->attributor->attribute($observed, $candidates);
        $second = $this->attributor->attribute($observed, $candidates);

        $this->assertSame($first, $second);
    }
}
