<?php

namespace App\Services\Ai\Kernel\Architecture\Scanner;

use Illuminate\Support\Facades\File;

class ProviderAudit
{
    public function __construct(private ScanPrimitivesSupport $primitives)
    {
    }

    /**
     * @return array<string,callable(): array<int,string>>
     */
    public function checks(): array
    {
        return [
            'ap146_provider_cost_rate_inbox_replay' => fn (): array => $this->scanProviderCostRateInboxReplay(),
            'ap15_provider_memory_privacy' => fn (): array => $this->scanProviderMemoryPrivacy(),
            'ap80_provider_projection_audit_input_contract' => fn (): array => $this->scanProviderProjectionAuditInputContract(),
            'ap87_provider_projection_input_contract' => fn (): array => $this->scanProviderProjectionInputContract(),
            'ap99_provider_usage_performance_contract' => fn (): array => $this->scanProviderUsagePerformanceContract(),
            'ap178_provider_release_anti_wrapper_contract' => fn (): array => $this->scanProviderReleaseAntiWrapperContract(),
        ];
    }

    /**
     * @return array<int,string>
     */
    private function scanProviderReleaseAntiWrapperContract(): array
    {
        $violations = [];
        $servicePath = app_path('Services/Ai/Kernel/Architecture/AtlasProviderReleaseIntelligenceService.php');
        $sourceRegistryPath = app_path('Services/Ai/Kernel/Architecture/AtlasProviderReleaseSourceRegistry.php');
        $commandTestPath = base_path('tests/Feature/Ai/AtlasAiProviderReleaseReviewCommandTest.php');
        $docPath = base_path('docs/engineering-knowledge-base/atlas-ai-provider-evolution-intelligence.md');
        $matrixPath = base_path('docs/engineering-knowledge-base/architecture-audit/implemented-vs-scaffold-matrix.md');

        $service = File::exists($servicePath) ? File::get($servicePath) : '';
        $sourceRegistry = File::exists($sourceRegistryPath) ? File::get($sourceRegistryPath) : '';
        $commandTest = File::exists($commandTestPath) ? File::get($commandTestPath) : '';
        $doc = File::exists($docPath) ? File::get($docPath) : '';
        $matrix = File::exists($matrixPath) ? File::get($matrixPath) : '';

        foreach ([
            "'anti_wrapper_contract' => \$this->antiWrapperContract",
            'atlas.provider_release.anti_wrapper_contract.v1',
            'atlas_substitutes_direct_provider_channels_by_orchestrating_them',
            'external_provider_improvement_must_make_atlas_stronger_or_be_archived',
            'benchmark_against_direct_provider_baseline',
            'direct_provider_channel_as_primary_product',
            "'default_model_change_allowed' => false",
            "'manual_override_only_until_promoted' => true",
            "'may_change_default_model' => false",
            "'may_change_domain_maturity' => false",
            "'may_store_provider_credentials' => false",
            "'may_call_provider_vertical_directly' => false",
            "'promotion_gate' => [",
            'atlas.provider_release.promotion_gate.v1',
            "'promotion_allowed' => false",
            "'routing_promotion_allowed_now' => false",
            "'domain_maturity_promotion_allowed_now' => false",
            "'credential_activation_allowed_now' => false",
            "'provider_direct_channel_allowed_now' => false",
            'atlas.provider_release.promotion_review_packet.v1',
            "'required_human_decision' => 'approve_or_reject_provider_release_absorption'",
            "'required_decision_receipt' => true",
            "'rollback_plan_required' => true",
            "'policy_patch_review_required' => true",
            'change_atlas_decide_routing_policy',
            'press_release_to_default_model',
            'provider_vertical_agent_to_domain_ready',
        ] as $token) {
            if (! str_contains($service, $token)) {
                $violations[] = "app/Services/Ai/Kernel/Architecture/AtlasProviderReleaseIntelligenceService.php: AP-178 provider release anti-wrapper contract must stay fail-closed [{$token}]";
            }
        }

        foreach ([
            'atlas.provider_release.future_activation_review.v1',
            "'network_fetching_enabled' => false",
            "'auto_envelope_write_allowed' => false",
            "'auto_decide_signal_allowed' => false",
            "'auto_policy_patch_allowed' => false",
            "'promotion_allowed' => false",
            'create_dedicated_provider_release_fetch_runtime_ap_before_any_activation',
            'human_review_before_any_decide_or_policy_signal',
            'background_web_crawler',
            'direct_decide_signal',
            'default_model_change',
            'provider_watchlist_to_background_fetcher',
            'source_detection_to_default_model_change',
        ] as $token) {
            if (! str_contains($sourceRegistry, $token)) {
                $violations[] = "app/Services/Ai/Kernel/Architecture/AtlasProviderReleaseSourceRegistry.php: AP-178 provider release source registry must keep future ingestion fail-closed [{$token}]";
            }
        }

        foreach ([
            'anti_wrapper_contract.schema_version',
            'atlas.provider_release.anti_wrapper_contract.v1',
            'atlas_substitutes_direct_provider_channels_by_orchestrating_them',
            'direct_provider_channel_as_primary_product',
            'absorption_plan.promotion_gate.schema_version',
            'absorption_plan.promotion_gate.promotion_allowed',
            'absorption_plan.promotion_gate.routing_promotion_allowed_now',
            'absorption_plan.promotion_gate.review_packet.schema_version',
            'absorption_plan.promotion_gate.review_packet.required_human_decision',
            'absorption_plan.promotion_gate.review_packet.required_decision_receipt',
            'curator_proposal.promotion_review_ref.schema_version',
            'provider_vertical_agent_to_domain_ready',
            'decide_signal.default_model_change_allowed',
            'decide_signal.promotion_allowed',
            'decide_signal.manual_override_only_until_promoted',
            'source_registry_context.future_activation_review_contract.schema_version',
            'source_registry_context.future_activation_review_contract.status',
            'human_review_before_any_decide_or_policy_signal',
            'default_model_change',
        ] as $token) {
            if (! str_contains($commandTest, $token)) {
                $violations[] = "tests/Feature/Ai/AtlasAiProviderReleaseReviewCommandTest.php: AP-178 provider release anti-wrapper output must be tested [{$token}]";
            }
        }

        foreach ([
            'anti_wrapper_contract',
            'Atlas como camada acima dos',
            'nunca canal direto, default de modelo',
            '`promotion_gate` exige source, owner doc, Rivals/AP-99, review humano e novo',
            '`promotion_review_packet` fixa decisao humana, rollback, evidence e proibicoes',
            '`future_activation_review_contract`',
            'sem AP dedicado, rate limit, source gate, Ledger, AP-99/Rivals e review humano',
        ] as $token) {
            if (! str_contains($doc, $token)) {
                $violations[] = "docs/engineering-knowledge-base/atlas-ai-provider-evolution-intelligence.md: AP-178 provider release anti-wrapper contract must be documented [{$token}]";
            }
        }

        foreach ([
            'Provider Release Intelligence',
            '`anti_wrapper_contract`',
            '`promotion_gate`',
            '`promotion_review_packet`',
            'novo receipt e review humano',
        ] as $token) {
            if (! str_contains($matrix, $token)) {
                $violations[] = "docs/engineering-knowledge-base/architecture-audit/implemented-vs-scaffold-matrix.md: AP-178 provider release matrix row must expose anti-wrapper status [{$token}]";
            }
        }

        return $violations;
    }

    /**
     * @return array<int,string>
     */
    private function scanProviderUsagePerformanceContract(): array
    {
        $violations = [];
        $payloadPath = app_path('Services/Ai/Kernel/Evidence/ProviderUsagePayload.php');
        $projectionPath = app_path('Services/Ai/Kernel/Evidence/ProviderPerformanceProjection.php');
        $workerPath = app_path('Services/Ai/AiWorker.php');
        $strategyPath = app_path('Services/Ai/Cli/AtlasCliProviderStrategyService.php');
        $dynamicComputeMarketPath = app_path('Services/Ai/Kernel/Decision/DynamicComputeMarketAdvisor.php');
        $dynamicComputeMarketReportPath = app_path('Services/Ai/Kernel/Decision/DynamicComputeMarketReportService.php');
        $dynamicComputeMarketCommandPath = app_path('Console/Commands/AtlasAiDynamicComputeMarketCommand.php');
        $dynamicComputeMarketApiPath = app_path('Http/Controllers/AtlasAiDynamicComputeMarketController.php');
        $selfImprovementPath = app_path('Services/Ai/SelfImprovement/AtlasSelfImprovementRuntime.php');
        $inboxActionsPath = app_path('Services/Ai/Mobile/InboxActionRegistry.php');
        $costRateServicePath = app_path('Services/Ai/Telemetry/AiProviderCostRateService.php');
        $costRateCommandPath = app_path('Console/Commands/AiTelemetryCostRatesCommand.php');
        $replayServicePath = app_path('Services/Ai/Kernel/Evidence/AtlasLedgerReplayService.php');
        $commandPath = app_path('Console/Commands/AtlasAiProviderPerformanceCommand.php');
        $mcpPath = app_path('Services/Ai/AtlasOpenBrainMcpService.php');
        $apiPath = app_path('Http/Controllers/AtlasAiProviderPerformanceController.php');
        $observabilityPath = app_path('Http/Controllers/AiObservabilityController.php');
        $routesPath = base_path('routes/api.php');
        $bootstrapPath = base_path('bootstrap/app.php');
        $projectionTestPath = base_path('tests/Unit/Ai/ProviderPerformanceProjectionTest.php');
        $workerTestPath = base_path('tests/Feature/Ai/AiWorkerProviderChoiceTest.php');
        $commandTestPath = base_path('tests/Feature/Ai/AtlasAiProviderPerformanceCommandTest.php');
        $mcpTestPath = base_path('tests/Feature/Ai/AtlasOpenBrainMcpServiceTest.php');
        $apiTestPath = base_path('tests/Feature/Ai/AtlasAiProviderPerformanceApiTest.php');
        $dynamicComputeMarketCommandTestPath = base_path('tests/Feature/Ai/AtlasAiDynamicComputeMarketCommandTest.php');
        $dynamicComputeMarketApiTestPath = base_path('tests/Feature/Ai/AtlasAiDynamicComputeMarketApiTest.php');
        $selfImprovementRuntimeTestPath = base_path('tests/Feature/Ai/AtlasSelfImprovementRuntimeTest.php');
        $observabilityTestPath = base_path('tests/Feature/Ai/AiObservabilityKernelSloTest.php');
        $decideReceiptTestPath = base_path('tests/Unit/Ai/AtlasDecideReceiptIntegrationTest.php');
        $telemetryMetricsTestPath = base_path('tests/Feature/AiTelemetryMetricsTest.php');
        $telemetryDiagnosticsTestPath = base_path('tests/Feature/AiTelemetryToolDiagnosticsTest.php');
        $telemetryDocPath = base_path('docs/atlas-ai-telemetry.md');
        $modelSelectionDocPath = base_path('docs/engineering-knowledge-base/atlas-ai-model-selection-strategy.md');
        $dynamicComputeMarketApPath = base_path('docs/ap/AP-147-dynamic-compute-market-shadow-surface.md');

        $payload = $this->primitives->fileContents($payloadPath);
        $projection = $this->primitives->fileContents($projectionPath);
        $worker = $this->primitives->fileContents($workerPath);
        $strategy = $this->primitives->fileContents($strategyPath);
        $dynamicComputeMarket = $this->primitives->fileContents($dynamicComputeMarketPath);
        $dynamicComputeMarketReport = $this->primitives->fileContents($dynamicComputeMarketReportPath);
        $dynamicComputeMarketCommand = $this->primitives->fileContents($dynamicComputeMarketCommandPath);
        $dynamicComputeMarketApi = $this->primitives->fileContents($dynamicComputeMarketApiPath);
        $selfImprovement = $this->primitives->fileContents($selfImprovementPath);
        $inboxActions = $this->primitives->fileContents($inboxActionsPath);
        $costRateService = $this->primitives->fileContents($costRateServicePath);
        $costRateCommand = $this->primitives->fileContents($costRateCommandPath);
        $replayService = $this->primitives->fileContents($replayServicePath);
        $command = $this->primitives->fileContents($commandPath);
        $mcp = $this->primitives->fileContents($mcpPath);
        $reportTools = $this->primitives->fileContents(app_path('Services/Ai/OpenBrainMcp/ReportTools.php'));
        $api = $this->primitives->fileContents($apiPath);
        $observability = $this->primitives->fileContents($observabilityPath);
        $routes = $this->primitives->fileContents($routesPath);
        $bootstrap = $this->primitives->fileContents($bootstrapPath);
        $projectionTest = $this->primitives->fileContents($projectionTestPath);
        $workerTest = $this->primitives->fileContents($workerTestPath);
        $commandTest = $this->primitives->fileContents($commandTestPath);
        $mcpTest = $this->primitives->fileContents($mcpTestPath);
        $apiTest = $this->primitives->fileContents($apiTestPath);
        $dynamicComputeMarketCommandTest = $this->primitives->fileContents($dynamicComputeMarketCommandTestPath);
        $dynamicComputeMarketApiTest = $this->primitives->fileContents($dynamicComputeMarketApiTestPath);
        $selfImprovementRuntimeTest = $this->primitives->fileContents($selfImprovementRuntimeTestPath);
        $observabilityTest = $this->primitives->fileContents($observabilityTestPath);
        $decideReceiptTest = $this->primitives->fileContents($decideReceiptTestPath);
        $telemetryMetricsTest = $this->primitives->fileContents($telemetryMetricsTestPath);
        $telemetryDiagnosticsTest = $this->primitives->fileContents($telemetryDiagnosticsTestPath);
        $telemetryDoc = $this->primitives->fileContents($telemetryDocPath);
        $modelSelectionDoc = $this->primitives->fileContents($modelSelectionDocPath);
        $dynamicComputeMarketAp = $this->primitives->fileContents($dynamicComputeMarketApPath);

        $violations = array_merge($violations, $this->primitives->missingTokenViolations($payload, [
            'class ProviderUsagePayload',
            "public const SCHEMA_VERSION = 'atlas.provider_usage.v1'",
            'public function called(AiJob $job, AiJobAttempt $attempt',
            'public function returned(AiJob $job, AiJobAttempt $attempt, AiProviderResult $result',
            'public function fallback(AiJob $job, AiJobAttempt $attempt, AiProviderResult $result',
            "'provider_cli'",
            "'domain'",
            "'flow'",
            "'task_type'",
            "'specialist_profile'",
            "'risk'",
            "'router_decision_id'",
            "'selection_mode'",
            "'total_tokens'",
            "'cost_microusd'",
            "'cost_confidence'",
            "'cost_mode'",
        ], "app/Services/Ai/Kernel/Evidence/ProviderUsagePayload.php: provider usage payload must keep AP-99 normalized field"));

        $violations = array_merge($violations, $this->primitives->missingTokenViolations($projection, [
            'class ProviderPerformanceProjection',
            'public function reportForWindow(CarbonInterface $since',
            'LedgerEventType::ProviderReturned',
            'LedgerEventType::ProviderFallback',
            "'provider_cli'",
            "'domain'",
            "'task_type'",
            "'specialist_profile'",
            "'success_rate'",
            "'average_latency_seconds'",
            "'average_cost_microusd'",
            "'cost_confidence_counts'",
            "'groups'",
        ], "app/Services/Ai/Kernel/Evidence/ProviderPerformanceProjection.php: provider performance projection must aggregate AP-99 ledger events"));

        $violations = array_merge($violations, $this->primitives->missingTokenViolations($worker, [
            'ProviderUsagePayload $providerUsage',
            'LedgerEventType::ProviderCalled',
            'LedgerEventType::ProviderReturned',
            'LedgerEventType::ProviderFallback',
            '$this->providerUsage->called(',
            '$this->providerUsage->returned(',
            '$this->providerUsage->fallback(',
        ], "app/Services/Ai/AiWorker.php: worker must emit normalized provider usage events for AP-99"));

        $violations = array_merge($violations, $this->primitives->missingTokenViolations($strategy, [
            'ProviderPerformanceProjection $performance',
            "'empirical_performance'",
            '$this->performance->reportForWindow(',
        ], "app/Services/Ai/Cli/AtlasCliProviderStrategyService.php: Strategy Matrix must expose AP-99 empirical performance projection"));

        $violations = array_merge($violations, $this->primitives->missingTokenViolations($dynamicComputeMarket, [
            'class DynamicComputeMarketAdvisor',
            'ProviderPerformanceProjection $providerPerformance',
            "'mode' => 'shadow_advisory'",
            "'authority' => 'advisory_only_atlas_decide_remains_authority'",
            "'routing_control'",
            "'changes_provider' => false",
            "'proposal_evidence_contract'",
            'atlas.dynamic_compute_market.proposal_evidence.v1',
            'atlas.dynamic_compute_market.proposal_review_packet.v1',
            "'required_human_decision' => 'approve_or_reject_dynamic_compute_market_policy_change'",
            "'required_decision_receipt' => true",
            "'rollback_plan_required' => \$opensProposal",
            "'policy_patch_review_required' => \$opensProposal",
            'bypass_atlas_decide_authority',
            'draft_only_until_benchmark_and_review',
            "'quality_basis'",
            "'latency_basis'",
            "'cost_basis'",
            "'missing_cost_status'",
            "'sample_size'",
            "'recommended_next_action'",
            "'recommendation_reason'",
            "'benchmark_candidate'",
            'run_controlled_provider_benchmark_before_policy_change',
        ], "app/Services/Ai/Kernel/Decision/DynamicComputeMarketAdvisor.php: Dynamic Compute Market must stay advisory, explainable, and non-routing"));

        $violations = array_merge($violations, $this->primitives->missingTokenViolations($dynamicComputeMarketReport, [
            'class DynamicComputeMarketReportService',
            'DynamicComputeMarketAdvisor $advisor',
            "'schema_version' => 'atlas.dynamic_compute_market_report.v1'",
            "'mode' => 'report_only'",
            "'authority' => 'read_only_no_routing_change'",
            "'dynamic_compute_market' => \$market",
            'private function requiredScalar',
        ], "app/Services/Ai/Kernel/Decision/DynamicComputeMarketReportService.php: Dynamic Compute Market report service must stay read-only and advisor-backed"));

        $violations = array_merge($violations, $this->primitives->missingTokenViolations($dynamicComputeMarketCommand, [
            "protected \$signature = 'atlas:ai:dynamic-compute-market",
            'DynamicComputeMarketReportService $reports',
            "'status' => 'invalid_input'",
            'Atlas Dynamic Compute Market',
            'Changes provider',
        ], "app/Console/Commands/AtlasAiDynamicComputeMarketCommand.php: Dynamic Compute Market CLI must expose governed read-only advice"));

        $violations = array_merge($violations, $this->primitives->missingTokenViolations($dynamicComputeMarketApi, [
            'class AtlasAiDynamicComputeMarketController',
            'DynamicComputeMarketReportService $reports',
            "'provider' => ['required', 'string', 'max:120']",
            '$reports->report($data)',
            "=== 'ok' ? 200 : 503",
        ], "app/Http/Controllers/AtlasAiDynamicComputeMarketController.php: Dynamic Compute Market API must expose authenticated read-only report contract"));

        $violations = array_merge($violations, $this->primitives->missingTokenViolations($selfImprovement, [
            'ProviderPerformanceProjection $providerPerformance',
            'DynamicComputeMarketAdvisor $dynamicComputeMarket',
            'providerPerformanceFindings(',
            'dynamicComputeMarketFindings(',
        ], "app/Services/Ai/SelfImprovement/AtlasSelfImprovementRuntime.php: provider_performance_review must consume AP-99 projection"));

        // Pins relocated under GOD-DEBULK D3 (2026-07-23): providerPerformanceFindings +
        // dynamicComputeMarketFindings moved verbatim from AtlasSelfImprovementRuntime into the
        // Runtime/ProviderPerformanceSection family class; the AP-99 proposal-only + dedupe-key
        // invariants are unchanged, only the file moved (facade keeps same-signature delegators).
        $providerPerformanceSection = $this->primitives->fileContents(app_path('Services/Ai/SelfImprovement/Runtime/ProviderPerformanceSection.php'));
        $violations = array_merge($violations, $this->primitives->missingTokenViolations($providerPerformanceSection, [
            'atlas.self_improvement.dynamic_compute_market.v1',
            'Benchmark revisavel do Dynamic Compute Market',
            'run_controlled_provider_benchmark_before_policy_change',
            'self-improvement:provider-performance:',
            'self-improvement:provider-cost-rates:',
            'self-improvement:dynamic-compute-market:',
            'configure_provider_cost_rates',
            'atlas.provider_usage.v1',
        ], "app/Services/Ai/SelfImprovement/Runtime/ProviderPerformanceSection.php: provider_performance_review must consume AP-99 projection"));

        $violations = array_merge($violations, $this->primitives->missingTokenViolations($inboxActions, [
            "'configure_provider_cost_rates' => \$this->configureProviderCostRates(\$locked, \$input)",
            'private readonly AiProviderCostRateService $providerCostRates',
            'private function configureProviderCostRates(AiInboxItem $item, array $input): array',
            "'schema_version' => 'atlas.inbox_action.provider_cost_rates.v1'",
            '$this->providerCostRates->upsert($rateTemplate)',
        ], "app/Services/Ai/Mobile/InboxActionRegistry.php: AP-99 provider cost-rate Inbox action must close unknown-cost findings"));

        $violations = array_merge($violations, $this->primitives->missingTokenViolations($costRateService, [
            'private function requiredString',
            'private function nonNegativeInt',
            'private function currency',
            'effective_until must not be before effective_from.',
            '{$field} must be greater than or equal to 0.',
            'currency must be a 3 to 8 character code.',
        ], "app/Services/Ai/Telemetry/AiProviderCostRateService.php: AP-99 cost rates must reject invalid provider/model/rate windows before contaminating AP-99"));

        $violations = array_merge($violations, $this->primitives->missingTokenViolations($costRateCommand, [
            'private function renderError',
            "'ok' => false",
            'Invalid cost rate input:',
            "'currency'",
            "'input uUSD/1K'",
            "'output uUSD/1K'",
        ], "app/Console/Commands/AiTelemetryCostRatesCommand.php: AP-99 cost-rate CLI must report governed validation failures"));

        $violations = array_merge($violations, $this->primitives->missingTokenViolations($replayService, [
            'provider_cost_rate_action_count',
            'provider_cost_rate_applied_count',
            'provider_cost_rate_provider_counts',
            'configure_provider_cost_rates_action_without_applied_rate',
            'provider_cost_rates_configured',
        ], "app/Services/Ai/Kernel/Evidence/AtlasLedgerReplayService.php: AP-99 provider cost-rate Inbox action must be projected in replay reports"));

        $violations = array_merge($violations, $this->primitives->missingTokenViolations($command, [
            "protected \$signature = 'atlas:ai:provider-performance",
            'ProviderPerformanceProjection $performance',
            '$performance->reportForWindow(',
            "'provider_performance'",
            '{--provider=',
            '{--specialist-profile=',
            "data_get(\$report, 'review_signal.status'",
        ], "app/Console/Commands/AtlasAiProviderPerformanceCommand.php: AP-99 must expose provider performance through CLI read model"));

        $violations = array_merge($violations, $this->primitives->missingTokenViolations($bootstrap, [
            'AtlasAiProviderPerformanceCommand::class',
            'AtlasAiDynamicComputeMarketCommand::class',
        ], "bootstrap/app.php: AP-99 provider performance CLI command must be registered"));

        // Façade keeps the tools() schema + dispatch; the report handlers were relocated
        // under GOD-DEBULK D3 to OpenBrainMcp/ReportTools (invariant unchanged).
        $violations = array_merge($violations, $this->primitives->missingTokenViolations($mcp, [
            "'name' => 'atlas_provider_performance_report'",
            "'atlas_provider_performance_report' => \$this->toolResponse(\$id, \$this->reportTools->providerPerformanceReport(\$arguments))",
            "'name' => 'atlas_dynamic_compute_market_report'",
            "'atlas_dynamic_compute_market_report' => \$this->toolResponse(\$id, \$this->reportTools->dynamicComputeMarketReport(\$arguments))",
        ], "app/Services/Ai/AtlasOpenBrainMcpService.php: AP-99 provider performance must be available as a read-only MCP report"));

        $violations = array_merge($violations, $this->primitives->missingTokenViolations($reportTools, [
            'ProviderPerformanceProjection $providerPerformance',
            'providerPerformanceReport(array $arguments)',
            '$this->providerPerformance->reportForWindow(',
            'dynamicComputeMarketReport(array $arguments)',
            'DynamicComputeMarketReportService $dynamicComputeMarketReports',
        ], "app/Services/Ai/OpenBrainMcp/ReportTools.php: AP-99 provider performance must be available as a read-only MCP report"));

        $violations = array_merge($violations, $this->primitives->missingTokenViolations($api, [
            'class AtlasAiProviderPerformanceController',
            'ProviderPerformanceProjection $performance',
            'KernelReplayReportInput $input',
            '$performance->reportForWindow(',
            "'provider_performance'",
            "'ledger_unavailable'",
        ], "app/Http/Controllers/AtlasAiProviderPerformanceController.php: AP-99 provider performance API must expose the shared read model"));

        $violations = array_merge($violations, $this->primitives->missingTokenViolations($routes, [
            'AtlasAiProviderPerformanceController::class',
            "'/ai/provider-performance'",
            'AtlasAiDynamicComputeMarketController::class',
            "'/ai/dynamic-compute-market'",
        ], "routes/api.php: AP-99 provider performance API route must be registered"));

        $violations = array_merge($violations, $this->primitives->missingTokenViolations($observability, [
            'ProviderPerformanceProjection $providerPerformance',
            '$providerPerformance->reportForWindow($since)',
            "'provider_performance' => \$providerPerformanceReport",
        ], "app/Http/Controllers/AiObservabilityController.php: AP-99 provider performance must appear in Observability through the shared projection"));

        $violations = array_merge($violations, $this->primitives->missingTokenViolations($projectionTest, [
            'ProviderPerformanceProjectionTest',
            'provider_performance_projection_groups',
            'ProviderUsagePayload::SCHEMA_VERSION',
        ], "tests/Unit/Ai/ProviderPerformanceProjectionTest.php: AP-99 projection contract must have focused tests"));

        $violations = array_merge($violations, $this->primitives->missingTokenViolations($workerTest, [
            'atlas.provider_usage.v1',
            "'router_fallback_provider'",
            "'selection_mode'",
        ], "tests/Feature/Ai/AiWorkerProviderChoiceTest.php: AP-99 worker hot path must assert normalized provider usage payload"));

        $violations = array_merge($violations, $this->primitives->missingTokenViolations($commandTest, [
            'AtlasAiProviderPerformanceCommandTest',
            'atlas:ai:provider-performance',
            'provider_performance.event_count',
            'provider_performance.average_cost_microusd',
            'provider_performance.cost_confidence_counts',
            'Review signal',
            'ledger_unavailable',
        ], "tests/Feature/Ai/AtlasAiProviderPerformanceCommandTest.php: AP-99 provider performance CLI must be covered"));

        $violations = array_merge($violations, $this->primitives->missingTokenViolations($mcpTest, [
            'atlas_provider_performance_report',
            'test_provider_performance_report_summarizes_normalized_provider_usage',
            'provider_performance.success_rate',
            "'provider_cli' => 'codex_cli'",
            'test_inbox_action_report_tool_exposes_provider_cost_rate_actions',
            'provider_cost_rate_action_count',
            'atlas_dynamic_compute_market_report',
            'test_dynamic_compute_market_report_exposes_read_only_shadow_advice',
            'test_dynamic_compute_market_report_preserves_review_signal_when_ledger_is_unavailable',
            'ledger_unavailable',
        ], "tests/Feature/Ai/AtlasOpenBrainMcpServiceTest.php: AP-99 provider performance MCP report must be covered"));

        $violations = array_merge($violations, $this->primitives->missingTokenViolations($apiTest, [
            'AtlasAiProviderPerformanceApiTest',
            '/ai/provider-performance?hours=24&provider=codex_cli&domain=programming',
            'provider_performance.review_signal.status',
            'provider_performance.average_cost_microusd',
            'provider_performance.cost_confidence_counts.estimated',
            'wait_for_provider_usage_evidence',
        ], "tests/Feature/Ai/AtlasAiProviderPerformanceApiTest.php: AP-99 provider performance API must be covered"));

        $violations = array_merge($violations, $this->primitives->missingTokenViolations($dynamicComputeMarketCommandTest, [
            'AtlasAiDynamicComputeMarketCommandTest',
            'atlas:ai:dynamic-compute-market',
            'atlas.dynamic_compute_market_report.v1',
            'read_only_no_routing_change',
            'dynamic_compute_market.routing_control.changes_provider',
            'dynamic_compute_market.proposal_gate.review_packet.schema_version',
            'provider is required.',
            'test_command_reports_unavailable_without_ap99_ledger_projection',
            'ledger_unavailable',
        ], "tests/Feature/Ai/AtlasAiDynamicComputeMarketCommandTest.php: Dynamic Compute Market CLI surface must be covered"));

        $violations = array_merge($violations, $this->primitives->missingTokenViolations($dynamicComputeMarketApiTest, [
            'AtlasAiDynamicComputeMarketApiTest',
            '/ai/dynamic-compute-market?provider=codex_cli',
            'atlas.dynamic_compute_market_report.v1',
            'read_only_no_routing_change',
            'dynamic_compute_market.routing_control.changes_provider',
            'dynamic_compute_market.proposal_gate.review_packet.schema_version',
            'test_api_requires_atlas_token',
            'test_api_reports_unavailable_without_ap99_ledger_projection',
            'ledger_unavailable',
        ], "tests/Feature/Ai/AtlasAiDynamicComputeMarketApiTest.php: Dynamic Compute Market API surface must be covered"));

        $violations = array_merge($violations, $this->primitives->missingTokenViolations($selfImprovementRuntimeTest, [
            'test_provider_performance_review_emits_dynamic_compute_market_benchmark_proposal',
            'test_self_improvement_command_surfaces_dynamic_compute_market_proposal_without_routing_change',
            'atlas.self_improvement.dynamic_compute_market.v1',
            'routing_control.changes_provider',
            'routing_control.routing_authority',
            'metadata.proposal_evidence_contract',
        ], "tests/Feature/Ai/AtlasSelfImprovementRuntimeTest.php: AP-147 Curator proposal-only contract must be covered"));

        $violations = array_merge($violations, $this->primitives->missingTokenViolations($observabilityTest, [
            'test_observability_payload_includes_provider_performance_summary',
            'ProviderUsagePayload::SCHEMA_VERSION',
            'provider_performance.provider_counts.codex_cli',
            'provider_performance.review_signal.status',
            'test_observability_payload_exposes_provider_cost_rate_inbox_actions',
            'inbox_actions.provider_cost_rate_action_count',
        ], "tests/Feature/Ai/AiObservabilityKernelSloTest.php: AP-99 provider performance Observability payload must be covered"));

        $violations = array_merge($violations, $this->primitives->missingTokenViolations($telemetryMetricsTest, [
            'test_cost_rate_upsert_rejects_negative_input_and_output_rates',
            'test_cost_rate_upsert_rejects_empty_provider_and_model',
            'test_cost_rate_upsert_rejects_effective_until_before_effective_from',
            'test_cost_rate_command_reports_invalid_input_as_json_and_human_error',
            'test_cost_rate_import_reports_indexed_validation_errors',
        ], "tests/Feature/AiTelemetryMetricsTest.php: AP-99 cost-rate governance must be covered"));

        $violations = array_merge($violations, $this->primitives->missingTokenViolations($telemetryDiagnosticsTest, [
            'test_cost_rate_service_rejects_invalid_effective_window',
            'effective_until must not be before effective_from.',
        ], "tests/Feature/AiTelemetryToolDiagnosticsTest.php: AP-99 cost-rate diagnostics must cover invalid windows"));

        $violations = array_merge($violations, $this->primitives->missingTokenViolations($telemetryDoc, [
            'Cost rates sao governados',
            'provider/model obrigatorios',
            'micro-USD por 1K tokens',
            'effective_until',
        ], "docs/atlas-ai-telemetry.md: AP-99 cost-rate governance must be documented"));

        $violations = array_merge($violations, $this->primitives->missingTokenViolations($decideReceiptTest, [
            'test_dynamic_compute_market_uses_ap99_provider_performance_inside_decision_receipt',
            'test_dynamic_compute_market_requests_cost_rates_when_quality_is_ok_but_cost_is_unknown',
            'test_dynamic_compute_market_recommends_benchmark_when_better_alternative_has_insufficient_sample',
            'test_dynamic_compute_market_keeps_selected_provider_when_evidence_is_stable',
            'test_dynamic_compute_market_prefers_sufficient_sample_benchmark_candidate',
            'test_dynamic_compute_market_prefers_higher_quality_when_candidate_samples_are_sufficient',
            'recommended_next_action',
            'recommendation_reason',
            'explanation.quality_basis',
            'explanation.latency_basis',
            'explanation.cost_basis',
            'routing_control.changes_provider',
        ], "tests/Unit/Ai/AtlasDecideReceiptIntegrationTest.php: Dynamic Compute Market receipt explainability must be covered"));

        $violations = array_merge($violations, $this->primitives->missingTokenViolations($modelSelectionDoc, [
            'DynamicComputeMarketAdvisor',
            'Dynamic Compute Market Report',
            'quality_basis',
            'latency_basis',
            'cost_basis',
            'recommended_next_action',
            'recommendation_reason',
            'benchmark controlado',
            'proposal_review_packet',
        ], "docs/engineering-knowledge-base/atlas-ai-model-selection-strategy.md: Dynamic Compute Market explainability must be documented"));

        $violations = array_merge($violations, $this->primitives->missingTokenViolations($dynamicComputeMarketAp, [
            'AP-147',
            'implemented-shadow-evidence-contract',
            'atlas.dynamic_compute_market_report.v1',
            'read_only_no_routing_change',
            'routing_control.changes_provider=false',
            'proposal_review_packet',
            'atlas_dynamic_compute_market_report',
            'AtlasAiDynamicComputeMarketCommandTest',
            'AtlasAiDynamicComputeMarketApiTest',
            'AtlasOpenBrainMcpServiceTest::test_dynamic_compute_market_report_exposes_read_only_shadow_advice',
            'AtlasOpenBrainMcpServiceTest::test_dynamic_compute_market_report_preserves_review_signal_when_ledger_is_unavailable',
            'AtlasSelfImprovementRuntimeTest::test_provider_performance_review_emits_dynamic_compute_market_benchmark_proposal',
            'AtlasSelfImprovementRuntimeTest::test_self_improvement_command_surfaces_dynamic_compute_market_proposal_without_routing_change',
        ], "docs/ap/AP-147-dynamic-compute-market-shadow-surface.md: Dynamic Compute Market shadow surface contract must be documented"));

        return $violations;
    }

    /**
     * @return array<int,string>
     */
    private function scanProviderProjectionAuditInputContract(): array
    {
        $inputPath = app_path('Services/Ai/Provider/ProviderProjectionAuditInput.php');
        $servicePath = app_path('Services/Ai/Instrumentation/AtlasProviderProjectionAuditService.php');
        $testPath = base_path('tests/Unit/Ai/Provider/ProviderProjectionAuditInputTest.php');
        $docsPath = base_path('docs/engineering-knowledge-base/atlas-ai-kernel-architecture.md');
        $violations = [];

        $input = File::exists($inputPath) ? File::get($inputPath) : '';
        $service = File::exists($servicePath) ? File::get($servicePath) : '';
        $test = File::exists($testPath) ? File::get($testPath) : '';
        $docs = $this->primitives->kernelDocumentationCorpus();

        foreach ([
            'final class ProviderProjectionAuditInput',
            'public const DEFAULT_AUDIT_LIMIT = 50',
            'public const MAX_AUDIT_LIMIT = 200',
            'public const DEFAULT_SUMMARY_DAYS = 30',
            'public const MAX_SUMMARY_DAYS = 365',
            'public const DEFAULT_PURGE_OLDER_THAN_DAYS = 90',
            'public const MAX_PURGE_OLDER_THAN_DAYS = 3650',
            'public function auditLimit(',
            'public function summaryDays(',
            'public function purgeOlderThanDays(',
        ] as $token) {
            if (! str_contains($input, $token)) {
                $violations[] = "app/Services/Ai/Provider/ProviderProjectionAuditInput.php: provider projection audit input contract is incomplete [{$token}]";
            }
        }

        foreach ([
            'private readonly ProviderProjectionAuditInput $input',
            '$this->input->auditLimit(',
            '$this->input->summaryDays(',
            '$this->input->purgeOlderThanDays(',
        ] as $token) {
            if (! str_contains($service, $token)) {
                $violations[] = "app/Services/Ai/Instrumentation/AtlasProviderProjectionAuditService.php: provider projection audit must use shared input contract [{$token}]";
            }
        }

        foreach ([
            'test_normalizes_provider_projection_audit_windows_with_canonical_caps',
            'ProviderProjectionAuditInput::MAX_AUDIT_LIMIT',
            'ProviderProjectionAuditInput::MAX_PURGE_OLDER_THAN_DAYS',
        ] as $token) {
            if (! str_contains($test, $token)) {
                $violations[] = "tests/Unit/Ai/Provider/ProviderProjectionAuditInputTest.php: provider projection audit input contract must be covered [{$token}]";
            }
        }

        foreach ([
            'provider projection audit input contract',
            'AP-80',
        ] as $token) {
            if (! str_contains($docs, $token)) {
                $violations[] = "docs/engineering-knowledge-base/atlas-ai-kernel-architecture.md: docs must describe provider projection audit input contract [{$token}]";
            }
        }

        return $violations;
    }

    /**
     * @return array<int,string>
     */
    private function scanProviderProjectionInputContract(): array
    {
        $inputPath = app_path('Services/Ai/Provider/ProviderProjectionInput.php');
        $servicePath = app_path('Services/Ai/Instrumentation/AtlasProviderProjectionService.php');
        $testPath = base_path('tests/Unit/Ai/Provider/ProviderProjectionInputTest.php');
        $docsPath = base_path('docs/engineering-knowledge-base/atlas-ai-kernel-architecture.md');
        $violations = [];

        $input = File::exists($inputPath) ? File::get($inputPath) : '';
        $service = File::exists($servicePath) ? File::get($servicePath) : '';
        $test = File::exists($testPath) ? File::get($testPath) : '';
        $docs = $this->primitives->kernelDocumentationCorpus();

        foreach ([
            'final class ProviderProjectionInput',
            'public const DEFAULT_MAX_LINES = 80',
            'public const MAX_MAX_LINES = 240',
            'public const DEFAULT_MEMORY_LIMIT = 18',
            'public const MAX_MEMORY_LIMIT = 80',
            'public const DEFAULT_MEMORY_CHARS = 220',
            'public const MAX_MEMORY_CHARS = 1200',
            'public function maxLines(',
            'public function memoryLimit(',
            'public function memoryChars(',
        ] as $token) {
            if (! str_contains($input, $token)) {
                $violations[] = "app/Services/Ai/Provider/ProviderProjectionInput.php: provider projection input contract is incomplete [{$token}]";
            }
        }

        foreach ([
            '?ProviderProjectionInput $input = null',
            '$this->projectionInput()->maxLines($options[\'max_lines\'] ?? null)',
            '$this->projectionInput()->memoryLimit($options[\'memory_limit\'] ?? null)',
            '$this->projectionInput()->memoryChars()',
            'private function projectionInput(): ProviderProjectionInput',
        ] as $token) {
            if (! str_contains($service, $token)) {
                $violations[] = "app/Services/Ai/Instrumentation/AtlasProviderProjectionService.php: provider projection must use shared input contract [{$token}]";
            }
        }

        foreach ([
            'test_normalizes_provider_projection_limits_with_canonical_caps',
            'ProviderProjectionInput::MAX_MAX_LINES',
            'ProviderProjectionInput::MAX_MEMORY_LIMIT',
            'ProviderProjectionInput::MAX_MEMORY_CHARS',
        ] as $token) {
            if (! str_contains($test, $token)) {
                $violations[] = "tests/Unit/Ai/Provider/ProviderProjectionInputTest.php: provider projection input contract must be covered [{$token}]";
            }
        }

        foreach ([
            'provider projection input contract',
            'AP-87',
        ] as $token) {
            if (! str_contains($docs, $token)) {
                $violations[] = "docs/engineering-knowledge-base/atlas-ai-kernel-architecture.md: docs must describe provider projection input contract [{$token}]";
            }
        }

        return $violations;
    }

    /**
     * @return array<int,string>
     */
    private function scanProviderMemoryPrivacy(): array
    {
        $privacyPath = app_path('Services/Ai/MemoryGovernance/AtlasMemoryPrivacyService.php');
        $projectionPath = app_path('Services/Ai/Instrumentation/AtlasProviderProjectionService.php');
        $openBrainPath = app_path('Services/Ai/Memory/AtlasHybridMemoryRetrievalService.php');

        $violations = [];

        if (! File::exists($privacyPath)) {
            return ["missing privacy service [{$privacyPath}]"];
        }

        $privacy = File::get($privacyPath);
        $projection = File::exists($projectionPath) ? File::get($projectionPath) : '';
        $openBrain = File::exists($openBrainPath) ? File::get($openBrainPath) : '';

        $checks = [
            'providerDecision exposes auditable privacy decision' => 'providerDecision(AtlasMemoryEntry $entry)',
            'providerAllowed computes canonical privacy class' => 'privacyClass(data_get($entry->metadata',
            'providerAllowed applies external-ai block list' => 'externalAiAllowed($privacyClass',
            'providerDecision honors explicit metadata block' => 'metadataExternalAiAllowed !== false',
            'providerTitle redacts raw title fallback' => 'AtlasSecurity::redactString((string) $entry->title)',
            // MAXM-04 may use the stricter providerBoundText projection, which
            // refuses raw fallback entirely unless an explicit verification
            // stamp exists. Accept that stronger contract as equivalent proof.
            'providerSummary redacts raw summary fallback' => [
                'AtlasSecurity::redactString((string) $entry->summary)',
                "providerBoundText(\n            (string) (\$entry->summary ?? '')",
            ],
            'providerBody redacts raw body fallback' => [
                'AtlasSecurity::redactString((string) $entry->body)',
                "providerBoundText((string) \$entry->body",
            ],
        ];

        foreach ($checks as $label => $token) {
            $tokens = is_array($token) ? $token : [$token];
            if (! collect($tokens)->contains(static fn (string $candidate): bool => str_contains($privacy, $candidate))) {
                $violations[] = "app/Services/Ai/MemoryGovernance/AtlasMemoryPrivacyService.php: missing {$label} [{$token}]";
            }
        }

        if (! str_contains($projection, 'providerDecision($entry)')) {
            $violations[] = 'app/Services/Ai/Instrumentation/AtlasProviderProjectionService.php: provider projections must filter memory through AtlasMemoryPrivacyService::providerDecision';
        }

        if (! str_contains($projection, 'recordProviderMemoryBlocked($entry')) {
            $violations[] = 'app/Services/Ai/Instrumentation/AtlasProviderProjectionService.php: provider projections must record blocked memory decisions to the Evidence Ledger';
        }

        if (! str_contains($openBrain, 'providerAllowed($entry)')) {
            $violations[] = 'app/Services/Ai/Memory/AtlasHybridMemoryRetrievalService.php: Open Brain provider context must filter memory through AtlasMemoryPrivacyService::providerAllowed';
        }

        $mcpPath = app_path('Services/Ai/AtlasOpenBrainMcpService.php');
        $mcp = File::exists($mcpPath) ? File::get($mcpPath) : '';
        if (! str_contains($mcp, 'providerDecision($entry)') || ! str_contains($mcp, "recordProviderMemoryBlocked(\$entry, \$privacyDecision, 'open_brain_mcp'")) {
            $violations[] = 'app/Services/Ai/AtlasOpenBrainMcpService.php: atlas_memory_get must record blocked provider memory decisions to the Evidence Ledger';
        }

        $ledgerPath = app_path('Services/Ai/Kernel/Evidence/AtlasEvidenceLedger.php');
        $ledger = File::exists($ledgerPath) ? File::get($ledgerPath) : '';
        if (! str_contains($ledger, 'recordProviderMemoryBlocked(') || ! str_contains($ledger, 'atlas.memory_provider_privacy')) {
            $violations[] = 'app/Services/Ai/Kernel/Evidence/AtlasEvidenceLedger.php: missing provider memory privacy block event recorder';
        }

        return $violations;
    }

    /**
     * @return array<int,string>
     */
    private function scanProviderCostRateInboxReplay(): array
    {
        $inboxActionsPath = app_path('Services/Ai/Mobile/InboxActionRegistry.php');
        $replayPath = app_path('Services/Ai/Kernel/Evidence/AtlasLedgerReplayService.php');
        $selfImprovementPath = app_path('Services/Ai/SelfImprovement/AtlasSelfImprovementRuntime.php');
        $commandPath = app_path('Console/Commands/AtlasAiInboxActionReportCommand.php');
        $ledgerReplayTestPath = base_path('tests/Unit/Ai/Kernel/LedgerReplayServiceTest.php');
        $inboxActionTestPath = base_path('tests/Feature/Ai/InboxLedgerProjectionActionTest.php');
        $selfImprovementTestPath = base_path('tests/Feature/Ai/AtlasSelfImprovementRuntimeTest.php');
        $commandTestPath = base_path('tests/Feature/Ai/AtlasAiInboxActionReportCommandTest.php');
        $mcpTestPath = base_path('tests/Feature/Ai/AtlasOpenBrainMcpServiceTest.php');
        $observabilityTestPath = base_path('tests/Feature/Ai/AiObservabilityKernelSloTest.php');
        $docsPath = base_path('docs/engineering-knowledge-base/atlas-ai-kernel-architecture.md');
        $apDocPath = base_path('docs/ap/AP-146-provider-cost-rate-inbox-replay.md');

        $inboxActions = File::exists($inboxActionsPath) ? File::get($inboxActionsPath) : '';
        $replay = File::exists($replayPath) ? File::get($replayPath) : '';
        $selfImprovement = File::exists($selfImprovementPath) ? File::get($selfImprovementPath) : '';
        $command = File::exists($commandPath) ? File::get($commandPath) : '';
        $ledgerReplayTest = File::exists($ledgerReplayTestPath) ? File::get($ledgerReplayTestPath) : '';
        $inboxActionTest = File::exists($inboxActionTestPath) ? File::get($inboxActionTestPath) : '';
        $selfImprovementTest = File::exists($selfImprovementTestPath) ? File::get($selfImprovementTestPath) : '';
        $commandTest = File::exists($commandTestPath) ? File::get($commandTestPath) : '';
        $mcpTest = File::exists($mcpTestPath) ? File::get($mcpTestPath) : '';
        $observabilityTest = File::exists($observabilityTestPath) ? File::get($observabilityTestPath) : '';
        $docs = $this->primitives->kernelDocumentationCorpus();
        $apDoc = File::exists($apDocPath) ? File::get($apDocPath) : '';
        $violations = [];

        foreach ([
            "'configure_provider_cost_rates' => \$this->configureProviderCostRates(\$locked, \$input)",
            'private readonly AiProviderCostRateService $providerCostRates',
            'private function configureProviderCostRates(AiInboxItem $item, array $input): array',
            "'schema_version' => 'atlas.inbox_action.provider_cost_rates.v1'",
            '$this->providerCostRates->upsert($rateTemplate)',
            'recordInboxActionLedgerEvent($fresh, $actionId, $serializedResult, $actor, $idempotencyKey)',
        ] as $token) {
            if (! str_contains($inboxActions, $token)) {
                $violations[] = "app/Services/Ai/Mobile/InboxActionRegistry.php: AP-146 provider cost-rate Inbox action must record applied/previewed rates [{$token}]";
            }
        }

        foreach ([
            'provider_cost_rate_action_count',
            'provider_cost_rate_applied_count',
            'provider_cost_rate_provider_counts',
            'provider_cost_rate_model_counts',
            'provider_cost_rate_schema_version',
            'provider_cost_rate_input_microusd',
            'provider_cost_rate_output_microusd',
            'provider_cost_rate_applied',
            'provider_cost_rates_configured',
            'configure_provider_cost_rates_action_without_applied_rate',
        ] as $token) {
            if (! str_contains($replay, $token)) {
                $violations[] = "app/Services/Ai/Kernel/Evidence/AtlasLedgerReplayService.php: AP-146 provider cost-rate action must be projected by inbox replay [{$token}]";
            }
        }

        foreach ([
            "'available_actions' => [",
        ] as $token) {
            if (! str_contains($selfImprovement, $token)) {
                $violations[] = "app/Services/Ai/SelfImprovement/AtlasSelfImprovementRuntime.php: AP-146 Curator must reopen previewed provider cost-rate actions [{$token}]";
            }
        }

        // Pins relocated under GOD-DEBULK D3 (2026-07-23): inboxActionReplayFindings moved verbatim
        // from AtlasSelfImprovementRuntime into the Runtime/InboxActionReplaySection family class;
        // the AP-146 previewed-cost-rate reopen invariant is unchanged, only the file moved.
        $inboxActionReplaySectionPath = app_path('Services/Ai/SelfImprovement/Runtime/InboxActionReplaySection.php');
        $inboxActionReplaySection = File::exists($inboxActionReplaySectionPath) ? File::get($inboxActionReplaySectionPath) : '';
        foreach ([
            'configure_provider_cost_rates_action_without_applied_rate',
            'Completar rates de custo dos providers no Inbox',
            'atlas.provider_cost_rates.curator_completion_request.v1',
            "'id' => 'configure_provider_cost_rates'",
            "'gap_type' => \$providerCostRateGapReason",
        ] as $token) {
            if (! str_contains($inboxActionReplaySection, $token)) {
                $violations[] = "app/Services/Ai/SelfImprovement/Runtime/InboxActionReplaySection.php: AP-146 Curator must reopen previewed provider cost-rate actions [{$token}]";
            }
        }

        foreach ([
            'renderProviderCostRateSummary',
            'Provider cost-rate actions',
            'Applied cost-rate actions',
            'provider_cost_rate_provider_counts',
            'provider_cost_rate_model_counts',
        ] as $token) {
            if (! str_contains($command, $token)) {
                $violations[] = "app/Console/Commands/AtlasAiInboxActionReportCommand.php: AP-146 human CLI report must expose provider cost-rate summary [{$token}]";
            }
        }

        foreach ([
            'test_inbox_action_window_report_projects_provider_cost_rate_actions',
            'test_inbox_action_window_report_warns_when_provider_cost_rate_action_is_only_previewed',
            'provider_cost_rate_action_count',
            'provider_cost_rates_configured',
            'configure_provider_cost_rates_action_without_applied_rate',
        ] as $token) {
            if (! str_contains($ledgerReplayTest, $token)) {
                $violations[] = "tests/Unit/Ai/Kernel/LedgerReplayServiceTest.php: AP-146 replay projection must be tested [{$token}]";
            }
        }

        foreach ([
            'test_inbox_action_configures_provider_cost_rates_with_human_supplied_rates_and_ledger_evidence',
            'test_inbox_action_previews_provider_cost_rate_template_without_resolving_item',
            'atlas.inbox_action.provider_cost_rates.v1',
            'configure_provider_cost_rates',
        ] as $token) {
            if (! str_contains($inboxActionTest, $token)) {
                $violations[] = "tests/Feature/Ai/InboxLedgerProjectionActionTest.php: AP-146 Inbox action execution must be tested [{$token}]";
            }
        }

        foreach ([
            'test_self_improvement_detects_provider_cost_rate_action_without_applied_rate',
            'test_self_improvement_does_not_flag_provider_cost_rate_action_when_rate_was_applied',
            'atlas.provider_cost_rates.curator_completion_request.v1',
            'configure_provider_cost_rates_action_without_applied_rate',
        ] as $token) {
            if (! str_contains($selfImprovementTest, $token)) {
                $violations[] = "tests/Feature/Ai/AtlasSelfImprovementRuntimeTest.php: AP-146 Self-Improvement replay gap must be tested [{$token}]";
            }
        }

        foreach ([
            'test_command_human_output_includes_provider_cost_rate_summary_when_configured',
            'Provider cost-rate actions',
            'Applied cost-rate actions',
            'codex_cli:gpt-5.2',
        ] as $token) {
            if (! str_contains($commandTest, $token)) {
                $violations[] = "tests/Feature/Ai/AtlasAiInboxActionReportCommandTest.php: AP-146 human CLI summary must be tested [{$token}]";
            }
        }

        foreach ([
            'test_inbox_action_report_tool_exposes_provider_cost_rate_actions',
            'provider_cost_rate_action_count',
            'configure_provider_cost_rates',
        ] as $token) {
            if (! str_contains($mcpTest, $token)) {
                $violations[] = "tests/Feature/Ai/AtlasOpenBrainMcpServiceTest.php: AP-146 MCP report must expose provider cost-rate actions [{$token}]";
            }
        }

        foreach ([
            'test_observability_payload_exposes_provider_cost_rate_inbox_actions',
            'inbox_actions.provider_cost_rate_action_count',
            'provider_cost_rate_applied_count',
        ] as $token) {
            if (! str_contains($observabilityTest, $token)) {
                $violations[] = "tests/Feature/Ai/AiObservabilityKernelSloTest.php: AP-146 Observability must expose provider cost-rate replay [{$token}]";
            }
        }

        foreach ([
            'AP-146',
            'Provider Cost Rate Inbox Replay Contract',
            'configure_provider_cost_rates',
            'atlas.inbox_action.provider_cost_rates.v1',
            'provider_cost_rates_configured',
            'configure_provider_cost_rates_action_without_applied_rate',
        ] as $token) {
            if (! str_contains($docs, $token)) {
                $violations[] = "docs/engineering-knowledge-base/atlas-ai-kernel-architecture.md: AP-146 Provider Cost Rate Inbox Replay must be documented [{$token}]";
            }
            if (! str_contains($apDoc, $token)) {
                $violations[] = "docs/ap/AP-146-provider-cost-rate-inbox-replay.md: AP-146 contract doc must exist [{$token}]";
            }
        }

        return $violations;
    }
}
