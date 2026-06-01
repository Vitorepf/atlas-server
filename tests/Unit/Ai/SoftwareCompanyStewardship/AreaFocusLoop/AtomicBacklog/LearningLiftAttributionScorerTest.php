<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\SoftwareCompanyStewardship\AreaFocusLoop\AtomicBacklog;

use App\Services\Ai\SoftwareCompanyStewardship\AreaFocusLoop\AtomicBacklog\LearningLiftAttributionScorer;
use PHPUnit\Framework\TestCase;

final class LearningLiftAttributionScorerTest extends TestCase
{
    private LearningLiftAttributionScorer $scorer;

    protected function setUp(): void
    {
        $this->scorer = new LearningLiftAttributionScorer();
    }

    public function testLowerCostAndHigherQualityYieldsPositiveVerdict(): void
    {
        $result = $this->scorer->score(
            [
                'cost' => 100.0,
                'flow_quality' => 0.6,
                'test_pass_rate' => 0.8,
                'repair_loop' => 4,
            ],
            [
                'cost' => 70.0,
                'flow_quality' => 0.75,
                'test_pass_rate' => 0.9,
                'repair_loop' => 3,
            ],
            ['confidence' => 0.9],
        );

        $this->assertSame('atlas.loop.learning_lift_attribution.v1', $result['schema_version']);
        $this->assertSame('positive', $result['verdict']);
        $this->assertSame(23, $result['lift_score']);
        $this->assertSame([], $result['blockers']);
        $this->assertSame(0.9, $result['attribution_confidence']);
        $this->assertSame(0.3, $result['component_deltas']['cost']);
        $this->assertSame(0.25, $result['component_deltas']['flow_quality']);
        $this->assertSame(0.125, $result['component_deltas']['test_pass_rate']);
        $this->assertSame(0.25, $result['component_deltas']['repair_loop']);
    }

    public function testHigherRepairRateYieldsRegressionVerdict(): void
    {
        $result = $this->scorer->score(
            [
                'cost' => 100.0,
                'flow_quality' => 0.7,
                'test_pass_rate' => 0.9,
                'repair_loop' => 2,
            ],
            [
                'cost' => 130.0,
                'flow_quality' => 0.6,
                'test_pass_rate' => 0.8,
                'repair_loop' => 5,
            ],
            ['confidence' => 0.85],
        );

        $this->assertSame('regression', $result['verdict']);
        $this->assertSame(-35, $result['lift_score']);
        $this->assertSame(-0.3, $result['component_deltas']['cost']);
        $this->assertSame(-1.0, $result['component_deltas']['repair_loop']);
        $this->assertSame([], $result['blockers']);
        $this->assertSame(0.85, $result['attribution_confidence']);
    }

    public function testMissingBaselineBlocks(): void
    {
        $result = $this->scorer->score(
            [],
            [
                'cost' => 50.0,
                'flow_quality' => 0.9,
                'test_pass_rate' => 0.95,
                'repair_loop' => 1,
            ],
            ['confidence' => 0.99],
        );

        $this->assertSame(['missing_baseline'], $result['blockers']);
        $this->assertSame(0, $result['lift_score']);
        $this->assertSame('neutral', $result['verdict']);
        $this->assertSame(0.0, $result['attribution_confidence']);
        $this->assertSame(0.0, $result['component_deltas']['cost']);
        $this->assertSame(0.0, $result['component_deltas']['repair_loop']);
    }

    public function testLowAttributionConfidenceYieldsNeutralNotAttributable(): void
    {
        $result = $this->scorer->score(
            [
                'cost' => 100.0,
                'flow_quality' => 0.6,
                'test_pass_rate' => 0.8,
                'repair_loop' => 4,
            ],
            [
                'cost' => 70.0,
                'flow_quality' => 0.75,
                'test_pass_rate' => 0.9,
                'repair_loop' => 3,
            ],
            ['attributed_samples' => 2, 'total_samples' => 10],
        );

        $this->assertSame('neutral_not_attributable', $result['verdict']);
        $this->assertSame(0.2, $result['attribution_confidence']);
        // The measured lift is still real and positive; only attribution gates the verdict.
        $this->assertSame(23, $result['lift_score']);
        $this->assertSame([], $result['blockers']);
    }

    public function testWeightsClampLiftScoreToPositiveCeiling(): void
    {
        $baseline = [
            'cost' => 100.0,
            'flow_quality' => 9.0,
            'test_pass_rate' => 9.0,
            'repair_loop' => 100.0,
        ];

        $after = [
            'cost' => 0.0,
            'flow_quality' => 18.0,
            'test_pass_rate' => 18.0,
            'repair_loop' => 0.0,
        ];

        $result = $this->scorer->score($baseline, $after, ['confidence' => 1.0]);

        $this->assertSame(100, $result['lift_score']);
        $this->assertSame('positive', $result['verdict']);
        $this->assertSame(1.0, $result['component_deltas']['flow_quality']);

        // Doubling the magnitude of every improvement keeps the score pinned at
        // the ceiling, proving the clamp rather than a fixture coincidence.
        $extremeAfter = [
            'cost' => -100.0,
            'flow_quality' => 36.0,
            'test_pass_rate' => 36.0,
            'repair_loop' => -200.0,
        ];

        $extremeResult = $this->scorer->score($baseline, $extremeAfter, ['confidence' => 1.0]);

        $this->assertSame(100, $extremeResult['lift_score']);
    }

    public function testWeightsClampLiftScoreToNegativeFloor(): void
    {
        $baseline = [
            'cost' => 100.0,
            'flow_quality' => 9.0,
            'test_pass_rate' => 9.0,
            'repair_loop' => 1.0,
        ];

        $after = [
            'cost' => 200.0,
            'flow_quality' => 0.0,
            'test_pass_rate' => 0.0,
            'repair_loop' => 101.0,
        ];

        $result = $this->scorer->score($baseline, $after, ['confidence' => 1.0]);

        $this->assertSame(-100, $result['lift_score']);
        $this->assertSame('regression', $result['verdict']);
        $this->assertSame(-1.0, $result['component_deltas']['repair_loop']);
    }

    public function testIdenticalInputIsDeterministic(): void
    {
        $baseline = [
            'cost' => 100.0,
            'flow_quality' => 0.6,
            'test_pass_rate' => 0.8,
            'repair_loop' => 4,
        ];
        $after = [
            'cost' => 70.0,
            'flow_quality' => 0.75,
            'test_pass_rate' => 0.9,
            'repair_loop' => 3,
        ];
        $attribution = ['confidence' => 0.9];

        $first = $this->scorer->score($baseline, $after, $attribution);
        $second = $this->scorer->score($baseline, $after, $attribution);

        $this->assertSame($first, $second);
    }
}
