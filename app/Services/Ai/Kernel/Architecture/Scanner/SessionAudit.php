<?php

namespace App\Services\Ai\Kernel\Architecture\Scanner;

use Illuminate\Support\Facades\File;

class SessionAudit
{
    public function __construct(private ScanPrimitivesSupport $primitives) {}

    /**
     * @return array<string,callable(): array<int,string>>
     */
    public function checks(): array
    {
        return [
            'ap173_session_bootstrap_docs_split_plan_contract' => fn (): array => $this->scanSessionBootstrapDocsSplitPlanContract(),
            'ap174_session_bootstrap_architecture_operations_contract' => fn (): array => $this->scanSessionBootstrapArchitectureOperationsContract(),
            'ap175_feature_placement_architecture_operations_contract' => fn (): array => $this->scanFeaturePlacementArchitectureOperationsContract(),
        ];
    }

    /**
     * @return array<int,string>
     */
    private function scanSessionBootstrapDocsSplitPlanContract(): array
    {
        $violations = [];
        $servicePath = app_path('Services/Ai/Kernel/Architecture/AtlasSessionBootstrapService.php');
        $commandPath = app_path('Console/Commands/AtlasAiSessionBootstrapCommand.php');
        $apiTestPath = base_path('tests/Feature/Ai/AtlasAiGovernanceApiTest.php');
        $commandTestPath = base_path('tests/Feature/Ai/AtlasAiSessionBootstrapCommandTest.php');
        $mcpTestPath = base_path('tests/Feature/Ai/AtlasOpenBrainMcpServiceTest.php');
        $docsPath = base_path('docs/engineering-knowledge-base/atlas-ai-kernel-architecture.md');
        $sessionDocPath = base_path('docs/engineering-knowledge-base/atlas-ai-session-bootstrap.md');
        $apDocPath = base_path('docs/ap/AP-173-session-bootstrap-docs-split-plan-contract.md');

        $service = File::exists($servicePath) ? File::get($servicePath) : '';
        $command = File::exists($commandPath) ? File::get($commandPath) : '';
        $apiTest = File::exists($apiTestPath) ? File::get($apiTestPath) : '';
        $commandTest = File::exists($commandTestPath) ? File::get($commandTestPath) : '';
        $mcpTest = File::exists($mcpTestPath) ? File::get($mcpTestPath) : '';
        $docs = $this->primitives->kernelDocumentationCorpus();
        $sessionDoc = File::exists($sessionDocPath) ? File::get($sessionDocPath) : '';
        $apDoc = File::exists($apDocPath) ? File::get($apDocPath) : '';

        foreach ([
            'AtlasDocumentationSplitPlanService $splitPlan',
            '$splitOwner = $this->splitOwner',
            "\$splitPlan = \$this->splitPlan->plan(['owner' => \$splitOwner])",
            "'docs_split_plan' => [",
            "'owner' => \$splitOwner",
            "'execution_order' => \$splitPlan['execution_order'] ?? []",
            "'first_doc' => data_get(\$splitPlan, 'docs.0')",
            "'command' => 'php artisan atlas:ai:docs-split-plan --owner='.\$splitOwner.' --json'",
            'private function splitOwner(array $placement, string $task): string',
            "return 'memory_open_brain'",
            "return 'human_knowledge_surface'",
            "return 'tool_runtime'",
            "return 'domain_architecture'",
        ] as $token) {
            if (! str_contains($service, $token)) {
                $violations[] = "app/Services/Ai/Kernel/Architecture/AtlasSessionBootstrapService.php: AP-173 session bootstrap must include focused docs_split_plan [{$token}]";
            }
        }

        foreach ([
            'Docs split owner',
            "data_get(\$payload, 'docs_split_plan.owner')",
            "data_get(\$payload, 'docs_split_plan.split_required_count')",
        ] as $token) {
            if (! str_contains($command, $token)) {
                $violations[] = "app/Console/Commands/AtlasAiSessionBootstrapCommand.php: AP-173 CLI must surface focused docs split owner [{$token}]";
            }
        }

        $operationsCatalogPath = app_path('Services/Ai/Kernel/Architecture/AtlasArchitectureOperationsCatalog.php');
        $operationsCatalog = File::exists($operationsCatalogPath) ? File::get($operationsCatalogPath) : '';
        foreach ([
            "'id' => 'session_bootstrap'",
            "'output_contract' => [",
            "'docs_split_plan' => [",
            "'split_required_count'",
            "'total_split_required_count'",
            "'first_doc'",
        ] as $token) {
            if (! str_contains($operationsCatalog, $token)) {
                $violations[] = "app/Services/Ai/Kernel/Architecture/AtlasArchitectureOperationsCatalog.php: AP-173 session_bootstrap operation must declare docs_split_plan output contract [{$token}]";
            }
        }

        foreach ([
            "assertJsonPath('docs_split_plan.owner', 'knowledge_governance')",
            "assertJsonPath('docs_split_plan.command', 'php artisan atlas:ai:docs-split-plan --owner=knowledge_governance --json')",
        ] as $token) {
            if (! str_contains($apiTest, $token)) {
                $violations[] = "tests/Feature/Ai/AtlasAiGovernanceApiTest.php: AP-173 API bootstrap docs_split_plan must be covered [{$token}]";
            }
        }

        foreach ([
            "data_get(\$payload, 'docs_split_plan.owner')",
            "data_get(\$payload, 'docs_split_plan.command')",
            'test_session_bootstrap_focuses_docs_split_plan_for_memory_tasks',
            "'php artisan atlas:ai:docs-split-plan --owner=memory_open_brain --json'",
        ] as $token) {
            if (! str_contains($commandTest, $token)) {
                $violations[] = "tests/Feature/Ai/AtlasAiSessionBootstrapCommandTest.php: AP-173 CLI bootstrap docs_split_plan must be covered [{$token}]";
            }
        }

        foreach ([
            "data_get(\$bootstrap, 'docs_split_plan.owner')",
            "data_get(\$bootstrap, 'docs_split_plan.command')",
        ] as $token) {
            if (! str_contains($mcpTest, $token)) {
                $violations[] = "tests/Feature/Ai/AtlasOpenBrainMcpServiceTest.php: AP-173 MCP bootstrap docs_split_plan must be covered [{$token}]";
            }
        }

        foreach ([
            'AP-173',
            'Session Bootstrap Docs Split Plan Contract',
            'docs_split_plan',
            'ap173_session_bootstrap_docs_split_plan_contract',
        ] as $token) {
            if (! str_contains($docs, $token)) {
                $violations[] = "docs/engineering-knowledge-base/atlas-ai-kernel-architecture.md: AP-173 session bootstrap docs split plan contract must be documented [{$token}]";
            }
            if (! str_contains($sessionDoc, $token)) {
                $violations[] = "docs/engineering-knowledge-base/atlas-ai-session-bootstrap.md: AP-173 bootstrap doc must mention docs split plan contract [{$token}]";
            }
            if (! str_contains($apDoc, $token)) {
                $violations[] = "docs/ap/AP-173-session-bootstrap-docs-split-plan-contract.md: AP-173 contract doc must exist [{$token}]";
            }
        }

        return $violations;
    }

    /**
     * @return array<int,string>
     */
    private function scanSessionBootstrapArchitectureOperationsContract(): array
    {
        $violations = [];
        $servicePath = app_path('Services/Ai/Kernel/Architecture/AtlasSessionBootstrapService.php');
        $apiTestPath = base_path('tests/Feature/Ai/AtlasAiGovernanceApiTest.php');
        $commandTestPath = base_path('tests/Feature/Ai/AtlasAiSessionBootstrapCommandTest.php');
        $mcpTestPath = base_path('tests/Feature/Ai/AtlasOpenBrainMcpServiceTest.php');
        $docsPath = base_path('docs/engineering-knowledge-base/atlas-ai-kernel-architecture.md');
        $sessionDocPath = base_path('docs/engineering-knowledge-base/atlas-ai-session-bootstrap.md');
        $apDocPath = base_path('docs/ap/AP-174-session-bootstrap-architecture-operations-contract.md');

        $service = File::exists($servicePath) ? File::get($servicePath) : '';
        $apiTest = File::exists($apiTestPath) ? File::get($apiTestPath) : '';
        $commandTest = File::exists($commandTestPath) ? File::get($commandTestPath) : '';
        $mcpTest = File::exists($mcpTestPath) ? File::get($mcpTestPath) : '';
        $docs = $this->primitives->kernelDocumentationCorpus();
        $sessionDoc = File::exists($sessionDocPath) ? File::get($sessionDocPath) : '';
        $apDoc = File::exists($apDocPath) ? File::get($apDocPath) : '';

        foreach ([
            'AtlasArchitectureOperationsCatalog $operations',
            "'architecture_operations' => \$this->sessionOperations(\$placement['placement'] ?? [])",
            'private function sessionOperations(array $placement = []): array',
            "'architecture_readiness'",
            "'coverage_boundary' => \$coverageBoundary",
            "'safe_next_blocks' => \$safeNextBlocks",
            "'session_bootstrap'",
            "'feature_placement'",
            "'documentation_split_plan'",
            "'architecture_validate'",
            "'provider_projection_status'",
            "'code_intelligence_index'",
            "'voice_realtime_dependencies'",
        ] as $token) {
            if (! str_contains($service, $token)) {
                $violations[] = "app/Services/Ai/Kernel/Architecture/AtlasSessionBootstrapService.php: AP-174 session bootstrap must include focused architecture_operations [{$token}]";
            }
        }

        foreach ([
            "assertJsonPath('architecture_operations.schema_version', 'atlas.architecture_operations.v1')",
            'architecture_operations.operation_ids',
            "'architecture_readiness'",
            "assertJsonPath('coverage_boundary.schema_version', 'atlas.implemented_vs_scaffold.coverage_boundary.v1')",
            "assertJsonPath('safe_next_blocks.0.block', 'Voice Realtime product loop')",
            "'architecture_validate'",
            "'voice_realtime_dependencies'",
        ] as $token) {
            if (! str_contains($apiTest, $token)) {
                $violations[] = "tests/Feature/Ai/AtlasAiGovernanceApiTest.php: AP-174 API bootstrap architecture_operations must be covered [{$token}]";
            }
        }

        foreach ([
            "data_get(\$payload, 'architecture_operations.schema_version')",
            "data_get(\$payload, 'architecture_operations.operation_ids')",
            "'architecture_readiness'",
            "data_get(\$payload, 'coverage_boundary.schema_version')",
            "data_get(\$payload, 'safe_next_blocks.0.block')",
            "'provider_projection_status'",
            "'voice_realtime_dependencies'",
        ] as $token) {
            if (! str_contains($commandTest, $token)) {
                $violations[] = "tests/Feature/Ai/AtlasAiSessionBootstrapCommandTest.php: AP-174 CLI bootstrap architecture_operations must be covered [{$token}]";
            }
        }

        foreach ([
            "data_get(\$bootstrap, 'architecture_operations.operation_ids')",
            "'architecture_readiness'",
            "data_get(\$bootstrap, 'coverage_boundary.schema_version')",
            "data_get(\$bootstrap, 'safe_next_blocks.0.block')",
            "'documentation_split_plan'",
            "'architecture_validate'",
            "'voice_realtime_dependencies'",
        ] as $token) {
            if (! str_contains($mcpTest, $token)) {
                $violations[] = "tests/Feature/Ai/AtlasOpenBrainMcpServiceTest.php: AP-174 MCP bootstrap architecture_operations must be covered [{$token}]";
            }
        }

        foreach ([
            'AP-174',
            'Session Bootstrap Architecture Operations Contract',
            'architecture_operations',
            'voice_realtime_dependencies',
            'ap174_session_bootstrap_architecture_operations_contract',
        ] as $token) {
            if (! str_contains($docs, $token)) {
                $violations[] = "docs/engineering-knowledge-base/atlas-ai-kernel-architecture.md: AP-174 session bootstrap architecture operations contract must be documented [{$token}]";
            }
            if (! str_contains($sessionDoc, $token)) {
                $violations[] = "docs/engineering-knowledge-base/atlas-ai-session-bootstrap.md: AP-174 bootstrap doc must mention architecture operations contract [{$token}]";
            }
            if (! str_contains($apDoc, $token)) {
                $violations[] = "docs/ap/AP-174-session-bootstrap-architecture-operations-contract.md: AP-174 contract doc must exist [{$token}]";
            }
        }

        return $violations;
    }

    /**
     * @return array<int,string>
     */
    private function scanFeaturePlacementArchitectureOperationsContract(): array
    {
        $violations = [];
        $servicePath = app_path('Services/Ai/Kernel/Architecture/AtlasFeaturePlacementService.php');
        $apiTestPath = base_path('tests/Feature/Ai/AtlasAiGovernanceApiTest.php');
        $commandTestPath = base_path('tests/Feature/Ai/AtlasAiSessionBootstrapCommandTest.php');
        $mcpTestPath = base_path('tests/Feature/Ai/AtlasOpenBrainMcpServiceTest.php');
        $docsPath = base_path('docs/engineering-knowledge-base/atlas-ai-kernel-architecture.md');
        $apDocPath = base_path('docs/ap/AP-175-feature-placement-architecture-operations-contract.md');

        $service = File::exists($servicePath) ? File::get($servicePath) : '';
        $apiTest = File::exists($apiTestPath) ? File::get($apiTestPath) : '';
        $commandTest = File::exists($commandTestPath) ? File::get($commandTestPath) : '';
        $mcpTest = File::exists($mcpTestPath) ? File::get($mcpTestPath) : '';
        $docs = $this->primitives->kernelDocumentationCorpus();
        $apDoc = File::exists($apDocPath) ? File::get($apDocPath) : '';

        foreach ([
            'AtlasArchitectureOperationsCatalog $operations',
            "'architecture_operations' => \$this->placementOperations(\$placement)",
            'private function placementOperations(array $placement = []): array',
            "'architecture_readiness'",
            "'feature_placement'",
            "'session_bootstrap'",
            "'documentation_split_plan'",
            "'architecture_validate'",
            "'code_intelligence_index'",
            "'voice_realtime_dependencies'",
            "'owner_layer_operations' => [",
            "'runtime' => \$this->operations->summary(['owner_layer' => 'runtime'])",
        ] as $token) {
            if (! str_contains($service, $token)) {
                $violations[] = "app/Services/Ai/Kernel/Architecture/AtlasFeaturePlacementService.php: AP-175 feature placement must include focused architecture_operations [{$token}]";
            }
        }

        foreach ([
            "assertJsonPath('architecture_operations.schema_version', 'atlas.architecture_operations.v1')",
            'architecture_operations.operation_ids',
            'architecture_operations.owner_layer_operations.runtime.operation_ids',
            "'architecture_readiness'",
            "'feature_placement'",
            "'voice_realtime_dependencies'",
        ] as $token) {
            if (! str_contains($apiTest, $token)) {
                $violations[] = "tests/Feature/Ai/AtlasAiGovernanceApiTest.php: AP-175 API feature placement architecture_operations must be covered [{$token}]";
            }
        }

        foreach ([
            "data_get(\$payload, 'architecture_operations.schema_version')",
            "data_get(\$payload, 'architecture_operations.operation_ids')",
            "data_get(\$payload, 'architecture_operations.owner_layer_operations.runtime.operation_ids')",
            "'architecture_readiness'",
            "'feature_placement'",
            "'voice_realtime_dependencies'",
        ] as $token) {
            if (! str_contains($commandTest, $token)) {
                $violations[] = "tests/Feature/Ai/AtlasAiSessionBootstrapCommandTest.php: AP-175 CLI feature placement architecture_operations must be covered [{$token}]";
            }
        }

        foreach ([
            "data_get(\$placement, 'architecture_operations.operation_ids')",
            "data_get(\$placement, 'architecture_operations.owner_layer_operations.runtime.operation_ids')",
            "'architecture_readiness'",
            "'feature_placement'",
            "'architecture_validate'",
            "'voice_realtime_dependencies'",
        ] as $token) {
            if (! str_contains($mcpTest, $token)) {
                $violations[] = "tests/Feature/Ai/AtlasOpenBrainMcpServiceTest.php: AP-175 MCP feature placement architecture_operations must be covered [{$token}]";
            }
        }

        foreach ([
            'AP-175',
            'Feature Placement Architecture Operations Contract',
            'architecture_operations',
            'voice_realtime_dependencies',
            'ap175_feature_placement_architecture_operations_contract',
        ] as $token) {
            if (! str_contains($docs, $token)) {
                $violations[] = "docs/engineering-knowledge-base/atlas-ai-kernel-architecture.md: AP-175 feature placement architecture operations contract must be documented [{$token}]";
            }
            if (! str_contains($apDoc, $token)) {
                $violations[] = "docs/ap/AP-175-feature-placement-architecture-operations-contract.md: AP-175 contract doc must exist [{$token}]";
            }
        }

        return $violations;
    }
}
