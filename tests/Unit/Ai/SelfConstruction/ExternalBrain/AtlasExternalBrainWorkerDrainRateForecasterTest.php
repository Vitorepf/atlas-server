<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\SelfConstruction\ExternalBrain;

use App\Services\Ai\SelfConstruction\ExternalBrain\AtlasExternalBrainWorkerDrainRateForecaster;
use PHPUnit\Framework\TestCase;

final class AtlasExternalBrainWorkerDrainRateForecasterTest extends TestCase
{
    private AtlasExternalBrainWorkerDrainRateForecaster $forecaster;

    protected function setUp(): void
    {
        parent::setUp();
        $this->forecaster = new AtlasExternalBrainWorkerDrainRateForecaster();
    }

    // AC 2: forecasted drain increases with more workers and recent completions
    public function test_drain_increases_with_more_workers(): void
    {
        $low = $this->forecaster->forecast([
            'active_workers' => 2,
            'claimable_depth' => 50,
            'recent_completions' => 10,
            'window_seconds' => 3600,
        ]);

        $high = $this->forecaster->forecast([
            'active_workers' => 8,
            'claimable_depth' => 50,
            'recent_completions' => 40,
            'window_seconds' => 3600,
        ]);

        $this->assertGreaterThan(
            $low['forecasted_drain_per_hour'],
            $high['forecasted_drain_per_hour'],
            'drain must increase with more workers+completions'
        );
    }

    // AC 3: high give_back velocity lowers effective healthy supply
    public function test_high_give_back_lowers_effective_supply(): void
    {
        $healthy = $this->forecaster->forecast([
            'active_workers' => 5,
            'claimable_depth' => 50,
            'recent_completions' => 20,
            'recent_give_backs' => 0,
            'window_seconds' => 3600,
        ]);

        $eroded = $this->forecaster->forecast([
            'active_workers' => 5,
            'claimable_depth' => 50,
            'recent_completions' => 20,
            'recent_give_backs' => 20,
            'window_seconds' => 3600,
        ]);

        $this->assertGreaterThan(
            $eroded['effective_healthy_supply'],
            $healthy['effective_healthy_supply'],
            'high give_back velocity must lower effective supply'
        );
    }

    // AC 4: recommendations include originate_batch_size and quality_warning fields
    public function test_output_has_originate_batch_size_and_quality_warning(): void
    {
        $result = $this->forecaster->forecast([
            'active_workers' => 10,
            'claimable_depth' => 5,
            'recent_completions' => 50,
            'recent_give_backs' => 30,
            'window_seconds' => 3600,
        ]);

        $this->assertArrayHasKey('originate_batch_size', $result);
        $this->assertArrayHasKey('quality_warning', $result);
        $this->assertIsInt($result['originate_batch_size']);
    }

    public function test_comfortable_supply_yields_zero_batch_size(): void
    {
        $result = $this->forecaster->forecast([
            'active_workers' => 3,
            'claimable_depth' => 500,
            'recent_completions' => 5,
            'window_seconds' => 3600,
        ]);

        $this->assertSame(0, $result['originate_batch_size']);
        $this->assertSame('originate_comfortable', $result['recommendation']);
    }

    public function test_quality_erosion_lowers_effective_supply(): void
    {
        $clean = $this->forecaster->forecast([
            'active_workers' => 5,
            'claimable_depth' => 50,
            'recent_completions' => 10,
            'quality_erosion' => 0.0,
            'window_seconds' => 3600,
        ]);

        $eroded = $this->forecaster->forecast([
            'active_workers' => 5,
            'claimable_depth' => 50,
            'recent_completions' => 10,
            'quality_erosion' => 0.5,
            'window_seconds' => 3600,
        ]);

        $this->assertGreaterThan($eroded['effective_healthy_supply'], $clean['effective_healthy_supply']);
    }

    public function test_high_give_back_triggers_quality_warning(): void
    {
        $result = $this->forecaster->forecast([
            'active_workers' => 5,
            'claimable_depth' => 50,
            'recent_completions' => 5,
            'recent_give_backs' => 20,
            'window_seconds' => 3600,
        ]);

        $this->assertNotNull($result['quality_warning']);
        $this->assertStringContainsString('give_back', $result['quality_warning']);
    }
}
