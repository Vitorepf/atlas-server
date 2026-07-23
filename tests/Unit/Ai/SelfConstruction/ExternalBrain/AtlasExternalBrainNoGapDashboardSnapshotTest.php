<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\SelfConstruction\ExternalBrain;

use App\Services\Ai\SelfConstruction\ExternalBrain\AtlasExternalBrainNoGapDashboardSnapshot;
use PHPUnit\Framework\TestCase;

final class AtlasExternalBrainNoGapDashboardSnapshotTest extends TestCase
{
    private function svc(): AtlasExternalBrainNoGapDashboardSnapshot
    {
        return new AtlasExternalBrainNoGapDashboardSnapshot;
    }

    private function fullyClosedInput(): array
    {
        return [
            'gaps_by_area' => [],
            'detached_task_risks' => [],
            'e2e_proof_status' => ['passed' => true],
            'knowledge_sync_status' => ['current' => true],
        ];
    }

    // ── AC: required snapshot fields ──────────────────────────────────────────

    public function test_snapshot_includes_all_required_fields(): void
    {
        $r = $this->svc()->compose($this->fullyClosedInput());

        foreach ([
            'total_gaps', 'gaps_by_area', 'detached_task_risks',
            'e2e_proof_status', 'knowledge_sync_status', 'next_closure_task',
        ] as $field) {
            $this->assertArrayHasKey($field, $r);
        }
        $this->assertSame(AtlasExternalBrainNoGapDashboardSnapshot::SCHEMA, $r['schema']);
    }

    // ── AC: total_gaps > 0 implies readiness=false ────────────────────────────

    public function test_open_area_gaps_make_readiness_false(): void
    {
        $r = $this->svc()->compose(array_merge($this->fullyClosedInput(), [
            'gaps_by_area' => ['maestro' => 2],
        ]));

        $this->assertGreaterThan(0, $r['total_gaps']);
        $this->assertFalse($r['readiness']);
    }

    public function test_detached_task_risk_makes_readiness_false(): void
    {
        $r = $this->svc()->compose(array_merge($this->fullyClosedInput(), [
            'detached_task_risks' => [['task_id' => 'x-1', 'reason' => 'no live consumer']],
        ]));

        $this->assertGreaterThan(0, $r['total_gaps']);
        $this->assertFalse($r['readiness']);
    }

    public function test_e2e_not_passed_makes_readiness_false(): void
    {
        $r = $this->svc()->compose(array_merge($this->fullyClosedInput(), [
            'e2e_proof_status' => ['passed' => false],
        ]));

        $this->assertGreaterThan(0, $r['total_gaps']);
        $this->assertFalse($r['readiness']);
    }

    public function test_knowledge_sync_not_current_makes_readiness_false(): void
    {
        $r = $this->svc()->compose(array_merge($this->fullyClosedInput(), [
            'knowledge_sync_status' => ['current' => false],
        ]));

        $this->assertGreaterThan(0, $r['total_gaps']);
        $this->assertFalse($r['readiness']);
    }

    // ── AC: all inputs closed and synced → readiness true, next_closure_task null ──

    public function test_all_closed_yields_readiness_true_and_null_next_closure_task(): void
    {
        $r = $this->svc()->compose($this->fullyClosedInput());

        $this->assertSame(0, $r['total_gaps']);
        $this->assertTrue($r['readiness']);
        $this->assertNull($r['next_closure_task']);
    }

    // ── total_gaps arithmetic ──────────────────────────────────────────────────

    public function test_total_gaps_sums_area_gaps_across_multiple_areas(): void
    {
        $r = $this->svc()->compose(array_merge($this->fullyClosedInput(), [
            'gaps_by_area' => ['maestro' => 2, 'workers' => 3],
        ]));

        $this->assertSame(5, $r['total_gaps']);
    }

    public function test_total_gaps_combines_all_four_sources(): void
    {
        $r = $this->svc()->compose([
            'gaps_by_area' => ['maestro' => 1],
            'detached_task_risks' => [['task_id' => 'x-1']],
            'e2e_proof_status' => ['passed' => false],
            'knowledge_sync_status' => ['current' => false],
        ]);

        // 1 area gap + 1 detached risk + 1 unproven E2E + 1 stale sync = 4.
        $this->assertSame(4, $r['total_gaps']);
        $this->assertFalse($r['readiness']);
    }

    public function test_zero_count_areas_are_excluded_from_gaps_by_area_and_total(): void
    {
        $r = $this->svc()->compose(array_merge($this->fullyClosedInput(), [
            'gaps_by_area' => ['maestro' => 0, 'workers' => 0],
        ]));

        $this->assertSame([], $r['gaps_by_area']);
        $this->assertSame(0, $r['total_gaps']);
        $this->assertTrue($r['readiness']);
    }

    // ── next_closure_task priority ─────────────────────────────────────────────

    public function test_next_closure_task_targets_alphabetically_first_gap_area(): void
    {
        $r = $this->svc()->compose(array_merge($this->fullyClosedInput(), [
            'gaps_by_area' => ['workers' => 1, 'anti_goodhart' => 1],
        ]));

        $this->assertSame('close_gap_in:anti_goodhart', $r['next_closure_task']);
    }

    public function test_next_closure_task_is_detached_risk_when_no_area_gaps(): void
    {
        $r = $this->svc()->compose(array_merge($this->fullyClosedInput(), [
            'detached_task_risks' => [['task_id' => 'x-1']],
        ]));

        $this->assertSame('reattach_detached_task_risk', $r['next_closure_task']);
    }

    public function test_next_closure_task_is_prove_e2e_when_only_e2e_open(): void
    {
        $r = $this->svc()->compose(array_merge($this->fullyClosedInput(), [
            'e2e_proof_status' => ['passed' => false],
        ]));

        $this->assertSame('prove_e2e_status', $r['next_closure_task']);
    }

    public function test_next_closure_task_is_sync_knowledge_when_only_sync_open(): void
    {
        $r = $this->svc()->compose(array_merge($this->fullyClosedInput(), [
            'knowledge_sync_status' => ['current' => false],
        ]));

        $this->assertSame('sync_knowledge', $r['next_closure_task']);
    }

    public function test_area_gap_takes_priority_over_all_other_sources(): void
    {
        $r = $this->svc()->compose([
            'gaps_by_area' => ['maestro' => 1],
            'detached_task_risks' => [['task_id' => 'x-1']],
            'e2e_proof_status' => ['passed' => false],
            'knowledge_sync_status' => ['current' => false],
        ]);

        $this->assertSame('close_gap_in:maestro', $r['next_closure_task']);
    }

    // ── determinism ────────────────────────────────────────────────────────────

    public function test_compose_is_deterministic(): void
    {
        $input = array_merge($this->fullyClosedInput(), ['gaps_by_area' => ['maestro' => 1]]);

        $this->assertSame(
            $this->svc()->compose($input),
            $this->svc()->compose($input),
        );
    }
}
