<?php

namespace App\Services\Ai\Kernel\Architecture\Scanner;

use Illuminate\Support\Facades\File;

class ArchitectureOperationsAudit
{
    public function __construct(private ScanPrimitivesSupport $primitives) {}

    /**
     * @return array<string,callable(): array<int,string>>
     */
    public function checks(): array
    {
        return [
            'ap128_architecture_operations_shared_catalog' => fn (): array => $this->scanArchitectureOperationsSharedCatalog(),
            'ap129_architecture_operations_mcp_tool' => fn (): array => $this->scanArchitectureOperationsMcpTool(),
            'ap130_architecture_operations_direct_surfaces' => fn (): array => $this->scanArchitectureOperationsDirectSurfaces(),
            'ap132_architecture_operations_metadata_contract' => fn (): array => $this->scanArchitectureOperationsMetadataContract(),
            'ap133_architecture_operations_filter_contract' => fn (): array => $this->scanArchitectureOperationsFilterContract(),
        ];
    }

    /**
     * @return array<int,string>
     */
    private function scanArchitectureOperationsFilterContract(): array
    {
        $violations = [];
        $catalogPath = app_path('Services/Ai/Kernel/Architecture/AtlasArchitectureOperationsCatalog.php');
        $commandPath = app_path('Console/Commands/AtlasAiArchitectureOperationsCommand.php');
        $controllerPath = app_path('Http/Controllers/AtlasAiArchitectureOperationsController.php');
        $mcpPath = app_path('Services/Ai/AtlasOpenBrainMcpService.php');
        $unitTestPath = base_path('tests/Unit/Ai/Kernel/Architecture/AtlasArchitectureOperationsCatalogTest.php');
        $commandTestPath = base_path('tests/Feature/Ai/AtlasAiArchitectureOperationsCommandTest.php');
        $apiTestPath = base_path('tests/Feature/Ai/AtlasAiArchitectureOperationsApiTest.php');
        $mcpTestPath = base_path('tests/Feature/Ai/AtlasOpenBrainMcpServiceTest.php');
        $docsPath = base_path('docs/engineering-knowledge-base/atlas-ai-kernel-architecture.md');
        $apDocPath = base_path('docs/ap/AP-133-architecture-operations-filter-contract.md');

        $catalog = File::exists($catalogPath) ? File::get($catalogPath) : '';
        $command = File::exists($commandPath) ? File::get($commandPath) : '';
        $controller = File::exists($controllerPath) ? File::get($controllerPath) : '';
        $mcp = File::exists($mcpPath) ? File::get($mcpPath) : '';
        $architectureTools = File::exists(app_path('Services/Ai/OpenBrainMcp/ArchitectureTools.php')) ? File::get(app_path('Services/Ai/OpenBrainMcp/ArchitectureTools.php')) : '';
        $unitTest = File::exists($unitTestPath) ? File::get($unitTestPath) : '';
        $commandTest = File::exists($commandTestPath) ? File::get($commandTestPath) : '';
        $apiTest = File::exists($apiTestPath) ? File::get($apiTestPath) : '';
        $mcpTest = File::exists($mcpTestPath) ? File::get($mcpTestPath) : '';
        $docs = $this->primitives->kernelDocumentationCorpus();
        $apDoc = File::exists($apDocPath) ? File::get($apDocPath) : '';

        foreach ([
            'public function summary(array $filters = []): array',
            'private function normalizeFilters(array $filters): array',
            'private function matchesFilters(array $command, array $filters): bool',
            "'filters' => \$filters",
            "'id'",
            "'kind'",
            "'section'",
            "'surface'",
            "'owner_layer'",
            '$value !== $this->sectionKey()',
        ] as $token) {
            if (! str_contains($catalog, $token)) {
                $violations[] = "app/Services/Ai/Kernel/Architecture/AtlasArchitectureOperationsCatalog.php: AP-133 catalog must support canonical id/kind/section/surface filters [{$token}]";
            }
        }

        foreach ([
            '{--id= : Filter by stable operation id}',
            '{--kind= : Filter by operation kind}',
            '{--section= : Filter by canonical operation section}',
            '{--surface= : Filter by operation surface}',
            '{--owner-layer= : Filter by governing owner layer}',
            '$catalog->summary($this->filters())',
            'private function filters(): array',
        ] as $token) {
            if (! str_contains($command, $token)) {
                $violations[] = "app/Console/Commands/AtlasAiArchitectureOperationsCommand.php: AP-133 CLI must expose id/kind/section/surface filters [{$token}]";
            }
        }

        foreach ([
            "'id' => ['nullable', 'string', 'max:120']",
            "'kind' => ['nullable', 'string', 'max:120']",
            "'section' => ['nullable', 'string', 'max:120']",
            "'surface' => ['nullable', 'string', 'max:120']",
            "'owner_layer' => ['nullable', 'string', 'max:120']",
            '$catalog->summary($data)',
        ] as $token) {
            if (! str_contains($controller, $token)) {
                $violations[] = "app/Http/Controllers/AtlasAiArchitectureOperationsController.php: AP-133 API must expose id/kind/section/surface filters [{$token}]";
            }
        }

        // Façade keeps the tools() schema + dispatch; the handler was relocated under
        // GOD-DEBULK D3 to OpenBrainMcp/ArchitectureTools (invariant unchanged).
        foreach ([
            "'id' => ['type' => 'string'",
            "'kind' => ['type' => 'string'",
            "'section' => ['type' => 'string'",
            "'surface' => ['type' => 'string'",
            "'owner_layer' => ['type' => 'string'",
            "'atlas_architecture_operations' => \$this->toolResponse(\$id, \$this->architectureTools->architectureOperations(\$arguments))",
        ] as $token) {
            if (! str_contains($mcp, $token)) {
                $violations[] = "app/Services/Ai/AtlasOpenBrainMcpService.php: AP-133 MCP must expose id/kind/section/surface filters [{$token}]";
            }
        }

        if (! str_contains($architectureTools, "\$this->architectureOperations->summary(\$this->onlyScalarFilters(\$arguments, ['id', 'kind', 'section', 'surface', 'owner_layer']))")) {
            $violations[] = "app/Services/Ai/OpenBrainMcp/ArchitectureTools.php: AP-133 MCP must expose id/kind/section/surface filters [\$this->architectureOperations->summary(\$this->onlyScalarFilters(\$arguments, ['id', 'kind', 'section', 'surface', 'owner_layer']))]";
        }

        foreach ([
            'test_catalog_filters_architecture_operations_by_id_and_kind',
            "summary(['id' => 'provider_performance_report'])",
            "summary(['kind' => 'evidence_report'])",
            "summary(['section' => 'arquitetura_mae'])",
            "summary(['surface' => 'runtime'])",
            "'filters'",
        ] as $token) {
            if (! str_contains($unitTest, $token)) {
                $violations[] = "tests/Unit/Ai/Kernel/Architecture/AtlasArchitectureOperationsCatalogTest.php: AP-133 catalog filters must be unit tested [{$token}]";
            }
        }

        foreach ([
            'test_command_filters_architecture_operations_by_id_and_kind',
            "'--kind' => 'evidence_report'",
            "'--section' => 'arquitetura_mae'",
            "'--surface' => 'runtime'",
            "'--id' => 'inbox_action_report'",
            'architecture_operations.filters',
        ] as $token) {
            if (! str_contains($commandTest, $token)) {
                $violations[] = "tests/Feature/Ai/AtlasAiArchitectureOperationsCommandTest.php: AP-133 CLI filters must be covered [{$token}]";
            }
        }

        foreach ([
            'test_api_filters_architecture_operations_catalog',
            '/ai/architecture/operations?kind=evidence_report',
            '/ai/architecture/operations?section=arquitetura_mae',
            '/ai/architecture/operations?surface=runtime',
            '/ai/architecture/operations?id=provider_performance_report',
            'architecture_operations.filters',
        ] as $token) {
            if (! str_contains($apiTest, $token)) {
                $violations[] = "tests/Feature/Ai/AtlasAiArchitectureOperationsApiTest.php: AP-133 API filters must be covered [{$token}]";
            }
        }

        foreach ([
            'test_architecture_operations_tool_filters_shared_operations_catalog',
            "'arguments' => ['kind' => 'evidence_report']",
            "'arguments' => ['section' => 'arquitetura_mae']",
            "'arguments' => ['surface' => 'runtime']",
            'architecture_operations.filters',
        ] as $token) {
            if (! str_contains($mcpTest, $token)) {
                $violations[] = "tests/Feature/Ai/AtlasOpenBrainMcpServiceTest.php: AP-133 MCP filters must be covered [{$token}]";
            }
        }

        foreach ([
            'AP-133',
            'Architecture Operations Filter Contract',
            'id/kind/section/surface',
            'ap133_architecture_operations_filter_contract',
        ] as $token) {
            if (! str_contains($docs, $token)) {
                $violations[] = "docs/engineering-knowledge-base/atlas-ai-kernel-architecture.md: AP-133 architecture operations filter contract must be documented [{$token}]";
            }
            if (! str_contains($apDoc, $token)) {
                $violations[] = "docs/ap/AP-133-architecture-operations-filter-contract.md: AP-133 contract doc must exist [{$token}]";
            }
        }

        return $violations;
    }

    /**
     * @return array<int,string>
     */
    private function scanArchitectureOperationsMetadataContract(): array
    {
        $violations = [];
        $catalogPath = app_path('Services/Ai/Kernel/Architecture/AtlasArchitectureOperationsCatalog.php');
        $unitTestPath = base_path('tests/Unit/Ai/Kernel/Architecture/AtlasArchitectureOperationsCatalogTest.php');
        $commandTestPath = base_path('tests/Feature/Ai/AtlasAiArchitectureOperationsCommandTest.php');
        $apiTestPath = base_path('tests/Feature/Ai/AtlasAiArchitectureOperationsApiTest.php');
        $observabilityTestPath = base_path('tests/Feature/Ai/AiObservabilityKernelSloTest.php');
        $mcpTestPath = base_path('tests/Feature/Ai/AtlasOpenBrainMcpServiceTest.php');
        $docsPath = base_path('docs/engineering-knowledge-base/atlas-ai-kernel-architecture.md');
        $apDocPath = base_path('docs/ap/AP-132-architecture-operations-metadata-contract.md');

        $catalog = File::exists($catalogPath) ? File::get($catalogPath) : '';
        $unitTest = File::exists($unitTestPath) ? File::get($unitTestPath) : '';
        $commandTest = File::exists($commandTestPath) ? File::get($commandTestPath) : '';
        $apiTest = File::exists($apiTestPath) ? File::get($apiTestPath) : '';
        $observabilityTest = File::exists($observabilityTestPath) ? File::get($observabilityTestPath) : '';
        $mcpTest = File::exists($mcpTestPath) ? File::get($mcpTestPath) : '';
        $docs = $this->primitives->kernelDocumentationCorpus();
        $apDoc = File::exists($apDocPath) ? File::get($apDocPath) : '';

        foreach ([
            "'schema_version' => 'atlas.architecture_operations.v1'",
            "'operation_ids' => \$this->operationIds(",
            "'id' => 'architecture_operations'",
            "'surface' => 'cli'",
            "'kind' => 'catalog'",
            "'output' => 'json'",
            "'kind' => 'evidence_report'",
        ] as $token) {
            if (! str_contains($catalog, $token)) {
                $violations[] = "app/Services/Ai/Kernel/Architecture/AtlasArchitectureOperationsCatalog.php: AP-132 architecture operations must expose stable machine-readable metadata [{$token}]";
            }
        }

        foreach ([
            "assertSame('atlas.architecture_operations.v1'",
            "'architecture_operations'",
            "'kernel_slo_report'",
            'commands.0.id',
            'commands.0.kind',
            'commands.0.surface',
            'commands.10.kind',
        ] as $token) {
            if (! str_contains($unitTest, $token)) {
                $violations[] = "tests/Unit/Ai/Kernel/Architecture/AtlasArchitectureOperationsCatalogTest.php: AP-132 catalog metadata must be unit tested [{$token}]";
            }
        }

        foreach ([
            'architecture_operations.schema_version',
            'architecture_operations.operation_ids',
            'architecture_operations.commands.0.id',
            'architecture_operations.commands.0.kind',
            'architecture_operations.commands.0.surface',
        ] as $token) {
            if (! str_contains($commandTest, $token)) {
                $violations[] = "tests/Feature/Ai/AtlasAiArchitectureOperationsCommandTest.php: AP-132 CLI metadata contract must be covered [{$token}]";
            }
            if (! str_contains($apiTest, $token)) {
                $violations[] = "tests/Feature/Ai/AtlasAiArchitectureOperationsApiTest.php: AP-132 API metadata contract must be covered [{$token}]";
            }
        }

        foreach ([
            'architecture_operations.schema_version',
            'architecture_operations.operation_ids',
            'architecture_operations.commands.0.id',
            'architecture_operations.commands.0.kind',
        ] as $token) {
            if (! str_contains($observabilityTest, $token)) {
                $violations[] = "tests/Feature/Ai/AiObservabilityKernelSloTest.php: AP-132 Observability metadata contract must be covered [{$token}]";
            }
            if (! str_contains($mcpTest, $token)) {
                $violations[] = "tests/Feature/Ai/AtlasOpenBrainMcpServiceTest.php: AP-132 MCP metadata contract must be covered [{$token}]";
            }
        }

        foreach ([
            'AP-132',
            'Architecture Operations Metadata Contract',
            'atlas.architecture_operations.v1',
            'operation_ids',
            'ap132_architecture_operations_metadata_contract',
        ] as $token) {
            if (! str_contains($docs, $token)) {
                $violations[] = "docs/engineering-knowledge-base/atlas-ai-kernel-architecture.md: AP-132 architecture operations metadata contract must be documented [{$token}]";
            }
            if (! str_contains($apDoc, $token)) {
                $violations[] = "docs/ap/AP-132-architecture-operations-metadata-contract.md: AP-132 contract doc must exist [{$token}]";
            }
        }

        return $violations;
    }

    /**
     * @return array<int,string>
     */
    private function scanArchitectureOperationsDirectSurfaces(): array
    {
        $violations = [];
        $commandPath = app_path('Console/Commands/AtlasAiArchitectureOperationsCommand.php');
        $controllerPath = app_path('Http/Controllers/AtlasAiArchitectureOperationsController.php');
        $governanceControllerPath = app_path('Http/Controllers/AtlasAiGovernanceController.php');
        $routesPath = base_path('routes/api.php');
        $commandTestPath = base_path('tests/Feature/Ai/AtlasAiArchitectureOperationsCommandTest.php');
        $apiTestPath = base_path('tests/Feature/Ai/AtlasAiArchitectureOperationsApiTest.php');
        $docsPath = base_path('docs/engineering-knowledge-base/atlas-ai-kernel-architecture.md');
        $apDocPath = base_path('docs/ap/AP-130-architecture-operations-direct-surfaces.md');

        $command = File::exists($commandPath) ? File::get($commandPath) : '';
        $controller = File::exists($controllerPath) ? File::get($controllerPath) : '';
        $governanceController = File::exists($governanceControllerPath) ? File::get($governanceControllerPath) : '';
        $routes = File::exists($routesPath) ? File::get($routesPath) : '';
        $commandTest = File::exists($commandTestPath) ? File::get($commandTestPath) : '';
        $apiTest = File::exists($apiTestPath) ? File::get($apiTestPath) : '';
        $docs = $this->primitives->kernelDocumentationCorpus();
        $apDoc = File::exists($apDocPath) ? File::get($apDocPath) : '';

        foreach ([
            "protected \$signature = 'atlas:ai:architecture-operations",
            'AtlasArchitectureOperationsCatalog $catalog',
            "'architecture_operations' => \$catalog->summary(\$this->filters())",
            'Atlas AI Architecture Operations',
        ] as $token) {
            if (! str_contains($command, $token)) {
                $violations[] = "app/Console/Commands/AtlasAiArchitectureOperationsCommand.php: AP-130 architecture operations CLI surface must consume shared catalog [{$token}]";
            }
        }

        foreach ([
            'class AtlasAiArchitectureOperationsController extends Controller',
            'AtlasArchitectureOperationsCatalog $catalog',
            "'architecture_operations' => \$catalog->summary(\$data)",
        ] as $token) {
            if (! str_contains($controller, $token)) {
                $violations[] = "app/Http/Controllers/AtlasAiArchitectureOperationsController.php: AP-130 architecture operations API surface must consume shared catalog [{$token}]";
            }
        }

        foreach ([
            'AtlasAiArchitectureOperationsController',
            "Route::get('/ai/architecture/operations', AtlasAiArchitectureOperationsController::class)",
            'AtlasAiGovernanceController',
            "Route::get('/ai/session-bootstrap', [AtlasAiGovernanceController::class, 'sessionBootstrap'])",
            "Route::get('/ai/feature-placement', [AtlasAiGovernanceController::class, 'placeFeature'])",
            "Route::get('/ai/docs-split-plan', [AtlasAiGovernanceController::class, 'docsSplitPlan'])",
            "'strict' => ['nullable', 'boolean']",
            "'owner' => ['nullable', 'string', 'max:120']",
            "'severity' => ['nullable', 'string', 'max:120']",
            "'status' => ['nullable', 'string', 'max:120']",
            'AtlasGovernanceGateService $gate',
            '$gate->httpStatus($payload',
        ] as $token) {
            if (! str_contains($routes.$controller.$governanceController, $token)) {
                $violations[] = "routes/api.php: AP-130 architecture operations API route must exist [{$token}]";
            }
        }

        foreach ([
            'test_command_exposes_architecture_operations_as_json',
            'test_command_human_output_lists_architecture_operations',
            'atlas:ai:architecture-operations',
            'architecture_operations.command_count',
            'php artisan atlas:ai:architecture-operations --json',
        ] as $token) {
            if (! str_contains($commandTest, $token)) {
                $violations[] = "tests/Feature/Ai/AtlasAiArchitectureOperationsCommandTest.php: AP-130 architecture operations CLI must be covered [{$token}]";
            }
        }

        foreach ([
            'test_api_exposes_architecture_operations_catalog',
            'test_api_requires_atlas_token',
            '/ai/architecture/operations',
            'architecture_operations.command_count',
            'php artisan atlas:ai:architecture-operations --json',
            '/ai/session-bootstrap',
            '/ai/feature-placement',
            '/ai/docs-split-plan',
        ] as $token) {
            if (! str_contains($apiTest, $token)) {
                $violations[] = "tests/Feature/Ai/AtlasAiArchitectureOperationsApiTest.php: AP-130 architecture operations API must be covered [{$token}]";
            }
        }

        foreach ([
            'AP-130',
            'Architecture Operations Direct Surfaces',
            'atlas:ai:architecture-operations',
            '/ai/architecture/operations',
            'ap130_architecture_operations_direct_surfaces',
        ] as $token) {
            if (! str_contains($docs, $token)) {
                $violations[] = "docs/engineering-knowledge-base/atlas-ai-kernel-architecture.md: AP-130 direct architecture operations surfaces must be documented [{$token}]";
            }
            if (! str_contains($apDoc, $token)) {
                $violations[] = "docs/ap/AP-130-architecture-operations-direct-surfaces.md: AP-130 contract doc must exist [{$token}]";
            }
        }

        return $violations;
    }

    /**
     * @return array<int,string>
     */
    private function scanArchitectureOperationsMcpTool(): array
    {
        $violations = [];
        $mcpPath = app_path('Services/Ai/AtlasOpenBrainMcpService.php');
        $gatePath = app_path('Services/Ai/Kernel/Architecture/AtlasGovernanceGateService.php');
        $testPath = base_path('tests/Feature/Ai/AtlasOpenBrainMcpServiceTest.php');
        $docsPath = base_path('docs/engineering-knowledge-base/atlas-ai-kernel-architecture.md');
        $apDocPath = base_path('docs/ap/AP-129-architecture-operations-mcp-tool.md');

        $architectureToolsPath = app_path('Services/Ai/OpenBrainMcp/ArchitectureTools.php');

        $mcp = File::exists($mcpPath) ? File::get($mcpPath) : '';
        $architectureTools = File::exists($architectureToolsPath) ? File::get($architectureToolsPath) : '';
        $gate = File::exists($gatePath) ? File::get($gatePath) : '';
        $test = File::exists($testPath) ? File::get($testPath) : '';
        $docs = $this->primitives->kernelDocumentationCorpus();
        $apDoc = File::exists($apDocPath) ? File::get($apDocPath) : '';

        // Façade keeps the tools() schema + dispatch + the strict governance gate; the
        // handlers + their service dependencies were relocated under GOD-DEBULK D3 to
        // OpenBrainMcp/ArchitectureTools (invariant unchanged).
        foreach ([
            "'name' => 'atlas_architecture_operations'",
            "'name' => 'atlas_session_bootstrap'",
            "'name' => 'atlas_feature_placement'",
            "'name' => 'atlas_docs_split_plan'",
            "'atlas_architecture_operations' => \$this->toolResponse(\$id, \$this->architectureTools->architectureOperations(\$arguments))",
            "'atlas_session_bootstrap' => \$this->toolResponse(\$id, \$this->architectureTools->sessionBootstrap(\$arguments))",
            "'atlas_feature_placement' => \$this->toolResponse(\$id, \$this->architectureTools->featurePlacement(\$arguments))",
            "'atlas_docs_split_plan' => \$this->toolResponse(\$id, \$this->architectureTools->docsSplitPlan(\$arguments))",
            "'owner' => ['type' => 'string'",
            "'severity' => ['type' => 'string'",
            "'status' => ['type' => 'string'",
            "'strict' => ['type' => 'boolean'",
            'AtlasGovernanceGateService $governanceGate',
            "'writes' => false",
        ] as $token) {
            if (! str_contains($mcp, $token)) {
                $violations[] = "app/Services/Ai/AtlasOpenBrainMcpService.php: AP-129 architecture operations must be exposed as read-only MCP tool [{$token}]";
            }
        }

        foreach ([
            'AtlasArchitectureOperationsCatalog $architectureOperations',
            'AtlasSessionBootstrapService $sessionBootstrap',
            'AtlasFeaturePlacementService $featurePlacement',
            'AtlasDocumentationSplitPlanService $documentationSplitPlan',
            'public function architectureOperations(array $arguments): array',
            'public function sessionBootstrap(array $arguments): array',
            'public function featurePlacement(array $arguments): array',
            'public function docsSplitPlan(array $arguments): array',
            "\$this->architectureOperations->summary(\$this->onlyScalarFilters(\$arguments, ['id', 'kind', 'section', 'surface', 'owner_layer']))",
            "\$this->documentationSplitPlan->plan(\$this->onlyScalarFilters(\$arguments, ['owner', 'severity', 'status']))",
            '$strictBlocked',
            '$this->governanceGate->strictBlocked',
            '$this->governanceGate->mcpError',
        ] as $token) {
            if (! str_contains($architectureTools, $token)) {
                $violations[] = "app/Services/Ai/OpenBrainMcp/ArchitectureTools.php: AP-129 architecture operations must be exposed as read-only MCP tool [{$token}]";
            }
        }

        foreach ([
            'session_bootstrap_blocked_by_strict_gate',
            'feature_placement_blocked_by_strict_gate',
        ] as $token) {
            if (! str_contains($gate, $token)) {
                $violations[] = "app/Services/Ai/Kernel/Architecture/AtlasGovernanceGateService.php: AP-129 strict MCP gate errors must stay centralized [{$token}]";
            }
        }

        foreach ([
            'test_architecture_operations_tool_exposes_shared_operations_catalog',
            'atlas_architecture_operations',
            'atlas_session_bootstrap',
            'atlas_feature_placement',
            'atlas_docs_split_plan',
            'architecture_operations.section',
            'architecture_operations.command_count',
            'agent_behavior_report',
            'test_governance_tools_expose_session_bootstrap_feature_placement_and_split_plan',
            'test_session_bootstrap_tool_strict_mode_reports_blocked_gate',
            'test_feature_placement_tool_requires_feature',
            'test_feature_placement_tool_strict_mode_reports_blocked_gate',
            "'arguments' => ['status' => 'split_required']",
            "data_get(\$splitPlan, 'filters.status')",
            'session_bootstrap_blocked_by_strict_gate',
            'feature_placement_blocked_by_strict_gate',
            'php artisan atlas:ai:agent-behavior-report --hours=24 --json',
            'provider_release_review',
            'php artisan atlas:ai:provider-release-review --provider=<provider> --title="<release>" --json',
            'php artisan atlas:ai:dynamic-compute-market --provider=<provider> --domain=<domain> --flow=<flow> --json',
            'php artisan atlas:ai:self-improve --flow=provider_performance_review --hours=168 --json',
            'php artisan atlas:ai:self-improve --flow=provider_release_review --hours=168 --json',
            'php artisan atlas:ai:telemetry:cost-rates --missing --hours=168 --json',
            'php artisan atlas:ai:inbox-action-report --hours=24 --json',
        ] as $token) {
            if (! str_contains($test, $token)) {
                $violations[] = "tests/Feature/Ai/AtlasOpenBrainMcpServiceTest.php: AP-129 MCP architecture operations tool must be covered [{$token}]";
            }
        }

        foreach ([
            'AP-129',
            'Architecture Operations MCP Tool',
            'atlas_architecture_operations',
            'AtlasArchitectureOperationsCatalog',
            'ap129_architecture_operations_mcp_tool',
        ] as $token) {
            if (! str_contains($docs, $token)) {
                $violations[] = "docs/engineering-knowledge-base/atlas-ai-kernel-architecture.md: AP-129 architecture operations MCP tool must be documented [{$token}]";
            }
            if (! str_contains($apDoc, $token)) {
                $violations[] = "docs/ap/AP-129-architecture-operations-mcp-tool.md: AP-129 contract doc must exist [{$token}]";
            }
        }

        return $violations;
    }

    /**
     * @return array<int,string>
     */
    private function scanArchitectureOperationsSharedCatalog(): array
    {
        $violations = [];
        $catalogPath = app_path('Services/Ai/Kernel/Architecture/AtlasArchitectureOperationsCatalog.php');
        $helpPath = app_path('Console/Commands/AtlasCliHelpCommand.php');
        $observabilityPath = app_path('Http/Controllers/AiObservabilityController.php');
        $unitTestPath = base_path('tests/Unit/Ai/Kernel/Architecture/AtlasArchitectureOperationsCatalogTest.php');
        $observabilityTestPath = base_path('tests/Feature/Ai/AiObservabilityKernelSloTest.php');
        $docsPath = base_path('docs/engineering-knowledge-base/atlas-ai-kernel-architecture.md');
        $apDocPath = base_path('docs/ap/AP-128-architecture-operations-shared-catalog.md');

        $catalog = File::exists($catalogPath) ? File::get($catalogPath) : '';
        $help = File::exists($helpPath) ? File::get($helpPath) : '';
        $observability = File::exists($observabilityPath) ? File::get($observabilityPath) : '';
        $unitTest = File::exists($unitTestPath) ? File::get($unitTestPath) : '';
        $observabilityTest = File::exists($observabilityTestPath) ? File::get($observabilityTestPath) : '';
        $docs = $this->primitives->kernelDocumentationCorpus();
        $apDoc = File::exists($apDocPath) ? File::get($apDocPath) : '';

        foreach ([
            'final class AtlasArchitectureOperationsCatalog',
            "return 'arquitetura_mae'",
            'public function commands(): array',
            'public function summary(array $filters = []): array',
            "'command_count' => count(\$commands)",
            "'php artisan atlas:ai:architecture-operations --json'",
            "'php artisan atlas:ai:architecture-validate'",
            "'atlas engineering knowledge docs-health --json'",
            "'php artisan atlas:ai:session-bootstrap --task=\"<task>\" --json'",
            "'php artisan atlas:ai:place-feature \"<feature>\" --json'",
            "'php artisan atlas:ai:docs-split-plan --json'",
            "'focused_command' => 'php artisan atlas:ai:docs-split-plan --owner=<owner_area> --json'",
            "'filter_options' => ['owner', 'severity', 'status']",
            "'mcp_tool' => 'atlas_docs_split_plan'",
            "'api_endpoint' => '/ai/session-bootstrap'",
            "'api_endpoint' => '/ai/feature-placement'",
            "'api_endpoint' => '/ai/docs-split-plan'",
            "'atlas engineering knowledge sync --prune --json'",
            "'atlas engineering knowledge index-code --prune --summary-only --json'",
            "'php artisan atlas:ai:agent-behavior-report --hours=24 --json'",
            "'php artisan atlas:ai:dynamic-compute-market --provider=<provider> --domain=<domain> --flow=<flow> --json'",
            "'php artisan atlas:ai:self-improve --flow=provider_performance_review --hours=168 --json'",
            "'php artisan atlas:ai:provider-release-review --provider=<provider> --title=\"<release>\" --json'",
            "'php artisan atlas:ai:self-improve --flow=provider_release_review --hours=168 --json'",
            "'php artisan atlas:ai:telemetry:cost-rates --missing --hours=168 --json'",
            "'php artisan atlas:ai:telemetry:cost-rates --provider=<provider> --model=<model> --input-microusd=<input> --output-microusd=<output> --json'",
            "'php artisan atlas:ai:inbox-action-report --hours=24 --json'",
        ] as $token) {
            if (! str_contains($catalog, $token)) {
                $violations[] = "app/Services/Ai/Kernel/Architecture/AtlasArchitectureOperationsCatalog.php: AP-128 shared architecture operations catalog must exist [{$token}]";
            }
        }

        foreach ([
            'AtlasArchitectureOperationsCatalog $architectureOperations',
            '$architectureOperations->sectionKey() => $architectureOperations->commands()',
        ] as $token) {
            if (! str_contains($help, $token)) {
                $violations[] = "app/Console/Commands/AtlasCliHelpCommand.php: AP-128 CLI help must consume shared architecture operations catalog [{$token}]";
            }
        }

        foreach ([
            'AtlasArchitectureOperationsCatalog $architectureOperations',
            "'architecture_operations' => \$architectureOperations->summary()",
        ] as $token) {
            if (! str_contains($observability, $token)) {
                $violations[] = "app/Http/Controllers/AiObservabilityController.php: AP-128 Observability must expose shared architecture operations catalog [{$token}]";
            }
        }

        foreach ([
            'test_catalog_exposes_canonical_architecture_operations',
            'AtlasArchitectureOperationsCatalog',
            "'arquitetura_mae'",
            'php artisan atlas:ai:architecture-operations --json',
            'atlas engineering knowledge docs-health --json',
            'php artisan atlas:ai:session-bootstrap --task="<task>" --json',
            'php artisan atlas:ai:place-feature "<feature>" --json',
            'php artisan atlas:ai:docs-split-plan --json',
            'php artisan atlas:ai:docs-split-plan --owner=<owner_area> --json',
            "data_get(\$commandsById, 'documentation_split_plan.filter_options')",
            "data_get(\$commandsById, 'documentation_split_plan.mcp_tool')",
            '/ai/session-bootstrap',
            '/ai/feature-placement',
            '/ai/docs-split-plan',
            'atlas engineering knowledge sync --prune --json',
            'atlas engineering knowledge index-code --prune --summary-only --json',
            'php artisan atlas:ai:agent-behavior-report --hours=24 --json',
            'php artisan atlas:ai:dynamic-compute-market --provider=<provider> --domain=<domain> --flow=<flow> --json',
            'php artisan atlas:ai:self-improve --flow=provider_performance_review --hours=168 --json',
            'php artisan atlas:ai:provider-release-review --provider=<provider> --title="<release>" --json',
            'php artisan atlas:ai:self-improve --flow=provider_release_review --hours=168 --json',
            'php artisan atlas:ai:telemetry:cost-rates --missing --hours=168 --json',
            'php artisan atlas:ai:telemetry:cost-rates --provider=<provider> --model=<model> --input-microusd=<input> --output-microusd=<output> --json',
            'php artisan atlas:ai:inbox-action-report --hours=24 --json',
        ] as $token) {
            if (! str_contains($unitTest, $token)) {
                $violations[] = "tests/Unit/Ai/Kernel/Architecture/AtlasArchitectureOperationsCatalogTest.php: AP-128 catalog must be unit tested [{$token}]";
            }
        }

        foreach ([
            'test_observability_payload_includes_architecture_operations_catalog',
            "assertJsonPath('architecture_operations.section', 'arquitetura_mae')",
            "\$this->assertSame(count(\$commands), \$response->json('architecture_operations.command_count'))",
            'php artisan atlas:ai:architecture-operations --json',
            'atlas engineering knowledge docs-health --json',
            'php artisan atlas:ai:session-bootstrap --task="<task>" --json',
            'php artisan atlas:ai:place-feature "<feature>" --json',
            'php artisan atlas:ai:docs-split-plan --json',
            '/ai/session-bootstrap',
            '/ai/feature-placement',
            '/ai/docs-split-plan',
            'atlas engineering knowledge sync --prune --json',
            'atlas engineering knowledge index-code --prune --summary-only --json',
            'php artisan atlas:ai:agent-behavior-report --hours=24 --json',
            'php artisan atlas:ai:self-improvement-schedule-report --hours=24 --json',
            'php artisan atlas:ai:self-improve --flow=provider_performance_review --hours=168 --json',
            'php artisan atlas:ai:provider-release-review --provider=<provider> --title="<release>" --json',
            'php artisan atlas:ai:self-improve --flow=provider_release_review --hours=168 --json',
            'php artisan atlas:ai:ledger <id> --json',
            'php artisan atlas:ai:telemetry:cost-rates --missing --hours=168 --json',
        ] as $token) {
            if (! str_contains($observabilityTest, $token)) {
                $violations[] = "tests/Feature/Ai/AiObservabilityKernelSloTest.php: AP-128 Observability architecture operations catalog must be covered [{$token}]";
            }
        }

        foreach ([
            'AP-128',
            'Architecture Operations Shared Catalog',
            'AtlasArchitectureOperationsCatalog',
            'architecture_operations',
            'ap128_architecture_operations_shared_catalog',
        ] as $token) {
            if (! str_contains($docs, $token)) {
                $violations[] = "docs/engineering-knowledge-base/atlas-ai-kernel-architecture.md: AP-128 shared architecture operations catalog must be documented [{$token}]";
            }
            if (! str_contains($apDoc, $token)) {
                $violations[] = "docs/ap/AP-128-architecture-operations-shared-catalog.md: AP-128 contract doc must exist [{$token}]";
            }
        }

        return $violations;
    }
}
