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

        foreach (['schema', 'decision', 'reason', 'urgent_override'] as $key) {
            $this->assertArrayHasKey($key, $result);
        }
        $this->assertSame(AtlasExternalBrainQueuePressureGovernor::SCHEMA, $result['schema']);
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
}
