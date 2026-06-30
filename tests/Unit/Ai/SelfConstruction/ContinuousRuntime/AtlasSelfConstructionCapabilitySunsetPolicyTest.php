<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\SelfConstruction\ContinuousRuntime;

use App\Services\Ai\SelfConstruction\ContinuousRuntime\AtlasSelfConstructionCapabilitySunsetPolicy;
use Tests\TestCase;

final class AtlasSelfConstructionCapabilitySunsetPolicyTest extends TestCase
{
    private function svc(): AtlasSelfConstructionCapabilitySunsetPolicy
    {
        return new AtlasSelfConstructionCapabilitySunsetPolicy;
    }

    private function cap(array $overrides = []): array
    {
        return $overrides + [
            'value_proof' => 8.0,
            'usage_frequency' => 7.0,
            'maintenance_cost' => 2.0,
            'replacement_owner_id' => '',
            'replacement_owner_ready' => false,
            'is_critical' => false,
            'safety_evidence' => [],
        ];
    }

    // ── keep ──────────────────────────────────────────────────────────────────

    public function test_high_value_is_keep(): void
    {
        $r = $this->svc()->evaluate($this->cap());

        $this->assertSame(AtlasSelfConstructionCapabilitySunsetPolicy::DECISION_KEEP, $r['decision']);
        $this->assertFalse($r['refused']);
        $this->assertContains('sufficient_value', $r['reasons']);
        $this->assertSame([], $r['required_safety_checks']);
    }

    // ── freeze ────────────────────────────────────────────────────────────────

    public function test_low_value_only_is_freeze(): void
    {
        // low value but usage and cost don't trigger retire
        $r = $this->svc()->evaluate($this->cap([
            'value_proof' => 2.0,
            'usage_frequency' => 8.0,
            'maintenance_cost' => 2.0,
        ]));

        $this->assertSame(AtlasSelfConstructionCapabilitySunsetPolicy::DECISION_FREEZE, $r['decision']);
        $this->assertContains('low_value_proof', $r['reasons']);
        $this->assertContains('confirm_no_active_development', $r['required_safety_checks']);
    }

    // ── retire ────────────────────────────────────────────────────────────────

    public function test_low_value_low_usage_high_cost_is_retire(): void
    {
        $r = $this->svc()->evaluate($this->cap([
            'value_proof' => 1.0,
            'usage_frequency' => 1.0,
            'maintenance_cost' => 9.0,
        ]));

        $this->assertSame(AtlasSelfConstructionCapabilitySunsetPolicy::DECISION_RETIRE, $r['decision']);
        $this->assertFalse($r['refused']);
        $this->assertContains('low_value_proof', $r['reasons']);
        $this->assertContains('low_usage', $r['reasons']);
        $this->assertContains('high_maintenance_cost', $r['reasons']);
    }

    public function test_retire_safety_checks(): void
    {
        $r = $this->svc()->evaluate($this->cap([
            'value_proof' => 1.0,
            'usage_frequency' => 1.0,
            'maintenance_cost' => 9.0,
        ]));

        $this->assertContains('confirm_no_active_consumers', $r['required_safety_checks']);
        $this->assertContains('confirm_replacement_tested', $r['required_safety_checks']);
    }

    // ── refused retire (critical) ─────────────────────────────────────────────

    public function test_critical_without_replacement_refuses_retire(): void
    {
        $r = $this->svc()->evaluate($this->cap([
            'value_proof' => 1.0,
            'usage_frequency' => 1.0,
            'maintenance_cost' => 9.0,
            'is_critical' => true,
            'safety_evidence' => [],
        ]));

        $this->assertSame(AtlasSelfConstructionCapabilitySunsetPolicy::DECISION_KEEP, $r['decision']);
        $this->assertTrue($r['refused']);
        $this->assertContains('critical_no_replacement', $r['reasons']);
        $this->assertContains('retirement_refused', $r['reasons']);
    }

    public function test_critical_with_safety_evidence_can_retire(): void
    {
        $r = $this->svc()->evaluate($this->cap([
            'value_proof' => 1.0,
            'usage_frequency' => 1.0,
            'maintenance_cost' => 9.0,
            'is_critical' => true,
            'safety_evidence' => ['manual_audit_passed'],
        ]));

        $this->assertSame(AtlasSelfConstructionCapabilitySunsetPolicy::DECISION_RETIRE, $r['decision']);
        $this->assertFalse($r['refused']);
    }

    // ── merge ─────────────────────────────────────────────────────────────────

    public function test_replacement_owner_ready_is_merge(): void
    {
        $r = $this->svc()->evaluate($this->cap([
            'replacement_owner_id' => 'cap-owner-1',
            'replacement_owner_ready' => true,
        ]));

        $this->assertSame(AtlasSelfConstructionCapabilitySunsetPolicy::DECISION_MERGE, $r['decision']);
        $this->assertContains('replacement_owner_ready', $r['reasons']);
    }

    public function test_replacement_owner_not_ready_does_not_merge(): void
    {
        $r = $this->svc()->evaluate($this->cap([
            'replacement_owner_id' => 'cap-owner-1',
            'replacement_owner_ready' => false,
        ]));

        $this->assertNotSame(AtlasSelfConstructionCapabilitySunsetPolicy::DECISION_MERGE, $r['decision']);
    }

    public function test_merge_safety_checks(): void
    {
        $r = $this->svc()->evaluate($this->cap([
            'replacement_owner_id' => 'cap-owner-2',
            'replacement_owner_ready' => true,
        ]));

        $this->assertContains('confirm_migration_tested', $r['required_safety_checks']);
        $this->assertContains('confirm_replacement_owner_covers_behaviors', $r['required_safety_checks']);
    }

    public function test_merge_takes_priority_over_retire_signals(): void
    {
        // Low value+usage+high cost AND has replacement → merge wins
        $r = $this->svc()->evaluate($this->cap([
            'value_proof' => 1.0,
            'usage_frequency' => 1.0,
            'maintenance_cost' => 9.0,
            'replacement_owner_id' => 'owner-x',
            'replacement_owner_ready' => true,
        ]));

        $this->assertSame(AtlasSelfConstructionCapabilitySunsetPolicy::DECISION_MERGE, $r['decision']);
    }

    // ── schema ────────────────────────────────────────────────────────────────

    public function test_schema_version_always_present(): void
    {
        $r = $this->svc()->evaluate($this->cap());

        $this->assertSame(AtlasSelfConstructionCapabilitySunsetPolicy::SCHEMA, $r['schema_version']);
    }
}
