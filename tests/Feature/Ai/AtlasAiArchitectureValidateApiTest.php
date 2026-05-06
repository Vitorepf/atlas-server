<?php

namespace Tests\Feature\Ai;

use App\Services\Ai\Kernel\Architecture\AtlasAiArchitectureValidationService;
use Tests\TestCase;

class AtlasAiArchitectureValidateApiTest extends TestCase
{
    private array $headers = ['X-Atlas-Token' => 'test-token-with-enough-length-123'];

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('atlas.token', 'test-token-with-enough-length-123');
    }

    public function test_architecture_validate_api_exposes_kernel_contract_for_app_and_curator(): void
    {
        $response = $this->getJson('/ai/architecture/validate', $this->headers)
            ->assertOk()
            ->assertJsonPath('status', 'ok')
            ->assertJsonPath('kernel.valid', true)
            ->assertJsonPath('kernel.static_scan.valid', true)
            ->assertJsonPath('kernel.static_scan.summary.failed_count', 0)
            ->assertJsonPath('kernel.static_scan.summary.violation_count', 0)
            ->assertJsonPath('capabilities.valid', true)
            ->assertJsonPath('domains.valid', true)
            ->assertJsonPath('orchestrators.valid', true);

        $this->assertGreaterThanOrEqual(30, $response->json('kernel.static_scan.summary.total_count'));
        $this->assertSame(
            $response->json('kernel.static_scan.summary.total_count'),
            $response->json('kernel.static_scan.summary.passed_count'),
        );
        $this->assertContains('ap36_kernel_pipeline_health_read_model', $response->json('kernel.static_scan.summary.valid_keys'));
        $this->assertContains('ap37_architecture_validation_surface', $response->json('kernel.static_scan.summary.valid_keys'));
        $this->assertContains('ap38_architecture_validation_observability', $response->json('kernel.static_scan.summary.valid_keys'));
        $this->assertContains('ap39_architecture_validation_contract_parity', $response->json('kernel.static_scan.summary.valid_keys'));
        $this->assertContains('ap40_architecture_validation_mcp_tool', $response->json('kernel.static_scan.summary.valid_keys'));
        $this->assertContains('ap41_self_improvement_architecture_validation_review', $response->json('kernel.static_scan.summary.valid_keys'));
        $this->assertContains('ap42_self_improvement_architecture_audit_schedule', $response->json('kernel.static_scan.summary.valid_keys'));
        $this->assertContains('ap43_self_improvement_flow_cadence_contract', $response->json('kernel.static_scan.summary.valid_keys'));
        $this->assertContains('ap44_self_improvement_command_next_run_contract', $response->json('kernel.static_scan.summary.valid_keys'));
        $this->assertContains('ap45_self_improvement_schedule_mcp_tool', $response->json('kernel.static_scan.summary.valid_keys'));
        $this->assertContains('ap46_self_improvement_schedule_health_review', $response->json('kernel.static_scan.summary.valid_keys'));
        $this->assertContains('ap47_self_improvement_schedule_health_ledger_event', $response->json('kernel.static_scan.summary.valid_keys'));
        $this->assertContains('ap48_self_improvement_schedule_replay_read_model', $response->json('kernel.static_scan.summary.valid_keys'));
        $this->assertContains('ap49_self_improvement_schedule_replay_surfaces', $response->json('kernel.static_scan.summary.valid_keys'));
        $this->assertContains('ap50_self_improvement_schedule_replay_mcp_tool', $response->json('kernel.static_scan.summary.valid_keys'));
        $this->assertContains('ap51_self_improvement_schedule_replay_review', $response->json('kernel.static_scan.summary.valid_keys'));
        $this->assertContains('ap52_self_improvement_schedule_replay_review_signal', $response->json('kernel.static_scan.summary.valid_keys'));
        $this->assertContains('ap53_self_improvement_schedule_replay_review_signal_surfaces', $response->json('kernel.static_scan.summary.valid_keys'));
        $this->assertContains('ap54_kernel_pipeline_review_signal', $response->json('kernel.static_scan.summary.valid_keys'));
        $this->assertContains('ap55_repair_loop_review_signal', $response->json('kernel.static_scan.summary.valid_keys'));
        $this->assertContains('ap56_slo_review_signal', $response->json('kernel.static_scan.summary.valid_keys'));
        $this->assertContains('ap57_slo_mcp_tool', $response->json('kernel.static_scan.summary.valid_keys'));
        $this->assertContains('ap58_kernel_pipeline_mcp_tool', $response->json('kernel.static_scan.summary.valid_keys'));
        $this->assertContains('ap59_repair_loop_mcp_tool', $response->json('kernel.static_scan.summary.valid_keys'));
        $this->assertContains('ap60_repair_loop_unavailable_review_signal', $response->json('kernel.static_scan.summary.valid_keys'));
        $this->assertContains('ap61_slo_unavailable_review_signal', $response->json('kernel.static_scan.summary.valid_keys'));
        $this->assertContains('ap62_mcp_replay_unavailable_review_signal', $response->json('kernel.static_scan.summary.valid_keys'));
        $this->assertContains('ap63_schedule_replay_unavailable_shape_parity', $response->json('kernel.static_scan.summary.valid_keys'));
        $this->assertContains('ap64_mcp_replay_window_contract', $response->json('kernel.static_scan.summary.valid_keys'));
        $this->assertContains('ap65_mcp_replay_filter_contract', $response->json('kernel.static_scan.summary.valid_keys'));
        $this->assertContains('ap66_replay_report_input_contract', $response->json('kernel.static_scan.summary.valid_keys'));
        $this->assertContains('ap67_observability_replay_input_contract', $response->json('kernel.static_scan.summary.valid_keys'));
        $this->assertContains('ap68_replay_report_validation_limit_contract', $response->json('kernel.static_scan.summary.valid_keys'));
        $this->assertContains('ap69_self_improvement_runtime_window_contract', $response->json('kernel.static_scan.summary.valid_keys'));
        $this->assertContains('ap70_self_improvement_schedule_window_contract', $response->json('kernel.static_scan.summary.valid_keys'));
        $this->assertContains('ap71_self_improvement_orchestrator_window_contract', $response->json('kernel.static_scan.summary.valid_keys'));
        $this->assertContains('ap72_telemetry_window_input_contract', $response->json('kernel.static_scan.summary.valid_keys'));
        $this->assertContains('ap73_runtime_budget_window_contract', $response->json('kernel.static_scan.summary.valid_keys'));
        $this->assertContains('ap74_ledger_envelope_input_contract', $response->json('kernel.static_scan.summary.valid_keys'));
        $this->assertContains('ap75_ledger_envelope_report_contract', $response->json('kernel.static_scan.summary.valid_keys'));
        $this->assertContains('ap76_telemetry_list_limit_contract', $response->json('kernel.static_scan.summary.valid_keys'));
        $this->assertContains('ap77_programming_iteration_policy_contract', $response->json('kernel.static_scan.summary.valid_keys'));
        $this->assertContains('ap78_open_brain_mcp_input_contract', $response->json('kernel.static_scan.summary.valid_keys'));
        $this->assertContains('ap79_memory_query_input_contract', $response->json('kernel.static_scan.summary.valid_keys'));
        $this->assertContains('ap80_provider_projection_audit_input_contract', $response->json('kernel.static_scan.summary.valid_keys'));
        $this->assertContains('ap81_conversation_context_input_contract', $response->json('kernel.static_scan.summary.valid_keys'));
        $this->assertContains('ap82_retrieval_rank_input_contract', $response->json('kernel.static_scan.summary.valid_keys'));
        $this->assertContains('ap83_atlas_vault_command_input_contract', $response->json('kernel.static_scan.summary.valid_keys'));
        $this->assertContains('ap84_memory_recall_input_contract', $response->json('kernel.static_scan.summary.valid_keys'));
        $this->assertContains('ap85_context_pack_memory_input_contract', $response->json('kernel.static_scan.summary.valid_keys'));
        $this->assertContains('ap86_semantic_context_input_contract', $response->json('kernel.static_scan.summary.valid_keys'));
        $this->assertContains('ap87_provider_projection_input_contract', $response->json('kernel.static_scan.summary.valid_keys'));
        $this->assertContains('ap88_test_command_input_contract', $response->json('kernel.static_scan.summary.valid_keys'));
        $this->assertSame([], $response->json('kernel.static_scan.summary.failed_keys'));
        $this->assertSame([], $response->json('kernel.static_scan.ap35_surface_adapter_parity_map_coverage.unmapped_adapters'));
    }

    public function test_architecture_validate_api_matches_shared_service_contract(): void
    {
        $expected = $this->app->make(AtlasAiArchitectureValidationService::class)->payload();

        $response = $this->getJson('/ai/architecture/validate', $this->headers)
            ->assertOk();

        $this->assertSame($expected['status'], $response->json('status'));
        $this->assertSame(data_get($expected, 'kernel.valid'), $response->json('kernel.valid'));
        $this->assertSame(data_get($expected, 'kernel.static_scan.summary.total_count'), $response->json('kernel.static_scan.summary.total_count'));
        $this->assertSame(data_get($expected, 'kernel.static_scan.summary.failed_count'), $response->json('kernel.static_scan.summary.failed_count'));
        $this->assertSame(data_get($expected, 'kernel.static_scan.summary.violation_count'), $response->json('kernel.static_scan.summary.violation_count'));
        $this->assertSame(data_get($expected, 'capabilities.count'), $response->json('capabilities.count'));
        $this->assertSame(data_get($expected, 'domains.domain_count'), $response->json('domains.domain_count'));
        $this->assertSame(data_get($expected, 'domains.flow_count'), $response->json('domains.flow_count'));
        $this->assertSame(data_get($expected, 'orchestrators.count'), $response->json('orchestrators.count'));
        $this->assertSame(data_get($expected, 'onboarding.domain_count'), $response->json('onboarding.domain_count'));
        $this->assertSame(data_get($expected, 'onboarding.status_counts'), $response->json('onboarding.status_counts'));
    }

    public function test_architecture_validate_api_requires_atlas_token(): void
    {
        $this->getJson('/ai/architecture/validate')
            ->assertUnauthorized();
    }
}
