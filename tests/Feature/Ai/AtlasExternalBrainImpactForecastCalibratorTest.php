<?php

declare(strict_types=1);

namespace Tests\Feature\Ai;

use App\Services\Ai\SelfConstruction\ExternalBrain\AtlasExternalBrainImpactForecastCalibrator;
use Tests\TestCase;

final class AtlasExternalBrainImpactForecastCalibratorTest extends TestCase
{
    private function calibrator(): AtlasExternalBrainImpactForecastCalibrator
    {
        return new AtlasExternalBrainImpactForecastCalibrator;
    }

    private function calibrationFor(array $calibrations, string $family): array
    {
        foreach ($calibrations as $c) {
            if ($c['task_family'] === $family) {
                return $c;
            }
        }
        $this->fail("no calibration found for family {$family}");
    }

    public function test_downstream_unlocks_and_green_evidence_calibrate_higher_than_raw_commit_count(): void
    {
        $result = $this->calibrator()->calibrate(
            [
                ['task_family' => 'unlock-family', 'predicted_leverage' => 'medium'],
                ['task_family' => 'commit-family', 'predicted_leverage' => 'medium'],
            ],
            [
                [
                    'task_family' => 'unlock-family',
                    'actual_outcome' => 'delivered',
                    'capability_delta' => 'medium',
                    'downstream_unlocks' => 6,
                    'green_evidence' => true,
                    'commit_count' => 1,
                ],
                [
                    'task_family' => 'commit-family',
                    'actual_outcome' => 'delivered',
                    'capability_delta' => 'medium',
                    'commit_count' => 20,
                ],
            ],
        );

        $unlockCalibration = $this->calibrationFor($result['calibrations'], 'unlock-family');
        $commitCalibration = $this->calibrationFor($result['calibrations'], 'commit-family');

        // Same predicted_leverage (medium) for both, but downstream unlocks + green evidence push
        // the unlock family's actual (realized) score above the raw-commit-count family's score.
        $this->assertGreaterThan($commitCalibration['confidence_adjustment'], $unlockCalibration['confidence_adjustment']);
        $this->assertSame('up', $unlockCalibration['next_ranking_hint']);
        $this->assertSame('hold', $commitCalibration['next_ranking_hint']);
    }

    public function test_give_back_churn_proxy_and_no_delta_lower_actual_score_and_create_down_ranking_hints(): void
    {
        $result = $this->calibrator()->calibrate(
            [
                ['task_family' => 'churn-family', 'predicted_leverage' => 'high'],
                ['task_family' => 'proxy-family', 'predicted_leverage' => 'high'],
                ['task_family' => 'no-delta-family', 'predicted_leverage' => 'high'],
            ],
            [
                [
                    'task_family' => 'churn-family',
                    'actual_outcome' => 'delivered',
                    'capability_delta' => 'high',
                    'give_back_churn' => 3,
                ],
                [
                    'task_family' => 'proxy-family',
                    'actual_outcome' => 'proxy',
                ],
                [
                    'task_family' => 'no-delta-family',
                    'actual_outcome' => 'delivered',
                    'capability_delta' => 'none',
                ],
            ],
        );

        foreach (['churn-family', 'proxy-family', 'no-delta-family'] as $family) {
            $calibration = $this->calibrationFor($result['calibrations'], $family);
            $this->assertSame('down', $calibration['next_ranking_hint']);
        }
    }

    public function test_repeated_overclaim_is_flagged_only_after_enough_observations(): void
    {
        $singleObservation = $this->calibrator()->calibrate(
            [['task_family' => 'family-a', 'predicted_leverage' => 'high']],
            [['task_family' => 'family-a', 'actual_outcome' => 'give_back']],
        );
        $singleCalibration = $this->calibrationFor($singleObservation['calibrations'], 'family-a');
        $this->assertSame([], $singleCalibration['repeated_overclaim_flags']);
        $this->assertSame([], $singleObservation['repeated_overclaim_flags']);

        $repeatedObservations = $this->calibrator()->calibrate(
            [
                ['task_family' => 'family-a', 'predicted_leverage' => 'high'],
                ['task_family' => 'family-a', 'predicted_leverage' => 'high'],
            ],
            [
                ['task_family' => 'family-a', 'actual_outcome' => 'give_back'],
                ['task_family' => 'family-a', 'actual_outcome' => 'give_back'],
            ],
        );
        $repeatedCalibration = $this->calibrationFor($repeatedObservations['calibrations'], 'family-a');
        $this->assertNotEmpty($repeatedCalibration['repeated_overclaim_flags']);
        $this->assertNotEmpty($repeatedObservations['repeated_overclaim_flags']);
    }

    public function test_confidence_adjustment_stays_bounded_between_negative_one_and_one(): void
    {
        $result = $this->calibrator()->calibrate(
            [['task_family' => 'family-b', 'predicted_leverage' => 'low']],
            [['task_family' => 'family-b', 'actual_outcome' => 'delivered', 'capability_delta' => 'high', 'downstream_unlocks' => 10, 'green_evidence' => true]],
        );

        $calibration = $this->calibrationFor($result['calibrations'], 'family-b');
        $this->assertGreaterThanOrEqual(-1.0, $calibration['confidence_adjustment']);
        $this->assertLessThanOrEqual(1.0, $calibration['confidence_adjustment']);
    }
}
