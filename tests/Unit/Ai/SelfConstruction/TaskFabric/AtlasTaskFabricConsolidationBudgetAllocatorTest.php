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
}
