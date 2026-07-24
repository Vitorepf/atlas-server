<?php

namespace App\Services\Ai\Kernel\Architecture\Scanner;

use Illuminate\Support\Facades\File;

class ArchitectureAudit
{
    public function __construct(private ScanPrimitivesSupport $primitives) {}

    /**
     * @return array<string,callable(): array<int,string>>
     */
    public function checks(): array
    {
        return [
            'ap37_architecture_validation_surface' => fn (): array => $this->scanArchitectureValidationSurface(),
            'ap38_architecture_validation_observability' => fn (): array => $this->scanArchitectureValidationObservability(),
            'ap39_architecture_validation_contract_parity' => fn (): array => $this->scanArchitectureValidationContractParity(),
            'ap40_architecture_validation_mcp_tool' => fn (): array => $this->scanArchitectureValidationMcpTool(),
            'ap126_architecture_validate_post_ap98_human_output' => fn (): array => $this->scanArchitectureValidatePostAp98HumanOutput(),
            'ap176_architecture_readiness_snapshot' => fn (): array => $this->scanArchitectureReadinessSnapshot(),
            'ap177_architecture_readiness_mcp_tool' => fn (): array => $this->scanArchitectureReadinessMcpTool(),
        ];
    }

    /**
     * @return array<int,string>
     */
    private function scanArchitectureValidationSurface(): array
    {
        $servicePath = app_path('Services/Ai/Kernel/Architecture/AtlasAiArchitectureValidationService.php');
        $commandPath = app_path('Console/Commands/AtlasAiArchitectureValidateCommand.php');
        $controllerPath = app_path('Http/Controllers/AtlasAiArchitectureValidateController.php');
        $routesPath = base_path('routes/api.php');
        $commandTestPath = base_path('tests/Feature/Ai/AtlasAiArchitectureValidateCommandTest.php');
        $apiTestPath = base_path('tests/Feature/Ai/AtlasAiArchitectureValidateApiTest.php');
        $docsPath = base_path('docs/engineering-knowledge-base/atlas-ai-kernel-architecture.md');
        $violations = [];

        $service = File::exists($servicePath) ? File::get($servicePath) : '';
        $command = File::exists($commandPath) ? File::get($commandPath) : '';
        $controller = File::exists($controllerPath) ? File::get($controllerPath) : '';
        $routes = File::exists($routesPath) ? File::get($routesPath) : '';
        $commandTest = File::exists($commandTestPath) ? File::get($commandTestPath) : '';
        $apiTest = File::exists($apiTestPath) ? File::get($apiTestPath) : '';
        $docs = $this->primitives->kernelDocumentationCorpus();

        foreach ([
            'class AtlasAiArchitectureValidationService',
            'public function payload(): array',
            'private function staticScanPayload(',
            'private function staticScanSummary(array $staticScan): array',
            "'static_scan' => \$staticScanPayload",
            "'validated_at' => now()->toJSON()",
        ] as $token) {
            if (! str_contains($service, $token)) {
                $violations[] = "app/Services/Ai/Kernel/Architecture/AtlasAiArchitectureValidationService.php: architecture validation payload must live in the shared kernel service [{$token}]";
            }
        }

        foreach ([
            'AtlasAiArchitectureValidationService',
            'public function handle(AtlasAiArchitectureValidationService $validation): int',
            '$payload = $validation->payload();',
        ] as $token) {
            if (! str_contains($command, $token)) {
                $violations[] = "app/Console/Commands/AtlasAiArchitectureValidateCommand.php: CLI must render the shared architecture validation payload [{$token}]";
            }
        }

        foreach ([
            'class AtlasAiArchitectureValidateController',
            'public function __invoke(AtlasAiArchitectureValidationService $validation): JsonResponse',
            '$payload = $validation->payload();',
            "response()->json(\$payload, \$payload['status'] === 'ok' ? 200 : 503)",
        ] as $token) {
            if (! str_contains($controller, $token)) {
                $violations[] = "app/Http/Controllers/AtlasAiArchitectureValidateController.php: API must expose the shared architecture validation payload [{$token}]";
            }
        }

        if (! str_contains($routes, 'AtlasAiArchitectureValidateController') || ! str_contains($routes, "Route::get('/ai/architecture/validate', AtlasAiArchitectureValidateController::class);")) {
            $violations[] = 'routes/api.php: GET /ai/architecture/validate must be registered inside the atlas.token API group';
        }

        foreach ([
            "data_get(\$payload, 'kernel.static_scan.summary.total_count')",
            "data_get(\$payload, 'kernel.static_scan.summary.violation_count')",
        ] as $token) {
            if (! str_contains($commandTest, $token)) {
                $violations[] = "tests/Feature/Ai/AtlasAiArchitectureValidateCommandTest.php: CLI test must lock static scan summary payload [{$token}]";
            }
        }

        foreach ([
            "'/ai/architecture/validate'",
            "assertJsonPath('kernel.static_scan.summary.failed_count', 0)",
            "assertContains('ap36_kernel_pipeline_health_read_model'",
            'assertUnauthorized()',
        ] as $token) {
            if (! str_contains($apiTest, $token)) {
                $violations[] = "tests/Feature/Ai/AtlasAiArchitectureValidateApiTest.php: API test must lock architecture validation route and auth [{$token}]";
            }
        }

        foreach ([
            '`GET /ai/architecture/validate`',
            '`kernel.static_scan.summary`',
        ] as $token) {
            if (! str_contains($docs, $token)) {
                $violations[] = "docs/engineering-knowledge-base/atlas-ai-kernel-architecture.md: canonical docs must describe architecture validation API surface [{$token}]";
            }
        }

        return $violations;
    }

    /**
     * @return array<int,string>
     */
    private function scanArchitectureValidationObservability(): array
    {
        $observabilityPath = app_path('Http/Controllers/AiObservabilityController.php');
        $observabilityTestPath = base_path('tests/Feature/Ai/AiObservabilityKernelSloTest.php');
        $docsPath = base_path('docs/engineering-knowledge-base/atlas-ai-kernel-architecture.md');
        $violations = [];

        $observability = File::exists($observabilityPath) ? File::get($observabilityPath) : '';
        $observabilityTest = File::exists($observabilityTestPath) ? File::get($observabilityTestPath) : '';
        $docs = $this->primitives->kernelDocumentationCorpus();

        foreach ([
            'AtlasAiArchitectureValidationService',
            'AtlasAiArchitectureValidationService $architectureValidation',
            '$kernelSlo = $ledgerReplay->sloReportForWindow($since)',
            '$kernelRepair = $ledgerReplay->repairReportForWindow($since)',
            '$kernelPipeline = $ledgerReplay->kernelPipelineReportForWindow($since)',
            '$architecturePayload = $architectureValidation->payload();',
            "'architecture_validation' => \$this->architectureValidationSummary(\$architecturePayload)",
            'private function architectureValidationSummary(array $payload): array',
            "'summary' => data_get(\$payload, 'kernel.static_scan.summary', [])",
        ] as $token) {
            if (! str_contains($observability, $token)) {
                $violations[] = "app/Http/Controllers/AiObservabilityController.php: observability must expose compact architecture validation health without duplicating payload [{$token}]";
            }
        }

        foreach ([
            'test_observability_payload_includes_architecture_validation_summary',
            "assertJsonPath('architecture_validation.status', 'ok')",
            "assertJsonPath('architecture_validation.kernel.static_scan.summary.failed_count', 0)",
            "assertContains('ap37_architecture_validation_surface'",
        ] as $token) {
            if (! str_contains($observabilityTest, $token)) {
                $violations[] = "tests/Feature/Ai/AiObservabilityKernelSloTest.php: observability test must lock architecture validation summary [{$token}]";
            }
        }

        foreach ([
            '`architecture_validation`',
            'compacto de arquitetura',
        ] as $token) {
            if (! str_contains($docs, $token)) {
                $violations[] = "docs/engineering-knowledge-base/atlas-ai-kernel-architecture.md: canonical docs must describe architecture validation observability summary [{$token}]";
            }
        }

        return $violations;
    }

    /**
     * @return array<int,string>
     */
    private function scanArchitectureValidationContractParity(): array
    {
        $servicePath = app_path('Services/Ai/Kernel/Architecture/AtlasAiArchitectureValidationService.php');
        $commandTestPath = base_path('tests/Feature/Ai/AtlasAiArchitectureValidateCommandTest.php');
        $apiTestPath = base_path('tests/Feature/Ai/AtlasAiArchitectureValidateApiTest.php');
        $kernelDocsPath = base_path('docs/engineering-knowledge-base/atlas-ai-kernel-architecture.md');
        $operatingDocsPath = base_path('docs/engineering-knowledge-base/atlas-ai-operating-system.md');
        $violations = [];

        $service = File::exists($servicePath) ? File::get($servicePath) : '';
        $commandTest = File::exists($commandTestPath) ? File::get($commandTestPath) : '';
        $apiTest = File::exists($apiTestPath) ? File::get($apiTestPath) : '';
        $kernelDocs = $this->primitives->kernelDocumentationCorpus();
        $operatingDocs = File::exists($operatingDocsPath) ? File::get($operatingDocsPath) : '';

        foreach ([
            'public function payload(): array',
            'private function staticScanSummary(array $staticScan): array',
            "'schema_version' => 1",
            "'status' =>",
            "'validated_at' => now()->toJSON()",
        ] as $token) {
            if (! str_contains($service, $token)) {
                $violations[] = "app/Services/Ai/Kernel/Architecture/AtlasAiArchitectureValidationService.php: shared payload service must remain the canonical contract source [{$token}]";
            }
        }

        foreach ([
            'test_architecture_validate_api_matches_shared_service_contract',
            'AtlasAiArchitectureValidationService::class',
            '$expected = $this->app->make(AtlasAiArchitectureValidationService::class)->payload();',
            "assertSame(data_get(\$expected, 'kernel.static_scan.summary.total_count'), \$response->json('kernel.static_scan.summary.total_count'))",
            "assertSame(data_get(\$expected, 'capabilities.count'), \$response->json('capabilities.count'))",
            "assertSame(data_get(\$expected, 'onboarding.domain_count'), \$response->json('onboarding.domain_count'))",
        ] as $token) {
            if (! str_contains($apiTest, $token)) {
                $violations[] = "tests/Feature/Ai/AtlasAiArchitectureValidateApiTest.php: API must prove parity with the shared validation service [{$token}]";
            }
        }

        foreach ([
            'test_command_validates_architecture_contracts_as_json',
            "data_get(\$payload, 'kernel.static_scan.summary.total_count')",
            "data_get(\$payload, 'kernel.static_scan.summary.passed_count')",
            "assertContains('ap39_architecture_validation_contract_parity'",
        ] as $token) {
            if (! str_contains($commandTest, $token)) {
                $violations[] = "tests/Feature/Ai/AtlasAiArchitectureValidateCommandTest.php: CLI must lock the same architecture validation contract summary [{$token}]";
            }
        }

        foreach ([
            'AP-39',
            'fonte unica',
            '`AtlasAiArchitectureValidationService`',
        ] as $token) {
            if (! str_contains($kernelDocs, $token) && ! str_contains($operatingDocs, $token)) {
                $violations[] = "docs/engineering-knowledge-base: canonical docs must describe architecture validation contract parity [{$token}]";
            }
        }

        return $violations;
    }

    /**
     * @return array<int,string>
     */
    private function scanArchitectureValidationMcpTool(): array
    {
        $mcpPath = app_path('Services/Ai/AtlasOpenBrainMcpService.php');
        $testPath = base_path('tests/Feature/Ai/AtlasOpenBrainMcpServiceTest.php');
        $kernelDocsPath = base_path('docs/engineering-knowledge-base/atlas-ai-kernel-architecture.md');
        $memoryDocsPath = base_path('docs/engineering-knowledge-base/atlas-ai-memory-context-core-open-brain.md');
        $violations = [];

        $architectureToolsPath = app_path('Services/Ai/OpenBrainMcp/ArchitectureTools.php');

        $mcp = File::exists($mcpPath) ? File::get($mcpPath) : '';
        $architectureTools = File::exists($architectureToolsPath) ? File::get($architectureToolsPath) : '';
        $test = File::exists($testPath) ? File::get($testPath) : '';
        $kernelDocs = $this->primitives->kernelDocumentationCorpus();
        $memoryDocs = File::exists($memoryDocsPath) ? File::get($memoryDocsPath) : '';

        // Façade keeps the tools() schema + dispatch + the shared-service dependency; the
        // handler was relocated under GOD-DEBULK D3 to OpenBrainMcp/ArchitectureTools.
        foreach ([
            'AtlasAiArchitectureValidationService',
            'private readonly AtlasAiArchitectureValidationService $architectureValidation',
            "'name' => 'atlas_architecture_validate'",
            "'title' => 'Atlas Architecture Validate'",
            "'atlas_architecture_validate' => \$this->toolResponse(\$id, \$this->architectureTools->architectureValidate(\$arguments))",
            "'writes' => false",
        ] as $token) {
            if (! str_contains($mcp, $token)) {
                $violations[] = "app/Services/Ai/AtlasOpenBrainMcpService.php: MCP must expose architecture validation via shared service [{$token}]";
            }
        }

        foreach ([
            'public function architectureValidate(array $arguments): array',
            '$payload = $this->architectureValidation->payload();',
        ] as $token) {
            if (! str_contains($architectureTools, $token)) {
                $violations[] = "app/Services/Ai/OpenBrainMcp/ArchitectureTools.php: MCP must expose architecture validation via shared service [{$token}]";
            }
        }

        foreach ([
            'test_architecture_validate_tool_exposes_shared_contract_summary',
            'test_architecture_validate_tool_rejects_invalid_detail',
            "assertContains('atlas_architecture_validate'",
            "assertSame('atlas_architecture_validate', \$structured['tool'])",
            "assertContains(\n            'ap39_architecture_validation_contract_parity'",
        ] as $token) {
            if (! str_contains($test, $token)) {
                $violations[] = "tests/Feature/Ai/AtlasOpenBrainMcpServiceTest.php: MCP architecture validation tool must be tested [{$token}]";
            }
        }

        foreach ([
            '`atlas_architecture_validate`',
            'Open Brain/MCP',
        ] as $token) {
            if (! str_contains($kernelDocs, $token) && ! str_contains($memoryDocs, $token)) {
                $violations[] = "docs/engineering-knowledge-base: MCP docs must describe architecture validation tool [{$token}]";
            }
        }

        return $violations;
    }

    /**
     * @return array<int,string>
     */
    private function scanArchitectureValidatePostAp98HumanOutput(): array
    {
        $violations = [];
        $commandPath = app_path('Console/Commands/AtlasAiArchitectureValidateCommand.php');
        $testPath = base_path('tests/Feature/Ai/AtlasAiArchitectureValidateCommandTest.php');
        $docsPath = base_path('docs/engineering-knowledge-base/atlas-ai-kernel-architecture.md');
        $apDocPath = base_path('docs/ap/AP-126-architecture-validate-post-ap98-human-output.md');

        $command = File::exists($commandPath) ? File::get($commandPath) : '';
        $test = File::exists($testPath) ? File::get($testPath) : '';
        $docs = $this->primitives->kernelDocumentationCorpus();
        $apDoc = File::exists($apDocPath) ? File::get($apDocPath) : '';

        foreach ([
            'renderPostAp98StaticScanViolations($payload)',
            'private function renderPostAp98StaticScanViolations(array $payload): void',
            "data_get(\$payload, 'kernel.static_scan', [])",
            "preg_match('/^ap(?P<number>\\d+)_/', \$key, \$matches)",
            "(int) \$matches['number'] <= 98",
            '$this->error("[kernel.static.{$key}] ".$violation)',
        ] as $token) {
            if (! str_contains($command, $token)) {
                $violations[] = "app/Console/Commands/AtlasAiArchitectureValidateCommand.php: AP-126 human output must render post-AP98 static scan violations generically [{$token}]";
            }
        }

        foreach ([
            'test_human_output_renders_post_ap98_static_scan_violations',
            'ap125_inbox_action_report_surfaces',
            '[kernel.static.ap125_inbox_action_report_surfaces]',
            'AP-125 synthetic violation for human output',
        ] as $token) {
            if (! str_contains($test, $token)) {
                $violations[] = "tests/Feature/Ai/AtlasAiArchitectureValidateCommandTest.php: AP-126 human output regression must be covered [{$token}]";
            }
        }

        foreach ([
            'AP-126',
            'Architecture Validate Post-AP98 Human Output',
            'renderPostAp98StaticScanViolations',
            'ap126_architecture_validate_post_ap98_human_output',
        ] as $token) {
            if (! str_contains($docs, $token)) {
                $violations[] = "docs/engineering-knowledge-base/atlas-ai-kernel-architecture.md: AP-126 architecture validate human output must be documented [{$token}]";
            }
            if (! str_contains($apDoc, $token)) {
                $violations[] = "docs/ap/AP-126-architecture-validate-post-ap98-human-output.md: AP-126 contract doc must exist [{$token}]";
            }
        }

        return $violations;
    }

    /**
     * @return array<int,string>
     */
    private function scanArchitectureReadinessSnapshot(): array
    {
        $violations = [];
        $servicePath = app_path('Services/Ai/Kernel/Architecture/AtlasArchitectureReadinessService.php');
        $commandPath = app_path('Console/Commands/AtlasAiArchitectureReadinessCommand.php');
        $controllerPath = app_path('Http/Controllers/AtlasAiGovernanceController.php');
        $routesPath = base_path('routes/api.php');
        $catalogPath = app_path('Services/Ai/Kernel/Architecture/AtlasArchitectureOperationsCatalog.php');
        $commandTestPath = base_path('tests/Feature/Ai/AtlasAiArchitectureReadinessCommandTest.php');
        $apiTestPath = base_path('tests/Feature/Ai/AtlasAiGovernanceApiTest.php');
        $catalogTestPath = base_path('tests/Unit/Ai/Kernel/Architecture/AtlasArchitectureOperationsCatalogTest.php');
        $docsPath = base_path('docs/engineering-knowledge-base/atlas-ai-kernel-architecture.md');
        $apDocPath = base_path('docs/ap/AP-176-architecture-readiness-snapshot.md');

        $service = File::exists($servicePath) ? File::get($servicePath) : '';
        $command = File::exists($commandPath) ? File::get($commandPath) : '';
        $controller = File::exists($controllerPath) ? File::get($controllerPath) : '';
        $routes = File::exists($routesPath) ? File::get($routesPath) : '';
        $catalog = File::exists($catalogPath) ? File::get($catalogPath) : '';
        $commandTest = File::exists($commandTestPath) ? File::get($commandTestPath) : '';
        $apiTest = File::exists($apiTestPath) ? File::get($apiTestPath) : '';
        $catalogTest = File::exists($catalogTestPath) ? File::get($catalogTestPath) : '';
        $docs = $this->primitives->kernelDocumentationCorpus();
        $apDoc = File::exists($apDocPath) ? File::get($apDocPath) : '';

        foreach ([
            'AtlasAiArchitectureValidationService $validation',
            'AtlasDocumentationSplitPlanService $splitPlan',
            'AtlasProviderProjectionService $projection',
            'AtlasArchitectureOperationsCatalog $operations',
            "'schema_version' => 'atlas.architecture_readiness.v1'",
            "'checks' => \$checks",
            "'docs_split_plan' => [",
            "'provider_projection' => [",
            "'coverage_boundary' => \$this->implementedVsScaffoldCoverageBoundary()",
            "'safe_next_blocks' => \$this->implementedVsScaffoldSafeNextBlocks()",
            "'architecture_operations' => \$this->readinessOperations()",
            "'review_signal' => [",
            'private function implementedVsScaffoldCoverageBoundary(): array',
            'private function implementedVsScaffoldSafeNextBlocks(): array',
            'private function readinessOperations(): array',
            "'owner_layer_operations' => [",
            "'runtime' => \$this->operations->summary(['owner_layer' => 'runtime'])",
        ] as $token) {
            if (! str_contains($service, $token)) {
                $violations[] = "app/Services/Ai/Kernel/Architecture/AtlasArchitectureReadinessService.php: AP-176 readiness snapshot must aggregate existing governance authorities [{$token}]";
            }
        }

        foreach ([
            "protected \$signature = 'atlas:ai:architecture-readiness",
            'AtlasArchitectureReadinessService $readiness',
            "'workspace' => \$this->option('workspace')",
            "'owner' => \$this->option('owner')",
        ] as $token) {
            if (! str_contains($command, $token)) {
                $violations[] = "app/Console/Commands/AtlasAiArchitectureReadinessCommand.php: AP-176 CLI must expose readiness snapshot [{$token}]";
            }
        }

        foreach ([
            'public function architectureReadiness(Request $request, AtlasArchitectureReadinessService $readiness): JsonResponse',
            "'workspace' => ['nullable', 'string', 'max:500']",
            "'owner' => ['nullable', 'string', 'max:120']",
            '$readiness->snapshot($data)',
        ] as $token) {
            if (! str_contains($controller, $token)) {
                $violations[] = "app/Http/Controllers/AtlasAiGovernanceController.php: AP-176 API controller must expose readiness snapshot [{$token}]";
            }
        }

        if (! str_contains($routes, "Route::get('/ai/architecture/readiness', [AtlasAiGovernanceController::class, 'architectureReadiness'])")) {
            $violations[] = 'routes/api.php: AP-176 API route /ai/architecture/readiness must exist';
        }

        foreach ([
            "'id' => 'architecture_readiness'",
            "'command' => 'php artisan atlas:ai:architecture-readiness --json'",
            "'kind' => 'readiness'",
            "'api_endpoint' => '/ai/architecture/readiness'",
        ] as $token) {
            if (! str_contains($catalog, $token)) {
                $violations[] = "app/Services/Ai/Kernel/Architecture/AtlasArchitectureOperationsCatalog.php: AP-176 catalog must publish architecture_readiness [{$token}]";
            }
        }

        foreach ([
            'test_command_returns_architecture_readiness_snapshot_as_json',
            "data_get(\$payload, 'schema_version')",
            'architecture_readiness',
            'safe_next_blocks.0.block',
            'coverage_boundary.schema_version',
            'architecture_operations.owner_layer_operations.runtime.operation_ids',
            'review_signal.required_next_commands',
        ] as $token) {
            if (! str_contains($commandTest, $token)) {
                $violations[] = "tests/Feature/Ai/AtlasAiArchitectureReadinessCommandTest.php: AP-176 command tests must cover readiness output [{$token}]";
            }
        }

        foreach ([
            'test_architecture_readiness_api_returns_governance_snapshot',
            '/ai/architecture/readiness?owner=kernel_architecture',
            "assertJsonPath('schema_version', 'atlas.architecture_readiness.v1')",
            "assertJsonPath('coverage_boundary.schema_version', 'atlas.implemented_vs_scaffold.coverage_boundary.v1')",
            "assertJsonPath('safe_next_blocks.0.block', 'Voice Realtime product loop')",
            "assertJsonPath('architecture_operations.commands.0.id', 'architecture_readiness')",
            'architecture_operations.owner_layer_operations.runtime.operation_ids',
        ] as $token) {
            if (! str_contains($apiTest, $token)) {
                $violations[] = "tests/Feature/Ai/AtlasAiGovernanceApiTest.php: AP-176 API tests must cover readiness output [{$token}]";
            }
        }

        foreach ([
            "'architecture_readiness'",
            "'php artisan atlas:ai:architecture-readiness --json'",
            "summary(['kind' => 'readiness'])",
        ] as $token) {
            if (! str_contains($catalogTest, $token)) {
                $violations[] = "tests/Unit/Ai/Kernel/Architecture/AtlasArchitectureOperationsCatalogTest.php: AP-176 catalog tests must cover readiness operation [{$token}]";
            }
        }

        foreach ([
            'AP-176',
            'Architecture Readiness Snapshot',
            'ap176_architecture_readiness_snapshot',
        ] as $token) {
            if (! str_contains($docs, $token)) {
                $violations[] = "docs/engineering-knowledge-base/atlas-ai-kernel-architecture.md: AP-176 readiness snapshot must be documented [{$token}]";
            }
            if (! str_contains($apDoc, $token)) {
                $violations[] = "docs/ap/AP-176-architecture-readiness-snapshot.md: AP-176 contract doc must exist [{$token}]";
            }
        }

        return $violations;
    }

    /**
     * @return array<int,string>
     */
    private function scanArchitectureReadinessMcpTool(): array
    {
        $violations = [];
        $mcpPath = app_path('Services/Ai/AtlasOpenBrainMcpService.php');
        $catalogPath = app_path('Services/Ai/Kernel/Architecture/AtlasArchitectureOperationsCatalog.php');
        $mcpTestPath = base_path('tests/Feature/Ai/AtlasOpenBrainMcpServiceTest.php');
        $catalogTestPath = base_path('tests/Unit/Ai/Kernel/Architecture/AtlasArchitectureOperationsCatalogTest.php');
        $docsPath = base_path('docs/engineering-knowledge-base/atlas-ai-kernel-architecture.md');
        $apDocPath = base_path('docs/ap/AP-177-architecture-readiness-mcp-tool.md');

        $architectureToolsPath = app_path('Services/Ai/OpenBrainMcp/ArchitectureTools.php');

        $mcp = File::exists($mcpPath) ? File::get($mcpPath) : '';
        $architectureTools = File::exists($architectureToolsPath) ? File::get($architectureToolsPath) : '';
        $catalog = File::exists($catalogPath) ? File::get($catalogPath) : '';
        $mcpTest = File::exists($mcpTestPath) ? File::get($mcpTestPath) : '';
        $catalogTest = File::exists($catalogTestPath) ? File::get($catalogTestPath) : '';
        $docs = $this->primitives->kernelDocumentationCorpus();
        $apDoc = File::exists($apDocPath) ? File::get($apDocPath) : '';

        // Façade keeps the tools() schema + dispatch + the readiness-service dependency;
        // the handler was relocated under GOD-DEBULK D3 to OpenBrainMcp/ArchitectureTools.
        foreach ([
            'AtlasArchitectureReadinessService',
            'private readonly AtlasArchitectureReadinessService $architectureReadiness',
            "'name' => 'atlas_architecture_readiness'",
            "'title' => 'Atlas Architecture Readiness'",
            "'workspace' => ['type' => 'string'",
            "'owner' => ['type' => 'string'",
            "'atlas_architecture_readiness' => \$this->toolResponse(\$id, \$this->architectureTools->architectureReadiness(\$arguments))",
            "'writes' => false",
        ] as $token) {
            if (! str_contains($mcp, $token)) {
                $violations[] = "app/Services/Ai/AtlasOpenBrainMcpService.php: AP-177 MCP must expose architecture readiness as read-only tool [{$token}]";
            }
        }

        foreach ([
            'public function architectureReadiness(array $arguments): array',
            "'tool' => 'atlas_architecture_readiness'",
            "'architecture_readiness' => \$payload",
        ] as $token) {
            if (! str_contains($architectureTools, $token)) {
                $violations[] = "app/Services/Ai/OpenBrainMcp/ArchitectureTools.php: AP-177 MCP must expose architecture readiness as read-only tool [{$token}]";
            }
        }

        if (! str_contains($catalog, "'mcp_tool' => 'atlas_architecture_readiness'")) {
            $violations[] = 'app/Services/Ai/Kernel/Architecture/AtlasArchitectureOperationsCatalog.php: AP-177 architecture_readiness operation must declare mcp_tool atlas_architecture_readiness';
        }

        foreach ([
            'test_architecture_readiness_tool_exposes_preimplementation_snapshot',
            "'atlas_architecture_readiness'",
            "'atlas.architecture_readiness.v1'",
            'architecture_readiness.architecture_operations.owner_layer_operations.runtime.operation_ids',
            "'continue_implementation_with_session_bootstrap_and_feature_placement'",
        ] as $token) {
            if (! str_contains($mcpTest, $token)) {
                $violations[] = "tests/Feature/Ai/AtlasOpenBrainMcpServiceTest.php: AP-177 MCP tests must lock readiness output [{$token}]";
            }
        }

        if (! str_contains($catalogTest, "'atlas_architecture_readiness'")) {
            $violations[] = 'tests/Unit/Ai/Kernel/Architecture/AtlasArchitectureOperationsCatalogTest.php: AP-177 catalog test must lock architecture_readiness mcp_tool metadata';
        }

        foreach ([
            'AP-177',
            'Architecture Readiness MCP Tool',
            'ap177_architecture_readiness_mcp_tool',
        ] as $token) {
            if (! str_contains($docs, $token)) {
                $violations[] = "docs/engineering-knowledge-base/atlas-ai-kernel-architecture.md: AP-177 MCP readiness tool must be documented [{$token}]";
            }
            if (! str_contains($apDoc, $token)) {
                $violations[] = "docs/ap/AP-177-architecture-readiness-mcp-tool.md: AP-177 contract doc must exist [{$token}]";
            }
        }

        return $violations;
    }
}
