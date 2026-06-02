<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\SoftwareCompanyStewardship\AreaFocusLoop;

use App\Services\Ai\SoftwareCompanyStewardship\AreaFocusLoop\L8SystemEvolutionTwinPredictionScorer;
use PHPUnit\Framework\TestCase;

final class L8SystemEvolutionTwinPredictionScorerTest extends TestCase
{
    private L8SystemEvolutionTwinPredictionScorer $scorer;

    protected function setUp(): void
    {
        $this->scorer = new L8SystemEvolutionTwinPredictionScorer();
    }

    public function testPerfectPredictionScoresHigh(): void
    {
        $predictions = [
            ['candidate_id' => 'c1', 'predicted_direction' => 'improve', 'confidence' => 1.0],
            ['candidate_id' => 'c2', 'predicted_direction' => 'regress', 'confidence' => 1.0],
            ['candidate_id' => 'c3', 'predicted_direction' => 'neutral', 'confidence' => 1.0],
            ['candidate_id' => 'c4', 'predicted_direction' => 'improve', 'confidence' => 1.0],
            ['candidate_id' => 'c5', 'predicted_direction' => 'regress', 'confidence' => 1.0],
        ];
        $outcomes = [
            ['candidate_id' => 'c1', 'actual_delta' => 0.30],
            ['candidate_id' => 'c2', 'actual_delta' => -0.20],
            ['candidate_id' => 'c3', 'actual_delta' => 0.0],
            ['candidate_id' => 'c4', 'actual_delta' => 0.15],
            ['candidate_id' => 'c5', 'actual_delta' => -0.05],
        ];

        $result = $this->scorer->score($predictions, $outcomes);

        $this->assertSame('atlas.aaeos.l8.system_evolution_twin_prediction_score.v1', $result['schema_version']);
        $this->assertSame(5, $result['sample_count']);
        $this->assertSame(5, $result['correct_count']);
        $this->assertSame(1.0, $result['accuracy']);
        $this->assertSame(1.0, $result['twin_accuracy']);
        $this->assertSame(0.0, $result['calibration_error']);
        $this->assertFalse($result['blocked']);
        $this->assertFalse($result['stale_model']);
        $this->assertGreaterThanOrEqual(0.6, $result['accuracy']);
    }

    public function testWrongPredictionLowersAccuracy(): void
    {
        $outcomes = [
            ['candidate_id' => 'c1', 'actual_delta' => 0.30],
            ['candidate_id' => 'c2', 'actual_delta' => -0.20],
            ['candidate_id' => 'c3', 'actual_delta' => 0.10],
            ['candidate_id' => 'c4', 'actual_delta' => 0.15],
            ['candidate_id' => 'c5', 'actual_delta' => -0.05],
        ];

        $perfect = $this->scorer->score(
            [
                ['candidate_id' => 'c1', 'predicted_direction' => 'improve', 'confidence' => 1.0],
                ['candidate_id' => 'c2', 'predicted_direction' => 'regress', 'confidence' => 1.0],
                ['candidate_id' => 'c3', 'predicted_direction' => 'improve', 'confidence' => 1.0],
                ['candidate_id' => 'c4', 'predicted_direction' => 'improve', 'confidence' => 1.0],
                ['candidate_id' => 'c5', 'predicted_direction' => 'regress', 'confidence' => 1.0],
            ],
            $outcomes,
        );

        // c3 now predicted to regress while it actually improves => one wrong call.
        $withWrong = $this->scorer->score(
            [
                ['candidate_id' => 'c1', 'predicted_direction' => 'improve', 'confidence' => 1.0],
                ['candidate_id' => 'c2', 'predicted_direction' => 'regress', 'confidence' => 1.0],
                ['candidate_id' => 'c3', 'predicted_direction' => 'regress', 'confidence' => 1.0],
                ['candidate_id' => 'c4', 'predicted_direction' => 'improve', 'confidence' => 1.0],
                ['candidate_id' => 'c5', 'predicted_direction' => 'regress', 'confidence' => 1.0],
            ],
            $outcomes,
        );

        $this->assertSame(1.0, $perfect['accuracy']);
        $this->assertSame(0.8, $withWrong['accuracy']);
        $this->assertSame(4, $withWrong['correct_count']);
        $this->assertSame(5, $withWrong['sample_count']);
        $this->assertLessThan($perfect['accuracy'], $withWrong['accuracy']);
    }

    public function testSampleCountBelowThresholdBlocks(): void
    {
        $result = $this->scorer->score(
            [
                ['candidate_id' => 'c1', 'predicted_direction' => 'improve', 'confidence' => 0.9],
                ['candidate_id' => 'c2', 'predicted_direction' => 'regress', 'confidence' => 0.9],
            ],
            [
                ['candidate_id' => 'c1', 'actual_delta' => 0.25],
                ['candidate_id' => 'c2', 'actual_delta' => -0.25],
            ],
        );

        $this->assertSame(2, $result['sample_count']);
        $this->assertTrue($result['blocked']);
        $this->assertTrue($result['stale_model']);
    }

    public function testThresholdSampleCountUnblocks(): void
    {
        $result = $this->scorer->score(
            [
                ['candidate_id' => 'c1', 'predicted_direction' => 'improve', 'confidence' => 1.0],
                ['candidate_id' => 'c2', 'predicted_direction' => 'regress', 'confidence' => 1.0],
                ['candidate_id' => 'c3', 'predicted_direction' => 'neutral', 'confidence' => 1.0],
            ],
            [
                ['candidate_id' => 'c1', 'actual_delta' => 0.25],
                ['candidate_id' => 'c2', 'actual_delta' => -0.25],
                ['candidate_id' => 'c3', 'actual_delta' => 0.0],
            ],
        );

        $this->assertSame(3, $result['sample_count']);
        $this->assertFalse($result['blocked']);
    }

    public function testDirectionInferredFromDeltaSignsGeneralises(): void
    {
        // No explicit direction labels: prediction direction comes from the sign
        // of predicted_delta, actual direction from the sign of actual_delta.
        $result = $this->scorer->score(
            [
                ['candidate_id' => 'a', 'predicted_delta' => 0.42, 'confidence' => 1.0],   // improve, actual improve => correct
                ['candidate_id' => 'b', 'predicted_delta' => -0.10, 'confidence' => 1.0],  // regress, actual regress => correct
                ['candidate_id' => 'd', 'predicted_delta' => 0.0, 'confidence' => 1.0],    // neutral, actual neutral => correct
                ['candidate_id' => 'e', 'predicted_delta' => 0.50, 'confidence' => 1.0],   // improve, actual regress => wrong
            ],
            [
                ['candidate_id' => 'a', 'actual_delta' => 3.0],
                ['candidate_id' => 'b', 'actual_delta' => -0.01],
                ['candidate_id' => 'd', 'actual_delta' => 0.0],
                ['candidate_id' => 'e', 'actual_delta' => -2.0],
            ],
        );

        $this->assertSame(4, $result['sample_count']);
        $this->assertSame(3, $result['correct_count']);
        $this->assertSame(0.75, $result['accuracy']);
        $this->assertFalse($result['blocked']);
    }

    public function testCalibrationErrorIsBrierMeanOverConfidence(): void
    {
        // All four predictions are correct, so the squared gap per sample is
        // (confidence - 1)^2 => {0, 0, 0.25, 1.0}; mean = 1.25 / 4 = 0.3125.
        $result = $this->scorer->score(
            [
                ['candidate_id' => 'c1', 'predicted_direction' => 'improve', 'confidence' => 1.0],
                ['candidate_id' => 'c2', 'predicted_direction' => 'regress', 'confidence' => 1.0],
                ['candidate_id' => 'c3', 'predicted_direction' => 'improve', 'confidence' => 0.5],
                ['candidate_id' => 'c4', 'predicted_direction' => 'regress', 'confidence' => 0.0],
            ],
            [
                ['candidate_id' => 'c1', 'actual_delta' => 0.20],
                ['candidate_id' => 'c2', 'actual_delta' => -0.20],
                ['candidate_id' => 'c3', 'actual_delta' => 0.20],
                ['candidate_id' => 'c4', 'actual_delta' => -0.20],
            ],
        );

        $this->assertSame(4, $result['sample_count']);
        $this->assertSame(1.0, $result['accuracy']);
        $this->assertEqualsWithDelta(0.3125, $result['calibration_error'], 1.0e-9);
    }

    public function testLowAccuracyFlagsStaleModelWhileBoundedTo01(): void
    {
        // Five samples, every direction wrong => accuracy 0.0, below the 0.6 floor.
        $result = $this->scorer->score(
            [
                ['candidate_id' => 'c1', 'predicted_direction' => 'regress', 'confidence' => 1.0],
                ['candidate_id' => 'c2', 'predicted_direction' => 'improve', 'confidence' => 1.0],
                ['candidate_id' => 'c3', 'predicted_direction' => 'regress', 'confidence' => 1.0],
                ['candidate_id' => 'c4', 'predicted_direction' => 'improve', 'confidence' => 1.0],
                ['candidate_id' => 'c5', 'predicted_direction' => 'improve', 'confidence' => 1.0],
            ],
            [
                ['candidate_id' => 'c1', 'actual_delta' => 0.30],
                ['candidate_id' => 'c2', 'actual_delta' => -0.30],
                ['candidate_id' => 'c3', 'actual_delta' => 0.30],
                ['candidate_id' => 'c4', 'actual_delta' => -0.30],
                ['candidate_id' => 'c5', 'actual_delta' => -0.30],
            ],
        );

        $this->assertSame(5, $result['sample_count']);
        $this->assertSame(0.0, $result['accuracy']);
        $this->assertFalse($result['blocked']);
        $this->assertTrue($result['stale_model']);
        $this->assertGreaterThanOrEqual(0.0, $result['accuracy']);
        $this->assertLessThanOrEqual(1.0, $result['accuracy']);
        $this->assertLessThanOrEqual(1.0, $result['calibration_error']);
    }

    public function testSubFloorAccuracyThatRoundsUpToFloorStillFlagsStale(): void
    {
        // 2401 correct of 4002 joined pairs => true accuracy 0.59995002..., which is
        // strictly below the 0.6 staleness floor but round(_, 4) === 0.6. The staleness
        // gate must use the unrounded ratio so a sub-floor model fails closed (stale)
        // rather than silently re-acquiring the right to steer selection.
        $predictions = [];
        $outcomes = [];
        for ($i = 0; $i < 4002; $i++) {
            $id = "k{$i}";
            $predictions[] = ['candidate_id' => $id, 'predicted_direction' => 'improve', 'confidence' => 1.0];
            $outcomes[] = ['candidate_id' => $id, 'actual_delta' => $i < 2401 ? 0.5 : -0.5];
        }

        $result = $this->scorer->score($predictions, $outcomes);

        $this->assertSame(4002, $result['sample_count']);
        $this->assertSame(2401, $result['correct_count']);
        // Reported (display) accuracy rounds to the floor, but the model is still stale.
        $this->assertSame(0.6, $result['accuracy']);
        $this->assertFalse($result['blocked']);
        $this->assertTrue($result['stale_model']);
    }

    public function testUnmatchedPredictionsAreNotScored(): void
    {
        // Only c1/c2/c3 have outcomes; the orphan prediction and orphan outcome
        // are excluded from the sample so the join, not the raw counts, drives it.
        $result = $this->scorer->score(
            [
                ['candidate_id' => 'c1', 'predicted_direction' => 'improve', 'confidence' => 1.0],
                ['candidate_id' => 'c2', 'predicted_direction' => 'regress', 'confidence' => 1.0],
                ['candidate_id' => 'c3', 'predicted_direction' => 'improve', 'confidence' => 1.0],
                ['candidate_id' => 'orphan', 'predicted_direction' => 'improve', 'confidence' => 1.0],
            ],
            [
                ['candidate_id' => 'c1', 'actual_delta' => 0.20],
                ['candidate_id' => 'c2', 'actual_delta' => -0.20],
                ['candidate_id' => 'c3', 'actual_delta' => 0.20],
                ['candidate_id' => 'unrelated', 'actual_delta' => 0.99],
            ],
        );

        $this->assertSame(3, $result['sample_count']);
        $this->assertSame(['c1', 'c2', 'c3'], $result['scored_candidate_ids']);
        $this->assertSame(1.0, $result['accuracy']);
    }

    public function testEmptyInputsBlockWithZeroSamples(): void
    {
        $result = $this->scorer->score([], []);

        $this->assertSame(0, $result['sample_count']);
        $this->assertSame(0.0, $result['accuracy']);
        $this->assertSame(0.0, $result['calibration_error']);
        $this->assertTrue($result['blocked']);
        $this->assertTrue($result['stale_model']);
    }

    public function testIdenticalInputIsDeterministic(): void
    {
        $predictions = [
            ['candidate_id' => 'c1', 'predicted_direction' => 'improve', 'confidence' => 0.8],
            ['candidate_id' => 'c2', 'predicted_delta' => -0.4, 'confidence' => 0.7],
            ['candidate_id' => 'c3', 'predicted_direction' => 'neutral', 'confidence' => 0.5],
        ];
        $outcomes = [
            ['candidate_id' => 'c1', 'actual_delta' => 0.10],
            ['candidate_id' => 'c2', 'actual_delta' => -0.10],
            ['candidate_id' => 'c3', 'actual_delta' => 0.0],
        ];

        $first = $this->scorer->score($predictions, $outcomes);
        $second = $this->scorer->score($predictions, $outcomes);

        $this->assertSame($first, $second);
    }
}
