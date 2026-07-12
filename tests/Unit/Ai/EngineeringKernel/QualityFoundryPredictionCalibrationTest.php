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
            'prediction_id' => 'p-1',
            'workspace_id' => 'atlas-server',
            'snapshot_hash' => 'not-a-hash',
            'order_hash' => str_repeat('b', 64),
            'expected_observation' => 'failure',
            'predicted_probability' => 0.8,
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
