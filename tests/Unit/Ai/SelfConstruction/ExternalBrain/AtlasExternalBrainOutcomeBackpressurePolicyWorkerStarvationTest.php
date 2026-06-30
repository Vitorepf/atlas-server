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

    // ── evaluateWorkerStarvationEscalation() ─────────────────────────────────

    public function test_fresh_starvation_outcome_forces_replenish_or_repair_despite_good_historical_success(): void
    {
        $result = $this->policy()->evaluateWorkerStarvationEscalation([
            'recent_outcomes' => [['outcome' => 'no_claimable_task']],
            'claimable_per_active_worker' => 10.0,
            'historical_success_rate' => 0.95,
        ]);

        $this->assertSame(AtlasExternalBrainOutcomeBackpressurePolicy::ESCALATION_REPLENISH_OR_REPAIR, $result['decision']);
        $this->assertTrue($result['fresh_starvation_outcome']);
    }

    public function test_claimable_per_active_worker_at_or_below_floor_forces_replenish_or_repair(): void
    {
        $result = $this->policy()->evaluateWorkerStarvationEscalation([
            'recent_outcomes' => [],
            'claimable_per_active_worker' => 2.0,
            'historical_success_rate' => 0.95,
        ]);

        $this->assertSame(AtlasExternalBrainOutcomeBackpressurePolicy::ESCALATION_REPLENISH_OR_REPAIR, $result['decision']);
        $this->assertTrue($result['below_worker_floor']);
    }

    public function test_healthy_worker_floor_with_no_starvation_evidence_stays_wait_observe(): void
    {
        $result = $this->policy()->evaluateWorkerStarvationEscalation([
            'recent_outcomes' => [['outcome' => 'success']],
            'claimable_per_active_worker' => 10.0,
        ]);

        $this->assertSame(AtlasExternalBrainOutcomeBackpressurePolicy::ESCALATION_WAIT_OBSERVE, $result['decision']);
    }
}
