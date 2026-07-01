<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\SelfConstruction\E2E;

use App\Services\Ai\SelfConstruction\E2E\AtlasSelfConstructionSelfHealingQueueRepairPlan;
use PHPUnit\Framework\TestCase;

/**
 * Proves AtlasSelfConstructionSelfHealingQueueRepairPlan: repeated_returns >= QUARANTINE_THRESHOLD ⇒
 * quarantine (poison re-serve prevented); missing_dependency ⇒ dependency_rewrite; clean queue ⇒
 * empty actions; emergency_kind ⇒ operator_visible with action='visibility_only' (operator NOT
 * automated); malformed packet ⇒ template_repair.
 */
final class AtlasSelfConstructionSelfHealingQueueRepairPlanTest extends TestCase
{
    public function test_poison_re_serve_prevention_quarantine_after_threshold(): void
    {
        $r = (new AtlasSelfConstructionSelfHealingQueueRepairPlan)->plan([
            ['packet_id' => 'p-poison', 'repeated_returns' => 5],
        ]);
        $this->assertCount(1, $r['actions']);
        $this->assertSame(AtlasSelfConstructionSelfHealingQueueRepairPlan::BUCKET_QUARANTINE, $r['actions'][0]['bucket']);
        $this->assertSame('cancel_until_respec', $r['actions'][0]['action']);
    }

    public function test_missing_dependency_rewrites_packet(): void
    {
        $r = (new AtlasSelfConstructionSelfHealingQueueRepairPlan)->plan([
            ['packet_id' => 'p-dep', 'missing_dependency' => 'svc-A'],
        ]);
        $this->assertSame(AtlasSelfConstructionSelfHealingQueueRepairPlan::BUCKET_DEPENDENCY, $r['actions'][0]['bucket']);
        $this->assertSame('enqueue_dependency_rewrite_packet', $r['actions'][0]['action']);
    }

    public function test_clean_queue_with_no_signals_emits_no_actions(): void
    {
        $r = (new AtlasSelfConstructionSelfHealingQueueRepairPlan)->plan([
            ['packet_id' => 'p-clean'],
        ]);
        $this->assertSame([], $r['actions']);
    }

    public function test_emergency_kind_yields_operator_visible_visibility_only(): void
    {
        $r = (new AtlasSelfConstructionSelfHealingQueueRepairPlan)->plan([
            ['packet_id' => 'p-em', 'emergency_kind' => 'constitution_edit_attempt'],
        ]);
        $this->assertSame(AtlasSelfConstructionSelfHealingQueueRepairPlan::BUCKET_OPERATOR_VISIBLE, $r['actions'][0]['bucket']);
        $this->assertSame('visibility_only', $r['actions'][0]['action']);
    }

    public function test_malformed_packet_routes_to_template_repair(): void
    {
        $r = (new AtlasSelfConstructionSelfHealingQueueRepairPlan)->plan([
            ['packet_id' => 'p-mal', 'malformed' => true],
        ]);
        $this->assertSame(AtlasSelfConstructionSelfHealingQueueRepairPlan::BUCKET_TEMPLATE, $r['actions'][0]['bucket']);
        $this->assertSame('enqueue_template_repair_packet', $r['actions'][0]['action']);
    }

    public function test_scope_repaired_packet_emits_no_action_observed(): void
    {
        $r = (new AtlasSelfConstructionSelfHealingQueueRepairPlan)->plan([
            ['packet_id' => 'p-sr', 'scope_repaired' => true],
        ]);
        $this->assertSame(AtlasSelfConstructionSelfHealingQueueRepairPlan::BUCKET_SCOPE_REPAIRED, $r['actions'][0]['bucket']);
        $this->assertSame('no_action', $r['actions'][0]['action']);
    }

    public function test_low_servable_depth_produces_atlas_native_queue_top_up_action(): void
    {
        $r = (new AtlasSelfConstructionSelfHealingQueueRepairPlan)->plan([
            ['packet_id' => 'p-depth', 'low_servable_depth' => true],
        ]);
        $this->assertCount(1, $r['actions']);
        $this->assertSame(AtlasSelfConstructionSelfHealingQueueRepairPlan::BUCKET_TOP_UP, $r['actions'][0]['bucket']);
        $this->assertSame('enqueue_top_up_packet', $r['actions'][0]['action']);
        $this->assertStringContainsString('low_servable_depth', $r['actions'][0]['reason']);
    }

    public function test_repeated_give_back_family_at_threshold_produces_respec_action(): void
    {
        $r = (new AtlasSelfConstructionSelfHealingQueueRepairPlan)->plan([
            ['packet_id' => 'p-gb', 'give_back_family' => 'refactor', 'repeated_give_backs' => AtlasSelfConstructionSelfHealingQueueRepairPlan::GIVE_BACK_RESPEC_THRESHOLD],
        ]);
        $this->assertCount(1, $r['actions']);
        $this->assertSame(AtlasSelfConstructionSelfHealingQueueRepairPlan::BUCKET_RESPEC, $r['actions'][0]['bucket']);
        $this->assertSame('enqueue_respec_packet', $r['actions'][0]['action']);
        $this->assertStringContainsString('refactor', $r['actions'][0]['reason']);
    }

    public function test_emergency_kind_has_highest_precedence_over_top_up_and_respec(): void
    {
        $r = (new AtlasSelfConstructionSelfHealingQueueRepairPlan)->plan([
            ['packet_id' => 'p-both', 'emergency_kind' => 'constitution_edit_attempt', 'low_servable_depth' => true, 'give_back_family' => 'refactor', 'repeated_give_backs' => 10],
        ]);
        $this->assertCount(1, $r['actions']);
        $this->assertSame(AtlasSelfConstructionSelfHealingQueueRepairPlan::BUCKET_OPERATOR_VISIBLE, $r['actions'][0]['bucket']);
        $this->assertSame('visibility_only', $r['actions'][0]['action']);
    }

    public function test_actions_sorted_byte_stably_by_bucket_then_packet_id(): void
    {
        $r = (new AtlasSelfConstructionSelfHealingQueueRepairPlan)->plan([
            ['packet_id' => 'p-z', 'repeated_returns' => 5],
            ['packet_id' => 'p-a', 'repeated_returns' => 5],
            ['packet_id' => 'p-m', 'malformed' => true],
        ]);
        $ids = array_column($r['actions'], 'packet_id');
        $this->assertSame(['p-a', 'p-z', 'p-m'], $ids); // quarantine bucket sorted first, then template
    }

    // ── worker-floor-driven recoverable-family repair (AC) ──────────────────────

    public function test_low_worker_floor_with_recoverable_family_and_safe_shape_produces_respec(): void
    {
        $r = (new AtlasSelfConstructionSelfHealingQueueRepairPlan)->plan([
            [
                'packet_id' => 'p-1',
                'recoverable_blocked_family' => 'missing_scope_fields',
                'has_implementation_scope' => true,
                'has_runnable_acceptance' => true,
            ],
        ], ['claimable_per_active_worker' => 1.5]);

        $this->assertSame(AtlasSelfConstructionSelfHealingQueueRepairPlan::BUCKET_RESPEC, $r['actions'][0]['bucket']);
        $this->assertSame('enqueue_respec_packet', $r['actions'][0]['action']);
    }

    public function test_low_worker_floor_prefer_top_up_produces_top_up_bucket(): void
    {
        $r = (new AtlasSelfConstructionSelfHealingQueueRepairPlan)->plan([
            [
                'packet_id' => 'p-1',
                'recoverable_blocked_family' => 'missing_scope_fields',
                'has_implementation_scope' => true,
                'has_runnable_acceptance' => true,
                'prefer_top_up' => true,
            ],
        ], ['claimable_per_active_worker' => 1.5]);

        $this->assertSame(AtlasSelfConstructionSelfHealingQueueRepairPlan::BUCKET_TOP_UP, $r['actions'][0]['bucket']);
    }

    public function test_low_worker_floor_without_implementation_scope_refuses_repair(): void
    {
        $r = (new AtlasSelfConstructionSelfHealingQueueRepairPlan)->plan([
            [
                'packet_id' => 'p-1',
                'recoverable_blocked_family' => 'missing_scope_fields',
                'has_implementation_scope' => false,
                'has_runnable_acceptance' => true,
            ],
        ], ['claimable_per_active_worker' => 1.5]);

        $this->assertSame(AtlasSelfConstructionSelfHealingQueueRepairPlan::BUCKET_REFUSED, $r['actions'][0]['bucket']);
        $this->assertSame('refuse_repair', $r['actions'][0]['action']);
    }

    public function test_low_worker_floor_without_runnable_acceptance_refuses_repair(): void
    {
        $r = (new AtlasSelfConstructionSelfHealingQueueRepairPlan)->plan([
            [
                'packet_id' => 'p-1',
                'recoverable_blocked_family' => 'missing_scope_fields',
                'has_implementation_scope' => true,
                'has_runnable_acceptance' => false,
            ],
        ], ['claimable_per_active_worker' => 1.5]);

        $this->assertSame(AtlasSelfConstructionSelfHealingQueueRepairPlan::BUCKET_REFUSED, $r['actions'][0]['bucket']);
    }

    public function test_comfortable_worker_floor_does_not_trigger_recoverable_family_repair(): void
    {
        $r = (new AtlasSelfConstructionSelfHealingQueueRepairPlan)->plan([
            [
                'packet_id' => 'p-1',
                'recoverable_blocked_family' => 'missing_scope_fields',
                'has_implementation_scope' => true,
                'has_runnable_acceptance' => true,
            ],
        ], ['claimable_per_active_worker' => 10.0]);

        $this->assertSame([], $r['actions']);
    }

    public function test_emergency_still_takes_precedence_over_worker_floor_repair(): void
    {
        $r = (new AtlasSelfConstructionSelfHealingQueueRepairPlan)->plan([
            [
                'packet_id' => 'p-1',
                'emergency_kind' => 'constitution_edit',
                'recoverable_blocked_family' => 'missing_scope_fields',
                'has_implementation_scope' => true,
                'has_runnable_acceptance' => true,
            ],
        ], ['claimable_per_active_worker' => 1.0]);

        $this->assertSame(AtlasSelfConstructionSelfHealingQueueRepairPlan::BUCKET_OPERATOR_VISIBLE, $r['actions'][0]['bucket']);
    }

    public function test_plan_without_context_argument_still_works_backward_compatibly(): void
    {
        $r = (new AtlasSelfConstructionSelfHealingQueueRepairPlan)->plan([
            ['packet_id' => 'p-1', 'malformed' => true],
        ]);

        $this->assertSame(AtlasSelfConstructionSelfHealingQueueRepairPlan::BUCKET_TEMPLATE, $r['actions'][0]['bucket']);
    }

    // ── distinguishing malformed / recoverable / duplicate / stale (AC) ─────────

    public function test_malformed_packet_with_exhausted_repair_attempts_routes_to_respec_not_muscle_serve(): void
    {
        $r = (new AtlasSelfConstructionSelfHealingQueueRepairPlan)->plan([
            [
                'packet_id' => 'p-mal-exhausted',
                'malformed' => true,
                'malformed_repair_attempts' => AtlasSelfConstructionSelfHealingQueueRepairPlan::MALFORMED_RESPEC_OR_RETIRE_THRESHOLD,
            ],
        ]);

        $this->assertSame(AtlasSelfConstructionSelfHealingQueueRepairPlan::BUCKET_RESPEC, $r['actions'][0]['bucket']);
        $this->assertSame('enqueue_respec_packet', $r['actions'][0]['action']);
        $this->assertNotSame('muscle_serve', $r['actions'][0]['action']);
        $this->assertStringContainsString('malformed_repair_exhausted', $r['actions'][0]['reason']);
    }

    public function test_malformed_packet_below_repair_attempt_threshold_still_template_repairs(): void
    {
        $r = (new AtlasSelfConstructionSelfHealingQueueRepairPlan)->plan([
            ['packet_id' => 'p-mal-fresh', 'malformed' => true, 'malformed_repair_attempts' => 1],
        ]);

        $this->assertSame(AtlasSelfConstructionSelfHealingQueueRepairPlan::BUCKET_TEMPLATE, $r['actions'][0]['bucket']);
        $this->assertNotSame('muscle_serve', $r['actions'][0]['action']);
    }

    public function test_released_recoverable_packet_routes_to_requeue(): void
    {
        $r = (new AtlasSelfConstructionSelfHealingQueueRepairPlan)->plan([
            ['packet_id' => 'p-recov', 'released_recoverable' => true],
        ]);

        $this->assertSame(AtlasSelfConstructionSelfHealingQueueRepairPlan::BUCKET_REQUEUE, $r['actions'][0]['bucket']);
        $this->assertSame('requeue_packet', $r['actions'][0]['action']);
    }

    public function test_duplicate_packet_routes_to_retire_with_reason(): void
    {
        $r = (new AtlasSelfConstructionSelfHealingQueueRepairPlan)->plan([
            ['packet_id' => 'p-dup', 'duplicate_of' => 'p-orig'],
        ]);

        $this->assertSame(AtlasSelfConstructionSelfHealingQueueRepairPlan::BUCKET_RETIRE, $r['actions'][0]['bucket']);
        $this->assertSame('retire_packet', $r['actions'][0]['action']);
        $this->assertStringContainsString('duplicate_of:p-orig', $r['actions'][0]['reason']);
    }

    public function test_stale_packet_routes_to_retire_with_reason(): void
    {
        $r = (new AtlasSelfConstructionSelfHealingQueueRepairPlan)->plan([
            ['packet_id' => 'p-stale', 'is_stale' => true, 'stale_reason' => 'scope_no_longer_exists'],
        ]);

        $this->assertSame(AtlasSelfConstructionSelfHealingQueueRepairPlan::BUCKET_RETIRE, $r['actions'][0]['bucket']);
        $this->assertSame('retire_packet', $r['actions'][0]['action']);
        $this->assertStringContainsString('stale:scope_no_longer_exists', $r['actions'][0]['reason']);
    }
}
