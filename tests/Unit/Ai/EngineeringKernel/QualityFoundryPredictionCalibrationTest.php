<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\EngineeringKernel;

use App\Services\Ai\EngineeringKernel\QualityFoundry\QualityFoundryPredictionCalibration;
use PHPUnit\Framework\TestCase;

final class QualityFoundryPredictionCalibrationTest extends TestCase
{
    public function test_prediction_receipt_requires_frozen_order_snapshot_and_observation_window(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('prediction_snapshot_hash_invalid');

        (new QualityFoundryPredictionCalibration)->issue([
            'prediction_id' => 'p-1', 'workspace_id' => 'atlas-server',
            'snapshot_hash' => 'not-a-hash', 'order_hash' => str_repeat('b', 64),
            'expected_observation' => 'failure', 'predicted_probability' => 0.8,
            'issued_at' => '2026-07-12T00:00:00Z',
            'observation_window' => ['from' => '2026-07-12T01:00:00Z', 'until' => '2026-07-12T02:00:00Z'],
        ]);
    }

    public function test_mismatched_or_simulated_observation_stays_unresolved(): void
    {
        $receipt = $this->receipt();
        $calibration = new QualityFoundryPredictionCalibration;

        $mismatch = $calibration->reconcile($receipt, [
            'snapshot_hash' => str_repeat('9', 64), 'order_hash' => $receipt['order_hash'],
            'observed' => true, 'real' => true, 'observed_at' => '2026-07-12T01:30:00Z',
        ]);
        self::assertSame('unresolved', $mismatch['status']);
        self::assertContains('snapshot_hash_mismatch', $mismatch['blockers']);

        $simulated = $calibration->reconcile($receipt, [
            'snapshot_hash' => $receipt['snapshot_hash'], 'order_hash' => $receipt['order_hash'],
            'observed' => true, 'real' => false, 'observed_at' => '2026-07-12T01:30:00Z',
        ]);
        self::assertSame('unresolved', $simulated['status']);
        self::assertContains('observed_outcome_not_real', $simulated['blockers']);
    }

    public function test_matching_real_outcome_resolves_and_unresolved_receipts_degrade_confidence(): void
    {
        $calibration = new QualityFoundryPredictionCalibration;
        $receipt = $this->receipt();
        $resolved = $calibration->reconcile($receipt, [
            'snapshot_hash' => $receipt['snapshot_hash'], 'order_hash' => $receipt['order_hash'],
            'release_hash' => $receipt['release_hash'], 'observed' => true, 'real' => true,
            'observed_at' => '2026-07-12T01:30:00Z',
        ]);

        self::assertSame('resolved', $resolved['status']);
        self::assertSame(0.04, $resolved['calibration_error']);

        $calibrated = $calibration->calibrate([$resolved]);
        self::assertSame('calibrated', $calibrated['status']);
        self::assertSame(1, $calibrated['resolved_count']);
        self::assertSame(0.96, $calibrated['confidence']);

        $degraded = $calibration->calibrate([$resolved, ['status' => 'unresolved', 'blockers' => ['outcome_missing']]]);
        self::assertSame('degraded', $degraded['status']);
        self::assertSame(1, $degraded['unresolved_count']);
        self::assertLessThan($calibrated['confidence'], $degraded['confidence']);
    }

    public function test_prediction_requires_a_real_matching_observation_and_never_becomes_claim_evidence(): void
    {
        $calibration = new QualityFoundryPredictionCalibration;
        $receipt = $calibration->issue($this->prediction());

        $simulation = $calibration->reconcile($receipt, [
            'snapshot_hash' => $receipt['snapshot_hash'], 'order_hash' => $receipt['order_hash'],
            'observed' => true, 'real' => false, 'observed_at' => '2026-07-14T00:00:00Z',
        ]);
        self::assertSame('unresolved', $simulation['status']);
        self::assertContains('observed_outcome_not_real', $simulation['blockers']);
        self::assertFalse($simulation['claim_eligible']);

        $missing = $calibration->reconcile($receipt, [
            'snapshot_hash' => $receipt['snapshot_hash'], 'order_hash' => $receipt['order_hash'],
            'real' => true, 'observed_at' => '2026-07-14T00:00:00Z',
        ]);
        self::assertContains('observed_outcome_missing', $missing['blockers']);
    }

    public function test_late_or_unresolved_observations_degrade_confidence_instead_of_becoming_current(): void
    {
        $calibration = new QualityFoundryPredictionCalibration;
        $receipt = $calibration->issue($this->prediction());
        $late = $calibration->reconcile($receipt, [
            'snapshot_hash' => $receipt['snapshot_hash'], 'order_hash' => $receipt['order_hash'],
            'observed' => true, 'real' => true, 'observed_at' => '2026-07-22T00:00:00Z',
        ]);
        $summary = $calibration->calibrate([$late, ['status' => 'pending']]);

        self::assertSame('degraded', $summary['status']);
        self::assertSame(1, $summary['late_count']);
        self::assertSame(1, $summary['unresolved_count']);
        self::assertLessThan(1.0, $summary['confidence']);
        self::assertGreaterThanOrEqual($summary['confidence_interval']['lower'], $summary['confidence']);
        self::assertLessThanOrEqual($summary['confidence_interval']['upper'], $summary['confidence']);
        self::assertFalse($summary['claim_eligible']);
    }

    public function test_temporal_or_hash_mismatch_cannot_resolve_a_prediction(): void
    {
        $calibration = new QualityFoundryPredictionCalibration;
        $receipt = $calibration->issue($this->prediction());

        $beforeWindow = $calibration->reconcile($receipt, [
            'snapshot_hash' => $receipt['snapshot_hash'], 'order_hash' => $receipt['order_hash'],
            'observed' => true, 'real' => true, 'observed_at' => '2026-07-12T23:59:59Z',
        ]);
        self::assertContains('observation_before_window', $beforeWindow['blockers']);

        $mismatched = $calibration->reconcile($receipt, [
            'snapshot_hash' => str_repeat('c', 64), 'order_hash' => $receipt['order_hash'],
            'observed' => true, 'real' => true, 'observed_at' => '2026-07-14T00:00:00Z',
        ]);
        self::assertContains('snapshot_hash_mismatch', $mismatched['blockers']);
        self::assertFalse($mismatched['claim_eligible']);
    }

    public function test_contradictory_real_observations_remain_unresolved(): void
    {
        $calibration = new QualityFoundryPredictionCalibration;
        $receipt = $calibration->issue($this->prediction());

        $trueObservation = $calibration->reconcile($receipt, [
            'snapshot_hash' => $receipt['snapshot_hash'], 'order_hash' => $receipt['order_hash'],
            'observed' => true, 'real' => true, 'observed_at' => '2026-07-14T00:00:00Z',
        ]);
        $falseObservation = $calibration->reconcile($receipt, [
            'snapshot_hash' => $receipt['snapshot_hash'], 'order_hash' => $receipt['order_hash'],
            'observed' => false, 'real' => true, 'observed_at' => '2026-07-15T00:00:00Z',
        ]);

        $summary = $calibration->calibrate([$trueObservation, $falseObservation]);

        self::assertSame('degraded', $summary['status']);
        self::assertSame(0, $summary['resolved_count']);
        self::assertSame(2, $summary['unresolved_count']);
        self::assertContains('contradictory_observation', $summary['blockers']);
    }

    /** @return array<string,mixed> */
    private function prediction(): array
    {
        return [
            'prediction_id' => 'prediction-1', 'workspace_id' => 'atlas-server',
            'snapshot_hash' => str_repeat('a', 64), 'order_hash' => str_repeat('b', 64),
            'expected_observation' => 'release remains healthy', 'predicted_probability' => 0.8,
            'issued_at' => '2026-07-12T00:00:00Z',
            'observation_window' => ['from' => '2026-07-13T00:00:00Z', 'until' => '2026-07-19T00:00:00Z'],
        ];
    }

    /** @return array<string,mixed> */
    private function receipt(): array
    {
        return (new QualityFoundryPredictionCalibration)->issue([
            'prediction_id' => 'p-1', 'workspace_id' => 'atlas-server',
            'snapshot_hash' => str_repeat('a', 64), 'order_hash' => str_repeat('b', 64),
            'release_hash' => str_repeat('c', 64), 'expected_observation' => 'failure',
            'predicted_probability' => 0.8, 'issued_at' => '2026-07-12T00:00:00Z',
            'observation_window' => ['from' => '2026-07-12T01:00:00Z', 'until' => '2026-07-12T02:00:00Z'],
        ]);
    }
}
