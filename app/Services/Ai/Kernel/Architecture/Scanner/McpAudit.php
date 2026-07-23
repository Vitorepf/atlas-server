<?php

namespace App\Services\Ai\Kernel\Architecture\Scanner;

use Illuminate\Support\Facades\File;

class McpAudit
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
            'ap19_mcp_domain_catalog_parity' => fn (): array => $this->scanMcpDomainCatalogParity(),
            'ap62_mcp_replay_unavailable_review_signal' => fn (): array => $this->scanMcpReplayUnavailableReviewSignal(),
            'ap64_mcp_replay_window_contract' => fn (): array => $this->scanMcpReplayWindowContract(),
            'ap65_mcp_replay_filter_contract' => fn (): array => $this->scanMcpReplayFilterContract(),
        ];
    }

    /**
     * @return array<int,string>
     */
    private function scanMcpDomainCatalogParity(): array
    {
        $mcpPath = app_path('Services/Ai/AtlasOpenBrainMcpService.php');
        $testPath = base_path('tests/Feature/Ai/AtlasOpenBrainMcpServiceTest.php');
        $docsPath = base_path('docs/engineering-knowledge-base/surface-domain-catalog-integration-plan.md');

        $architectureToolsPath = app_path('Services/Ai/OpenBrainMcp/ArchitectureTools.php');

        $mcp = File::exists($mcpPath) ? File::get($mcpPath) : '';
        $architectureTools = File::exists($architectureToolsPath) ? File::get($architectureToolsPath) : '';
        $test = File::exists($testPath) ? File::get($testPath) : '';
        $docs = File::exists($docsPath) ? File::get($docsPath) : '';

        $violations = [];

        // Façade keeps the tools() schema; the handler was relocated under GOD-DEBULK D3
        // to OpenBrainMcp/ArchitectureTools (invariant unchanged).
        foreach ([
            "'name' => 'atlas_domain_catalog'",
            "'onboarding_status' => ['type' => 'string'",
        ] as $token) {
            if (! str_contains($mcp, $token)) {
                $violations[] = "app/Services/Ai/AtlasOpenBrainMcpService.php: atlas_domain_catalog MCP tool must preserve Domain Catalog onboarding parity [{$token}]";
            }
        }

        foreach ([
            "\$onboardingStatus = \$this->string(\$arguments['onboarding_status'] ?? null)",
            "'invalid_onboarding_status'",
            "'allowed_onboarding_status' => ['ready', 'executable_incomplete', 'scaffold']",
            "'onboarding_status' => \$onboardingStatus",
        ] as $token) {
            if (! str_contains($architectureTools, $token)) {
                $violations[] = "app/Services/Ai/OpenBrainMcp/ArchitectureTools.php: atlas_domain_catalog MCP tool must preserve Domain Catalog onboarding parity [{$token}]";
            }
        }

        foreach ([
            'test_domain_catalog_tool_schema_exposes_onboarding_status_filter',
            'test_domain_catalog_tool_filters_by_onboarding_status',
            'test_domain_catalog_tool_rejects_invalid_onboarding_status_filter',
        ] as $token) {
            if (! str_contains($test, $token)) {
                $violations[] = "tests/Feature/Ai/AtlasOpenBrainMcpServiceTest.php: MCP Domain Catalog onboarding parity needs positive, schema, and invalid-filter coverage [{$token}]";
            }
        }

        if (! str_contains($docs, '`domain`, `flow`, `maturity` e `onboarding_status`')) {
            $violations[] = 'docs/engineering-knowledge-base/surface-domain-catalog-integration-plan.md: MCP Domain Catalog docs must mention onboarding_status parity';
        }

        return $violations;
    }

    /**
     * @return array<int,string>
     */
    private function scanMcpReplayUnavailableReviewSignal(): array
    {
        $testPath = base_path('tests/Feature/Ai/AtlasOpenBrainMcpServiceTest.php');
        $docsPath = base_path('docs/engineering-knowledge-base/atlas-ai-kernel-architecture.md');
        $memoryDocsPath = base_path('docs/engineering-knowledge-base/atlas-ai-memory-context-core-open-brain.md');
        $violations = [];

        $test = File::exists($testPath) ? File::get($testPath) : '';
        $docs = $this->primitives->kernelDocumentationCorpus();
        $memoryDocs = File::exists($memoryDocsPath) ? File::get($memoryDocsPath) : '';

        foreach ([
            'test_replay_report_tools_preserve_review_signal_when_ledger_is_unavailable',
            "'atlas_self_improvement_schedule_report'",
            "'atlas_kernel_slo_report'",
            "'atlas_kernel_pipeline_report'",
            "'atlas_repair_loop_report'",
            'wait_for_next_self_improvement_cycle',
            'wait_for_slo_evidence',
            'wait_for_kernel_pipeline_evidence',
            'wait_for_repair_loop_evidence',
        ] as $token) {
            if (! str_contains($test, $token)) {
                $violations[] = "tests/Feature/Ai/AtlasOpenBrainMcpServiceTest.php: MCP replay tools must preserve unavailable review_signal [{$token}]";
            }
        }

        foreach ([
            'mcp replay unavailable review_signal',
            'AP62',
        ] as $token) {
            if (! str_contains($docs, $token) && ! str_contains($memoryDocs, $token)) {
                $violations[] = "docs/engineering-knowledge-base: docs must describe MCP unavailable review_signal parity [{$token}]";
            }
        }

        return $violations;
    }

    /**
     * @return array<int,string>
     */
    private function scanMcpReplayWindowContract(): array
    {
        $mcpPath = app_path('Services/Ai/AtlasOpenBrainMcpService.php');
        $testPath = base_path('tests/Feature/Ai/AtlasOpenBrainMcpServiceTest.php');
        $docsPath = base_path('docs/engineering-knowledge-base/atlas-ai-kernel-architecture.md');
        $violations = [];

        $reportToolsPath = app_path('Services/Ai/OpenBrainMcp/ReportTools.php');

        $mcp = File::exists($mcpPath) ? File::get($mcpPath) : '';
        $reportTools = File::exists($reportToolsPath) ? File::get($reportToolsPath) : '';
        $test = File::exists($testPath) ? File::get($testPath) : '';
        $docs = $this->primitives->kernelDocumentationCorpus();

        // Canonical hours normalizer + its callers relocated under GOD-DEBULK D3 to
        // OpenBrainMcp/ReportTools (invariant unchanged).
        foreach ([
            'private function reportWindowHours(array $arguments): int',
            "return \$this->replayInput->hours(\$arguments['hours'] ?? null)",
            '$hours = $this->reportWindowHours($arguments)',
        ] as $token) {
            if (! str_contains($reportTools, $token)) {
                $violations[] = "app/Services/Ai/OpenBrainMcp/ReportTools.php: MCP replay tools must use one canonical hours normalizer [{$token}]";
            }
        }

        foreach ([
            'test_replay_report_tools_use_canonical_mcp_hours_window',
            "'tool' => 'atlas_self_improvement_schedule_report', 'input' => -5, 'expected' => 1",
            "'tool' => 'atlas_kernel_slo_report', 'input' => 9999, 'expected' => 720",
            "'tool' => 'atlas_kernel_pipeline_report', 'input' => ['bad'], 'expected' => 24",
            "'tool' => 'atlas_repair_loop_report', 'input' => '12', 'expected' => 12",
        ] as $token) {
            if (! str_contains($test, $token)) {
                $violations[] = "tests/Feature/Ai/AtlasOpenBrainMcpServiceTest.php: MCP replay hours normalization must be covered [{$token}]";
            }
        }

        foreach ([
            'mcp replay window contract',
            'AP-64',
        ] as $token) {
            if (! str_contains($docs, $token)) {
                $violations[] = "docs/engineering-knowledge-base/atlas-ai-kernel-architecture.md: docs must describe MCP replay window contract [{$token}]";
            }
        }

        return $violations;
    }

    /**
     * @return array<int,string>
     */
    private function scanMcpReplayFilterContract(): array
    {
        $mcpPath = app_path('Services/Ai/AtlasOpenBrainMcpService.php');
        $testPath = base_path('tests/Feature/Ai/AtlasOpenBrainMcpServiceTest.php');
        $docsPath = base_path('docs/engineering-knowledge-base/atlas-ai-kernel-architecture.md');
        $violations = [];

        $reportToolsPath = app_path('Services/Ai/OpenBrainMcp/ReportTools.php');

        $mcp = File::exists($mcpPath) ? File::get($mcpPath) : '';
        $reportTools = File::exists($reportToolsPath) ? File::get($reportToolsPath) : '';
        $test = File::exists($testPath) ? File::get($testPath) : '';
        $docs = $this->primitives->kernelDocumentationCorpus();

        // Canonical scalar filter normalizer + its callers relocated under GOD-DEBULK D3
        // to OpenBrainMcp/ReportTools (invariant unchanged).
        foreach ([
            "return \$this->onlyScalarFilters(\$arguments, ['domain', 'flow', 'surface_id', 'provider', 'model', 'runtime', 'tool_id'])",
            'private function onlyScalarFilters(array $arguments, array $allowed): array',
            'return $this->replayInput->scalarFilters($arguments, $allowed)',
        ] as $token) {
            if (! str_contains($reportTools, $token)) {
                $violations[] = "app/Services/Ai/OpenBrainMcp/ReportTools.php: MCP replay filters must share canonical scalar filter normalizer [{$token}]";
            }
        }

        foreach ([
            'test_replay_report_tools_use_canonical_scalar_filter_contract',
            "'expected' => ['domain' => 'programming', 'tool_id' => 'phpstan']",
            "'expected' => ['status' => 'rejected', 'emitter_stage' => 'atlas.test']",
            "'expected' => ['status' => 'completed']",
        ] as $token) {
            if (! str_contains($test, $token)) {
                $violations[] = "tests/Feature/Ai/AtlasOpenBrainMcpServiceTest.php: MCP replay scalar filter normalization must be covered [{$token}]";
            }
        }

        foreach ([
            'mcp replay filter contract',
            'AP-65',
        ] as $token) {
            if (! str_contains($docs, $token)) {
                $violations[] = "docs/engineering-knowledge-base/atlas-ai-kernel-architecture.md: docs must describe MCP replay filter contract [{$token}]";
            }
        }

        return $violations;
    }
}
