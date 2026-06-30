<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\SelfConstruction\ExternalBrain;

use App\Services\Ai\SelfConstruction\ExternalBrain\AtlasExternalBrainWorkerDrainRateForecaster;
use PHPUnit\Framework\TestCase;

final class AtlasExternalBrainWorkerDrainRateForecasterProjectionTest extends TestCase
{
    private function forecaster(): AtlasExternalBrainWorkerDrainRateForecaster
    {
        return new AtlasExternalBrainWorkerDrainRateForecaster;
    }

    public function test_sufficient_depth_and_non_blind_telemetry_recommends_wait(): void
    {
        $result = $this->forecaster()->recommendFromProjection([
            'queue_depth' => 20,
            'sufficient_depth_floor' => 10,
            'telemetry_confidence' => 'direct',
        ]);

        $this->assertSame(AtlasExternalBrainWorkerDrainRateForecaster::PROJECTION_RECOMMENDATION_WAIT, $result['recommendation']);
    }

    public function test_blind_telemetry_recommends_fix_projection_telemetry_before_wait(): void
    {
        $result = $this->forecaster()->recommendFromProjection([
            'queue_depth' => 20,
            'sufficient_depth_floor' => 10,
            'telemetry_confidence' => 'blind',
        ]);

        $this->assertSame(
            AtlasExternalBrainWorkerDrainRateForecaster::PROJECTION_RECOMMENDATION_FIX_PROJECTION_TELEMETRY,
            $result['recommendation'],
        );
    }

    public function test_low_depth_recommends_replenish_regardless_of_telemetry_mode(): void
    {
        $resultDirect = $this->forecaster()->recommendFromProjection([
            'queue_depth' => 2,
            'sufficient_depth_floor' => 10,
            'telemetry_confidence' => 'direct',
        ]);
        $resultBlind = $this->forecaster()->recommendFromProjection([
            'queue_depth' => 2,
            'sufficient_depth_floor' => 10,
            'telemetry_confidence' => 'blind',
        ]);

        $this->assertSame(AtlasExternalBrainWorkerDrainRateForecaster::PROJECTION_RECOMMENDATION_REPLENISH, $resultDirect['recommendation']);
        $this->assertSame(AtlasExternalBrainWorkerDrainRateForecaster::PROJECTION_RECOMMENDATION_REPLENISH, $resultBlind['recommendation']);
    }

    public function test_estimated_telemetry_with_sufficient_depth_recommends_wait(): void
    {
        $result = $this->forecaster()->recommendFromProjection([
            'queue_depth' => 15,
            'sufficient_depth_floor' => 10,
            'telemetry_confidence' => 'estimated',
        ]);

        $this->assertSame(AtlasExternalBrainWorkerDrainRateForecaster::PROJECTION_RECOMMENDATION_WAIT, $result['recommendation']);
    }
}
