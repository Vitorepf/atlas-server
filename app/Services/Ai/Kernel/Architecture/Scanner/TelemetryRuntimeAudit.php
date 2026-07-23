<?php

namespace App\Services\Ai\Kernel\Architecture\Scanner;

use Illuminate\Support\Facades\File;

class TelemetryRuntimeAudit
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
            'ap72_telemetry_window_input_contract' => fn (): array => $this->scanTelemetryWindowInputContract(),
            'ap76_telemetry_list_limit_contract' => fn (): array => $this->scanTelemetryListLimitContract(),
            'ap73_runtime_budget_window_contract' => fn (): array => $this->scanRuntimeBudgetWindowContract(),
            'ap201_runtime_language_boundary_contract' => fn (): array => $this->scanRuntimeLanguageBoundaryContract(),
        ];
    }

    /**
     * @return array<int,string>
     */
    private function scanTelemetryWindowInputContract(): array
    {
        $inputPath = app_path('Services/Ai/Telemetry/AiTelemetryWindowInput.php');
        $controllerPath = app_path('Http/Controllers/AiTelemetryMetricsController.php');
        $healthCommandPath = app_path('Console/Commands/AiTelemetryHealthCommand.php');
        $rollupCommandPath = app_path('Console/Commands/AiTelemetryRollupCommand.php');
        $testPath = base_path('tests/Unit/Ai/Telemetry/AiTelemetryWindowInputTest.php');
        $docsPath = base_path('docs/engineering-knowledge-base/atlas-ai-kernel-architecture.md');
        $violations = [];

        $input = File::exists($inputPath) ? File::get($inputPath) : '';
        $controller = File::exists($controllerPath) ? File::get($controllerPath) : '';
        $healthCommand = File::exists($healthCommandPath) ? File::get($healthCommandPath) : '';
        $rollupCommand = File::exists($rollupCommandPath) ? File::get($rollupCommandPath) : '';
        $test = File::exists($testPath) ? File::get($testPath) : '';
        $docs = $this->primitives->kernelDocumentationCorpus();

        foreach ([
            'final class AiTelemetryWindowInput',
            'public const DEFAULT_WINDOW_HOURS = 24',
            'public const DEFAULT_COST_RATE_WINDOW_HOURS = 168',
            'public const MAX_WINDOW_HOURS = 720',
            'return max(1, min(self::MAX_WINDOW_HOURS, (int) $value))',
        ] as $token) {
            if (! str_contains($input, $token)) {
                $violations[] = "app/Services/Ai/Telemetry/AiTelemetryWindowInput.php: telemetry window input contract is missing [{$token}]";
            }
        }

        foreach ([
            'AiTelemetryWindowInput $telemetryWindow',
            "'between:1,'.AiTelemetryWindowInput::MAX_WINDOW_HOURS",
            '$telemetryWindow->hours($data[\'hours\'] ?? null)',
            'AiTelemetryWindowInput::DEFAULT_COST_RATE_WINDOW_HOURS',
        ] as $token) {
            if (! str_contains($controller, $token)) {
                $violations[] = "app/Http/Controllers/AiTelemetryMetricsController.php: telemetry APIs must use shared window input [{$token}]";
            }
        }

        foreach ([
            'AiTelemetryWindowInput $telemetryWindow',
            '$telemetryWindow->hours($this->option(\'hours\'))',
        ] as $token) {
            if (! str_contains($healthCommand, $token)) {
                $violations[] = "app/Console/Commands/AiTelemetryHealthCommand.php: telemetry health command must use shared window input [{$token}]";
            }

            if (! str_contains($rollupCommand, $token)) {
                $violations[] = "app/Console/Commands/AiTelemetryRollupCommand.php: telemetry rollup command must use shared window input [{$token}]";
            }
        }

        foreach ([
            'test_hours_normalizes_telemetry_window_with_canonical_limits',
            'AiTelemetryWindowInput::MAX_WINDOW_HOURS',
            'AiTelemetryWindowInput::DEFAULT_COST_RATE_WINDOW_HOURS',
        ] as $token) {
            if (! str_contains($test, $token)) {
                $violations[] = "tests/Unit/Ai/Telemetry/AiTelemetryWindowInputTest.php: telemetry window input contract must be covered [{$token}]";
            }
        }

        foreach ([
            'telemetry window input contract',
            'AP-72',
        ] as $token) {
            if (! str_contains($docs, $token)) {
                $violations[] = "docs/engineering-knowledge-base/atlas-ai-kernel-architecture.md: docs must describe telemetry window input contract [{$token}]";
            }
        }

        return $violations;
    }

    /**
     * @return array<int,string>
     */
    private function scanTelemetryListLimitContract(): array
    {
        $inputPath = app_path('Services/Ai/Telemetry/AiTelemetryWindowInput.php');
        $controllerPath = app_path('Http/Controllers/AiTelemetryMetricsController.php');
        $unitTestPath = base_path('tests/Unit/Ai/Telemetry/AiTelemetryWindowInputTest.php');
        $featureTestPath = base_path('tests/Feature/AiTelemetryMetricsTest.php');
        $docsPath = base_path('docs/engineering-knowledge-base/atlas-ai-kernel-architecture.md');
        $violations = [];

        $input = File::exists($inputPath) ? File::get($inputPath) : '';
        $controller = File::exists($controllerPath) ? File::get($controllerPath) : '';
        $unitTest = File::exists($unitTestPath) ? File::get($unitTestPath) : '';
        $featureTest = File::exists($featureTestPath) ? File::get($featureTestPath) : '';
        $docs = $this->primitives->kernelDocumentationCorpus();

        foreach ([
            'public const DEFAULT_SUMMARY_LIMIT = 25',
            'public const MAX_SUMMARY_LIMIT = 100',
            'public const DEFAULT_COST_RATE_LIMIT = 100',
            'public const MAX_COST_RATE_LIMIT = 200',
            'public const DEFAULT_OUTCOME_LIMIT = 50',
            'public const MAX_OUTCOME_LIMIT = 200',
            'public function limit(mixed $value, int $default, int $max): int',
            'return max(1, min($max, (int) $value))',
        ] as $token) {
            if (! str_contains($input, $token)) {
                $violations[] = "app/Services/Ai/Telemetry/AiTelemetryWindowInput.php: telemetry list limit contract is incomplete [{$token}]";
            }
        }

        foreach ([
            "'between:1,'.AiTelemetryWindowInput::MAX_SUMMARY_LIMIT",
            "'between:1,'.AiTelemetryWindowInput::MAX_COST_RATE_LIMIT",
            "'between:1,'.AiTelemetryWindowInput::MAX_OUTCOME_LIMIT",
            'AiTelemetryWindowInput::DEFAULT_SUMMARY_LIMIT',
            'AiTelemetryWindowInput::DEFAULT_COST_RATE_LIMIT',
            'AiTelemetryWindowInput::DEFAULT_OUTCOME_LIMIT',
            '$telemetryWindow->limit(',
        ] as $token) {
            if (! str_contains($controller, $token)) {
                $violations[] = "app/Http/Controllers/AiTelemetryMetricsController.php: telemetry list APIs must use shared limit input [{$token}]";
            }
        }

        foreach ([
            'test_limit_normalizes_telemetry_list_limits_with_canonical_caps',
            'AiTelemetryWindowInput::MAX_SUMMARY_LIMIT',
            'AiTelemetryWindowInput::MAX_OUTCOME_LIMIT',
        ] as $token) {
            if (! str_contains($unitTest, $token)) {
                $violations[] = "tests/Unit/Ai/Telemetry/AiTelemetryWindowInputTest.php: telemetry list limit contract must be covered [{$token}]";
            }
        }

        foreach ([
            'test_telemetry_list_apis_use_canonical_limit_contracts',
            'AiTelemetryWindowInput::MAX_SUMMARY_LIMIT',
            'AiTelemetryWindowInput::MAX_COST_RATE_LIMIT',
            'AiTelemetryWindowInput::MAX_OUTCOME_LIMIT',
        ] as $token) {
            if (! str_contains($featureTest, $token)) {
                $violations[] = "tests/Feature/AiTelemetryMetricsTest.php: telemetry list API limits must be covered [{$token}]";
            }
        }

        foreach ([
            'telemetry list limit contract',
            'AP-76',
        ] as $token) {
            if (! str_contains($docs, $token)) {
                $violations[] = "docs/engineering-knowledge-base/atlas-ai-kernel-architecture.md: docs must describe telemetry list limit contract [{$token}]";
            }
        }

        return $violations;
    }

    /**
     * @return array<int,string>
     */
    private function scanRuntimeBudgetWindowContract(): array
    {
        $settingsPath = app_path('Services/Ai/Policy/AtlasAiRuntimeSettings.php');
        $budgetServicePath = app_path('Services/Ai/Policy/AiRuntimeBudgetService.php');
        $policyServicePath = app_path('Services/Ai/Policy/AtlasAiPolicyService.php');
        $testPath = base_path('tests/Unit/Ai/AtlasAiRuntimeSettingsTest.php');
        $docsPath = base_path('docs/engineering-knowledge-base/atlas-ai-kernel-architecture.md');
        $violations = [];

        $settings = File::exists($settingsPath) ? File::get($settingsPath) : '';
        $budgetService = File::exists($budgetServicePath) ? File::get($budgetServicePath) : '';
        $policyService = File::exists($policyServicePath) ? File::get($policyServicePath) : '';
        $test = File::exists($testPath) ? File::get($testPath) : '';
        $docs = $this->primitives->kernelDocumentationCorpus();

        foreach ([
            'public const DEFAULT_BUDGET_WINDOW_HOURS = 24',
            'public const MAX_BUDGET_WINDOW_HOURS = 168',
            'public function normalizeBudgetWindowHours(mixed $value): int',
            'return max(1, min(self::MAX_BUDGET_WINDOW_HOURS, (int) $value))',
        ] as $token) {
            if (! str_contains($settings, $token)) {
                $violations[] = "app/Services/Ai/Policy/AtlasAiRuntimeSettings.php: runtime budget window contract is incomplete [{$token}]";
            }
        }

        if (str_contains($settings, 'min(168') || str_contains($settings, '?? 24)')) {
            $violations[] = 'app/Services/Ai/Policy/AtlasAiRuntimeSettings.php: runtime budget window must not duplicate numeric window limits';
        }

        foreach ([
            'AtlasAiRuntimeSettings::DEFAULT_BUDGET_WINDOW_HOURS',
            'atlas.runtime_budget.governance_contract.v1',
            "'autonomy_escalation_allowed' => false",
            "'budget_limit_auto_raise_allowed' => false",
            "'requires_human_review_for_limit_change' => true",
            "'requires_decision_receipt_for_limit_change' => true",
            'bypass_budget_block',
        ] as $token) {
            if (! str_contains($budgetService, $token)) {
                $violations[] = "app/Services/Ai/Policy/AiRuntimeBudgetService.php: runtime budget payload must use shared budget window default [{$token}]";
            }

            if (! str_contains($policyService, $token)) {
                $violations[] = "app/Services/Ai/Policy/AtlasAiPolicyService.php: effective policy must use shared budget window default [{$token}]";
            }
        }

        foreach ([
            'test_budget_window_hours_uses_explicit_policy_contract',
            'AtlasAiRuntimeSettings::MAX_BUDGET_WINDOW_HOURS',
            'AtlasAiRuntimeSettings::DEFAULT_BUDGET_WINDOW_HOURS',
            'atlas.runtime_budget.governance_contract.v1',
            'governance_contract.requires_decision_receipt_for_limit_change',
        ] as $token) {
            if (! str_contains($test, $token)) {
                $violations[] = "tests/Unit/Ai/AtlasAiRuntimeSettingsTest.php: runtime budget window contract must be covered [{$token}]";
            }
        }

        foreach ([
            'runtime budget window contract',
            'AP-73',
            'atlas.runtime_budget.governance_contract.v1',
            'autonomy_escalation_allowed=false',
        ] as $token) {
            if (! str_contains($docs, $token)) {
                $violations[] = "docs/engineering-knowledge-base/atlas-ai-kernel-architecture.md: docs must describe runtime budget window contract [{$token}]";
            }
        }

        return $violations;
    }

    /**
     * @return array<int,string>
     */
    private function scanRuntimeLanguageBoundaryContract(): array
    {
        $violations = [];
        $runtimeDocPath = base_path('docs/engineering-knowledge-base/atlas-ai-runtime-language-boundaries.md');
        $staticScansDocPath = base_path('docs/engineering-knowledge-base/kernel/static-scans.md');
        $architectureTestPath = base_path('tests/Feature/Ai/AtlasAiArchitectureValidateCommandTest.php');
        $runtimeReportPath = app_path('Services/Ai/Kernel/Architecture/AtlasRuntimeLanguageBoundaryReportService.php');
        $runtimeCommandTestPath = base_path('tests/Feature/Ai/AtlasAiRuntimeBoundaryCommandTest.php');
        $runtimeApiTestPath = base_path('tests/Feature/Ai/AtlasAiRuntimeBoundaryApiTest.php');
        $mcpTestPath = base_path('tests/Feature/Ai/AtlasOpenBrainMcpServiceTest.php');
        $architectureOperationsCatalogPath = app_path('Services/Ai/Kernel/Architecture/AtlasArchitectureOperationsCatalog.php');
        $architectureOperationsCommandTestPath = base_path('tests/Feature/Ai/AtlasAiArchitectureOperationsCommandTest.php');
        $architectureOperationsApiTestPath = base_path('tests/Feature/Ai/AtlasAiArchitectureOperationsApiTest.php');
        $architectureOperationsCatalogTestPath = base_path('tests/Unit/Ai/Kernel/Architecture/AtlasArchitectureOperationsCatalogTest.php');
        $featurePlacementPath = app_path('Services/Ai/Kernel/Architecture/AtlasFeaturePlacementService.php');
        $featurePlacementTestPath = base_path('tests/Feature/Ai/AtlasAiSessionBootstrapCommandTest.php');

        $runtimeDoc = File::exists($runtimeDocPath) ? File::get($runtimeDocPath) : '';
        $staticScansDoc = File::exists($staticScansDocPath) ? File::get($staticScansDocPath) : '';
        $architectureTest = File::exists($architectureTestPath) ? File::get($architectureTestPath) : '';
        $runtimeReport = File::exists($runtimeReportPath) ? File::get($runtimeReportPath) : '';
        $runtimeCommandTest = File::exists($runtimeCommandTestPath) ? File::get($runtimeCommandTestPath) : '';
        $runtimeApiTest = File::exists($runtimeApiTestPath) ? File::get($runtimeApiTestPath) : '';
        $mcpTest = File::exists($mcpTestPath) ? File::get($mcpTestPath) : '';
        $architectureOperationsCatalog = File::exists($architectureOperationsCatalogPath) ? File::get($architectureOperationsCatalogPath) : '';
        $architectureOperationsCommandTest = File::exists($architectureOperationsCommandTestPath) ? File::get($architectureOperationsCommandTestPath) : '';
        $architectureOperationsApiTest = File::exists($architectureOperationsApiTestPath) ? File::get($architectureOperationsApiTestPath) : '';
        $architectureOperationsCatalogTest = File::exists($architectureOperationsCatalogTestPath) ? File::get($architectureOperationsCatalogTestPath) : '';
        $featurePlacement = File::exists($featurePlacementPath) ? File::get($featurePlacementPath) : '';
        $featurePlacementTest = File::exists($featurePlacementTestPath) ? File::get($featurePlacementTestPath) : '';

        foreach ([
            'Laravel decide e governa',
            'python_ai_data',
            'go_edge',
            'swift_native_mac',
            'DecisionReceipt',
            'Adapters Laravel podem manter manifest, chunking, privacy gate, chamada governada de',
            'Eles nao podem virar Vector RAG',
            'Graph RAG, reranker, clustering',
        ] as $token) {
            if (! str_contains($runtimeDoc, $token)) {
                $violations[] = "docs/engineering-knowledge-base/atlas-ai-runtime-language-boundaries.md: runtime language boundary doctrine must document [{$token}]";
            }
        }

        foreach ([
            'Runtime language boundary',
            'Heavy RAG/ML libraries or direct Go/Swift native runtime shortcuts in Laravel `app/`, including services, semantic adapters, controllers, jobs and commands, must fail runtime language boundary',
            'EmbeddingService',
        ] as $token) {
            if (! str_contains($staticScansDoc, $token)) {
                $violations[] = "docs/engineering-knowledge-base/kernel/static-scans.md: AP-201 runtime language boundary scan must be documented [{$token}]";
            }
        }

        foreach ([
            'ap201_runtime_language_boundary_contract',
            'runtime language boundary',
        ] as $token) {
            if (! str_contains($architectureTest, $token)) {
                $violations[] = "tests/Feature/Ai/AtlasAiArchitectureValidateCommandTest.php: AP-201 runtime language boundary scan must be asserted [{$token}]";
            }
        }

        foreach ([
            'AtlasRuntimeLanguageBoundaryReportService',
            'atlas.runtime_boundary_preflight_gate.v1',
            'runtime_promotion_policy',
            'run_feature_placement_strict',
            'skip_decision_receipt_for_runtime',
            'atlas.runtime_promotion_policy.v1',
            'runtime_invocation_contract',
            'runtime_owner_map',
            'decision_receipt_hash',
            'evidence_sink',
            'create_parallel_context_store',
            'python_ai_data',
            'go_edge',
            'swift_native_mac',
            'runtimes/python',
            'runtimes/go',
            'runtimes/swift',
            'atlas_runtime_boundary',
        ] as $token) {
            if (! str_contains($runtimeReport, $token)) {
                $violations[] = "app/Services/Ai/Kernel/Architecture/AtlasRuntimeLanguageBoundaryReportService.php: AP-201 runtime boundary report must expose executable invocation contract [{$token}]";
            }
        }

        foreach ([
            'preflight_gate.schema_version',
            'runtime_promotion_policy.schema_version',
            'skip_decision_receipt_for_runtime',
            'runtime_invocation_contract.schema_version',
            'runtime_owner_map.python_ai_data.allowed_write_scope',
            'decision_receipt_hash',
        ] as $token) {
            if (! str_contains($runtimeCommandTest, $token)) {
                $violations[] = "tests/Feature/Ai/AtlasAiRuntimeBoundaryCommandTest.php: AP-201 CLI runtime invocation contract must be asserted [{$token}]";
            }
            if (! str_contains($runtimeApiTest, $token)) {
                $violations[] = "tests/Feature/Ai/AtlasAiRuntimeBoundaryApiTest.php: AP-201 API runtime invocation contract must be asserted [{$token}]";
            }
            if (! str_contains($mcpTest, $token)) {
                $violations[] = "tests/Feature/Ai/AtlasOpenBrainMcpServiceTest.php: AP-201 MCP runtime invocation contract must be asserted [{$token}]";
            }
        }

        foreach ([
            "'id' => 'runtime_language_boundary'",
            "'owner_layer' => 'runtime'",
            "'governed_runtimes' => ['python_ai_data', 'go_edge', 'swift_native_mac']",
            "'pre_implementation_gate' => true",
            "'required_for_terms' => ['python', 'rag', 'embedding', 'faiss', 'go', 'swift', 'livekit', 'ml']",
        ] as $token) {
            if (! str_contains($architectureOperationsCatalog, $token)) {
                $violations[] = "app/Services/Ai/Kernel/Architecture/AtlasArchitectureOperationsCatalog.php: AP-201 runtime boundary operation must be discoverable by runtime owner layer [{$token}]";
            }
        }

        foreach ([
            "'--owner-layer' => 'runtime'",
            "['runtime_language_boundary']",
            'architecture_operations.commands.0.owner_layer',
            'architecture_operations.commands.0.pre_implementation_gate',
            'architecture_operations.commands.0.governed_runtimes',
            "'owner_layer' => 'runtime'",
        ] as $token) {
            if (! str_contains($architectureOperationsCommandTest, $token)
                && ! str_contains($architectureOperationsApiTest, $token)
                && ! str_contains($architectureOperationsCatalogTest, $token)
                && ! str_contains($mcpTest, $token)) {
                $violations[] = "tests: AP-201 runtime boundary owner-layer discovery must be asserted across CLI/API/catalog/MCP [{$token}]";
            }
        }

        foreach ([
            'private function runtimeOwnerDocs',
            'private function runtimeInvocationContract',
            'AtlasRuntimeLanguageBoundaryReportService $runtimeBoundary',
            '$this->runtimeBoundary->invocationContract()',
            'selected_runtime_family',
            'placement_runtime_alias',
            'do_not_implement_heavy_rag_embeddings_rerank_graph_or_ml_inside_laravel_app',
            'do_not_put_provider_policy_memory_or_domain_decision_inside_go_edge_runtime',
            'do_not_capture_mic_screen_keychain_touchid_or_accessibility_without_kernel_policy_and_user_consent',
            'run_runtime_language_boundary_before_and_after_changes',
            'declare_kernel_decision_receipt_contract_for_runtime_invocation',
            'prove_runtime_outputs_return_to_evidence_ledger_or_output_renderer',
            'faiss',
            'reranker',
            'go_edge_concurrency',
            'swift_native_mac',
        ] as $token) {
            if (! str_contains($featurePlacement, $token)) {
                $violations[] = "app/Services/Ai/Kernel/Architecture/AtlasFeaturePlacementService.php: AP-201 feature placement must route specialized runtimes with owner docs and forbidden scopes [{$token}]";
            }
        }

        foreach ([
            'test_place_feature_routes_graph_rag_to_python_runtime_boundary',
            'test_place_feature_routes_go_edge_streaming_to_go_runtime_boundary',
            'test_place_feature_routes_swift_native_mac_to_swift_runtime_boundary',
            'implementation_contract.runtime_invocation_contract.schema_version',
            'implementation_contract.runtime_invocation_contract.selected_runtime_family',
            'docs/engineering-knowledge-base/atlas-ai-local-performance-memory-strategy.md',
            'do_not_implement_heavy_rag_embeddings_rerank_graph_or_ml_inside_laravel_app',
            'do_not_put_provider_policy_memory_or_domain_decision_inside_go_edge_runtime',
            'do_not_capture_mic_screen_keychain_touchid_or_accessibility_without_kernel_policy_and_user_consent',
        ] as $token) {
            if (! str_contains($featurePlacementTest, $token)) {
                $violations[] = "tests/Feature/Ai/AtlasAiSessionBootstrapCommandTest.php: AP-201 feature placement runtime routing must be asserted [{$token}]";
            }
        }

        foreach ($this->scanEmbeddingServiceRuntimeBoundary() as $violation) {
            $violations[] = $violation;
        }

        foreach ($this->scanRuntimeBoundaryForbiddenLaravelImplementations() as $violation) {
            $violations[] = $violation;
        }

        sort($violations);

        return $violations;
    }

    /**
     * @return array<int,string>
     */
    private function scanEmbeddingServiceRuntimeBoundary(): array
    {
        $violations = [];
        $embeddingServicePath = app_path('Services/Semantic/EmbeddingService.php');
        $matrixPath = base_path('docs/engineering-knowledge-base/architecture-audit/implemented-vs-scaffold-matrix.md');
        $runtimeBoundaryTestPath = base_path('tests/Unit/Ai/Kernel/Architecture/KernelArchitectureStaticScannerRuntimeLanguageBoundaryTest.php');

        $embeddingService = File::exists($embeddingServicePath) ? File::get($embeddingServicePath) : '';
        $matrix = File::exists($matrixPath) ? File::get($matrixPath) : '';
        $runtimeBoundaryTest = File::exists($runtimeBoundaryTestPath) ? File::get($runtimeBoundaryTestPath) : '';

        foreach ([
            'SemanticRagRuntimeClient',
            'embedWithSemanticRag',
            'embedWithOpenAi',
            'No real embedding provider available',
            'crc32 hash fake was retired',
        ] as $token) {
            if (! str_contains($embeddingService, $token)) {
                $violations[] = "app/Services/Semantic/EmbeddingService.php: AP-201 requires EmbeddingService to remain a real-provider adapter or explicit failure boundary [{$token}]";
            }
        }

        foreach ([
            'EmbeddingService` real-provider adapter',
            'nao promover para Vector RAG, Graph RAG, reranker ou clustering',
            'FAISS/Chroma/LangGraph/NetworkX/Pandas/Polars/scikit/reranker/clustering',
        ] as $token) {
            if (! str_contains($matrix, $token)) {
                $violations[] = "docs/engineering-knowledge-base/architecture-audit/implemented-vs-scaffold-matrix.md: AP-201 must preserve EmbeddingService boundary in the scaffold matrix [{$token}]";
            }
        }

        foreach ([
            'test_runtime_language_boundary_scan_flags_heavy_ai_runtime_inside_laravel_app',
            'test_runtime_language_boundary_scan_flags_heavy_ai_runtime_inside_semantic_adapter',
            'test_runtime_language_boundary_scan_flags_heavy_ai_runtime_inside_controller',
            'test_runtime_language_boundary_scan_flags_heavy_ai_runtime_inside_job',
            'test_runtime_language_boundary_scan_flags_heavy_ai_runtime_inside_command',
            'test_runtime_language_boundary_scan_flags_direct_go_runtime_inside_controller',
            'test_runtime_language_boundary_scan_flags_direct_swift_native_runtime_inside_command',
            'test_runtime_language_boundary_scan_flags_laravel_process_facade_runtime_escape',
            'test_runtime_language_boundary_scan_flags_symfony_process_runtime_escapes',
            'test_runtime_language_boundary_scan_preserves_embedding_service_as_real_provider_adapter',
            'ForbiddenChromaRegression.php',
            'ForbiddenLangGraphController.php',
            'ForbiddenPandasJob.php',
            'ForbiddenNumpyCommand.php',
            'ForbiddenGoEdgeController.php',
            'ForbiddenSwiftNativeCommand.php',
            'ForbiddenLaravelProcessRuntimeController.php',
            'ForbiddenSymfonyProcessGoController.php',
            'ForbiddenSymfonyProcessSwiftCommand.php',
            'test_runtime_language_boundary_scan_flags_heavy_ai_runtime_inside_telemetry',
            'test_runtime_language_boundary_scan_preserves_real_telemetry_stats_and_runtime_clients',
            'ForbiddenNumpyTelemetryEngine.php',
        ] as $token) {
            if (! str_contains($runtimeBoundaryTest, $token)) {
                $violations[] = "tests/Unit/Ai/Kernel/Architecture/KernelArchitectureStaticScannerRuntimeLanguageBoundaryTest.php: AP-201 must cover semantic adapter regressions [{$token}]";
            }
        }

        foreach ([
            'faiss',
            'chromadb',
            'llama_index',
            'langgraph',
            'networkx',
            'pandas',
            'polars',
            'sklearn',
            'torch',
            'tensorflow',
            // Provider model identifiers may contain "transformers" (for
            // example a Hugging Face model slug). Heavy runtime detection is
            // handled by the import/process scanners below, not by matching a
            // provider adapter's model name.
            'similaritySearch',
            'nearestNeighbors',
            'GraphRag',
            'VectorRag',
            'Reranker',
            'Clusterer',
        ] as $token) {
            if (str_contains($embeddingService, $token)) {
                $violations[] = "app/Services/Semantic/EmbeddingService.php: forbidden AP-201 token [{$token}]. Heavy RAG/ML belongs in python_ai_data behind DecisionReceipt.";
            }
        }

        sort($violations);

        return array_values(array_unique($violations));
    }

    /**
     * @return array<int,string>
     */
    private function scanRuntimeBoundaryForbiddenLaravelImplementations(): array
    {
        // Curated allow-list of Laravel *implementation* subtrees, NOT the whole
        // app/Services/Ai tree. Scanning the full tree is deliberately rejected:
        // several Services/Ai subtrees legitimately NAME the forbidden tokens as
        // data and would self-flag (false positives) —
        //   - Services/Ai/Kernel/Architecture/* (this scanner + the boundary
        //     catalog/report/placement services literally enumerate faiss/numpy/
        //     CoreML/FaissIndex as patterns and documentation);
        //   - Services/Ai/RuntimeBoundary/* (the sanctioned PHP->Python bridge;
        //     its *GraphRagContract.php interface names match laravel_heavy_rag_engine,
        //     while *RuntimeClient.php spawns a GENERIC python entrypoint
        //     `new Process([venvPython, main.py, manifest])` with no heavy-lib
        //     literal in the array — correctly NOT matched);
        //   - Services/Ai/Programming/* + Services/Ai/Mobile/* (delegating adapters
        //     named *GraphRag*/*Reranker* match by name, not by hand-rolled math).
        // Telemetry IS included: hand-rolled KS / Mann-Kendall / CUSUM / EWMA /
        // Wilson math lived there undetected until it was moved to
        // runtimes/python/stats_engine behind StatsEngineRuntimeClient. Any NEW
        // implementation subtree that could host heavy data/AI numerics belongs
        // here; meta/boundary/catalog subtrees (above) must not be added.
        $roots = [
            app_path('Services/Ai/Context'),
            app_path('Services/Ai/Learning'),
            app_path('Services/Ai/Domain'),
            app_path('Services/Ai/Memory'),
            app_path('Services/Ai/Provider'),
            app_path('Services/Ai/Surface'),
            app_path('Services/Ai/Telemetry'),
            app_path('Services/Semantic'),
            app_path('Http/Controllers'),
            app_path('Jobs'),
            app_path('Console/Commands'),
        ];

        $patterns = [
            'python_import_faiss' => '/\b(import|from)\s+(faiss|chromadb|llama_index|langgraph|networkx|pandas|polars|numpy|sklearn|torch|tensorflow|transformers)\b/i',
            'process_exec_heavy_ai_runtime' => '/\b(shell_exec|exec|passthru|proc_open)\s*\([^;]*(faiss|chromadb|llama_index|langgraph|networkx|pandas|polars|numpy|sklearn|torch|tensorflow|transformers)/is',
            'laravel_process_heavy_ai_runtime' => '/\bProcess::(?:run|start|forever|pipe)\s*\([^;]*(faiss|chromadb|llama_index|langgraph|networkx|pandas|polars|numpy|sklearn|torch|tensorflow|transformers)/is',
            'symfony_process_shell_heavy_ai_runtime' => '/\bProcess::fromShellCommandline\s*\([^;]*(faiss|chromadb|llama_index|langgraph|networkx|pandas|polars|numpy|sklearn|torch|tensorflow|transformers)/is',
            'symfony_process_array_heavy_ai_runtime' => '/\bnew\s+Process\s*\(\s*\[[^\]]*(python|python3)[^\]]*(faiss|chromadb|llama_index|langgraph|networkx|pandas|polars|numpy|sklearn|torch|tensorflow|transformers)[^\]]*\]/is',
            'laravel_heavy_rag_class' => '/\bnew\s+(FaissIndex|ChromaCollection|LlamaIndex|LangGraph|NetworkX|PandasDataFrame|PolarsDataFrame|TorchModel|TensorFlowModel|TransformersPipeline)\b/i',
            'direct_heavy_rag_symbol' => '/\b(FaissIndex|ChromaCollection|LlamaIndexRunner|LangGraphRunner|NetworkXGraph|PandasDataFrame|PolarsDataFrame|TorchTensor|TensorFlowModel|TransformersPipeline)\b/',
            'laravel_heavy_rag_engine' => '/\b(class|function)\s+\w*(GraphRag|VectorRag|Rerank|Faiss|Chroma|LangGraph|LlamaIndex)\w*\b/i',
            'process_exec_go_edge_runtime' => '/\b(shell_exec|exec|passthru|proc_open)\s*\([^;]*(go\s+(run|build|test)|nats|kafka|webhook-ingestor|postback-ingestor)/is',
            'laravel_process_go_edge_runtime' => '/\bProcess::(?:run|start|forever|pipe|fromShellCommandline)\s*\([^;]*(go\s+(run|build|test)|nats|kafka|webhook-ingestor|postback-ingestor)/is',
            'symfony_process_array_go_edge_runtime' => '/\bnew\s+Process\s*\(\s*\[[^\]]*go[^\]]*(run|build|test|nats|kafka|webhook-ingestor|postback-ingestor)[^\]]*\]/is',
            'process_exec_swift_native_runtime' => '/\b(shell_exec|exec|passthru|proc_open)\s*\([^;]*(swift\s+(run|build|test)|swiftc|ScreenCaptureKit|FSEvents|AVAudioEngine|CoreML|NSWorkspace)/is',
            'laravel_process_swift_native_runtime' => '/\bProcess::(?:run|start|forever|pipe|fromShellCommandline)\s*\([^;]*(swift\s+(run|build|test)|swiftc|ScreenCaptureKit|FSEvents|AVAudioEngine|CoreML|NSWorkspace)/is',
            'symfony_process_array_swift_native_runtime' => '/\bnew\s+Process\s*\(\s*\[[^\]]*(swift|swiftc|ScreenCaptureKit|FSEvents|AVAudioEngine|CoreML|NSWorkspace)[^\]]*\]/is',
            'direct_swift_native_symbol' => '/\b(ScreenCaptureKit|FSEvents|AVAudioEngine|CoreML|NSWorkspace|SFSpeechRecognizer|LAContext|SecKeychain)\b/',
        ];

        $violations = [];

        foreach ($roots as $root) {
            if (! File::isDirectory($root)) {
                continue;
            }

            foreach (File::allFiles($root) as $file) {
                if ($file->getExtension() !== 'php') {
                    continue;
                }

                $path = $file->getRealPath() ?: $file->getPathname();
                $relativePath = str_replace(base_path().DIRECTORY_SEPARATOR, '', $path);
                $contents = File::get($path);

                foreach ($patterns as $patternId => $pattern) {
                    if (preg_match($pattern, $contents) === 1) {
                        $violations[] = "{$relativePath}: forbidden runtime language boundary violation [{$patternId}]. Heavy AI/Data runtime belongs in python_ai_data, go_edge, or swift_native_mac behind Kernel DecisionReceipt.";
                    }
                }
            }
        }

        sort($violations);

        return array_values(array_unique($violations));
    }
}
