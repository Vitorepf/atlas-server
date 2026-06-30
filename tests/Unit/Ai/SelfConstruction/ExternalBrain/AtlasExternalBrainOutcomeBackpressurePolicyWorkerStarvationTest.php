<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\SelfConstruction\ExternalBrain;

use App\Services\Ai\SelfConstruction\ExternalBrain\AtlasExternalBrainOutcomeBackpressurePolicy;
use PHPUnit\Framework\TestCase;

final class AtlasExternalBrainOutcomeBackpressurePolicyWorkerStarvationTest extends TestCase
{
    private function policy(): AtlasExternalBrainOutcomeBackpressurePolicy
    {
        return new AtlasExternalBrainOutcomeBackpressurePolicy;
    }

    public function test_recent_no_claimable_task_outcomes_increase_generation_pressure(): void
    {
        $result = $this->policy()->evaluateOutcomePressure([
            'recent_outcomes' => [
                ['outcome' => 'no_claimable_task'],
                ['outcome' => 'no_claimable_task'],
                ['outcome' => 'success'],
            ],
        ]);

        $this->assertSame(AtlasExternalBrainOutcomeBackpressurePolicy::PRESSURE_INCREASE_GENERATION, $result['pressure']);
        $this->assertSame('worker_starvation_feedback', $result['reason']);
        $this->assertSame(2, $result['starvation_count']);
    }

    public function test_recent_no_self_sufficient_task_outcomes_also_increase_generation_pressure(): void
    {
        $result = $this->policy()->evaluateOutcomePressure([
            'recent_outcomes' => [['outcome' => 'no_self_sufficient_task']],
        ]);

        $this->assertSame(AtlasExternalBrainOutcomeBackpressurePolicy::PRESSURE_INCREASE_GENERATION, $result['pressure']);
        $this->assertSame('worker_starvation_feedback', $result['reason']);
    }

    public function test_verification_failed_outcomes_preserve_existing_failure_pressure_behavior(): void
    {
        $result = $this->policy()->evaluateOutcomePressure([
            'recent_outcomes' => [
                ['outcome' => 'verification_failed'],
                ['outcome' => 'verification_failed'],
            ],
        ]);

        $this->assertSame(AtlasExternalBrainOutcomeBackpressurePolicy::PRESSURE_DECREASE_GENERATION, $result['pressure']);
        $this->assertSame('verification_failure_pressure', $result['reason']);
        $this->assertSame(2, $result['verification_failed_count']);
    }

    public function test_starvation_takes_priority_over_verification_failures(): void
    {
        $result = $this->policy()->evaluateOutcomePressure([
            'recent_outcomes' => [
                ['outcome' => 'verification_failed'],
                ['outcome' => 'no_claimable_task'],
            ],
        ]);

        $this->assertSame(AtlasExternalBrainOutcomeBackpressurePolicy::PRESSURE_INCREASE_GENERATION, $result['pressure']);
    }

    public function test_no_signals_returns_neutral_pressure(): void
    {
        $result = $this->policy()->evaluateOutcomePressure(['recent_outcomes' => [['outcome' => 'success']]]);

        $this->assertSame(AtlasExternalBrainOutcomeBackpressurePolicy::PRESSURE_NEUTRAL, $result['pressure']);
    }
}
