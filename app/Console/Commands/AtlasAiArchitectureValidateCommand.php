<?php

namespace App\Console\Commands;

use App\Services\Ai\Kernel\Architecture\AtlasAiArchitectureValidationService;
use Illuminate\Console\Command;

class AtlasAiArchitectureValidateCommand extends Command
{
    protected $signature = 'atlas:ai:architecture-validate
        {--json : Print machine-readable JSON}';

    protected $description = 'Validate Atlas AI executable architecture contracts for capabilities and domain profiles.';

    public function handle(AtlasAiArchitectureValidationService $validation): int
    {
        $payload = $validation->payload();

        if ((bool) $this->option('json')) {
            $this->line(json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));

            return $payload['status'] === 'ok' ? self::SUCCESS : self::FAILURE;
        }

        $this->components->twoColumnDetail('<fg=bright-blue;options=bold>Atlas AI Architecture</>', $payload['status']);
        $this->components->twoColumnDetail('Capabilities', $payload['capabilities']['count'].' across '.$payload['capabilities']['surface_count'].' surfaces');
        $this->components->twoColumnDetail('Orchestrators', (string) $payload['orchestrators']['count']);
        $this->components->twoColumnDetail('Domains', $payload['domains']['domain_count'].' domains / '.$payload['domains']['flow_count'].' flows');
        $this->components->twoColumnDetail('Kernel', $payload['kernel']['failure_domains']['count'].' failure domains / '.$payload['kernel']['failure_classifier']['rule_count'].' classifier rules / '.$payload['kernel']['slo_targets']['count'].' SLO targets');
        $this->components->twoColumnDetail('Surface adapters', (string) $payload['kernel']['surface_adapters']['count']);
        $this->components->twoColumnDetail('Provider drivers', (string) $payload['kernel']['provider_drivers']['count']);
        $this->components->twoColumnDetail('Static APs', data_get($payload, 'kernel.static_scan.summary.passed_count').'/'.data_get($payload, 'kernel.static_scan.summary.total_count').' passed');
        $this->components->twoColumnDetail('Documentation', data_get($payload, 'documentation.status', 'unknown').' / '.data_get($payload, 'documentation.summary.oversized_count', 0).' split-required');
        $this->components->twoColumnDetail('Onboarding', $payload['onboarding']['ready_domains'].' ready / '.$payload['onboarding']['scaffold_domains'].' scaffold / '.$payload['onboarding']['executable_incomplete_domains'].' executable incomplete');
        $this->components->twoColumnDetail('Catalog source', $payload['domains']['source']);

        foreach ($payload['capabilities']['errors'] as $error) {
            $this->error('[capability] '.$error);
        }

        foreach ($payload['capabilities']['surface_adapter_parity']['errors'] as $error) {
            $this->error('[capability.surface] '.$error);
        }

        foreach ($payload['domains']['errors'] as $error) {
            $this->error('[domain] '.$error);
        }

        foreach ($payload['orchestrators']['errors'] as $error) {
            $this->error('[orchestrator] '.$error);
        }

        foreach ($payload['kernel']['failure_domains']['missing_handlers'] as $error) {
            $this->error('[kernel.failure] missing handler for '.$error);
        }

        foreach ($payload['kernel']['failure_classifier']['missing_domains'] as $error) {
            $this->error('[kernel.failure_classifier] missing classifier rule for '.$error);
        }

        foreach ($payload['kernel']['failure_classifier']['duplicate_signals'] as $error) {
            $this->error('[kernel.failure_classifier] duplicate classifier signal '.$error);
        }

        foreach ($payload['kernel']['slo_targets']['errors'] as $error) {
            $this->error('[kernel.slo] '.$error);
        }

        foreach ($payload['kernel']['surface_adapters']['errors'] as $error) {
            $this->error('[kernel.surface] '.$error);
        }

        foreach ($payload['kernel']['provider_drivers']['errors'] as $error) {
            $this->error('[kernel.provider] '.$error);
        }

        foreach ($payload['kernel']['provider_drivers']['warnings'] as $warning) {
            $this->warn('[kernel.provider] '.$warning);
        }

        foreach ($payload['kernel']['static_scan']['ap1_surface_provider_bypass']['violations'] as $violation) {
            $this->error('[kernel.static.ap1] '.$violation);
        }

        foreach ($payload['kernel']['static_scan']['ap2_surface_context_bypass']['violations'] as $violation) {
            $this->error('[kernel.static.ap2] '.$violation);
        }

        foreach ($payload['kernel']['static_scan']['ap6_decision_receipt_propagation']['violations'] as $violation) {
            $this->error('[kernel.static.ap6] '.$violation);
        }

        foreach ($payload['kernel']['static_scan']['ap13_decision_receipt_runtime_guard']['violations'] as $violation) {
            $this->error('[kernel.static.ap13] '.$violation);
        }

        foreach ($payload['kernel']['static_scan']['ap12_provider_driver_identity_bypass']['violations'] as $violation) {
            $this->error('[kernel.static.ap12] '.$violation);
        }

        foreach ($payload['kernel']['static_scan']['ap14_tool_tier_hot_path']['violations'] as $violation) {
            $this->error('[kernel.static.ap14] '.$violation);
        }

        foreach ($payload['kernel']['static_scan']['ap15_provider_memory_privacy']['violations'] as $violation) {
            $this->error('[kernel.static.ap15] '.$violation);
        }

        foreach ($payload['kernel']['static_scan']['ap16_slo_observability']['violations'] as $violation) {
            $this->error('[kernel.static.ap16] '.$violation);
        }

        foreach ($payload['kernel']['static_scan']['ap17_kernel_pipeline_contract']['violations'] as $violation) {
            $this->error('[kernel.static.ap17] '.$violation);
        }

        foreach ($payload['kernel']['static_scan']['ap18_repair_loop_contract']['violations'] as $violation) {
            $this->error('[kernel.static.ap18] '.$violation);
        }

        foreach ($payload['kernel']['static_scan']['ap19_mcp_domain_catalog_parity']['violations'] as $violation) {
            $this->error('[kernel.static.ap19] '.$violation);
        }

        foreach ($payload['kernel']['static_scan']['ap20_cli_fix_dev_repair_alias']['violations'] as $violation) {
            $this->error('[kernel.static.ap20] '.$violation);
        }

        foreach ($payload['kernel']['static_scan']['ap21_cli_continue_dev_resume_alias']['violations'] as $violation) {
            $this->error('[kernel.static.ap21] '.$violation);
        }

        foreach ($payload['kernel']['static_scan']['ap22_cli_forge_programming_harness_contract']['violations'] as $violation) {
            $this->error('[kernel.static.ap22] '.$violation);
        }

        foreach ($payload['kernel']['static_scan']['ap23_chat_dev_programming_contract']['violations'] as $violation) {
            $this->error('[kernel.static.ap23] '.$violation);
        }

        foreach (data_get($payload, 'documentation.violations', []) as $violation) {
            $this->error('[documentation] '.$violation);
        }

        foreach ($payload['kernel']['static_scan']['ap24_surface_alias_canonicalization']['violations'] as $violation) {
            $this->error('[kernel.static.ap24] '.$violation);
        }

        foreach ($payload['kernel']['static_scan']['ap25_decide_model_selection_contract']['violations'] as $violation) {
            $this->error('[kernel.static.ap25] '.$violation);
        }

        foreach ($payload['kernel']['static_scan']['ap26_cli_dev_model_selection_contract']['violations'] as $violation) {
            $this->error('[kernel.static.ap26] '.$violation);
        }

        foreach ($payload['kernel']['static_scan']['ap27_chat_model_selection_contract']['violations'] as $violation) {
            $this->error('[kernel.static.ap27] '.$violation);
        }

        foreach ($payload['kernel']['static_scan']['ap28_kernel_model_selection_contract_factory']['violations'] as $violation) {
            $this->error('[kernel.static.ap28] '.$violation);
        }

        foreach ($payload['kernel']['static_scan']['ap29_programming_surface_contract_factory']['violations'] as $violation) {
            $this->error('[kernel.static.ap29] '.$violation);
        }

        foreach ($payload['kernel']['static_scan']['ap30_chat_programming_contract_factory']['violations'] as $violation) {
            $this->error('[kernel.static.ap30] '.$violation);
        }

        foreach ($payload['kernel']['static_scan']['ap31_continue_resume_contract_factory']['violations'] as $violation) {
            $this->error('[kernel.static.ap31] '.$violation);
        }

        foreach ($payload['kernel']['static_scan']['ap32_fix_contract_factory']['violations'] as $violation) {
            $this->error('[kernel.static.ap32] '.$violation);
        }

        foreach ($payload['kernel']['static_scan']['ap33_surface_capability_parity']['violations'] as $violation) {
            $this->error('[kernel.static.ap33] '.$violation);
        }

        foreach ($payload['kernel']['static_scan']['ap34_capability_surface_coverage']['violations'] as $violation) {
            $this->error('[kernel.static.ap34] '.$violation);
        }

        foreach ($payload['kernel']['static_scan']['ap35_surface_adapter_parity_map_coverage']['violations'] as $violation) {
            $this->error('[kernel.static.ap35] '.$violation);
        }

        foreach ($payload['kernel']['static_scan']['ap36_kernel_pipeline_health_read_model']['violations'] as $violation) {
            $this->error('[kernel.static.ap36] '.$violation);
        }

        foreach ($payload['kernel']['static_scan']['ap37_architecture_validation_surface']['violations'] as $violation) {
            $this->error('[kernel.static.ap37] '.$violation);
        }

        foreach ($payload['kernel']['static_scan']['ap38_architecture_validation_observability']['violations'] as $violation) {
            $this->error('[kernel.static.ap38] '.$violation);
        }

        foreach ($payload['kernel']['static_scan']['ap39_architecture_validation_contract_parity']['violations'] as $violation) {
            $this->error('[kernel.static.ap39] '.$violation);
        }

        foreach ($payload['kernel']['static_scan']['ap40_architecture_validation_mcp_tool']['violations'] as $violation) {
            $this->error('[kernel.static.ap40] '.$violation);
        }

        foreach ($payload['kernel']['static_scan']['ap41_self_improvement_architecture_validation_review']['violations'] as $violation) {
            $this->error('[kernel.static.ap41] '.$violation);
        }

        foreach ($payload['kernel']['static_scan']['ap42_self_improvement_architecture_audit_schedule']['violations'] as $violation) {
            $this->error('[kernel.static.ap42] '.$violation);
        }

        foreach ($payload['kernel']['static_scan']['ap43_self_improvement_flow_cadence_contract']['violations'] as $violation) {
            $this->error('[kernel.static.ap43] '.$violation);
        }

        foreach ($payload['kernel']['static_scan']['ap44_self_improvement_command_next_run_contract']['violations'] as $violation) {
            $this->error('[kernel.static.ap44] '.$violation);
        }

        foreach ($payload['kernel']['static_scan']['ap45_self_improvement_schedule_mcp_tool']['violations'] as $violation) {
            $this->error('[kernel.static.ap45] '.$violation);
        }

        foreach ($payload['kernel']['static_scan']['ap46_self_improvement_schedule_health_review']['violations'] as $violation) {
            $this->error('[kernel.static.ap46] '.$violation);
        }

        foreach ($payload['kernel']['static_scan']['ap47_self_improvement_schedule_health_ledger_event']['violations'] as $violation) {
            $this->error('[kernel.static.ap47] '.$violation);
        }

        foreach ($payload['kernel']['static_scan']['ap48_self_improvement_schedule_replay_read_model']['violations'] as $violation) {
            $this->error('[kernel.static.ap48] '.$violation);
        }

        foreach ($payload['kernel']['static_scan']['ap49_self_improvement_schedule_replay_surfaces']['violations'] as $violation) {
            $this->error('[kernel.static.ap49] '.$violation);
        }

        foreach ($payload['kernel']['static_scan']['ap50_self_improvement_schedule_replay_mcp_tool']['violations'] as $violation) {
            $this->error('[kernel.static.ap50] '.$violation);
        }

        foreach ($payload['kernel']['static_scan']['ap51_self_improvement_schedule_replay_review']['violations'] as $violation) {
            $this->error('[kernel.static.ap51] '.$violation);
        }

        foreach ($payload['kernel']['static_scan']['ap52_self_improvement_schedule_replay_review_signal']['violations'] as $violation) {
            $this->error('[kernel.static.ap52] '.$violation);
        }

        foreach ($payload['kernel']['static_scan']['ap53_self_improvement_schedule_replay_review_signal_surfaces']['violations'] as $violation) {
            $this->error('[kernel.static.ap53] '.$violation);
        }

        foreach ($payload['kernel']['static_scan']['ap54_kernel_pipeline_review_signal']['violations'] as $violation) {
            $this->error('[kernel.static.ap54] '.$violation);
        }

        foreach ($payload['kernel']['static_scan']['ap55_repair_loop_review_signal']['violations'] as $violation) {
            $this->error('[kernel.static.ap55] '.$violation);
        }

        foreach ($payload['kernel']['static_scan']['ap56_slo_review_signal']['violations'] as $violation) {
            $this->error('[kernel.static.ap56] '.$violation);
        }

        foreach ($payload['kernel']['static_scan']['ap57_slo_mcp_tool']['violations'] as $violation) {
            $this->error('[kernel.static.ap57] '.$violation);
        }

        foreach ($payload['kernel']['static_scan']['ap58_kernel_pipeline_mcp_tool']['violations'] as $violation) {
            $this->error('[kernel.static.ap58] '.$violation);
        }

        foreach ($payload['kernel']['static_scan']['ap59_repair_loop_mcp_tool']['violations'] as $violation) {
            $this->error('[kernel.static.ap59] '.$violation);
        }

        foreach ($payload['kernel']['static_scan']['ap60_repair_loop_unavailable_review_signal']['violations'] as $violation) {
            $this->error('[kernel.static.ap60] '.$violation);
        }

        foreach ($payload['kernel']['static_scan']['ap61_slo_unavailable_review_signal']['violations'] as $violation) {
            $this->error('[kernel.static.ap61] '.$violation);
        }

        foreach ($payload['kernel']['static_scan']['ap62_mcp_replay_unavailable_review_signal']['violations'] as $violation) {
            $this->error('[kernel.static.ap62] '.$violation);
        }

        foreach ($payload['kernel']['static_scan']['ap63_schedule_replay_unavailable_shape_parity']['violations'] as $violation) {
            $this->error('[kernel.static.ap63] '.$violation);
        }

        foreach ($payload['kernel']['static_scan']['ap64_mcp_replay_window_contract']['violations'] as $violation) {
            $this->error('[kernel.static.ap64] '.$violation);
        }

        foreach ($payload['kernel']['static_scan']['ap65_mcp_replay_filter_contract']['violations'] as $violation) {
            $this->error('[kernel.static.ap65] '.$violation);
        }

        foreach ($payload['kernel']['static_scan']['ap66_replay_report_input_contract']['violations'] as $violation) {
            $this->error('[kernel.static.ap66] '.$violation);
        }

        foreach ($payload['kernel']['static_scan']['ap67_observability_replay_input_contract']['violations'] as $violation) {
            $this->error('[kernel.static.ap67] '.$violation);
        }

        foreach ($payload['kernel']['static_scan']['ap68_replay_report_validation_limit_contract']['violations'] as $violation) {
            $this->error('[kernel.static.ap68] '.$violation);
        }

        foreach ($payload['kernel']['static_scan']['ap69_self_improvement_runtime_window_contract']['violations'] as $violation) {
            $this->error('[kernel.static.ap69] '.$violation);
        }

        foreach ($payload['kernel']['static_scan']['ap70_self_improvement_schedule_window_contract']['violations'] as $violation) {
            $this->error('[kernel.static.ap70] '.$violation);
        }

        foreach ($payload['kernel']['static_scan']['ap71_self_improvement_orchestrator_window_contract']['violations'] as $violation) {
            $this->error('[kernel.static.ap71] '.$violation);
        }

        foreach ($payload['kernel']['static_scan']['ap72_telemetry_window_input_contract']['violations'] as $violation) {
            $this->error('[kernel.static.ap72] '.$violation);
        }

        foreach ($payload['kernel']['static_scan']['ap73_runtime_budget_window_contract']['violations'] as $violation) {
            $this->error('[kernel.static.ap73] '.$violation);
        }

        foreach ($payload['kernel']['static_scan']['ap74_ledger_envelope_input_contract']['violations'] as $violation) {
            $this->error('[kernel.static.ap74] '.$violation);
        }

        foreach ($payload['kernel']['static_scan']['ap75_ledger_envelope_report_contract']['violations'] as $violation) {
            $this->error('[kernel.static.ap75] '.$violation);
        }

        foreach ($payload['kernel']['static_scan']['ap76_telemetry_list_limit_contract']['violations'] as $violation) {
            $this->error('[kernel.static.ap76] '.$violation);
        }

        foreach ($payload['kernel']['static_scan']['ap77_programming_iteration_policy_contract']['violations'] as $violation) {
            $this->error('[kernel.static.ap77] '.$violation);
        }

        foreach ($payload['kernel']['static_scan']['ap78_open_brain_mcp_input_contract']['violations'] as $violation) {
            $this->error('[kernel.static.ap78] '.$violation);
        }

        foreach ($payload['kernel']['static_scan']['ap79_memory_query_input_contract']['violations'] as $violation) {
            $this->error('[kernel.static.ap79] '.$violation);
        }

        foreach ($payload['kernel']['static_scan']['ap80_provider_projection_audit_input_contract']['violations'] as $violation) {
            $this->error('[kernel.static.ap80] '.$violation);
        }

        foreach ($payload['kernel']['static_scan']['ap81_conversation_context_input_contract']['violations'] as $violation) {
            $this->error('[kernel.static.ap81] '.$violation);
        }

        foreach ($payload['kernel']['static_scan']['ap82_retrieval_rank_input_contract']['violations'] as $violation) {
            $this->error('[kernel.static.ap82] '.$violation);
        }

        foreach ($payload['kernel']['static_scan']['ap83_atlas_vault_command_input_contract']['violations'] as $violation) {
            $this->error('[kernel.static.ap83] '.$violation);
        }

        foreach ($payload['kernel']['static_scan']['ap84_memory_recall_input_contract']['violations'] as $violation) {
            $this->error('[kernel.static.ap84] '.$violation);
        }

        foreach ($payload['kernel']['static_scan']['ap85_context_pack_memory_input_contract']['violations'] as $violation) {
            $this->error('[kernel.static.ap85] '.$violation);
        }

        foreach ($payload['kernel']['static_scan']['ap86_semantic_context_input_contract']['violations'] as $violation) {
            $this->error('[kernel.static.ap86] '.$violation);
        }

        foreach ($payload['kernel']['static_scan']['ap87_provider_projection_input_contract']['violations'] as $violation) {
            $this->error('[kernel.static.ap87] '.$violation);
        }

        foreach ($payload['kernel']['static_scan']['ap88_test_command_input_contract']['violations'] as $violation) {
            $this->error('[kernel.static.ap88] '.$violation);
        }

        foreach ($payload['kernel']['static_scan']['ap89_engineering_harness_runner_input_contract']['violations'] as $violation) {
            $this->error('[kernel.static.ap89] '.$violation);
        }

        foreach ($payload['kernel']['static_scan']['ap90_engineering_harnessability_input_contract']['violations'] as $violation) {
            $this->error('[kernel.static.ap90] '.$violation);
        }

        foreach ($payload['kernel']['static_scan']['ap91_engineering_docker_harness_input_contract']['violations'] as $violation) {
            $this->error('[kernel.static.ap91] '.$violation);
        }

        foreach ($payload['kernel']['static_scan']['ap92_engineering_test_matrix_input_contract']['violations'] as $violation) {
            $this->error('[kernel.static.ap92] '.$violation);
        }

        foreach ($payload['kernel']['static_scan']['ap93_engineering_claude_code_baseline_input_contract']['violations'] as $violation) {
            $this->error('[kernel.static.ap93] '.$violation);
        }

        foreach ($payload['kernel']['static_scan']['ap94_engineering_benchmark_input_contract']['violations'] as $violation) {
            $this->error('[kernel.static.ap94] '.$violation);
        }

        foreach ($payload['kernel']['static_scan']['ap95_engineering_context_intelligence_input_contract']['violations'] as $violation) {
            $this->error('[kernel.static.ap95] '.$violation);
        }

        foreach ($payload['kernel']['static_scan']['ap96_cli_limit_input_contract']['violations'] as $violation) {
            $this->error('[kernel.static.ap96] '.$violation);
        }

        foreach ($payload['kernel']['static_scan']['ap97_scheduler_input_contract']['violations'] as $violation) {
            $this->error('[kernel.static.ap97] '.$violation);
        }

        foreach ($payload['kernel']['static_scan']['ap98_self_improvement_input_contract']['violations'] as $violation) {
            $this->error('[kernel.static.ap98] '.$violation);
        }

        $this->renderPostAp98StaticScanViolations($payload);

        foreach ($payload['capabilities']['warnings'] as $warning) {
            $this->warn('[capability] '.$warning);
        }

        foreach ($payload['domains']['warnings'] as $warning) {
            $this->warn('[domain] '.$warning);
        }

        foreach ($payload['orchestrators']['warnings'] as $warning) {
            $this->warn('[orchestrator] '.$warning);
        }

        return $payload['status'] === 'ok' ? self::SUCCESS : self::FAILURE;
    }

    /**
     * @param  array<string,mixed>  $payload
     */
    private function renderPostAp98StaticScanViolations(array $payload): void
    {
        foreach ((array) data_get($payload, 'kernel.static_scan', []) as $key => $report) {
            if (! is_string($key) || $key === 'summary' || ! is_array($report)) {
                continue;
            }

            if (! preg_match('/^ap(?P<number>\d+)_/', $key, $matches)) {
                continue;
            }

            if ((int) $matches['number'] <= 98) {
                continue;
            }

            foreach ((array) ($report['violations'] ?? []) as $violation) {
                $this->error("[kernel.static.{$key}] ".$violation);
            }
        }
    }
}
