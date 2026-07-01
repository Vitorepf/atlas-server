<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\SelfConstruction\ExternalBrain;

use App\Services\Ai\SelfConstruction\ExternalBrain\AtlasExternalBrainQueuePressureGovernor;
use Tests\TestCase;

final class AtlasExternalBrainQueuePressureGovernorTest extends TestCase
{
    private function governor(): AtlasExternalBrainQueuePressureGovernor
    {
        return new AtlasExternalBrainQueuePressureGovernor;
    }

    private function input(array $queueState = [], array $candidate = [], array $context = []): array
    {
        return [
            'queue_state' => array_merge(['claimable_depth' => 5, 'active_leases' => 3], $queueState),
            'candidate'   => array_merge(['leverage_score' => 0.8, 'category' => 'architecture_unlock', 'task_class' => 'normal'], $candidate),
            'context'     => array_merge(['blocked_families' => [], 'worker_pressure' => 'normal'], $context),
        ];
    }

    public function test_schema_constant(): void
    {
        $this->assertSame(
            'atlas.external_brain.queue_pressure_governor.v1',
            AtlasExternalBrainQueuePressureGovernor::SCHEMA,
        );
    }

    public function test_output_has_canonical_keys(): void
    {
        $result = $this->governor()->decide($this->input());

        foreach (['schema', 'decision', 'reason', 'urgent_override', 'under_pressure', 'live_ratio_reason', 'batch_budget'] as $key) {
            $this->assertArrayHasKey($key, $result);
        }
        $this->assertSame(AtlasExternalBrainQueuePressureGovernor::SCHEMA, $result['schema']);

        foreach (['max_tasks', 'minimum_leverage_score', 'evidence_floor', 'reason'] as $bk) {
            $this->assertArrayHasKey($bk, $result['batch_budget']);
        }
    }

    public function test_healthy_queue_high_leverage_enqueues_now(): void
    {
        $result = $this->governor()->decide($this->input(
            queueState: ['claimable_depth' => 5, 'active_leases' => 3],
            candidate:  ['leverage_score' => 0.85, 'category' => 'architecture_unlock', 'task_class' => 'normal'],
            context:    ['blocked_families' => [], 'worker_pressure' => 'normal'],
        ));

        $this->assertSame(AtlasExternalBrainQueuePressureGovernor::DECISION_ENQUEUE_NOW, $result['decision']);
        $this->assertFalse($result['urgent_override']);
    }

    public function test_high_claimable_low_leverage_defers(): void
    {
        // claimable_depth >= HIGH_CLAIMABLE_DEPTH (30), leverage below HIGH_LEVERAGE (0.75)
        $result = $this->governor()->decide($this->input(
            queueState: ['claimable_depth' => 35, 'active_leases' => 3],
            candidate:  ['leverage_score' => 0.50, 'task_class' => 'normal'],
        ));

        $this->assertSame(AtlasExternalBrainQueuePressureGovernor::DECISION_DEFER, $result['decision']);
    }

    public function test_high_claimable_high_leverage_enqueues_now(): void
    {
        // Even with high claimable_depth, high-leverage prerequisites get through
        $result = $this->governor()->decide($this->input(
            queueState: ['claimable_depth' => 40, 'active_leases' => 3],
            candidate:  ['leverage_score' => 0.80, 'task_class' => 'normal'],
        ));

        $this->assertSame(AtlasExternalBrainQueuePressureGovernor::DECISION_ENQUEUE_NOW, $result['decision']);
    }

    public function test_high_active_leases_low_leverage_defers(): void
    {
        // active_leases >= HIGH_ACTIVE_LEASES (15), low leverage
        $result = $this->governor()->decide($this->input(
            queueState: ['claimable_depth' => 5, 'active_leases' => 20],
            candidate:  ['leverage_score' => 0.40, 'task_class' => 'normal'],
        ));

        $this->assertSame(AtlasExternalBrainQueuePressureGovernor::DECISION_DEFER, $result['decision']);
    }

    public function test_both_dimensions_saturated_stops_authoring(): void
    {
        // claimable >= 30 AND active_leases >= 15 → stop
        $result = $this->governor()->decide($this->input(
            queueState: ['claimable_depth' => 35, 'active_leases' => 20],
            candidate:  ['leverage_score' => 0.95, 'task_class' => 'normal'],
        ));

        $this->assertSame(AtlasExternalBrainQueuePressureGovernor::DECISION_STOP, $result['decision']);
    }

    public function test_blocked_family_defers_candidate(): void
    {
        $result = $this->governor()->decide($this->input(
            queueState: ['claimable_depth' => 5, 'active_leases' => 3],
            candidate:  ['leverage_score' => 0.9, 'category' => 'docs_sync', 'task_class' => 'normal'],
            context:    ['blocked_families' => ['docs_sync'], 'worker_pressure' => 'normal'],
        ));

        $this->assertSame(AtlasExternalBrainQueuePressureGovernor::DECISION_DEFER, $result['decision']);
    }

    public function test_high_worker_pressure_medium_leverage_consolidates(): void
    {
        $result = $this->governor()->decide($this->input(
            queueState: ['claimable_depth' => 5, 'active_leases' => 3],
            candidate:  ['leverage_score' => 0.60, 'task_class' => 'normal'],
            context:    ['blocked_families' => [], 'worker_pressure' => 'high'],
        ));

        $this->assertSame(AtlasExternalBrainQueuePressureGovernor::DECISION_CONSOLIDATE, $result['decision']);
    }

    public function test_high_worker_pressure_low_leverage_defers(): void
    {
        $result = $this->governor()->decide($this->input(
            queueState: ['claimable_depth' => 5, 'active_leases' => 3],
            candidate:  ['leverage_score' => 0.30, 'task_class' => 'normal'],
            context:    ['blocked_families' => [], 'worker_pressure' => 'high'],
        ));

        $this->assertSame(AtlasExternalBrainQueuePressureGovernor::DECISION_DEFER, $result['decision']);
    }

    public function test_malformed_class_always_enqueues_now(): void
    {
        // Even with critical pressure, malformed repairs bypass all checks
        $result = $this->governor()->decide($this->input(
            queueState: ['claimable_depth' => 99, 'active_leases' => 99],
            candidate:  ['leverage_score' => 0.0, 'task_class' => 'malformed'],
        ));

        $this->assertSame(AtlasExternalBrainQueuePressureGovernor::DECISION_ENQUEUE_NOW, $result['decision']);
        $this->assertTrue($result['urgent_override']);
    }

    public function test_collision_class_always_enqueues_now(): void
    {
        $result = $this->governor()->decide($this->input(
            queueState: ['claimable_depth' => 50, 'active_leases' => 20],
            candidate:  ['leverage_score' => 0.0, 'task_class' => 'collision'],
            context:    ['blocked_families' => ['architecture_unlock'], 'worker_pressure' => 'high'],
        ));

        $this->assertSame(AtlasExternalBrainQueuePressureGovernor::DECISION_ENQUEUE_NOW, $result['decision']);
        $this->assertTrue($result['urgent_override']);
    }

    public function test_lease_leak_class_always_enqueues_now(): void
    {
        $result = $this->governor()->decide($this->input(
            queueState: ['claimable_depth' => 50, 'active_leases' => 25],
            candidate:  ['leverage_score' => 0.0, 'task_class' => 'lease_leak'],
        ));

        $this->assertSame(AtlasExternalBrainQueuePressureGovernor::DECISION_ENQUEUE_NOW, $result['decision']);
        $this->assertTrue($result['urgent_override']);
    }

    public function test_empty_input_returns_enqueue_now_as_safe_default(): void
    {
        $result = $this->governor()->decide([]);

        $this->assertSame(AtlasExternalBrainQueuePressureGovernor::DECISION_ENQUEUE_NOW, $result['decision']);
        $this->assertFalse($result['urgent_override']);
    }

    public function test_reason_is_always_non_empty_string(): void
    {
        $scenarios = [
            $this->input(),
            $this->input(queueState: ['claimable_depth' => 99, 'active_leases' => 99]),
            $this->input(candidate: ['task_class' => 'malformed', 'leverage_score' => 0.0]),
            $this->input(context: ['worker_pressure' => 'high', 'blocked_families' => []],
                candidate: ['leverage_score' => 0.3, 'task_class' => 'normal']),
        ];

        foreach ($scenarios as $scenario) {
            $result = $this->governor()->decide($scenario);
            $this->assertIsString($result['reason']);
            $this->assertNotEmpty($result['reason']);
        }
    }

    // ── batch_budget values by scenario ──────────────────────────────────────

    public function test_healthy_queue_enqueue_budget_is_max_10(): void
    {
        $result = $this->governor()->decide($this->input());

        $budget = $result['batch_budget'];
        $this->assertSame(10, $budget['max_tasks']);
        $this->assertSame(0.30, $budget['minimum_leverage_score']);
        $this->assertSame(0.20, $budget['evidence_floor']);
    }

    public function test_high_pressure_bypass_enqueue_budget_is_tight_3(): void
    {
        // High claimable + high leverage → enqueue_now under pressure
        $result = $this->governor()->decide($this->input(
            queueState: ['claimable_depth' => 40, 'active_leases' => 3],
            candidate:  ['leverage_score' => 0.80, 'task_class' => 'normal'],
        ));

        $this->assertSame(AtlasExternalBrainQueuePressureGovernor::DECISION_ENQUEUE_NOW, $result['decision']);
        $budget = $result['batch_budget'];
        $this->assertSame(3, $budget['max_tasks']);
        $this->assertSame(0.75, $budget['minimum_leverage_score']);
        $this->assertSame(0.50, $budget['evidence_floor']);
    }

    public function test_defer_budget_is_max_1_high_floor(): void
    {
        $result = $this->governor()->decide($this->input(
            queueState: ['claimable_depth' => 35, 'active_leases' => 3],
            candidate:  ['leverage_score' => 0.50, 'task_class' => 'normal'],
        ));

        $this->assertSame(AtlasExternalBrainQueuePressureGovernor::DECISION_DEFER, $result['decision']);
        $budget = $result['batch_budget'];
        $this->assertSame(1, $budget['max_tasks']);
        $this->assertSame(0.75, $budget['minimum_leverage_score']);
        $this->assertSame(0.60, $budget['evidence_floor']);
    }

    public function test_consolidate_budget_is_max_3_medium_floor(): void
    {
        $result = $this->governor()->decide($this->input(
            candidate: ['leverage_score' => 0.60, 'task_class' => 'normal'],
            context:   ['blocked_families' => [], 'worker_pressure' => 'high'],
        ));

        $this->assertSame(AtlasExternalBrainQueuePressureGovernor::DECISION_CONSOLIDATE, $result['decision']);
        $budget = $result['batch_budget'];
        $this->assertSame(3, $budget['max_tasks']);
        $this->assertSame(0.50, $budget['minimum_leverage_score']);
        $this->assertSame(0.40, $budget['evidence_floor']);
    }

    public function test_stop_budget_is_max_0_full_floor(): void
    {
        $result = $this->governor()->decide($this->input(
            queueState: ['claimable_depth' => 35, 'active_leases' => 20],
            candidate:  ['leverage_score' => 0.95, 'task_class' => 'normal'],
        ));

        $this->assertSame(AtlasExternalBrainQueuePressureGovernor::DECISION_STOP, $result['decision']);
        $budget = $result['batch_budget'];
        $this->assertSame(0, $budget['max_tasks']);
        $this->assertSame(1.0, $budget['minimum_leverage_score']);
        $this->assertSame(1.0, $budget['evidence_floor']);
    }

    // ── live servable-per-worker ratio ─────────────────────────────────────────

    public function test_deep_servable_ratio_far_above_worker_capacity_defers_low_leverage_candidate(): void
    {
        // claimable_depth(20) is BELOW the old static HIGH_CLAIMABLE_DEPTH(30) threshold, but
        // servable_depth(100) / active_leases(10) = 10.0 is far above HIGH_SERVABLE_PER_WORKER_RATIO(5.0).
        $result = $this->governor()->decide($this->input(
            queueState: ['claimable_depth' => 20, 'active_leases' => 10, 'servable_depth' => 100],
            candidate:  ['leverage_score' => 0.40, 'task_class' => 'normal'],
        ));

        $this->assertSame(AtlasExternalBrainQueuePressureGovernor::DECISION_DEFER, $result['decision']);
        $this->assertStringContainsString('servable_per_worker_ratio', $result['reason']);
    }

    public function test_shallow_servable_depth_does_not_trigger_live_ratio_defer(): void
    {
        // servable_depth(5) / active_leases(10) = 0.5, far below the ratio threshold; claimable_depth
        // and active_leases are also both below their static thresholds — healthy queue.
        $result = $this->governor()->decide($this->input(
            queueState: ['claimable_depth' => 20, 'active_leases' => 10, 'servable_depth' => 5],
            candidate:  ['leverage_score' => 0.40, 'task_class' => 'normal'],
        ));

        $this->assertSame(AtlasExternalBrainQueuePressureGovernor::DECISION_ENQUEUE_NOW, $result['decision']);
    }

    public function test_dependency_unlock_score_bypasses_live_ratio_pressure_to_enqueue_now(): void
    {
        $result = $this->governor()->decide($this->input(
            queueState: ['claimable_depth' => 20, 'active_leases' => 10, 'servable_depth' => 100],
            candidate:  ['leverage_score' => 0.10, 'dependency_unlock_score' => 0.90, 'task_class' => 'normal'],
        ));

        $this->assertSame(AtlasExternalBrainQueuePressureGovernor::DECISION_ENQUEUE_NOW, $result['decision']);
        $this->assertFalse($result['urgent_override']);
    }

    public function test_urgent_repair_class_still_enqueues_now_under_high_live_ratio_depth(): void
    {
        $result = $this->governor()->decide($this->input(
            queueState: ['claimable_depth' => 20, 'active_leases' => 10, 'servable_depth' => 200],
            candidate:  ['leverage_score' => 0.0, 'task_class' => 'malformed'],
        ));

        $this->assertSame(AtlasExternalBrainQueuePressureGovernor::DECISION_ENQUEUE_NOW, $result['decision']);
        $this->assertTrue($result['urgent_override']);
    }

    public function test_batch_budget_shrinks_under_live_ratio_pressure_and_names_the_ratio(): void
    {
        $result = $this->governor()->decide($this->input(
            queueState: ['claimable_depth' => 20, 'active_leases' => 10, 'servable_depth' => 100],
            candidate:  ['leverage_score' => 0.40, 'task_class' => 'normal'],
        ));

        $this->assertSame(AtlasExternalBrainQueuePressureGovernor::DECISION_DEFER, $result['decision']);
        $budget = $result['batch_budget'];
        $this->assertSame(1, $budget['max_tasks'], 'batch budget must shrink to 1 under elevated live-ratio pressure');
        $this->assertStringContainsString('servable_per_worker_ratio', $budget['reason']);
    }

    public function test_deep_servable_ratio_sets_under_pressure_and_live_ratio_reason(): void
    {
        $result = $this->governor()->decide($this->input(
            queueState: ['claimable_depth' => 20, 'active_leases' => 10, 'servable_depth' => 100],
            candidate:  ['leverage_score' => 0.40, 'task_class' => 'normal'],
        ));

        $this->assertSame(AtlasExternalBrainQueuePressureGovernor::DECISION_DEFER, $result['decision']);
        $this->assertTrue($result['under_pressure']);
        $this->assertSame('10', $result['live_ratio_reason']);
    }

    public function test_healthy_queue_has_no_live_ratio_reason(): void
    {
        $result = $this->governor()->decide($this->input());

        $this->assertFalse($result['under_pressure']);
        $this->assertNull($result['live_ratio_reason']);
    }

    public function test_urgent_repair_classes_bypass_pressure_for_malformed_collision_and_lease_leak(): void
    {
        foreach (['malformed', 'collision', 'lease_leak'] as $taskClass) {
            $result = $this->governor()->decide($this->input(
                queueState: ['claimable_depth' => 99, 'active_leases' => 99, 'servable_depth' => 999],
                candidate:  ['leverage_score' => 0.0, 'task_class' => $taskClass],
            ));

            $this->assertSame(AtlasExternalBrainQueuePressureGovernor::DECISION_ENQUEUE_NOW, $result['decision'], "task_class={$taskClass} must enqueue_now");
            $this->assertTrue($result['urgent_override'], "task_class={$taskClass} must set urgent_override");
        }
    }

    public function test_urgent_repair_budget_has_no_floors(): void
    {
        $result = $this->governor()->decide($this->input(
            queueState: ['claimable_depth' => 99, 'active_leases' => 99],
            candidate:  ['leverage_score' => 0.0, 'task_class' => 'malformed'],
        ));

        $this->assertTrue($result['urgent_override']);
        $budget = $result['batch_budget'];
        $this->assertSame(1, $budget['max_tasks']);
        $this->assertSame(0.0, $budget['minimum_leverage_score']);
        $this->assertSame(0.0, $budget['evidence_floor']);
    }

    // ── Worker starvation: servable-per-worker below floor + replenishing candidate ──

    public function test_worker_starvation_below_floor_with_replenishing_candidate_enqueues_bounded_batch(): void
    {
        $result = $this->governor()->decide($this->input(
            queueState: ['claimable_depth' => 5, 'active_leases' => 4, 'servable_depth' => 2, 'worker_floor' => 1.0],
            candidate:  ['leverage_score' => 0.1, 'replenishes_worker_capacity' => true],
        ));

        $this->assertSame(AtlasExternalBrainQueuePressureGovernor::DECISION_ENQUEUE_NOW, $result['decision']);
        $this->assertTrue($result['under_pressure']);
        $this->assertLessThanOrEqual(3, $result['batch_budget']['max_tasks']);
    }

    public function test_worker_starvation_below_floor_without_replenishing_candidate_does_not_bypass(): void
    {
        $result = $this->governor()->decide($this->input(
            queueState: ['claimable_depth' => 5, 'active_leases' => 4, 'servable_depth' => 2, 'worker_floor' => 1.0],
            candidate:  ['leverage_score' => 0.1, 'replenishes_worker_capacity' => false],
        ));

        $this->assertNotSame('worker starvation', substr($result['reason'], 0, 16));
    }

    public function test_worker_floor_zero_never_triggers_starvation_bypass(): void
    {
        $result = $this->governor()->decide($this->input(
            queueState: ['claimable_depth' => 5, 'active_leases' => 4, 'servable_depth' => 2, 'worker_floor' => 0.0],
            candidate:  ['leverage_score' => 0.1, 'replenishes_worker_capacity' => true],
        ));

        $this->assertStringNotContainsString('worker starvation', $result['reason']);
    }

    // ── Sufficient depth defers low-leverage non-repair candidates ───────────

    public function test_sufficient_depth_defers_low_leverage_non_repair_candidate(): void
    {
        $result = $this->governor()->decide($this->input(
            queueState: ['claimable_depth' => 35, 'active_leases' => 3, 'servable_depth' => 5],
            candidate:  ['leverage_score' => 0.2, 'task_class' => 'normal'],
        ));

        $this->assertNotSame(AtlasExternalBrainQueuePressureGovernor::DECISION_ENQUEUE_NOW, $result['decision']);
    }

    // ── high_leverage_escape ──────────────────────────────────────────────────

    public function test_high_leverage_escape_true_when_high_leverage_bypasses_pressure(): void
    {
        $result = $this->governor()->decide($this->input(
            queueState: ['claimable_depth' => 40, 'active_leases' => 3],
            candidate:  ['leverage_score' => 0.80, 'task_class' => 'normal'],
        ));

        $this->assertSame(AtlasExternalBrainQueuePressureGovernor::DECISION_ENQUEUE_NOW, $result['decision']);
        $this->assertTrue($result['high_leverage_escape']);
    }

    public function test_high_leverage_escape_true_when_dependency_unlock_bypasses_pressure(): void
    {
        $result = $this->governor()->decide($this->input(
            queueState: ['claimable_depth' => 20, 'active_leases' => 10, 'servable_depth' => 100],
            candidate:  ['leverage_score' => 0.10, 'dependency_unlock_score' => 0.90, 'task_class' => 'normal'],
        ));

        $this->assertSame(AtlasExternalBrainQueuePressureGovernor::DECISION_ENQUEUE_NOW, $result['decision']);
        $this->assertTrue($result['high_leverage_escape']);
    }

    public function test_high_leverage_escape_false_on_healthy_queue(): void
    {
        $result = $this->governor()->decide($this->input());

        $this->assertFalse($result['high_leverage_escape']);
    }

    public function test_high_leverage_escape_false_on_low_leverage_defer_with_saturation_reason(): void
    {
        $result = $this->governor()->decide($this->input(
            queueState: ['claimable_depth' => 35, 'active_leases' => 3],
            candidate:  ['leverage_score' => 0.50, 'task_class' => 'normal'],
        ));

        $this->assertSame(AtlasExternalBrainQueuePressureGovernor::DECISION_DEFER, $result['decision']);
        $this->assertFalse($result['high_leverage_escape']);
        $this->assertStringContainsString('saturation_blocked_low_leverage', $result['reason']);
    }

    public function test_high_leverage_escape_false_on_urgent_override(): void
    {
        $result = $this->governor()->decide($this->input(
            queueState: ['claimable_depth' => 99, 'active_leases' => 99],
            candidate:  ['leverage_score' => 0.0, 'task_class' => 'malformed'],
        ));

        $this->assertFalse($result['high_leverage_escape']);
    }

    // ── new AC: completion_slope pressure ─────────────────────────────────────

    public function test_low_completion_slope_with_deep_servable_per_worker_defers_medium_leverage_candidate(): void
    {
        $result = $this->governor()->decide($this->input(
            queueState: ['claimable_depth' => 5, 'active_leases' => 10, 'servable_depth' => 30],
            candidate:  ['leverage_score' => 0.50, 'task_class' => 'normal'],
            context:    ['completion_slope' => 0.10],
        ));

        $this->assertSame(AtlasExternalBrainQueuePressureGovernor::DECISION_DEFER, $result['decision']);
    }

    public function test_urgent_repair_classes_bypass_completion_slope_pressure(): void
    {
        $result = $this->governor()->decide($this->input(
            queueState: ['claimable_depth' => 5, 'active_leases' => 10, 'servable_depth' => 30],
            candidate:  ['leverage_score' => 0.0, 'task_class' => 'malformed'],
            context:    ['completion_slope' => 0.05],
        ));

        $this->assertSame(AtlasExternalBrainQueuePressureGovernor::DECISION_ENQUEUE_NOW, $result['decision']);
        $this->assertTrue($result['urgent_override']);
    }

    public function test_high_dependency_unlock_score_can_enqueue_under_completion_slope_pressure(): void
    {
        $result = $this->governor()->decide($this->input(
            queueState: ['claimable_depth' => 5, 'active_leases' => 10, 'servable_depth' => 30],
            candidate:  ['leverage_score' => 0.10, 'dependency_unlock_score' => 0.90, 'task_class' => 'normal'],
            context:    ['completion_slope' => 0.10],
        ));

        $this->assertSame(AtlasExternalBrainQueuePressureGovernor::DECISION_ENQUEUE_NOW, $result['decision']);
        $this->assertTrue($result['high_leverage_escape']);
    }

    public function test_healthy_completion_slope_does_not_add_pressure(): void
    {
        $result = $this->governor()->decide($this->input(
            queueState: ['claimable_depth' => 5, 'active_leases' => 10, 'servable_depth' => 30],
            candidate:  ['leverage_score' => 0.50, 'task_class' => 'normal'],
            context:    ['completion_slope' => 0.90],
        ));

        $this->assertSame(AtlasExternalBrainQueuePressureGovernor::DECISION_ENQUEUE_NOW, $result['decision']);
    }

    // ── evaluateWorkerFloor: comfortable queue never returns a passive hold ────

    public function test_comfortable_worker_floor_returns_continue_search_for_high_leverage_with_positive_max_tasks(): void
    {
        $result = $this->governor()->evaluateWorkerFloor([
            'malformed_count' => 0,
            'claimable_per_active_worker' => 10.0,
            'active_leases' => 3,
        ]);

        $this->assertSame(AtlasExternalBrainQueuePressureGovernor::ACTION_CONTINUE_SEARCH_FOR_HIGH_LEVERAGE, $result['action']);
        $this->assertGreaterThan(0, $result['max_tasks']);
    }

    public function test_malformed_count_still_returns_hold_despite_comfortable_buffer(): void
    {
        $result = $this->governor()->evaluateWorkerFloor([
            'malformed_count' => 4,
            'claimable_per_active_worker' => 10.0,
            'active_leases' => 3,
        ]);

        $this->assertSame(AtlasExternalBrainQueuePressureGovernor::ACTION_HOLD, $result['action']);
        $this->assertSame(0, $result['max_tasks']);
    }

    public function test_thin_worker_buffer_still_requests_bounded_batch_not_continue_search(): void
    {
        // Starvation-driven request must remain distinct from the comfortable-queue signal.
        $result = $this->governor()->evaluateWorkerFloor([
            'malformed_count' => 0,
            'claimable_per_active_worker' => 1.0,
            'active_leases' => 3,
        ]);

        $this->assertSame(AtlasExternalBrainQueuePressureGovernor::ACTION_REQUEST_BOUNDED_BATCH, $result['action']);
    }

    // ── decide(): stop is reserved for true saturated queue AND lease pressure ─

    public function test_decide_does_not_stop_on_sufficient_queue_depth_alone(): void
    {
        // claimable_depth is high but active_leases is low — not both dimensions saturated.
        $result = $this->governor()->decide($this->input(
            queueState: ['claimable_depth' => 35, 'active_leases' => 3],
            candidate:  ['leverage_score' => 0.50, 'task_class' => 'normal'],
        ));

        $this->assertNotSame(AtlasExternalBrainQueuePressureGovernor::DECISION_STOP, $result['decision']);
    }

    public function test_decide_stops_only_when_queue_and_lease_pressure_are_both_critical(): void
    {
        $result = $this->governor()->decide($this->input(
            queueState: ['claimable_depth' => 35, 'active_leases' => 20],
            candidate:  ['leverage_score' => 0.50, 'task_class' => 'normal'],
        ));

        $this->assertSame(AtlasExternalBrainQueuePressureGovernor::DECISION_STOP, $result['decision']);
    }
}
