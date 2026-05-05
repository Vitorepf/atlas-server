<?php

namespace Tests\Feature\Ai;

use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

class AtlasAiArchitectureValidateCommandTest extends TestCase
{
    public function test_command_validates_architecture_contracts_as_json(): void
    {
        $exit = Artisan::call('atlas:ai:architecture-validate', [
            '--json' => true,
        ]);

        $payload = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);

        $this->assertSame(0, $exit);
        $this->assertSame('ok', $payload['status']);
        $this->assertTrue(data_get($payload, 'kernel.valid'));
        $this->assertTrue(data_get($payload, 'kernel.surface_adapter_contract'));
        $this->assertTrue(data_get($payload, 'kernel.provider_driver_contract'));
        $this->assertTrue(data_get($payload, 'kernel.failure_domains.valid'));
        $this->assertGreaterThanOrEqual(30, data_get($payload, 'kernel.failure_domains.count'));
        $this->assertSame([], data_get($payload, 'kernel.failure_domains.missing_handlers'));
        $this->assertTrue(data_get($payload, 'kernel.failure_classifier.valid'));
        $this->assertGreaterThanOrEqual(30, data_get($payload, 'kernel.failure_classifier.rule_count'));
        $this->assertGreaterThanOrEqual(8, data_get($payload, 'kernel.failure_classifier.status_code_count'));
        $this->assertSame([], data_get($payload, 'kernel.failure_classifier.missing_domains'));
        $this->assertSame([], data_get($payload, 'kernel.failure_classifier.duplicate_signals'));
        $this->assertTrue(data_get($payload, 'kernel.slo_targets.valid'));
        $this->assertGreaterThanOrEqual(14, data_get($payload, 'kernel.slo_targets.count'));
        $this->assertSame('atlas.kernel.slo_target.v1', data_get($payload, 'kernel.slo_targets.schema_version'));
        $this->assertContains('decide.issue', data_get($payload, 'kernel.slo_targets.stages'));
        $this->assertContains('ledger.append', data_get($payload, 'kernel.slo_targets.stages'));
        $this->assertSame([], data_get($payload, 'kernel.slo_targets.errors'));
        $this->assertTrue(data_get($payload, 'kernel.surface_adapters.valid'));
        $this->assertGreaterThanOrEqual(5, data_get($payload, 'kernel.surface_adapters.count'));
        $this->assertContains('atlas_cli_dev', data_get($payload, 'kernel.surface_adapters.surfaces'));
        $this->assertSame([], data_get($payload, 'kernel.surface_adapters.errors'));
        $this->assertTrue(data_get($payload, 'kernel.provider_drivers.valid'));
        $this->assertGreaterThanOrEqual(4, data_get($payload, 'kernel.provider_drivers.count'));
        $this->assertContains('codex_cli', data_get($payload, 'kernel.provider_drivers.providers'));
        $this->assertContains('claude_codex', data_get($payload, 'kernel.provider_drivers.providers'));
        $this->assertSame([], data_get($payload, 'kernel.provider_drivers.errors'));
        $this->assertIsArray(data_get($payload, 'kernel.provider_drivers.warnings'));
        $this->assertTrue(data_get($payload, 'kernel.static_scan.valid'));
        $this->assertSame([], data_get($payload, 'kernel.static_scan.ap1_surface_provider_bypass.violations'));
        $this->assertSame([], data_get($payload, 'kernel.static_scan.ap2_surface_context_bypass.violations'));
        $this->assertSame([], data_get($payload, 'kernel.static_scan.ap6_decision_receipt_propagation.violations'));
        $this->assertSame([], data_get($payload, 'kernel.static_scan.ap13_decision_receipt_runtime_guard.violations'));
        $this->assertSame([], data_get($payload, 'kernel.static_scan.ap12_provider_driver_identity_bypass.violations'));
        $this->assertSame([], data_get($payload, 'kernel.static_scan.ap14_tool_tier_hot_path.violations'));
        $this->assertSame([], data_get($payload, 'kernel.static_scan.ap15_provider_memory_privacy.violations'));
        $this->assertSame([], data_get($payload, 'kernel.static_scan.ap16_slo_observability.violations'));
        $this->assertTrue(data_get($payload, 'kernel.static_scan.ap17_kernel_pipeline_contract.valid'));
        $this->assertSame([], data_get($payload, 'kernel.static_scan.ap17_kernel_pipeline_contract.violations'));
        $this->assertTrue(data_get($payload, 'capabilities.valid'));
        $this->assertGreaterThanOrEqual(1, data_get($payload, 'capabilities.count'));
        $this->assertTrue(data_get($payload, 'orchestrators.valid'));
        $this->assertGreaterThanOrEqual(1, data_get($payload, 'orchestrators.count'));
        $this->assertTrue(data_get($payload, 'domains.valid'));
        $this->assertGreaterThanOrEqual(1, data_get($payload, 'domains.domain_count'));
        $this->assertGreaterThanOrEqual(1, data_get($payload, 'domains.flow_count'));
        $this->assertGreaterThanOrEqual(3, data_get($payload, 'onboarding.ready_domains'));
        $this->assertSame(0, data_get($payload, 'onboarding.executable_incomplete_domains'));
        $this->assertGreaterThanOrEqual(1, data_get($payload, 'onboarding.domain_count'));
    }
}
