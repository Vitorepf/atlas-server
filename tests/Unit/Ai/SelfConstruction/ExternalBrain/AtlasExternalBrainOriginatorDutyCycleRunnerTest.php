<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\SelfConstruction\ExternalBrain;

use App\Services\Ai\SelfConstruction\ExternalBrain\AtlasExternalBrainOriginatorDutyCycleRunner;
use Tests\TestCase;

/**
 * Proves AtlasExternalBrainOriginatorDutyCycleRunner composes the three
 * organs: coverage bridge routes, duty-cycle contract enforces, stop-condition gate decides.
 *
 * AC1: an over-running duty cycle is stopped by the gate.
 * AC2: a starved cycle routes coverage to the starved path.
 */
final class AtlasExternalBrainOriginatorDutyCycleRunnerTest extends TestCase
{
    private function runner(): AtlasExternalBrainOriginatorDutyCycleRunner
    {
        return new AtlasExternalBrainOriginatorDutyCycleRunner;
    }

    private function healthyCoverageVerdict(): array
    {
        return [
            'route_to_gaps' => false,
            'reasons' => ['surface_healthy'],
            'roadmap_coverage' => ['next_batch_should_target' => ['target-theme']],
            'surface_saturation' => ['verdict' => 'deepen'],
            'backlog_aging' => [],
            'queue_health' => ['malformed_rate' => 0.0, 'poison_rate' => 0.0],
        ];
    }

    private function starvedCoverageVerdict(): array
    {
        return [
            'route_to_gaps' => true,
            'reasons' => ['surface_saturated', 'coverage_uneven'],
            'roadmap_coverage' => ['next_batch_should_target' => ['undercovered-theme']],
            'surface_saturation' => ['verdict' => 'saturated'],
            'backlog_aging' => [],
            'queue_health' => ['malformed_rate' => 0.0, 'poison_rate' => 0.0],
        ];
    }

    private function activeDutyCycleFacts(): array
    {
        return [
            'mission_active' => true,
            'quota_remaining' => 10,
            'quota_complete' => false,
            'brain_enabled' => true,
            'claimable_depth' => 8,
            'servable_now' => 5,
            'active_workers' => 2,
            'replenish_action' => 'schedule',
        ];
    }

    private function overrunStopCondition(): array
    {
        return [
            'quality_target_reached' => false,
            'quality_target_evidence' => [],
            'all_escalation_modes_tried' => true,
            'escalation_evidence' => ['runtime', 'integration'],
            'queue_pressure_high' => false,
            'task_urgency' => 'normal',
            'no_high_leverage_surface_remaining' => true,
            'first_pass_only' => false,
            'open_surfaces_remaining' => 0,
            'remaining_escalation_modes' => 0,
            'deduplication_confirmed' => true,
        ];
    }

    private function healthyStopCondition(): array
    {
        return [
            'quality_target_reached' => false,
            'quality_target_evidence' => [],
            'all_escalation_modes_tried' => false,
            'escalation_evidence' => [],
            'queue_pressure_high' => false,
            'task_urgency' => 'normal',
            'no_high_leverage_surface_remaining' => false,
            'first_pass_only' => false,
            'open_surfaces_remaining' => 3,
            'remaining_escalation_modes' => 2,
            'deduplication_confirmed' => false,
        ];
    }

    // ── AC: over-running duty cycle stopped by gate ────────────────────────

    public function test_over_run_duty_cycle_is_stopped(): void
    {
        $result = $this->runner()->govern(
            $this->healthyCoverageVerdict(),
            $this->activeDutyCycleFacts(),
            $this->overrunStopCondition(),
        );

        $this->assertTrue($result['should_stop']);
        $this->assertSame('stopped', $result['status']);
        $this->assertFalse($result['should_originate']);
        $this->assertContains('origination_stopped:honest_stop', $result['blockers']);
    }

    // ── AC: starved cycle routes coverage to starved path ───────────────────

    public function test_starved_cycle_routes_coverage_to_gaps(): void
    {
        $result = $this->runner()->govern(
            $this->starvedCoverageVerdict(),
            $this->activeDutyCycleFacts(),
            $this->healthyStopCondition(),
        );

        $this->assertFalse($result['should_stop']);
        $this->assertSame('active', $result['status']);
        $this->assertTrue($result['should_originate']);
        $this->assertNotEmpty($result['routing']['target_gaps']);
        $this->assertContains('undercovered-theme', $result['routing']['target_gaps']);
    }

    // ── healthy cycle continues ────────────────────────────────────────────

    public function test_healthy_cycle_keeps_originating(): void
    {
        $result = $this->runner()->govern(
            $this->healthyCoverageVerdict(),
            $this->activeDutyCycleFacts(),
            $this->healthyStopCondition(),
        );

        $this->assertFalse($result['should_stop']);
        $this->assertSame('active', $result['status']);
        // No gaps to route to, so action is research or consolidate
        $this->assertContains($result['action'], ['research', 'consolidate']);
    }

    // ── output keys ────────────────────────────────────────────────────────

    public function test_output_has_required_keys(): void
    {
        $result = $this->runner()->govern(
            $this->healthyCoverageVerdict(),
            $this->activeDutyCycleFacts(),
            $this->healthyStopCondition(),
        );

        foreach (['schema', 'status', 'routing', 'duty_cycle', 'stop_condition', 'should_stop', 'should_originate', 'action', 'blockers'] as $key) {
            $this->assertArrayHasKey($key, $result, "Missing key: {$key}");
        }
    }
}
