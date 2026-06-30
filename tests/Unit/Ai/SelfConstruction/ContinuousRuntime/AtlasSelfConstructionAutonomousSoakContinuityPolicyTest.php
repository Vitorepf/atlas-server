<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\SelfConstruction\ContinuousRuntime;

use App\Services\Ai\SelfConstruction\ContinuousRuntime\AtlasSelfConstructionAutonomousSoakContinuityPolicy;
use Tests\TestCase;

final class AtlasSelfConstructionAutonomousSoakContinuityPolicyTest extends TestCase
{
    private function policy(): AtlasSelfConstructionAutonomousSoakContinuityPolicy
    {
        return new AtlasSelfConstructionAutonomousSoakContinuityPolicy();
    }

    private function healthyInput(): array
    {
        return [
            'claimable_depth'         => 20,
            'claimable_floor'         => 5,
            'worker_contention_rate'  => 0.20,
            'queue_saturation'        => 0.30,
            'evidence_freshness_seconds' => 100,
            'repeated_failure_count'  => 0,
            'safety_defect_detected'  => false,
        ];
    }

    // ── schema + structure ────────────────────────────────────────────────────

    public function test_schema_present(): void
    {
        $result = $this->policy()->evaluate([]);

        $this->assertSame(AtlasSelfConstructionAutonomousSoakContinuityPolicy::SCHEMA, $result['schema']);
    }

    public function test_output_has_required_keys(): void
    {
        $result = $this->policy()->evaluate([]);

        foreach (['schema', 'action', 'safety_reasons', 'replenishment_needed', 'worker_pressure', 'evidence_freshness_state'] as $k) {
            $this->assertArrayHasKey($k, $result);
        }
    }

    // ── continue ──────────────────────────────────────────────────────────────

    public function test_continue_when_all_healthy(): void
    {
        $result = $this->policy()->evaluate($this->healthyInput());

        $this->assertSame(AtlasSelfConstructionAutonomousSoakContinuityPolicy::ACTION_CONTINUE, $result['action']);
        $this->assertSame([], $result['safety_reasons']);
    }

    // ── pause ─────────────────────────────────────────────────────────────────

    public function test_pause_when_safety_defect_detected(): void
    {
        $result = $this->policy()->evaluate(array_merge($this->healthyInput(), [
            'safety_defect_detected' => true,
        ]));

        $this->assertSame(AtlasSelfConstructionAutonomousSoakContinuityPolicy::ACTION_PAUSE, $result['action']);
        $this->assertNotEmpty($result['safety_reasons']);
        $this->assertStringContainsString('safety_defect_detected', implode(' ', $result['safety_reasons']));
    }

    public function test_pause_when_repeated_failures_at_threshold(): void
    {
        $result = $this->policy()->evaluate(array_merge($this->healthyInput(), [
            'repeated_failure_count'     => 3,
            'repeated_failure_threshold' => 3,
        ]));

        $this->assertSame(AtlasSelfConstructionAutonomousSoakContinuityPolicy::ACTION_PAUSE, $result['action']);
        $this->assertStringContainsString('repeated_failure_count', implode(' ', $result['safety_reasons']));
    }

    public function test_pause_takes_priority_over_replenish(): void
    {
        $result = $this->policy()->evaluate([
            'claimable_depth'        => 0,
            'claimable_floor'        => 5,
            'safety_defect_detected' => true,
        ]);

        $this->assertSame(AtlasSelfConstructionAutonomousSoakContinuityPolicy::ACTION_PAUSE, $result['action']);
    }

    public function test_pause_takes_priority_over_slow_down(): void
    {
        $result = $this->policy()->evaluate([
            'claimable_depth'        => 20,
            'queue_saturation'       => 0.99,  // would slow_down
            'safety_defect_detected' => true,
        ]);

        $this->assertSame(AtlasSelfConstructionAutonomousSoakContinuityPolicy::ACTION_PAUSE, $result['action']);
    }

    // ── replenish ─────────────────────────────────────────────────────────────

    public function test_replenish_when_claimable_below_floor(): void
    {
        $result = $this->policy()->evaluate(array_merge($this->healthyInput(), [
            'claimable_depth' => 4,
            'claimable_floor' => 5,
        ]));

        $this->assertSame(AtlasSelfConstructionAutonomousSoakContinuityPolicy::ACTION_REPLENISH, $result['action']);
        $this->assertTrue($result['replenishment_needed']);
    }

    public function test_no_replenish_when_depth_at_floor(): void
    {
        $result = $this->policy()->evaluate(array_merge($this->healthyInput(), [
            'claimable_depth' => 5,
            'claimable_floor' => 5,
        ]));

        $this->assertNotSame(AtlasSelfConstructionAutonomousSoakContinuityPolicy::ACTION_REPLENISH, $result['action']);
        $this->assertFalse($result['replenishment_needed']);
    }

    public function test_replenish_takes_priority_over_slow_down(): void
    {
        $result = $this->policy()->evaluate([
            'claimable_depth'        => 2,
            'claimable_floor'        => 5,
            'queue_saturation'       => 0.99,  // would slow_down
            'safety_defect_detected' => false,
        ]);

        $this->assertSame(AtlasSelfConstructionAutonomousSoakContinuityPolicy::ACTION_REPLENISH, $result['action']);
    }

    // ── slow_down ─────────────────────────────────────────────────────────────

    public function test_slow_down_when_queue_saturation_at_threshold(): void
    {
        $result = $this->policy()->evaluate(array_merge($this->healthyInput(), [
            'queue_saturation'           => 0.85,
            'queue_saturation_threshold' => 0.85,
        ]));

        $this->assertSame(AtlasSelfConstructionAutonomousSoakContinuityPolicy::ACTION_SLOW_DOWN, $result['action']);
    }

    public function test_slow_down_when_worker_contention_at_threshold(): void
    {
        $result = $this->policy()->evaluate(array_merge($this->healthyInput(), [
            'worker_contention_rate'      => 0.70,
            'worker_contention_threshold' => 0.70,
        ]));

        $this->assertSame(AtlasSelfConstructionAutonomousSoakContinuityPolicy::ACTION_SLOW_DOWN, $result['action']);
    }

    // ── replenishment_needed (independent of action) ──────────────────────────

    public function test_replenishment_needed_true_even_when_action_is_pause(): void
    {
        $result = $this->policy()->evaluate([
            'claimable_depth'        => 2,
            'claimable_floor'        => 5,
            'safety_defect_detected' => true,
        ]);

        $this->assertSame(AtlasSelfConstructionAutonomousSoakContinuityPolicy::ACTION_PAUSE, $result['action']);
        $this->assertTrue($result['replenishment_needed']);
    }

    public function test_replenishment_needed_false_when_depth_ample(): void
    {
        $result = $this->policy()->evaluate($this->healthyInput());

        $this->assertFalse($result['replenishment_needed']);
    }

    // ── worker_pressure ───────────────────────────────────────────────────────

    public function test_worker_pressure_high_at_threshold(): void
    {
        $result = $this->policy()->evaluate(array_merge($this->healthyInput(), [
            'worker_contention_rate'      => 0.70,
            'worker_contention_threshold' => 0.70,
        ]));

        $this->assertSame(AtlasSelfConstructionAutonomousSoakContinuityPolicy::PRESSURE_HIGH, $result['worker_pressure']);
    }

    public function test_worker_pressure_medium_when_contention_between_50_and_threshold(): void
    {
        $result = $this->policy()->evaluate(array_merge($this->healthyInput(), [
            'worker_contention_rate'      => 0.60,
            'worker_contention_threshold' => 0.70,
        ]));

        $this->assertSame(AtlasSelfConstructionAutonomousSoakContinuityPolicy::PRESSURE_MEDIUM, $result['worker_pressure']);
    }

    public function test_worker_pressure_normal_when_contention_low(): void
    {
        $result = $this->policy()->evaluate($this->healthyInput());

        $this->assertSame(AtlasSelfConstructionAutonomousSoakContinuityPolicy::PRESSURE_NORMAL, $result['worker_pressure']);
    }

    // ── evidence_freshness_state ──────────────────────────────────────────────

    public function test_evidence_freshness_stale_when_age_exceeds_threshold(): void
    {
        $result = $this->policy()->evaluate(array_merge($this->healthyInput(), [
            'evidence_freshness_seconds'  => 3601,
            'evidence_freshness_threshold' => 3600,
        ]));

        $this->assertSame(AtlasSelfConstructionAutonomousSoakContinuityPolicy::FRESHNESS_STALE, $result['evidence_freshness_state']);
    }

    public function test_evidence_freshness_fresh_at_threshold(): void
    {
        $result = $this->policy()->evaluate(array_merge($this->healthyInput(), [
            'evidence_freshness_seconds'  => 3600,
            'evidence_freshness_threshold' => 3600,
        ]));

        $this->assertSame(AtlasSelfConstructionAutonomousSoakContinuityPolicy::FRESHNESS_FRESH, $result['evidence_freshness_state']);
    }

    // ── no operator dependency ────────────────────────────────────────────────

    public function test_steady_state_continue_requires_no_special_inputs(): void
    {
        // Minimal input — just enough claimable work; should continue without operator.
        $result = $this->policy()->evaluate(['claimable_depth' => 20]);

        $this->assertSame(AtlasSelfConstructionAutonomousSoakContinuityPolicy::ACTION_CONTINUE, $result['action']);
    }

    // ── determinism ──────────────────────────────────────────────────────────

    public function test_identical_input_yields_identical_output(): void
    {
        $input = $this->healthyInput();

        $this->assertSame($this->policy()->evaluate($input), $this->policy()->evaluate($input));
    }
}
