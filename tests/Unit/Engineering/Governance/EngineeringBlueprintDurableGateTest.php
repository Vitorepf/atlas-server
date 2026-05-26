<?php

declare(strict_types=1);

namespace Tests\Unit\Engineering\Governance;

use App\Services\Engineering\Governance\EngineeringBlueprintDurableGate;
use Tests\TestCase;

final class EngineeringBlueprintDurableGateTest extends TestCase
{
    private EngineeringBlueprintDurableGate $gate;

    protected function setUp(): void
    {
        parent::setUp();
        $this->gate = new EngineeringBlueprintDurableGate;
    }

    private function compliantStandard(): array
    {
        return [
            'blueprint_id' => 'bp-billing-refactor',
            'schema_version' => 'atlas.engineering.blueprint.v1',
            'spec_hash' => 'sha:abc',
            'gate_cascade' => ['contract_test', 'regression_suite', 'review_gate'],
            'replay_contract' => 'atlas.engineering.replay.v1',
            'budget' => ['max_duration_hours' => 4, 'max_repair_attempts' => 3],
            'tier' => 'standard',
        ];
    }

    public function test_approves_compliant_standard_blueprint(): void
    {
        $r = $this->gate->evaluate($this->compliantStandard());
        $this->assertTrue($r['may_execute_durable']);
        $this->assertSame([], $r['failed_checks']);
        $this->assertSame('atlas.engineering.blueprint_durable_gate.v1', $r['schema_version']);
    }

    public function test_blocks_missing_required_field(): void
    {
        $bp = $this->compliantStandard();
        unset($bp['spec_hash']);
        $r = $this->gate->evaluate($bp);
        $this->assertArrayHasKey('spec_hash', $r['failed_checks']);
    }

    public function test_blocks_invalid_tier(): void
    {
        $bp = $this->compliantStandard();
        $bp['tier'] = 'wibble';
        $r = $this->gate->evaluate($bp);
        $this->assertArrayHasKey('tier', $r['failed_checks']);
    }

    public function test_blocks_when_cascade_below_minimum(): void
    {
        $bp = $this->compliantStandard();
        $bp['gate_cascade'] = ['one_gate'];
        $r = $this->gate->evaluate($bp);
        $this->assertArrayHasKey('gate_cascade', $r['failed_checks']);
    }

    public function test_blocks_budget_duration_out_of_range(): void
    {
        $bp = $this->compliantStandard();
        $bp['budget'] = ['max_duration_hours' => 999, 'max_repair_attempts' => 1];
        $r = $this->gate->evaluate($bp);
        $this->assertArrayHasKey('budget.max_duration_hours', $r['failed_checks']);
    }

    public function test_enterprise_tier_requires_security_scan(): void
    {
        $bp = $this->compliantStandard();
        $bp['tier'] = 'enterprise';
        $r = $this->gate->evaluate($bp);
        $this->assertArrayHasKey('gate_cascade.security_scan', $r['failed_checks']);
    }

    public function test_enterprise_tier_passes_with_security_scan(): void
    {
        $bp = $this->compliantStandard();
        $bp['tier'] = 'enterprise';
        $bp['gate_cascade'][] = 'security_scan';
        $r = $this->gate->evaluate($bp);
        $this->assertTrue($r['may_execute_durable']);
    }

    public function test_critical_tier_requires_operator_review(): void
    {
        $bp = $this->compliantStandard();
        $bp['tier'] = 'critical';
        $bp['gate_cascade'][] = 'security_scan';
        $r = $this->gate->evaluate($bp);
        $this->assertArrayHasKey('gate_cascade.operator_review', $r['failed_checks']);
    }

    public function test_critical_tier_passes_with_full_cascade(): void
    {
        $bp = $this->compliantStandard();
        $bp['tier'] = 'critical';
        $bp['gate_cascade'] = ['contract_test', 'regression_suite', 'review_gate', 'security_scan', 'operator_review'];
        $r = $this->gate->evaluate($bp);
        $this->assertTrue($r['may_execute_durable']);
    }

    public function test_envelope_shape_is_stable(): void
    {
        $r = $this->gate->evaluate($this->compliantStandard());
        $this->assertSame([
            'schema_version', 'blueprint_id', 'may_execute_durable', 'tier',
            'passed_checks', 'failed_checks', 'detail', 'evaluated_at',
        ], array_keys($r));
    }
}
