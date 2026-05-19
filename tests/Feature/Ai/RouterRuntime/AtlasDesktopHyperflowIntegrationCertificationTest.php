<?php

namespace Tests\Feature\Ai\RouterRuntime;

use App\Services\Ai\RouterRuntime\AtlasDesktopHyperflowIntegrationCertificationService;
use Tests\TestCase;

/**
 * Anti-regression certification for the Atlas Desktop AI ↔ Hyperflow ↔
 * Rich Input integration. Asserts the four canonical checks listed in
 * `docs/engineering-knowledge-base/atlas-hyperflow-operation.md#Atlas Desktop AI Hyperflow Integration Canon`:
 *
 *   - desktop_hyperflow_runtime_integration
 *   - atlas_rich_input_shared_runtime
 *   - forge_rich_input_adapter
 *   - no_legacy_programming_dev_default
 *   - composer_to_hyperflow_enterprise_path
 *
 * No provider invocation. No rivals. No benchmark. This test exists to
 * make regressions in either layer (backend wiring or Desktop default)
 * obvious before they reach the cert API surface.
 */
class AtlasDesktopHyperflowIntegrationCertificationTest extends TestCase
{
    public function test_certification_envelope_shape_is_stable(): void
    {
        $result = app(AtlasDesktopHyperflowIntegrationCertificationService::class)->certify();

        $this->assertSame(
            AtlasDesktopHyperflowIntegrationCertificationService::SCHEMA_VERSION,
            $result['schema_version'],
        );
        $this->assertContains($result['status'], ['passed', 'blocked']);
        $this->assertArrayHasKey('summary', $result);
        $this->assertArrayHasKey('checks', $result);
        $this->assertSame(5, count($result['checks']));
        $this->assertFalse($result['writes']);
        $this->assertFalse($result['declares_teos']);
        $this->assertFalse($result['declares_benchmark']);
    }

    public function test_all_four_canonical_checks_pass_on_current_tree(): void
    {
        $result = app(AtlasDesktopHyperflowIntegrationCertificationService::class)->certify();

        $byId = [];
        foreach ($result['checks'] as $check) {
            $byId[$check['id']] = $check;
        }

        $this->assertSame(
            'passed',
            $byId['desktop_hyperflow_runtime_integration']['status'],
            'orchestrator must be wired BEFORE legacy router; gateway must propagate; resource must expose both shapes',
        );
        $this->assertSame(
            'passed',
            $byId['atlas_rich_input_shared_runtime']['status'],
            'Desktop attachments barrel + backend caps + doc canon must align',
        );
        $this->assertSame(
            'passed',
            $byId['forge_rich_input_adapter']['status'],
            'Forge work controller must accept rich_input.* + no parallel attachments runtime',
        );
        $this->assertSame(
            'passed',
            $byId['no_legacy_programming_dev_default']['status'],
            'Desktop must open in auto/auto and never silently degrade to programming.dev',
        );
        $this->assertSame(
            'passed',
            $byId['composer_to_hyperflow_enterprise_path']['status'],
            'Composer scope, bridge hints and Hyperflow surface-contract routing must be end-to-end locked',
        );

        $this->assertSame('passed', $result['status']);
        $this->assertSame([], $result['remaining_blockers']);
    }

    public function test_composer_to_hyperflow_enterprise_path_evidence_pins_bad_prompt_route(): void
    {
        $result = app(AtlasDesktopHyperflowIntegrationCertificationService::class)->certify();
        $byId = [];
        foreach ($result['checks'] as $check) {
            $byId[$check['id']] = $check;
        }
        $evidence = $byId['composer_to_hyperflow_enterprise_path']['evidence'];

        $this->assertTrue($evidence['desktop_composer_blocks_scope_escape']);
        $this->assertTrue($evidence['forge_composer_scoped_to_obra_modes']);
        $this->assertTrue($evidence['desktop_and_tauri_bridge_carry_composer_hints']);
        $this->assertTrue($evidence['hyperflow_consumes_surface_contract']);
        $this->assertTrue($evidence['intent_kernel_audits_surface_override']);
        $this->assertTrue($evidence['domain_router_honors_forge_mode_and_tool_plan']);
        $this->assertTrue($evidence['integration_tests_cover_bad_prompt_paths']);
    }

    public function test_desktop_hyperflow_runtime_integration_evidence_pins_the_call_order(): void
    {
        $result = app(AtlasDesktopHyperflowIntegrationCertificationService::class)->certify();
        $byId = [];
        foreach ($result['checks'] as $check) {
            $byId[$check['id']] = $check;
        }
        $evidence = $byId['desktop_hyperflow_runtime_integration']['evidence'];

        $this->assertTrue($evidence['orchestrator_present']);
        $this->assertTrue($evidence['controller_wires_orchestrator']);
        $this->assertTrue($evidence['orchestrator_runs_before_legacy_router']);
        $this->assertTrue($evidence['gateway_propagates_hyperflow_runtime']);
        $this->assertTrue($evidence['resource_exposes_hyperflow_runtime']);
        $this->assertTrue($evidence['resource_exposes_flat_hyperflow']);
    }

    public function test_no_legacy_programming_dev_default_evidence_pins_auto_first_invariants(): void
    {
        $result = app(AtlasDesktopHyperflowIntegrationCertificationService::class)->certify();
        $byId = [];
        foreach ($result['checks'] as $check) {
            $byId[$check['id']] = $check;
        }
        $evidence = $byId['no_legacy_programming_dev_default']['evidence'];

        $this->assertTrue($evidence['mode_options_auto_first'], 'MODE_OPTIONS[0].value must be `auto`');
        $this->assertTrue($evidence['default_task_for_auto_is_auto'], 'defaultTaskForMode(auto) must be `auto`');
        $this->assertTrue($evidence['flow_id_for_auto_auto_is_auto'], "flowIdForMode('auto','auto') must be 'auto'");
        $this->assertTrue($evidence['use_atlas_ai_starts_auto_auto'], "useAtlasAi must start with composerMode='auto' AND composerTask='auto'");
    }
}
