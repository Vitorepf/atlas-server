<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\AutonomousEvolution\Discovery;

use App\Services\Ai\AutonomousEvolution\Discovery\AtlasLoopChangeClassPriorService;
use PHPUnit\Framework\TestCase;

/**
 * Characterization coverage for the thin-prior danger-band decision.
 */
final class AtlasLoopChangeClassPriorServiceTest extends TestCase
{
    public function test_thin_prior_max_uncertainty_returns_false_outside_the_danger_band(): void
    {
        $service = new AtlasLoopChangeClassPriorService;

        $this->assertFalse($service->thinPriorMaxUncertainty([
            'samples' => 0,
            'enough_samples' => false,
            'hopeless' => false,
            'landing_rate' => 0.0,
            'floor_rate' => 0.15,
        ]), 'zero evidence must fail open instead of triggering the danger-band ask');

        $this->assertFalse($service->thinPriorMaxUncertainty([
            'samples' => 3,
            'enough_samples' => true,
            'hopeless' => false,
            'landing_rate' => 0.0,
            'floor_rate' => 0.15,
        ]), 'once the class has enough samples, DC6 must stay off');

        $this->assertFalse($service->thinPriorMaxUncertainty([
            'samples' => 3,
            'enough_samples' => false,
            'hopeless' => true,
            'landing_rate' => 0.0,
            'floor_rate' => 0.15,
        ]), 'already-hopeless priors belong to the DC4 path, not the thin-prior ask');

        $this->assertFalse($service->thinPriorMaxUncertainty([
            'samples' => 3,
            'enough_samples' => false,
            'hopeless' => false,
            'landing_rate' => 0.66,
            'floor_rate' => 0.15,
        ]), 'a thin prior above the floor is not in the danger band');
    }

    public function test_thin_prior_max_uncertainty_returns_true_only_for_thin_negative_priors(): void
    {
        $service = new AtlasLoopChangeClassPriorService;

        $this->assertTrue($service->thinPriorMaxUncertainty([
            'samples' => 3,
            'enough_samples' => false,
            'hopeless' => false,
            'landing_rate' => 0.0,
            'floor_rate' => 0.15,
        ]));
    }
}
