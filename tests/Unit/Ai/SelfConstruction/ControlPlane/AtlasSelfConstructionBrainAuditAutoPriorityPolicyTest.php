<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\SelfConstruction\ControlPlane;

use App\Services\Ai\SelfConstruction\ControlPlane\AtlasSelfConstructionBrainAuditAutoPriorityPolicy;
use Tests\TestCase;

final class AtlasSelfConstructionBrainAuditAutoPriorityPolicyTest extends TestCase
{
    private function svc(): AtlasSelfConstructionBrainAuditAutoPriorityPolicy
    {
        return new AtlasSelfConstructionBrainAuditAutoPriorityPolicy;
    }

    private function apply(string $status, string $severity, array $pending = []): array
    {
        return $this->svc()->apply([
            'audit_snapshot' => ['status' => $status, 'regression_severity' => $severity],
            'pending_actions' => $pending,
        ]);
    }

    // ── critical regression path ──────────────────────────────────────────────

    public function test_critical_gate_regression_selects_repair_gate(): void
    {
        $r = $this->apply('gate_regression', 'critical', ['generate_more_tasks', 'expand_frontier']);

        $this->assertSame('repair_gate', $r['selected_action']);
    }

    public function test_critical_regression_repair_gate_before_generate_more_tasks(): void
    {
        $r = $this->apply('gate_regression', 'critical', ['generate_more_tasks', 'repair_gate']);

        // repair_gate must be selected regardless of order in pending_actions
        $this->assertSame('repair_gate', $r['selected_action']);
        $this->assertTrue($r['priority_override']);
    }

    public function test_critical_regression_suppresses_backlog_and_expansion_actions(): void
    {
        $r = $this->apply('gate_regression', 'critical',
            ['generate_more_tasks', 'expand_frontier', 'cosmetic_consolidation', 'repair_gate']);

        $this->assertContains('generate_more_tasks', $r['suppressed_actions']);
        $this->assertContains('expand_frontier', $r['suppressed_actions']);
        $this->assertContains('cosmetic_consolidation', $r['suppressed_actions']);
        $this->assertNotContains('repair_gate', $r['suppressed_actions']);
    }

    public function test_critical_regression_sets_override_reason(): void
    {
        $r = $this->apply('gate_regression', 'critical');

        $this->assertSame('critical_gate_regression_repair_first', $r['override_reason']);
    }

    // ── non-critical regression (warning) ─────────────────────────────────────

    public function test_warning_regression_does_not_override(): void
    {
        $r = $this->apply('gate_regression', 'warning', ['generate_more_tasks']);

        $this->assertFalse($r['priority_override']);
        $this->assertSame('generate_more_tasks', $r['selected_action']);
    }

    // ── healthy audit path ────────────────────────────────────────────────────

    public function test_healthy_snapshot_uses_first_pending_action(): void
    {
        $r = $this->apply('healthy', 'none', ['schedule_workers', 'generate_more_tasks']);

        $this->assertFalse($r['priority_override']);
        $this->assertSame('schedule_workers', $r['selected_action']);
        $this->assertNull($r['override_reason']);
        $this->assertSame([], $r['suppressed_actions']);
    }

    public function test_healthy_snapshot_with_empty_pending_defaults_hold_position(): void
    {
        $r = $this->apply('healthy', 'none', []);

        $this->assertSame('hold_position', $r['selected_action']);
    }

    // ── schema ────────────────────────────────────────────────────────────────

    public function test_schema_version_present(): void
    {
        $r = $this->svc()->apply([]);

        $this->assertSame(AtlasSelfConstructionBrainAuditAutoPriorityPolicy::SCHEMA, $r['schema_version']);
    }

    // ── blocked/quarantined debt overhang (AC) ────────────────────────────────

    public function test_high_blocked_quarantined_debt_raises_queue_self_healing_despite_sufficient_claimable_depth(): void
    {
        $r = $this->svc()->apply([
            'audit_snapshot' => [
                'status' => 'healthy',
                'blocked_count' => 8,
                'quarantined_count' => 4,
                'claimable_depth' => 5,
            ],
            'pending_actions' => ['generate_more_tasks'],
        ]);

        $this->assertSame(AtlasSelfConstructionBrainAuditAutoPriorityPolicy::ACTION_QUEUE_SELF_HEALING, $r['selected_action']);
        $this->assertTrue($r['priority_override']);
        $this->assertSame('blocked_quarantined_debt_exceeds_claimable_depth', $r['override_reason']);
    }

    public function test_blocked_quarantined_debt_below_material_ratio_does_not_override(): void
    {
        $r = $this->svc()->apply([
            'audit_snapshot' => [
                'status' => 'healthy',
                'blocked_count' => 2,
                'quarantined_count' => 1,
                'claimable_depth' => 5,
            ],
            'pending_actions' => ['generate_more_tasks'],
        ]);

        $this->assertSame('generate_more_tasks', $r['selected_action']);
        $this->assertFalse($r['priority_override']);
    }

    public function test_blocked_quarantined_debt_never_counted_as_claimable_supply(): void
    {
        // 10 blocked + 0 claimable_depth: debt overhang is infinite relative to zero supply.
        $r = $this->svc()->apply([
            'audit_snapshot' => [
                'status' => 'healthy',
                'blocked_count' => 10,
                'quarantined_count' => 0,
                'claimable_depth' => 0,
            ],
            'pending_actions' => [],
        ]);

        $this->assertSame(AtlasSelfConstructionBrainAuditAutoPriorityPolicy::ACTION_QUEUE_SELF_HEALING, $r['selected_action']);
    }

    public function test_critical_gate_regression_still_takes_precedence_over_debt_overhang(): void
    {
        $r = $this->svc()->apply([
            'audit_snapshot' => [
                'status' => 'gate_regression',
                'regression_severity' => 'critical',
                'blocked_count' => 100,
                'quarantined_count' => 100,
                'claimable_depth' => 1,
            ],
            'pending_actions' => [],
        ]);

        $this->assertSame('repair_gate', $r['selected_action']);
    }

    public function test_zero_debt_does_not_trigger_self_healing_override(): void
    {
        $r = $this->svc()->apply([
            'audit_snapshot' => [
                'status' => 'healthy',
                'blocked_count' => 0,
                'quarantined_count' => 0,
                'claimable_depth' => 0,
            ],
            'pending_actions' => [],
        ]);

        $this->assertSame('hold_position', $r['selected_action']);
        $this->assertFalse($r['priority_override']);
    }
}
