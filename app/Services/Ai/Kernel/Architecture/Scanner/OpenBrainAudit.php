<?php

namespace App\Services\Ai\Kernel\Architecture\Scanner;

use Illuminate\Support\Facades\File;

class OpenBrainAudit
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
            'ap78_open_brain_mcp_input_contract' => fn (): array => $this->scanOpenBrainMcpInputContract(),
            'ap102_open_brain_retrieval_plan_summary_contract' => fn (): array => $this->scanOpenBrainRetrievalPlanSummaryContract(),
            'ap105_open_brain_retrieval_self_improvement_contract' => fn (): array => $this->scanOpenBrainRetrievalSelfImprovementContract(),
        ];
    }

    /**
     * @return array<int,string>
     */
    private function scanOpenBrainMcpInputContract(): array
    {
        $inputPath = app_path('Services/Ai/Kernel/Mcp/OpenBrainMcpInput.php');
        $servicePath = app_path('Services/Ai/AtlasOpenBrainMcpService.php');
        $testPath = base_path('tests/Unit/Ai/Kernel/OpenBrainMcpInputTest.php');
        $docsPath = base_path('docs/engineering-knowledge-base/atlas-ai-kernel-architecture.md');
        $violations = [];

        $navToolsPath = app_path('Services/Ai/OpenBrainMcp/NavigationTools.php');

        $input = File::exists($inputPath) ? File::get($inputPath) : '';
        $service = File::exists($servicePath) ? File::get($servicePath) : '';
        $navTools = File::exists($navToolsPath) ? File::get($navToolsPath) : '';
        $test = File::exists($testPath) ? File::get($testPath) : '';
        $docs = $this->primitives->kernelDocumentationCorpus();

        foreach ([
            'final class OpenBrainMcpInput',
            'public const DEFAULT_CODE_LIMIT = 20',
            'public const MAX_CODE_LIMIT = 100',
            'public const DEFAULT_DOCS_LIMIT = 10',
            'public const MAX_DOCS_LIMIT = 50',
            'public const DEFAULT_RECENT_CHANGES_LIMIT = 50',
            'public const MAX_RECENT_CHANGES_LIMIT = 200',
            'public const DEFAULT_DECISION_LIMIT = 5',
            'public const MAX_DECISION_LIMIT = 20',
            'public const DEFAULT_SYMBOLS_LIMIT = 50',
            'public const MAX_SYMBOLS_LIMIT = 200',
            'public function codeLimit(',
            'public function docsLimit(',
            'public function recentChangesLimit(',
            'public function decisionLimit(',
            'public function symbolsLimit(',
            'public function contextMemoryLimit(',
            'public function contextCodeLimit(',
            'public function contextDocsLimit(',
        ] as $token) {
            if (! str_contains($input, $token)) {
                $violations[] = "app/Services/Ai/Kernel/Mcp/OpenBrainMcpInput.php: Open Brain MCP input contract is incomplete [{$token}]";
            }
        }

        // Façade keeps the shared input dependency + the code/docs consumers; the
        // navigation consumers were relocated under GOD-DEBULK D3 to
        // OpenBrainMcp/NavigationTools (invariant unchanged).
        foreach ([
            'private readonly OpenBrainMcpInput $mcpInput',
            '$this->mcpInput->codeLimit(',
            '$this->mcpInput->docsLimit(',
        ] as $token) {
            if (! str_contains($service, $token)) {
                $violations[] = "app/Services/Ai/AtlasOpenBrainMcpService.php: MCP tools must use shared input contract [{$token}]";
            }
        }

        foreach ([
            'private readonly OpenBrainMcpInput $mcpInput',
            '$this->mcpInput->recentChangesLimit(',
            '$this->mcpInput->decisionLimit(',
            '$this->mcpInput->symbolsLimit(',
            '$this->mcpInput->contextMemoryLimit(',
            '$this->mcpInput->contextCodeLimit(',
            '$this->mcpInput->contextDocsLimit(',
        ] as $token) {
            if (! str_contains($navTools, $token)) {
                $violations[] = "app/Services/Ai/OpenBrainMcp/NavigationTools.php: MCP tools must use shared input contract [{$token}]";
            }
        }

        foreach ([
            'test_limits_normalize_mcp_tool_windows_with_canonical_caps',
            'OpenBrainMcpInput::MAX_CODE_LIMIT',
            'OpenBrainMcpInput::MAX_CONTEXT_DOCS_LIMIT',
        ] as $token) {
            if (! str_contains($test, $token)) {
                $violations[] = "tests/Unit/Ai/Kernel/OpenBrainMcpInputTest.php: Open Brain MCP input contract must be covered [{$token}]";
            }
        }

        foreach ([
            'open brain mcp input contract',
            'AP-78',
        ] as $token) {
            if (! str_contains($docs, $token)) {
                $violations[] = "docs/engineering-knowledge-base/atlas-ai-kernel-architecture.md: docs must describe Open Brain MCP input contract [{$token}]";
            }
        }

        return $violations;
    }

    /**
     * @return array<int,string>
     */
    private function scanOpenBrainRetrievalPlanSummaryContract(): array
    {
        $violations = [];
        // Pin relocated under GOD-DEBULK D3 (2026-07-22): the AP-102 retrieval-plan SUMMARY BUILDER
        // moved VERBATIM into RetrievalPlanSection; the façade still ASSEMBLES it into the summary
        // ('retrieval_plan' => $retrievalPlan) and renders the prompt header ('- retrieval_plan: mode=').
        // Invariant unchanged — every token is still required textually in its real new home.
        $sectionPath = app_path('Services/Ai/OpenBrainContextInjection/RetrievalPlanSection.php');
        $servicePath = app_path('Services/Ai/AtlasOpenBrainContextInjectionService.php');
        $testPath = base_path('tests/Unit/Ai/AtlasOpenBrainContextInjectionServiceTest.php');
        $docsPath = base_path('docs/engineering-knowledge-base/atlas-ai-kernel-architecture.md');

        $section = File::exists($sectionPath) ? File::get($sectionPath) : '';
        $service = File::exists($servicePath) ? File::get($servicePath) : '';
        $test = File::exists($testPath) ? File::get($testPath) : '';
        $docs = $this->primitives->kernelDocumentationCorpus();

        foreach ([
            'public function retrievalPlanSummary(array $retrievalPlan, array $contextRefs, array $knowledgeRefs, array $codeRefs, array $contextPack): ?array',
            "'selected_sources' => array_values(array_map",
            "'required_sources' => array_values(array_map",
            "'max_context_refs' => data_get(\$retrievalPlan, 'budgets.max_context_refs')",
            "'provider_safe_only' => (bool) data_get(\$retrievalPlan, 'policy.provider_safe_only', true)",
        ] as $token) {
            if (! str_contains($section, $token)) {
                $violations[] = "app/Services/Ai/OpenBrainContextInjection/RetrievalPlanSection.php: Open Brain must summarize AP-101 retrieval plan for audit [{$token}]";
            }
        }

        foreach ([
            "'retrieval_plan' => \$retrievalPlan",
            "'- retrieval_plan: mode='",
        ] as $token) {
            if (! str_contains($service, $token)) {
                $violations[] = "app/Services/Ai/AtlasOpenBrainContextInjectionService.php: Open Brain must assemble AP-101 retrieval plan into summary and prompt header [{$token}]";
            }
        }

        foreach ([
            'test_retrieval_plan_is_summarized_for_open_brain_audit_and_prompt_header',
            'summary.retrieval_plan.selected_sources',
            'summary.retrieval_plan.required_sources',
            'retrieval_plan: mode=audit_heavy',
        ] as $token) {
            if (! str_contains($test, $token)) {
                $violations[] = "tests/Unit/Ai/AtlasOpenBrainContextInjectionServiceTest.php: AP-102 Open Brain retrieval plan summary must be covered [{$token}]";
            }
        }

        foreach ([
            'AP-102',
            'Open Brain Retrieval Plan Summary',
            'summary.retrieval_plan',
        ] as $token) {
            if (! str_contains($docs, $token)) {
                $violations[] = "docs/engineering-knowledge-base/atlas-ai-kernel-architecture.md: AP-102 Open Brain retrieval summary contract must be documented [{$token}]";
            }
        }

        return $violations;
    }

    /**
     * @return array<int,string>
     */
    private function scanOpenBrainRetrievalSelfImprovementContract(): array
    {
        $violations = [];
        $runtimePath = app_path('Services/Ai/SelfImprovement/AtlasSelfImprovementRuntime.php');
        $testPath = base_path('tests/Feature/Ai/AtlasSelfImprovementRuntimeTest.php');
        $docsPath = base_path('docs/engineering-knowledge-base/atlas-ai-kernel-architecture.md');

        $runtime = File::exists($runtimePath) ? File::get($runtimePath) : '';
        $test = File::exists($testPath) ? File::get($testPath) : '';
        $docs = $this->primitives->kernelDocumentationCorpus();

        foreach ([
            'use App\Models\AtlasOpenBrainAccessLog;',
            'private function openBrainRetrievalFindings(int $hours, array $filters = []): array',
            "Schema::hasTable('atlas_open_brain_access_logs')",
            "data_get(\$summary, 'retrieval_plan.review_signal.status')",
            "'required_unavailable_source_counts' => \$requiredUnavailableSourceCounts",
            "'self-improvement:open-brain-retrieval:'",
            "'atlas.self_improvement.open_brain_retrieval.v1'",
        ] as $token) {
            if (! str_contains($runtime, $token)) {
                $violations[] = "app/Services/Ai/SelfImprovement/AtlasSelfImprovementRuntime.php: AP-105 Open Brain retrieval signal must feed Self-Improvement [{$token}]";
            }
        }

        foreach ([
            'test_self_improvement_detects_open_brain_retrieval_required_source_gaps',
            "'recommended_action' => 'refresh_evidence_replay_or_attach_trace_before_retry'",
            'required_unavailable_source_counts',
            'self-improvement:open-brain-retrieval:',
        ] as $token) {
            if (! str_contains($test, $token)) {
                $violations[] = "tests/Feature/Ai/AtlasSelfImprovementRuntimeTest.php: AP-105 Open Brain retrieval Self-Improvement contract must be covered [{$token}]";
            }
        }

        foreach ([
            'AP-105',
            'Open Brain Retrieval Self-Improvement',
            'self-improvement:open-brain-retrieval',
        ] as $token) {
            if (! str_contains($docs, $token)) {
                $violations[] = "docs/engineering-knowledge-base/atlas-ai-kernel-architecture.md: AP-105 Open Brain retrieval Self-Improvement contract must be documented [{$token}]";
            }
        }

        return $violations;
    }
}
