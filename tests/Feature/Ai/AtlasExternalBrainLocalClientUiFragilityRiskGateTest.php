<?php

declare(strict_types=1);

namespace Tests\Feature\Ai;

use App\Services\Ai\SelfConstruction\ExternalBrain\AtlasExternalBrainLocalClientUiFragilityRiskGate;
use Tests\TestCase;

final class AtlasExternalBrainLocalClientUiFragilityRiskGateTest extends TestCase
{
    private AtlasExternalBrainLocalClientUiFragilityRiskGate $gate;

    protected function setUp(): void
    {
        parent::setUp();
        $this->gate = new AtlasExternalBrainLocalClientUiFragilityRiskGate;
    }

    public function test_no_risk_facts_is_safe_for_24_7_with_no_risk_detected(): void
    {
        $result = $this->gate->evaluate([]);

        $this->assertSame(AtlasExternalBrainLocalClientUiFragilityRiskGate::SCHEMA, $result['schema']);
        $this->assertTrue($result['safe_for_24_7']);
        $this->assertSame('no_risk_detected', $result['recommendation']);
        $this->assertSame([], $result['risk_reasons']);
    }

    public function test_cannot_bound_cost_rejects_for_autonomous_loop(): void
    {
        $result = $this->gate->evaluate(['cannot_bound_cost' => true]);

        $this->assertFalse($result['safe_for_24_7']);
        $this->assertSame('reject_for_autonomous_loop', $result['recommendation']);
        $this->assertContains('cannot_bound_cost', $result['risk_reasons']);
    }

    public function test_cannot_enforce_timeout_rejects_for_autonomous_loop(): void
    {
        $result = $this->gate->evaluate(['cannot_enforce_timeout' => true]);

        $this->assertSame('reject_for_autonomous_loop', $result['recommendation']);
    }

    public function test_ui_only_downgrades_to_manual_muscle(): void
    {
        $result = $this->gate->evaluate(['ui_only' => true]);

        $this->assertFalse($result['safe_for_24_7']);
        $this->assertSame('downgrade_to_manual_muscle', $result['recommendation']);
    }

    public function test_requires_screen_focus_downgrades_to_manual_muscle(): void
    {
        $result = $this->gate->evaluate(['requires_screen_focus' => true]);

        $this->assertSame('downgrade_to_manual_muscle', $result['recommendation']);
    }

    public function test_brittle_selector_dependency_downgrades_to_manual_muscle(): void
    {
        $result = $this->gate->evaluate(['brittle_selector_dependency' => true]);

        $this->assertSame('downgrade_to_manual_muscle', $result['recommendation']);
    }

    public function test_cannot_stream_stdout_requires_headless_adapter(): void
    {
        $result = $this->gate->evaluate(['cannot_stream_stdout' => true]);

        $this->assertFalse($result['safe_for_24_7']);
        $this->assertSame('require_headless_adapter', $result['recommendation']);
    }

    public function test_cannot_set_workspace_requires_headless_adapter(): void
    {
        $result = $this->gate->evaluate(['cannot_set_workspace' => true]);

        $this->assertSame('require_headless_adapter', $result['recommendation']);
    }

    public function test_cost_boundary_risk_outranks_ui_fragility(): void
    {
        $result = $this->gate->evaluate([
            'ui_only' => true,
            'cannot_bound_cost' => true,
        ]);

        $this->assertSame('reject_for_autonomous_loop', $result['recommendation']);
    }

    public function test_ui_fragility_outranks_headless_adapter_gap(): void
    {
        $result = $this->gate->evaluate([
            'cannot_stream_stdout' => true,
            'ui_only' => true,
        ]);

        $this->assertSame('downgrade_to_manual_muscle', $result['recommendation']);
    }

    public function test_any_risk_fact_makes_unsafe_for_24_7(): void
    {
        foreach ([
            'ui_only',
            'requires_screen_focus',
            'brittle_selector_dependency',
            'cannot_stream_stdout',
            'cannot_set_workspace',
            'cannot_bound_cost',
            'cannot_enforce_timeout',
        ] as $riskFact) {
            $result = $this->gate->evaluate([$riskFact => true]);
            $this->assertFalse($result['safe_for_24_7'], "{$riskFact} should make safe_for_24_7 false");
        }
    }
}
