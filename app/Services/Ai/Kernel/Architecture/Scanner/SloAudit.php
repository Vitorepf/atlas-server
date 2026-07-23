<?php

namespace App\Services\Ai\Kernel\Architecture\Scanner;

use Illuminate\Support\Facades\File;

class SloAudit
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
            'ap16_slo_observability' => fn (): array => $this->scanSloObservability(),
            'ap56_slo_review_signal' => fn (): array => $this->scanSloReviewSignal(),
            'ap57_slo_mcp_tool' => fn (): array => $this->scanSloMcpTool(),
            'ap61_slo_unavailable_review_signal' => fn (): array => $this->scanSloUnavailableReviewSignal(),
        ];
    }

    /**
     * @return array<int,string>
     */
    private function scanSloObservability(): array
    {
        $probePath = app_path('Services/Ai/Kernel/Slo/KernelSloProbe.php');
        $ledgerPath = app_path('Services/Ai/Kernel/Evidence/AtlasEvidenceLedger.php');
        $eventTypePath = app_path('Services/Ai/Kernel/Evidence/LedgerEventType.php');
        $decidePath = app_path('Services/Ai/AtlasDecideService.php');
        $contextPath = app_path('Services/Ai/Context/AiContextPackBuilder.php');
        $toolGatePath = app_path('Services/Tools/AtlasToolGateService.php');
        $workerPath = app_path('Services/Ai/AiWorker.php');
        $surfaceAdapterPath = app_path('Services/Ai/Surface/Adapters/BaseSurfaceAdapter.php');
        $learningPromotionPath = app_path('Services/Ai/Memory/AtlasMemoryLearningPromotionService.php');
        $replayPath = app_path('Services/Ai/Kernel/Evidence/AtlasLedgerReplayService.php');
        $observabilityPath = app_path('Http/Controllers/AiObservabilityController.php');
        $violations = [];

        if (! File::exists($probePath)) {
            $violations[] = 'app/Services/Ai/Kernel/Slo/KernelSloProbe.php: missing SLO probe service';
        }

        $probe = File::exists($probePath) ? File::get($probePath) : '';
        $ledger = File::exists($ledgerPath) ? File::get($ledgerPath) : '';
        $eventType = File::exists($eventTypePath) ? File::get($eventTypePath) : '';
        $decide = File::exists($decidePath) ? File::get($decidePath) : '';
        $context = File::exists($contextPath) ? File::get($contextPath) : '';
        $toolGate = File::exists($toolGatePath) ? File::get($toolGatePath) : '';
        $worker = File::exists($workerPath) ? File::get($workerPath) : '';
        $surfaceAdapter = File::exists($surfaceAdapterPath) ? File::get($surfaceAdapterPath) : '';
        $learningPromotion = File::exists($learningPromotionPath) ? File::get($learningPromotionPath) : '';
        $replay = File::exists($replayPath) ? File::get($replayPath) : '';
        $observability = File::exists($observabilityPath) ? File::get($observabilityPath) : '';

        $probeChecks = [
            'probe assesses stage through KernelSloTargets' => 'targets->assess($stage, $durationMs, $success)',
            'probe records assessment to Evidence Ledger' => 'ledger->recordSloObservation($assessment, $context)',
            'probe supports callable measurement' => 'public function measure(string $stage, callable $callback',
            'probe records failed callable stages' => 'observe($stage, $this->durationMs($started), false, $context)',
        ];

        foreach ($probeChecks as $label => $token) {
            if (! str_contains($probe, $token)) {
                $violations[] = "app/Services/Ai/Kernel/Slo/KernelSloProbe.php: missing {$label} [{$token}]";
            }
        }

        if (! str_contains($ledger, 'recordSloObservation(') || ! str_contains($ledger, 'LedgerEventType::SloObserved')) {
            $violations[] = 'app/Services/Ai/Kernel/Evidence/AtlasEvidenceLedger.php: missing SLO observation recorder';
        }

        if (! str_contains($ledger, "'emitter_stage' => 'atlas.slo'")) {
            $violations[] = 'app/Services/Ai/Kernel/Evidence/AtlasEvidenceLedger.php: SLO observations must use atlas.slo emitter stage';
        }

        if (! str_contains($eventType, "case SloObserved = 'SLO_OBSERVED'")) {
            $violations[] = 'app/Services/Ai/Kernel/Evidence/LedgerEventType.php: missing SLO_OBSERVED event type';
        }

        if (! str_contains($decide, "slo->measure('decide.issue'")) {
            $violations[] = 'app/Services/Ai/AtlasDecideService.php: DecisionReceipt issuance must be instrumented with KernelSloProbe stage decide.issue';
        }

        if (! str_contains($decide, "slo->measure('provider.prepare'")) {
            $violations[] = 'app/Services/Ai/AtlasDecideService.php: provider request preparation must be instrumented with KernelSloProbe stage provider.prepare';
        }

        if (! str_contains($context, "slo->measure('context.compose'")) {
            $violations[] = 'app/Services/Ai/Context/AiContextPackBuilder.php: context pack composition must be instrumented with KernelSloProbe stage context.compose';
        }

        if (! str_contains($toolGate, "slo->measure('gate.evaluate'")) {
            $violations[] = 'app/Services/Tools/AtlasToolGateService.php: tool gate evaluation must be instrumented with KernelSloProbe stage gate.evaluate';
        }

        if (! str_contains($worker, "slo->measure('runtime.execute'")) {
            $violations[] = 'app/Services/Ai/AiWorker.php: provider runtime execution must be instrumented with KernelSloProbe stage runtime.execute';
        }

        if (! str_contains($worker, "slo->measure('repair.loop'")) {
            $violations[] = 'app/Services/Ai/AiWorker.php: native programming repair loop must be instrumented with KernelSloProbe stage repair.loop';
        }

        if (! str_contains($surfaceAdapter, "measure('output.render'")) {
            $violations[] = 'app/Services/Ai/Surface/Adapters/BaseSurfaceAdapter.php: surface output rendering must be instrumented with KernelSloProbe stage output.render';
        }

        if (! str_contains($learningPromotion, "slo->measure('learning.project'")) {
            $violations[] = 'app/Services/Ai/Memory/AtlasMemoryLearningPromotionService.php: memory learning projection must be instrumented with KernelSloProbe stage learning.project';
        }

        if (! str_contains($replay, 'repairReportForWindow(') || ! str_contains($replay, 'repairReportForEnvelope(')) {
            $violations[] = 'app/Services/Ai/Kernel/Evidence/AtlasLedgerReplayService.php: repair evidence must have envelope and window projections for observability/curator';
        }

        if (! str_contains($observability, '$kernelSlo = $ledgerReplay->sloReportForWindow($since)') || ! str_contains($observability, "'kernel_slo' => \$kernelSlo")) {
            $violations[] = 'app/Http/Controllers/AiObservabilityController.php: observability payload must expose kernel_slo read model';
        }

        if (! str_contains($observability, '$kernelRepair = $ledgerReplay->repairReportForWindow($since)') || ! str_contains($observability, "'kernel_repair' => \$kernelRepair")) {
            $violations[] = 'app/Http/Controllers/AiObservabilityController.php: observability payload must expose kernel_slo and kernel_repair read models';
        }

        if (! str_contains($observability, 'AtlasSelfImprovementScheduleService') || ! str_contains($observability, "'self_improvement_schedule' => \$scheduledSelfImprovement")) {
            $violations[] = 'app/Http/Controllers/AiObservabilityController.php: observability payload must expose the Self-Improvement recurring schedule plan';
        }

        return $violations;
    }

    /**
     * @return array<int,string>
     */
    private function scanSloReviewSignal(): array
    {
        $replayPath = app_path('Services/Ai/Kernel/Evidence/AtlasLedgerReplayService.php');
        $runtimePath = app_path('Services/Ai/SelfImprovement/AtlasSelfImprovementRuntime.php');
        $commandPath = app_path('Console/Commands/AtlasAiSloCommand.php');
        $unitTestPath = base_path('tests/Unit/Ai/Kernel/LedgerReplayServiceTest.php');
        $runtimeTestPath = base_path('tests/Feature/Ai/AtlasSelfImprovementRuntimeTest.php');
        $commandTestPath = base_path('tests/Feature/Ai/AtlasAiSloCommandTest.php');
        $apiTestPath = base_path('tests/Feature/Ai/AtlasAiSloApiTest.php');
        $observabilityTestPath = base_path('tests/Feature/Ai/AiObservabilityKernelSloTest.php');
        $domainDocsPath = base_path('docs/engineering-knowledge-base/domains/self-improvement.md');
        $kernelDocsPath = base_path('docs/engineering-knowledge-base/atlas-ai-kernel-architecture.md');
        $violations = [];

        $replay = File::exists($replayPath) ? File::get($replayPath) : '';
        $runtime = File::exists($runtimePath) ? File::get($runtimePath) : '';
        $command = File::exists($commandPath) ? File::get($commandPath) : '';
        $unitTest = File::exists($unitTestPath) ? File::get($unitTestPath) : '';
        $runtimeTest = File::exists($runtimeTestPath) ? File::get($runtimeTestPath) : '';
        $commandTest = File::exists($commandTestPath) ? File::get($commandTestPath) : '';
        $apiTest = File::exists($apiTestPath) ? File::get($apiTestPath) : '';
        $observabilityTest = File::exists($observabilityTestPath) ? File::get($observabilityTestPath) : '';
        $domainDocs = $this->primitives->selfImprovementDomainDocumentationCorpus();
        $kernelDocs = $this->primitives->kernelDocumentationCorpus();

        foreach ([
            '$reviewSignal = $this->sloReviewSignal($observations, $worstStatus, $worstSeverity, $failureCount, $stages)',
            "'review_signal' => \$reviewSignal",
            'private function sloReviewSignal(Collection $observations, ?string $worstStatus, ?string $worstSeverity, int $failureCount, array $stages): array',
            "'recommended_action' => 'open_reviewable_slo_regression_proposal'",
            "'recommended_action' => 'open_reviewable_slo_drift_proposal'",
        ] as $token) {
            if (! str_contains($replay, $token)) {
                $violations[] = "app/Services/Ai/Kernel/Evidence/AtlasLedgerReplayService.php: SLO replay must publish canonical review_signal [{$token}]";
            }
        }

        foreach ([
            "'review_signal' => \$report['review_signal'] ?? []",
        ] as $token) {
            if (! str_contains($runtime, $token)) {
                $violations[] = "app/Services/Ai/SelfImprovement/AtlasSelfImprovementRuntime.php: Self-Improvement must propagate SLO review_signal [{$token}]";
            }
        }

        foreach ([
            "data_get(\$slo, 'review_signal.status'",
            "data_get(\$slo, 'review_signal.severity'",
            "data_get(\$slo, 'review_signal.recommended_action'",
        ] as $token) {
            if (! str_contains($command, $token)) {
                $violations[] = "app/Console/Commands/AtlasAiSloCommand.php: SLO CLI must render review_signal [{$token}]";
            }
        }

        foreach ([
            "data_get(\$report, 'review_signal.status')",
            "data_get(\$report, 'review_signal.severity')",
            "data_get(\$report, 'review_signal.recommended_action')",
            'open_reviewable_slo_regression_proposal',
        ] as $token) {
            if (! str_contains($unitTest, $token)) {
                $violations[] = "tests/Unit/Ai/Kernel/LedgerReplayServiceTest.php: SLO review_signal must be covered [{$token}]";
            }
        }

        foreach ([
            "data_get(\$finding, 'metadata.review_signal.status')",
            "data_get(\$finding, 'metadata.review_signal.severity')",
            "data_get(\$finding, 'metadata.review_signal.recommended_action')",
            'open_reviewable_slo_regression_proposal',
        ] as $token) {
            if (! str_contains($runtimeTest, $token)) {
                $violations[] = "tests/Feature/Ai/AtlasSelfImprovementRuntimeTest.php: Curator SLO review_signal propagation must be covered [{$token}]";
            }
        }

        foreach ([
            "data_get(\$payload, 'kernel_slo.review_signal.status')",
            'test_command_human_output_includes_slo_review_signal',
        ] as $token) {
            if (! str_contains($commandTest, $token)) {
                $violations[] = "tests/Feature/Ai/AtlasAiSloCommandTest.php: SLO CLI review_signal must be covered [{$token}]";
            }
        }

        foreach ([
            "kernel_slo.review_signal.status', 'warning'",
            "kernel_slo.review_signal.severity', 'medium'",
            "kernel_slo.review_signal.recommended_action', 'open_reviewable_slo_drift_proposal'",
        ] as $token) {
            if (! str_contains($apiTest, $token)) {
                $violations[] = "tests/Feature/Ai/AtlasAiSloApiTest.php: SLO API review_signal must be covered [{$token}]";
            }
        }

        foreach ([
            "kernel_slo.review_signal.status', 'breach'",
            "kernel_slo.review_signal.severity', 'high'",
            "kernel_slo.review_signal.recommended_action', 'open_reviewable_slo_regression_proposal'",
        ] as $token) {
            if (! str_contains($observabilityTest, $token)) {
                $violations[] = "tests/Feature/Ai/AiObservabilityKernelSloTest.php: Observability SLO review_signal must be covered [{$token}]";
            }
        }

        foreach ([
            'slo review_signal',
            'AP56',
        ] as $token) {
            if (! str_contains($domainDocs, $token) && ! str_contains($kernelDocs, $token)) {
                $violations[] = "docs/engineering-knowledge-base: docs must describe SLO review_signal [{$token}]";
            }
        }

        return $violations;
    }

    /**
     * @return array<int,string>
     */
    private function scanSloMcpTool(): array
    {
        $mcpPath = app_path('Services/Ai/AtlasOpenBrainMcpService.php');
        $testPath = base_path('tests/Feature/Ai/AtlasOpenBrainMcpServiceTest.php');
        $docsPath = base_path('docs/engineering-knowledge-base/atlas-ai-kernel-architecture.md');
        $memoryDocsPath = base_path('docs/engineering-knowledge-base/atlas-ai-memory-context-core-open-brain.md');
        $violations = [];

        $reportToolsPath = app_path('Services/Ai/OpenBrainMcp/ReportTools.php');

        $mcp = File::exists($mcpPath) ? File::get($mcpPath) : '';
        $reportTools = File::exists($reportToolsPath) ? File::get($reportToolsPath) : '';
        $test = File::exists($testPath) ? File::get($testPath) : '';
        $docs = $this->primitives->kernelDocumentationCorpus();
        $memoryDocs = File::exists($memoryDocsPath) ? File::get($memoryDocsPath) : '';

        // Façade keeps the tools() schema + dispatch; the handler + slo filters were
        // relocated under GOD-DEBULK D3 to OpenBrainMcp/ReportTools (invariant unchanged).
        foreach ([
            "'name' => 'atlas_kernel_slo_report'",
            "'atlas_kernel_slo_report' => \$this->toolResponse(\$id, \$this->reportTools->kernelSloReport(\$arguments))",
            "'writes' => false",
        ] as $token) {
            if (! str_contains($mcp, $token)) {
                $violations[] = "app/Services/Ai/AtlasOpenBrainMcpService.php: MCP must expose read-only atlas_kernel_slo_report [{$token}]";
            }
        }

        foreach ([
            'public function kernelSloReport(array $arguments): array',
            '$this->ledgerReplay->sloReportForWindow(now()->subHours($hours), null, $filters)',
            'private function kernelSloFilters(array $arguments): array',
        ] as $token) {
            if (! str_contains($reportTools, $token)) {
                $violations[] = "app/Services/Ai/OpenBrainMcp/ReportTools.php: MCP must expose read-only atlas_kernel_slo_report [{$token}]";
            }
        }

        foreach ([
            'test_kernel_slo_report_tool_exposes_replay_read_model',
            "'name' => 'atlas_kernel_slo_report'",
            "data_get(\$structured, 'kernel_slo.review_signal.status')",
            'open_reviewable_slo_regression_proposal',
        ] as $token) {
            if (! str_contains($test, $token)) {
                $violations[] = "tests/Feature/Ai/AtlasOpenBrainMcpServiceTest.php: MCP SLO report tool must be covered [{$token}]";
            }
        }

        foreach ([
            'atlas_kernel_slo_report',
            'AP57',
        ] as $token) {
            if (! str_contains($docs, $token) && ! str_contains($memoryDocs, $token)) {
                $violations[] = "docs/engineering-knowledge-base: docs must describe MCP SLO report tool [{$token}]";
            }
        }

        return $violations;
    }

    /**
     * @return array<int,string>
     */
    private function scanSloUnavailableReviewSignal(): array
    {
        $replayPath = app_path('Services/Ai/Kernel/Evidence/AtlasLedgerReplayService.php');
        $commandTestPath = base_path('tests/Feature/Ai/AtlasAiSloCommandTest.php');
        $apiTestPath = base_path('tests/Feature/Ai/AtlasAiSloApiTest.php');
        $docsPath = base_path('docs/engineering-knowledge-base/atlas-ai-kernel-architecture.md');
        $violations = [];

        $replay = File::exists($replayPath) ? File::get($replayPath) : '';
        $commandTest = File::exists($commandTestPath) ? File::get($commandTestPath) : '';
        $apiTest = File::exists($apiTestPath) ? File::get($apiTestPath) : '';
        $docs = $this->primitives->kernelDocumentationCorpus();

        foreach ([
            '...$this->sloObservationSummary(new Collection)',
            "'recommended_action' => 'wait_for_slo_evidence'",
        ] as $token) {
            if (! str_contains($replay, $token)) {
                $violations[] = "app/Services/Ai/Kernel/Evidence/AtlasLedgerReplayService.php: SLO unavailable payload must preserve canonical review_signal [{$token}]";
            }
        }

        foreach ([
            "data_get(\$payload, 'kernel_slo.review_signal.status')",
            "data_get(\$payload, 'kernel_slo.review_signal.recommended_action')",
            'wait_for_slo_evidence',
        ] as $token) {
            if (! str_contains($commandTest, $token)) {
                $violations[] = "tests/Feature/Ai/AtlasAiSloCommandTest.php: SLO CLI unavailable review_signal must be covered [{$token}]";
            }
        }

        foreach ([
            "assertJsonPath('kernel_slo.review_signal.status', 'unknown')",
            "assertJsonPath('kernel_slo.review_signal.recommended_action', 'wait_for_slo_evidence')",
        ] as $token) {
            if (! str_contains($apiTest, $token)) {
                $violations[] = "tests/Feature/Ai/AtlasAiSloApiTest.php: SLO API unavailable review_signal must be covered [{$token}]";
            }
        }

        foreach ([
            'slo unavailable review_signal',
            'AP61',
        ] as $token) {
            if (! str_contains($docs, $token)) {
                $violations[] = "docs/engineering-knowledge-base/atlas-ai-kernel-architecture.md: docs must describe SLO unavailable review_signal parity [{$token}]";
            }
        }

        return $violations;
    }
}
