<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\SelfConstruction\TaskFabric;

use App\Services\Ai\SelfConstruction\TaskFabric\AtlasTaskFabricConsolidationBudgetAllocator;
use PHPUnit\Framework\TestCase;

final class AtlasTaskFabricConsolidationBudgetAllocatorTest extends TestCase
{
    private function allocator(): AtlasTaskFabricConsolidationBudgetAllocator
    {
        return new AtlasTaskFabricConsolidationBudgetAllocator;
    }

    private function healthy(): array
    {
        return [
            'batch_size'                => 20,
            'queue_depth'               => 30,
            'duplicate_pressure'        => 0.1,
            'orphaned_capability_count' => 5,
            'blocked_pressure'          => 0.1,
            'value_proof_density'       => 0.8,
        ];
    }

    // ── Output shape ──────────────────────────────────────────────────────────

    public function test_output_has_required_keys(): void
    {
        $r = $this->allocator()->allocate([]);
        $this->assertSame(AtlasTaskFabricConsolidationBudgetAllocator::SCHEMA, $r['schema_version']);
        $this->assertArrayHasKey('consolidation_budget', $r);
        $this->assertArrayHasKey('unblock_budget', $r);
        $this->assertArrayHasKey('implementation_budget', $r);
        $this->assertArrayHasKey('total_budget', $r);
        $this->assertArrayHasKey('active_triggers', $r);
    }

    public function test_budgets_sum_to_batch_size(): void
    {
        $r = $this->allocator()->allocate($this->healthy());
        $this->assertSame(
            $r['total_budget'],
            $r['consolidation_budget'] + $r['unblock_budget'] + $r['implementation_budget'],
        );
    }

    public function test_healthy_queue_uses_base_ratios(): void
    {
        $r = $this->allocator()->allocate($this->healthy());
        // Healthy: no triggers → base 0.15 consolidation, 0.10 unblock.
        // strong_value_proof_relief fires (density=0.8 > 0.7) → consolidation 0.10
        // 20 * 0.10 = 2 consolidation; 20 * 0.10 = 2 unblock; 16 implementation.
        $this->assertSame(2, $r['consolidation_budget']);
        $this->assertSame(2, $r['unblock_budget']);
        $this->assertSame(16, $r['implementation_budget']);
    }

    // ── AC2: upward pressure increases consolidation/unblock ─────────────────

    public function test_high_duplicate_pressure_increases_consolidation(): void
    {
        $r = $this->allocator()->allocate(array_merge($this->healthy(), [
            'duplicate_pressure'  => 0.5,  // > HIGH_DUPLICATE (0.3)
            'value_proof_density' => 0.5,  // neutral, no strong-relief
        ]));

        $this->assertContains('high_duplicate_pressure', $r['active_triggers']);
        // Consolidation ratio increased; budget should be higher than base 15% of 20 = 3.
        $this->assertGreaterThan(3, $r['consolidation_budget']);
    }

    public function test_high_orphan_count_increases_consolidation(): void
    {
        $r = $this->allocator()->allocate(array_merge($this->healthy(), [
            'orphaned_capability_count' => 25,  // > HIGH_ORPHAN (20)
            'value_proof_density'       => 0.5,
        ]));

        $this->assertContains('high_orphan_count', $r['active_triggers']);
        $this->assertGreaterThan(3, $r['consolidation_budget']);
    }

    public function test_weak_value_proof_increases_consolidation(): void
    {
        $r = $this->allocator()->allocate(array_merge($this->healthy(), [
            'value_proof_density' => 0.2,  // < WEAK_VALUE_PROOF (0.3)
        ]));

        $this->assertContains('weak_value_proof', $r['active_triggers']);
        $this->assertGreaterThan(3, $r['consolidation_budget']);
    }

    public function test_high_blocked_pressure_increases_unblock_budget(): void
    {
        $baseUnblock = $this->allocator()->allocate($this->healthy())['unblock_budget'];
        $r           = $this->allocator()->allocate(array_merge($this->healthy(), [
            'blocked_pressure' => 0.5,  // > HIGH_BLOCKED_PRESSURE (0.3)
        ]));

        $this->assertContains('high_blocked_pressure', $r['active_triggers']);
        $this->assertGreaterThan($baseUnblock, $r['unblock_budget']);
    }

    // ── AC2: downward relief decreases consolidation ──────────────────────────

    public function test_thin_queue_reduces_consolidation_ratio(): void
    {
        $r = $this->allocator()->allocate(array_merge($this->healthy(), [
            'queue_depth'         => 5,   // < THIN_QUEUE_DEPTH (10)
            'value_proof_density' => 0.5,
        ]));

        $this->assertContains('thin_queue_relief', $r['active_triggers']);
    }

    public function test_strong_value_proof_adds_relief(): void
    {
        $r = $this->allocator()->allocate(array_merge($this->healthy(), [
            'value_proof_density' => 0.9,  // > STRONG_VALUE_PROOF (0.7)
        ]));

        $this->assertContains('strong_value_proof_relief', $r['active_triggers']);
    }

    // ── Caps enforcement ─────────────────────────────────────────────────────

    public function test_consolidation_budget_never_exceeds_40_pct_of_batch(): void
    {
        // All upward triggers active.
        $r = $this->allocator()->allocate([
            'batch_size'                => 20,
            'queue_depth'               => 50,
            'duplicate_pressure'        => 0.9,
            'orphaned_capability_count' => 50,
            'blocked_pressure'          => 0.9,
            'value_proof_density'       => 0.1,
        ]);

        $this->assertLessThanOrEqual((int) floor(20 * 0.40), $r['consolidation_budget']);
    }

    public function test_implementation_budget_never_negative(): void
    {
        $r = $this->allocator()->allocate([
            'batch_size'                => 5,
            'duplicate_pressure'        => 0.9,
            'orphaned_capability_count' => 50,
            'blocked_pressure'          => 0.9,
            'value_proof_density'       => 0.1,
        ]);

        $this->assertGreaterThanOrEqual(0, $r['implementation_budget']);
    }

    public function test_output_is_deterministic(): void
    {
        $facts = $this->healthy();
        $a     = $this->allocator()->allocate($facts);
        $b     = $this->allocator()->allocate($facts);
        $this->assertSame(json_encode($a), json_encode($b));
    }

    // ── AC: deletion_budget / budget_ratio / rationale / target families ────────────

    public function test_high_duplication_allocates_nonzero_consolidation_and_deletion_budget(): void
    {
        $r = $this->allocator()->allocate(array_merge($this->healthy(), [
            'duplicate_pressure' => 0.9,
            'duplicate_capability_families' => ['family_a', 'family_b'],
        ]));

        $this->assertGreaterThan(0, $r['consolidation_budget']);
        $this->assertGreaterThan(0, $r['deletion_budget']);
        $this->assertSame(['family_a', 'family_b'], $r['consolidation_target_families']);
        $this->assertNotEmpty($r['rationale']);
    }

    public function test_thin_queue_and_urgent_blocker_reduce_but_never_erase_consolidation_budget(): void
    {
        $r = $this->allocator()->allocate([
            'batch_size'                => 10,
            'queue_depth'               => 5, // thin_queue_relief
            'duplicate_pressure'        => 0.9, // high debt trigger
            'orphaned_capability_count' => 0,
            'blocked_pressure'          => 0.9, // urgent blocker
            'value_proof_density'       => 1.0,
        ]);

        $this->assertGreaterThanOrEqual(1, $r['consolidation_budget']);
        $this->assertContains('thin_queue_relief', $r['active_triggers']);
    }

    public function test_output_includes_budget_ratio_rationale_and_target_families(): void
    {
        $r = $this->allocator()->allocate($this->healthy());

        $this->assertArrayHasKey('budget_ratio', $r);
        $this->assertArrayHasKey('consolidation', $r['budget_ratio']);
        $this->assertArrayHasKey('rationale', $r);
        $this->assertArrayHasKey('consolidation_target_families', $r);
        $this->assertSame([], $r['consolidation_target_families']);
    }

    // ── AC: deterministic budget with hard floor, starvation flag, pressure inputs, reason codes ──

    public function test_output_includes_hard_floor_starvation_flag_pressure_inputs_and_reason_codes(): void
    {
        $r = $this->allocator()->allocate($this->healthy());

        $this->assertArrayHasKey('hard_floor', $r);
        $this->assertArrayHasKey('starvation_flag', $r);
        $this->assertArrayHasKey('pressure_inputs', $r);
        $this->assertArrayHasKey('reason_codes', $r);
        $this->assertIsInt($r['hard_floor']);
        $this->assertIsBool($r['starvation_flag']);
        $this->assertArrayHasKey('duplicate_pressure', $r['pressure_inputs']);
        $this->assertArrayHasKey('active_worker_count', $r['pressure_inputs']);
    }

    // ── feature-heavy starvation: batch skewed fully toward implementation still reserves a slot ──

    public function test_feature_heavy_starvation_still_reserves_hard_floor_consolidation_slot(): void
    {
        // Small batch, no debt pressure, high value proof (relief), thin queue (relief) — the
        // natural ratio-computed consolidation budget rounds down to zero.
        $r = $this->allocator()->allocate([
            'batch_size'                => 10,
            'queue_depth'               => 5,
            'duplicate_pressure'        => 0.0,
            'orphaned_capability_count' => 0,
            'blocked_pressure'          => 0.0,
            'value_proof_density'       => 0.9,
        ]);

        $this->assertGreaterThanOrEqual(1, $r['consolidation_budget']);
        $this->assertTrue($r['starvation_flag'], 'a feature-heavy batch must flag that the hard floor rescued the slot');
        $this->assertContains('consolidation_hard_floor_enforced', $r['reason_codes']);
    }

    public function test_healthy_queue_with_real_consolidation_share_does_not_flag_starvation(): void
    {
        $r = $this->allocator()->allocate($this->healthy());

        $this->assertFalse($r['starvation_flag']);
        $this->assertNotContains('consolidation_hard_floor_enforced', $r['reason_codes']);
    }

    // ── low worker capacity signal ─────────────────────────────────────────────

    public function test_low_worker_capacity_is_flagged_without_changing_total_budget(): void
    {
        $r = $this->allocator()->allocate(array_merge($this->healthy(), [
            'active_worker_count' => 1,
        ]));

        $this->assertContains('low_worker_capacity_detected', $r['reason_codes']);
        $this->assertSame(1, $r['pressure_inputs']['active_worker_count']);
        $this->assertSame(
            $r['total_budget'],
            $r['consolidation_budget'] + $r['unblock_budget'] + $r['implementation_budget'],
        );
    }

    public function test_ample_worker_capacity_does_not_flag_low_capacity(): void
    {
        $r = $this->allocator()->allocate(array_merge($this->healthy(), [
            'active_worker_count' => 10,
        ]));

        $this->assertNotContains('low_worker_capacity_detected', $r['reason_codes']);
    }

    // ── zero-input safety: no crash, no fake padding, budgets stay internally consistent ──

    public function test_zero_input_facts_are_safe_and_produce_no_padding_inconsistency(): void
    {
        $r = $this->allocator()->allocate([]);

        $this->assertSame(
            $r['total_budget'],
            $r['consolidation_budget'] + $r['unblock_budget'] + $r['implementation_budget'],
        );
        $this->assertGreaterThanOrEqual(0, $r['consolidation_budget']);
        $this->assertGreaterThanOrEqual(0, $r['implementation_budget']);
        $this->assertSame(0, $r['pressure_inputs']['active_worker_count']);
        $this->assertNotContains('low_worker_capacity_detected', $r['reason_codes']);
    }
}
