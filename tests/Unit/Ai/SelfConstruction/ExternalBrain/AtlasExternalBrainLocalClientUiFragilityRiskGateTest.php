<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\SelfConstruction\ExternalBrain;

use App\Services\Ai\SelfConstruction\ExternalBrain\AtlasExternalBrainLocalClientUiFragilityRiskGate;
use Tests\TestCase;

final class AtlasExternalBrainLocalClientUiFragilityRiskGateTest extends TestCase
{
    private AtlasExternalBrainLocalClientUiFragilityRiskGate $gate;

    protected function setUp(): void
    {
        parent::setUp();
        $this->gate = new AtlasExternalBrainLocalClientUiFragilityRiskGate();
    }

    private function input(array $overrides = []): array
    {
        return array_merge([
            'ui_only' => false,
            'requires_screen_focus' => false,
            'brittle_selector_dependency' => false,
            'cannot_stream_stdout' => false,
            'cannot_set_workspace' => false,
            'cannot_bound_cost' => false,
            'cannot_enforce_timeout' => false,
        ], $overrides);
    }

    // ── Schema ───────────────────────────────────────────────────────────────────

    public function test_schema_constant(): void
    {
        $this->assertSame('atlas.external_brain.local_client_ui_fragility_risk_gate.v1', AtlasExternalBrainLocalClientUiFragilityRiskGate::SCHEMA);
    }

    // ── No risk ─────────────────────────────────────────────────────────────────

    public function test_no_risk_when_all_false(): void
    {
        $result = $this->gate->evaluate($this->input());
        $this->assertTrue($result['safe_for_24_7']);
        $this->assertSame('no_risk_detected', $result['recommendation']);
        $this->assertSame([], $result['risk_reasons']);
    }

    // ── reject_for_autonomous_loop ───────────────────────────────────────────────

    public function test_reject_when_cannot_bound_cost(): void
    {
        $result = $this->gate->evaluate($this->input(['cannot_bound_cost' => true]));
        $this->assertFalse($result['safe_for_24_7']);
        $this->assertSame('reject_for_autonomous_loop', $result['recommendation']);
    }

    public function test_reject_when_cannot_enforce_timeout(): void
    {
        $result = $this->gate->evaluate($this->input(['cannot_enforce_timeout' => true]));
        $this->assertFalse($result['safe_for_24_7']);
        $this->assertSame('reject_for_autonomous_loop', $result['recommendation']);
    }

    // ── downgrade_to_manual_muscle ───────────────────────────────────────────────

    public function test_downgrade_when_ui_only(): void
    {
        $result = $this->gate->evaluate($this->input(['ui_only' => true]));
        $this->assertFalse($result['safe_for_24_7']);
        $this->assertSame('downgrade_to_manual_muscle', $result['recommendation']);
    }

    public function test_downgrade_when_requires_screen_focus(): void
    {
        $result = $this->gate->evaluate($this->input(['requires_screen_focus' => true]));
        $this->assertFalse($result['safe_for_24_7']);
        $this->assertSame('downgrade_to_manual_muscle', $result['recommendation']);
    }

    public function test_downgrade_when_brittle_selector(): void
    {
        $result = $this->gate->evaluate($this->input(['brittle_selector_dependency' => true]));
        $this->assertFalse($result['safe_for_24_7']);
        $this->assertSame('downgrade_to_manual_muscle', $result['recommendation']);
    }

    // ── require_headless_adapter ─────────────────────────────────────────────────

    public function test_require_headless_when_cannot_stream_stdout(): void
    {
        $result = $this->gate->evaluate($this->input(['cannot_stream_stdout' => true]));
        $this->assertFalse($result['safe_for_24_7']);
        $this->assertSame('require_headless_adapter', $result['recommendation']);
    }

    public function test_require_headless_when_cannot_set_workspace(): void
    {
        $result = $this->gate->evaluate($this->input(['cannot_set_workspace' => true]));
        $this->assertFalse($result['safe_for_24_7']);
        $this->assertSame('require_headless_adapter', $result['recommendation']);
    }

    // ── Priority: reject beats downgrade ─────────────────────────────────────────

    public function test_reject_takes_priority_over_downgrade(): void
    {
        $result = $this->gate->evaluate($this->input([
            'ui_only' => true,
            'cannot_bound_cost' => true,
        ]));
        $this->assertSame('reject_for_autonomous_loop', $result['recommendation']);
    }

    // ── Risk reasons ─────────────────────────────────────────────────────────────

    public function test_risk_reasons_list_all_true_facts(): void
    {
        $result = $this->gate->evaluate($this->input([
            'ui_only' => true,
            'cannot_stream_stdout' => true,
        ]));
        $this->assertCount(2, $result['risk_reasons']);
        $this->assertContains('ui_only', $result['risk_reasons']);
        $this->assertContains('cannot_stream_stdout', $result['risk_reasons']);
    }

    // ── Facts in output ─────────────────────────────────────────────────────────

    public function test_output_contains_all_risk_facts(): void
    {
        $result = $this->gate->evaluate($this->input(['ui_only' => true]));
        $this->assertTrue($result['facts']['ui_only']);
        $this->assertFalse($result['facts']['cannot_bound_cost']);
    }

    // ── Determinism ──────────────────────────────────────────────────────────────

    public function test_result_is_deterministic(): void
    {
        $input = $this->input(['ui_only' => true]);
        $this->assertSame($this->gate->evaluate($input), $this->gate->evaluate($input));
    }

    // ── Empty input ─────────────────────────────────────────────────────────────

    public function test_empty_input_is_safe(): void
    {
        $result = $this->gate->evaluate([]);
        $this->assertTrue($result['safe_for_24_7']);
        $this->assertSame('no_risk_detected', $result['recommendation']);
    }

    // ── required_adapter_work ──

    public function test_required_adapter_work_empty_when_no_risks(): void
    {
        $result = $this->gate->evaluate([]);
        $this->assertSame([], $result['required_adapter_work']);
    }

    public function test_required_adapter_work_maps_ui_only(): void
    {
        $result = $this->gate->evaluate(['ui_only' => true]);
        $this->assertCount(1, $result['required_adapter_work']);
        $this->assertSame('replace_ui_automation_with_headless_api_or_cli', $result['required_adapter_work'][0]);
    }

    public function test_required_adapter_work_maps_multiple_risks(): void
    {
        $result = $this->gate->evaluate([
            'ui_only' => true,
            'cannot_stream_stdout' => true,
            'cannot_set_workspace' => true,
        ]);
        $this->assertCount(3, $result['required_adapter_work']);
        $this->assertContains('replace_ui_automation_with_headless_api_or_cli', $result['required_adapter_work']);
        $this->assertContains('implement_stdout_streaming_or_file_based_output_for_headless_invocation', $result['required_adapter_work']);
        $this->assertContains('add_workspace_path_configuration_for_headless_invocation', $result['required_adapter_work']);
    }

    public function test_required_adapter_work_maps_cost_and_timeout(): void
    {
        $result = $this->gate->evaluate([
            'cannot_bound_cost' => true,
            'cannot_enforce_timeout' => true,
        ]);
        $this->assertCount(2, $result['required_adapter_work']);
        $this->assertContains('implement_cost_bound_guard_or_pre_flight_cost_estimate', $result['required_adapter_work']);
        $this->assertContains('implement_timeout_enforcement_or_external_process_monitor', $result['required_adapter_work']);
    }
}
