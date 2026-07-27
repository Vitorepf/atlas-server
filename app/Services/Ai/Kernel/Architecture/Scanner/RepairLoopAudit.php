<?php

namespace App\Services\Ai\Kernel\Architecture\Scanner;

use App\Support\PeeledSource;
use App\Support\RoutesApiSource;
use Illuminate\Support\Facades\File;

class RepairLoopAudit
{
    public function __construct(private ScanPrimitivesSupport $primitives) {}

    /**
     * @return array<string,callable(): array<int,string>>
     */
    public function checks(): array
    {
        return [
            'ap18_repair_loop_contract' => fn (): array => $this->scanRepairLoopContract(),
            'ap55_repair_loop_review_signal' => fn (): array => $this->scanRepairLoopReviewSignal(),
            'ap59_repair_loop_mcp_tool' => fn (): array => $this->scanRepairLoopMcpTool(),
            'ap60_repair_loop_unavailable_review_signal' => fn (): array => $this->scanRepairLoopUnavailableReviewSignal(),
        ];
    }

    /**
     * @return array<int,string>
     */
    private function scanRepairLoopContract(): array
    {
        $repairPath = app_path('Services/Ai/Kernel/Repair');
        $requiredFiles = [
            'AtlasRepairOrchestrator.php',
            'RepairAttempt.php',
            'RepairDecision.php',
            'RepairDecisionStatus.php',
            'RepairPolicy.php',
            'RepairReason.php',
            'RepairRequest.php',
            'RepairRequestFactory.php',
            'RepairResult.php',
            'RepairStrategy.php',
            'RepairStrategyResolver.php',
        ];
        $violations = [];

        if (! File::isDirectory($repairPath)) {
            return ["missing repair loop directory [{$repairPath}]"];
        }

        foreach ($requiredFiles as $file) {
            if (! File::exists($repairPath.DIRECTORY_SEPARATOR.$file)) {
                $violations[] = "app/Services/Ai/Kernel/Repair/{$file}: missing repair contract file";
            }
        }

        foreach ($this->primitives->scanPhpFilesForForbiddenTokens($repairPath, [
            'App\\Services\\Ai\\Kernel\\Provider\\ProviderDriver',
            'App\\Services\\Ai\\Provider\\Drivers\\',
            'App\\Services\\Ai\\AiGatewayService',
            'App\\Services\\Ai\\AiWorker',
            'ClaudeCliProvider',
            'CodexCliProvider',
            'GeminiCliProvider',
            'ProviderDriverRegistry',
            'provider->execute(',
            'prepareRequest(',
            'Process::run(',
            'Process::start(',
            'exec(',
            'shell_exec(',
        ]) as $violation) {
            $violations[] = $violation;
        }

        $orchestratorPath = $repairPath.DIRECTORY_SEPARATOR.'AtlasRepairOrchestrator.php';
        $orchestrator = $this->primitives->fileContents($orchestratorPath);

        $violations = array_merge($violations, $this->primitives->missingTokenViolations($orchestrator, [
            'public function plan(RepairRequest $request): RepairDecision',
            'public function attempt(RepairRequest $request): RepairResult',
            'public function complianceReport(): array',
            'LedgerEventType::RepairInitiated',
            'LedgerEventType::RepairCompleted',
            'executed: false',
            'RepairReason::ExecutionBlockedByDryRun',
            'RepairReason::ExecutionNotImplementedContractFoundationOnly',
            "'execution_enabled' => false",
        ], 'app/Services/Ai/Kernel/Repair/AtlasRepairOrchestrator.php: repair loop must remain scaffold-safe'));

        $commandPath = app_path('Console/Commands/AtlasAiRepairCommand.php');
        $controllerPath = app_path('Http/Controllers/AtlasAiRepairController.php');
        $command = PeeledSource::read($commandPath);
        $controller = PeeledSource::read($controllerPath);
        $routes = RoutesApiSource::read();

        $violations = array_merge($violations, $this->primitives->missingTokenViolations($command, [
            'AtlasRepairOrchestrator',
            'atlas:ai:repair',
            '{--attempt-repair',
            'dryRun: true',
            'AtlasEvidenceLedger',
            'recordRepairDecision(',
            'recordRepairResult(',
            "'evidence_ledger' => \$this->ledgerEventPayload(\$ledgerEvent)",
            "'completed' => \$this->ledgerEventPayload(\$completedLedgerEvent)",
            "'status' => 'planned_scaffold'",
            "'status' => 'attempted_scaffold'",
            "'compliance' => \$repair->complianceReport()",
        ], 'app/Console/Commands/AtlasAiRepairCommand.php: missing safe repair CLI contract'));

        $violations = array_merge($violations, $this->primitives->missingTokenViolations($controller, [
            'AtlasRepairOrchestrator',
            'RepairRequest::fromArray',
            'AtlasEvidenceLedger',
            'recordRepairDecision(',
            'recordRepairResult(',
            "'dry_run' => true",
            "'endpoint' => 'POST /ai/repair'",
            "'evidence_ledger' => \$this->ledgerEventPayload(\$ledgerEvent)",
            "'completed' => \$this->ledgerEventPayload(\$completedLedgerEvent)",
            "'status' => 'planned_scaffold'",
            "'status' => 'attempted_scaffold'",
            "'compliance' => \$repair->complianceReport()",
        ], 'app/Http/Controllers/AtlasAiRepairController.php: missing safe repair API contract'));

        if (! str_contains($routes, 'AtlasAiRepairController') || ! str_contains($routes, "Route::post('/ai/repair', AtlasAiRepairController::class);")) {
            $violations[] = 'routes/api.php: POST /ai/repair must be registered inside the atlas.token API group';
        }

        $worker = $this->primitives->aiWorkerImplementationCorpus();
        $violations = array_merge($violations, $this->primitives->missingTokenViolations($worker, [
            'AtlasRepairOrchestrator',
            'RepairRequestFactory',
            'nativeProgrammingRepairKernelDecision(',
            'nativeProgrammingRepairEvidenceRefs(',
            '$this->repairOrchestrator->plan($request)',
            "'kernel_repair' => \$kernelRepairDecision?->toArray()",
            "'kernel_decision' => \$kernelRepairDecision?->toArray()",
            'kernel_repair_contract_blocks',
        ], 'app/Services/Ai/AiWorker.php: native programming repair must pass through kernel repair contract'));

        $programmingPath = app_path('Services/Ai/Programming/AtlasProgrammingOrchestrator.php');
        $programming = PeeledSource::read($programmingPath);
        $violations = array_merge($violations, $this->primitives->missingTokenViolations($programming, [
            'RepairStrategy',
            "\$plan['repair_execution_contract'] = \$this->repairExecutionContract(\$plan);",
            "'kernel_repair_contract' => [",
            "'orchestrator' => 'AtlasRepairOrchestrator'",
            "'request_factory' => 'RepairRequestFactory'",
            "'decision_required_before_enqueue' => true",
            "'blocks_when_kernel_blocks' => true",
            "'allowed_strategies' => RepairStrategy::values()",
            "'requires_evidence_for_heavy_repair' => true",
        ], 'app/Services/Ai/Programming/AtlasProgrammingOrchestrator.php: programming repair contract must declare kernel repair policy'));

        $harnessPath = app_path('Services/Engineering/EngineeringHarnessExecutionService.php');
        $harness = PeeledSource::read($harnessPath);
        $violations = array_merge($violations, $this->primitives->missingTokenViolations($harness, [
            'AtlasRepairOrchestrator',
            'RepairRequestFactory',
            'AtlasEvidenceLedger',
            'withKernelRepairDecision(',
            'kernelRepairDecision(',
            'recordRepairDecision($decision',
            '$this->repairOrchestrator->plan($repairRequest)',
            "\$result['kernel_repair_decision'] = \$decision->toArray();",
            "'orchestrator' => 'AtlasRepairOrchestrator'",
            "'decision_required_before_enqueue' => true",
            "'blocks_when_kernel_blocks' => true",
            "'executor' => 'engineering_harness'",
        ], 'app/Services/Engineering/EngineeringHarnessExecutionService.php: engineering harness failures must attach kernel repair decisions'));

        $ledgerPath = app_path('Services/Ai/Kernel/Evidence/AtlasEvidenceLedger.php');
        $ledger = PeeledSource::read($ledgerPath);
        $violations = array_merge($violations, $this->primitives->missingTokenViolations($ledger, [
            'public function recordRepairDecision(RepairDecision $decision',
            'public function recordRepairResult(RepairResult $result',
            'LedgerEventType::RepairInitiated',
            'LedgerEventType::RepairCompleted',
            "'emitter_stage' => \$context['emitter_stage'] ?? 'atlas.repair'",
            "'causation_id' => \$context['causation_id'] ?? data_get(\$result->decision->evidencePayload, 'decision_hash')",
            "'repair_executed' => false",
        ], 'app/Services/Ai/Kernel/Evidence/AtlasEvidenceLedger.php: repair decisions must be recordable as canonical evidence'));

        $replayPath = app_path('Services/Ai/Kernel/Evidence/AtlasLedgerReplayService.php');
        $ledgerCommandPath = app_path('Console/Commands/AtlasAiLedgerCommand.php');
        $ledgerControllerPath = app_path('Http/Controllers/AtlasAiLedgerController.php');
        $ledgerReportPath = app_path('Services/Ai/Kernel/Evidence/KernelLedgerEnvelopeReportService.php');
        $repairReportCommandPath = app_path('Console/Commands/AtlasAiRepairReportCommand.php');
        $repairReportControllerPath = app_path('Http/Controllers/AtlasAiRepairReportController.php');
        $selfImprovementCommandPath = app_path('Console/Commands/AtlasAiSelfImproveCommand.php');
        $selfImprovementSchedulePath = app_path('Services/Ai/SelfImprovement/AtlasSelfImprovementScheduleService.php');
        $selfImprovementScheduleControllerPath = app_path('Http/Controllers/AtlasAiSelfImprovementScheduleController.php');
        $selfImprovementScheduleHealthControllerPath = app_path('Http/Controllers/AtlasAiSelfImprovementScheduleHealthController.php');
        $bootstrapPath = base_path('bootstrap/app.php');
        $replay = PeeledSource::read($replayPath);
        $ledgerCommand = PeeledSource::read($ledgerCommandPath);
        $ledgerController = PeeledSource::read($ledgerControllerPath);
        $ledgerReport = PeeledSource::read($ledgerReportPath);
        $repairReportCommand = PeeledSource::read($repairReportCommandPath);
        $repairReportController = PeeledSource::read($repairReportControllerPath);
        $selfImprovementCommand = PeeledSource::read($selfImprovementCommandPath);
        $selfImprovementSchedule = PeeledSource::read($selfImprovementSchedulePath);
        $selfImprovementScheduleController = PeeledSource::read($selfImprovementScheduleControllerPath);
        $selfImprovementScheduleHealthController = PeeledSource::read($selfImprovementScheduleHealthControllerPath);
        $bootstrap = $this->primitives->fileContents($bootstrapPath);
        $violations = array_merge($violations, $this->primitives->missingTokenViolations($replay, [
            'public function repairReportForEnvelope(string $envelopeId): array',
            'public function repairReportForWindow(CarbonInterface $since, ?CarbonInterface $until = null, array $filters = []): array',
            'normalizedRepairFilters(',
            'matchesRepairFilters(',
            'LedgerEventType::RepairInitiated',
            'LedgerEventType::RepairCompleted',
            'requires_human_review',
            'repairEventFromEvent(',
        ], 'app/Services/Ai/Kernel/Evidence/AtlasLedgerReplayService.php: repair events must be projectable from ledger replay'));
        $violations = array_merge($violations, $this->primitives->missingTokenViolations($ledgerCommand, [
            '{--repair : Include Repair Loop summary for the envelope}',
            'KernelLedgerEnvelopeReportService $reports',
            'includeRepair: (bool) $this->option(\'repair\')',
        ], 'app/Console/Commands/AtlasAiLedgerCommand.php: ledger CLI must expose repair replay summary'));
        $violations = array_merge($violations, $this->primitives->missingTokenViolations($ledgerController, [
            "'repair' => ['nullable', 'boolean']",
            'KernelLedgerEnvelopeReportService $reports',
            'includeRepair: (bool) ($filters[\'repair\'] ?? false)',
        ], 'app/Http/Controllers/AtlasAiLedgerController.php: ledger API must expose repair replay summary'));
        $violations = array_merge($violations, $this->primitives->missingTokenViolations($ledgerReport, [
            'repairReportForEnvelope($envelopeId)',
            "\$payload['repair']",
        ], 'app/Services/Ai/Kernel/Evidence/KernelLedgerEnvelopeReportService.php: shared ledger report must expose repair replay summary'));
        $violations = array_merge($violations, $this->primitives->missingTokenViolations($repairReportCommand, [
            'atlas:ai:repair-report',
            'repairReportForWindow(',
            '{--status= : Filter by repair decision status}',
            '{--strategy= : Filter by repair strategy}',
            '{--failure-domain= : Filter by failure domain}',
            "'kernel_repair' => \$report",
            'ledger_unavailable',
        ], 'app/Console/Commands/AtlasAiRepairReportCommand.php: dedicated Repair Loop report CLI must expose window projection'));
        $violations = array_merge($violations, $this->primitives->missingTokenViolations($repairReportController, [
            'AtlasAiRepairReportController',
            'repairReportForWindow(',
            "'status' => ['nullable', 'string', 'max:80']",
            "'strategy' => ['nullable', 'string', 'max:120']",
            "'failure_domain' => ['nullable', 'string', 'max:160']",
            "'kernel_repair' => \$report",
            'ledger_unavailable',
        ], 'app/Http/Controllers/AtlasAiRepairReportController.php: dedicated Repair Loop report API must expose window projection'));
        if (! str_contains($routes, 'AtlasAiRepairReportController') || ! str_contains($routes, "Route::get('/ai/repair/report', AtlasAiRepairReportController::class);")) {
            $violations[] = 'routes/api.php: GET /ai/repair/report must be registered inside the atlas.token API group';
        }

        $violations = array_merge($violations, $this->primitives->missingTokenViolations($selfImprovementCommand, [
            'AtlasSelfImprovementScheduleService',
            '{--schedule-plan : Print the recurring self-improvement schedule plan}',
            '{--schedule-health : Print the compact recurring self-improvement schedule health}',
            '{--fail-on-schedule-warning : Return a non-zero exit code when the recurring schedule health is not healthy}',
            'renderSchedulePlan(',
            'renderScheduleHealth(',
            'Scheduler registration',
            'Registered commands',
            'Skipped reason',
            'schedulePlanExitCode(',
        ], 'app/Console/Commands/AtlasAiSelfImproveCommand.php: Self-Improvement schedule plan must be inspectable from CLI'));
        $violations = array_merge($violations, $this->primitives->missingTokenViolations($selfImprovementSchedule, [
            'schedulePlan()',
            'scheduleHealth()',
            'scheduledCommands()',
            "'schedulable'",
            'isSchedulable(',
            "'scheduler_registration'",
            'schedulerRegistrationForPlan(',
            "'registered_command_count'",
            "'skipped_reason'",
            "'plan_hash'",
            "'plan_hash_algorithm'",
            'planHash(',
            'canonicalize(',
            "'timezone'",
            "'next_run_at'",
            "'invalid_flows'",
            "'defaulted'",
            "'health'",
            'self_improvement_schedule_disabled',
            'invalid_self_improvement_flows_configured',
            'invalid_self_improvement_schedule_time',
            'invalid_self_improvement_schedule_timezone',
            'isValidTimezone(',
            "'nightly_review'",
            "'repair_loop_review'",
            "'kernel_pipeline_review'",
            'SUPPORTED_FLOWS',
        ], 'app/Services/Ai/SelfImprovement/AtlasSelfImprovementScheduleService.php: recurring Self-Improvement schedule must be centralized and include Repair Loop review by default'));
        $violations = array_merge($violations, $this->primitives->missingTokenViolations($selfImprovementScheduleController, [
            'AtlasAiSelfImprovementScheduleController',
            'AtlasSelfImprovementScheduleService',
            'schedulePlan()',
        ], 'app/Http/Controllers/AtlasAiSelfImprovementScheduleController.php: Self-Improvement schedule plan must be inspectable from API'));
        if (! str_contains($routes, 'AtlasAiSelfImprovementScheduleController') || ! str_contains($routes, "Route::get('/ai/self-improvement/schedule', AtlasAiSelfImprovementScheduleController::class);")) {
            $violations[] = 'routes/api.php: GET /ai/self-improvement/schedule must be registered inside the atlas.token API group';
        }
        $violations = array_merge($violations, $this->primitives->missingTokenViolations($selfImprovementScheduleHealthController, [
            'AtlasAiSelfImprovementScheduleHealthController',
            'AtlasSelfImprovementScheduleService',
            'scheduleHealth()',
        ], 'app/Http/Controllers/AtlasAiSelfImprovementScheduleHealthController.php: Self-Improvement schedule health must be inspectable from API'));
        if (! str_contains($routes, 'AtlasAiSelfImprovementScheduleHealthController') || ! str_contains($routes, "Route::get('/ai/self-improvement/schedule/health', AtlasAiSelfImprovementScheduleHealthController::class);")) {
            $violations[] = 'routes/api.php: GET /ai/self-improvement/schedule/health must be registered inside the atlas.token API group';
        }
        $violations = array_merge($violations, $this->primitives->missingTokenViolations($bootstrap, [
            'AtlasSelfImprovementScheduleService::class',
            'scheduledCommands()',
            "->dailyAt(\$selfImprovementCommand['time'])",
            "->timezone(\$selfImprovementCommand['timezone'])",
            '->withoutOverlapping()',
        ], 'bootstrap/app.php: recurring Self-Improvement scheduler registration must use the centralized schedule contract'));

        $selfImprovementPath = app_path('Services/Ai/SelfImprovement/AtlasSelfImprovementRuntime.php');
        $selfImprovement = PeeledSource::read($selfImprovementPath);
        $violations = array_merge($violations, $this->primitives->missingTokenViolations($selfImprovement, [
            'repairLoopFindings(',
            'repairReportForWindow(',
            'kernelPipelineFindings(',
            'kernelPipelineReportForWindow(',
            'normalizedRepairFilters(',
            'normalizedKernelPipelineFilters(',
            "'self-improvement:repair-loop:'",
            "'self-improvement:kernel-pipeline:'",
            'requires_human_review',
            'has_rejections',
            'RepairInitiated',
            'RepairCompleted',
            'KernelPipelineAccepted',
            'KernelPipelineRejected',
            'AtlasAiDomainCatalogService',
            'domainOnboardingFindings(',
            'normalizedDomainOnboardingFilters(',
            "'onboarding_status' => 'onboarding_status'",
            "'self-improvement:domain-onboarding:'",
            "'type' => 'domain_catalog'",
            'executable_incomplete_domains',
            'scaffold_domains',
        ], 'app/Services/Ai/SelfImprovement/AtlasSelfImprovementRuntime.php: Self-Improvement must consume Repair Loop, Kernel Pipeline, and Domain Catalog onboarding evidence'));

        return $violations;
    }

    /**
     * @return array<int,string>
     */
    private function scanRepairLoopReviewSignal(): array
    {
        $replayPath = app_path('Services/Ai/Kernel/Evidence/AtlasLedgerReplayService.php');
        $runtimePath = app_path('Services/Ai/SelfImprovement/AtlasSelfImprovementRuntime.php');
        $reportCommandPath = app_path('Console/Commands/AtlasAiRepairReportCommand.php');
        $ledgerCommandPath = app_path('Console/Commands/AtlasAiLedgerCommand.php');
        $unitTestPath = base_path('tests/Unit/Ai/Kernel/LedgerReplayServiceTest.php');
        $runtimeTestPath = base_path('tests/Feature/Ai/AtlasSelfImprovementRuntimeTest.php');
        $commandTestPath = base_path('tests/Feature/Ai/AtlasAiRepairReportCommandTest.php');
        $apiTestPath = base_path('tests/Feature/Ai/AtlasAiRepairReportApiTest.php');
        $observabilityTestPath = base_path('tests/Feature/Ai/AiObservabilityKernelSloTest.php');
        $domainDocsPath = base_path('docs/engineering-knowledge-base/domains/self-improvement.md');
        $kernelDocsPath = base_path('docs/engineering-knowledge-base/atlas-ai-kernel-architecture.md');
        $violations = [];

        $replay = PeeledSource::read($replayPath);
        $runtime = PeeledSource::read($runtimePath);
        $reportCommand = PeeledSource::read($reportCommandPath);
        $ledgerCommand = PeeledSource::read($ledgerCommandPath);
        $unitTest = File::exists($unitTestPath) ? File::get($unitTestPath) : '';
        $runtimeTest = File::exists($runtimeTestPath) ? File::get($runtimeTestPath) : '';
        $commandTest = File::exists($commandTestPath) ? File::get($commandTestPath) : '';
        $apiTest = File::exists($apiTestPath) ? File::get($apiTestPath) : '';
        $observabilityTest = File::exists($observabilityTestPath) ? File::get($observabilityTestPath) : '';
        $domainDocs = $this->primitives->selfImprovementDomainDocumentationCorpus();
        $kernelDocs = $this->primitives->kernelDocumentationCorpus();

        foreach ([
            '$reviewSignal = $this->repairReviewSignal($events, $statusCounts, $strategyCounts, $reasonCounts, $requiresHumanReview)',
            "'review_signal' => \$reviewSignal",
            'private function repairReviewSignal(Collection $events, array $statusCounts, array $strategyCounts, array $reasonCounts, bool $requiresHumanReview): array',
            "'recommended_action' => 'open_reviewable_repair_loop_human_review_proposal'",
            "'recommended_action' => 'open_reviewable_repair_loop_policy_proposal'",
        ] as $token) {
            if (! str_contains($replay, $token)) {
                $violations[] = "app/Services/Ai/Kernel/Evidence/AtlasLedgerReplayService.php: Repair Loop replay must publish canonical review_signal [{$token}]";
            }
        }

        foreach ([
            "'review_signal' => \$report['review_signal'] ?? []",
        ] as $token) {
            if (! str_contains($runtime, $token)) {
                $violations[] = "app/Services/Ai/SelfImprovement/AtlasSelfImprovementRuntime.php: Self-Improvement must propagate Repair Loop review_signal [{$token}]";
            }
        }

        foreach ([
            "data_get(\$repair, 'review_signal.status'",
            "data_get(\$repair, 'review_signal.severity'",
            "data_get(\$repair, 'review_signal.recommended_action'",
        ] as $token) {
            if (! str_contains($reportCommand, $token)) {
                $violations[] = "app/Console/Commands/AtlasAiRepairReportCommand.php: Repair report CLI must render review_signal [{$token}]";
            }
        }

        foreach ([
            "data_get(\$repair, 'review_signal.status'",
            "data_get(\$repair, 'review_signal.severity'",
        ] as $token) {
            if (! str_contains($ledgerCommand, $token)) {
                $violations[] = "app/Console/Commands/AtlasAiLedgerCommand.php: Ledger CLI repair summary must render review_signal [{$token}]";
            }
        }

        foreach ([
            "data_get(\$report, 'review_signal.status')",
            "data_get(\$report, 'review_signal.severity')",
            "data_get(\$report, 'review_signal.recommended_action')",
        ] as $token) {
            if (! str_contains($unitTest, $token)) {
                $violations[] = "tests/Unit/Ai/Kernel/LedgerReplayServiceTest.php: Repair Loop review_signal must be covered [{$token}]";
            }
        }

        foreach ([
            "data_get(\$finding, 'metadata.review_signal.status')",
            "data_get(\$finding, 'metadata.review_signal.severity')",
            "data_get(\$finding, 'metadata.review_signal.recommended_action')",
        ] as $token) {
            if (! str_contains($runtimeTest, $token)) {
                $violations[] = "tests/Feature/Ai/AtlasSelfImprovementRuntimeTest.php: Curator Repair Loop review_signal propagation must be covered [{$token}]";
            }
        }

        foreach ([
            "data_get(\$payload, 'kernel_repair.review_signal.status')",
            'test_command_human_output_includes_repair_review_signal',
        ] as $token) {
            if (! str_contains($commandTest, $token)) {
                $violations[] = "tests/Feature/Ai/AtlasAiRepairReportCommandTest.php: CLI Repair Loop review_signal must be covered [{$token}]";
            }
        }

        foreach ([
            "kernel_repair.review_signal.status', 'warning'",
            "kernel_repair.review_signal.severity', 'medium'",
            "kernel_repair.review_signal.recommended_action', 'open_reviewable_repair_loop_human_review_proposal'",
        ] as $token) {
            if (! str_contains($apiTest, $token) || ! str_contains($observabilityTest, $token)) {
                $violations[] = "tests/Feature/Ai: API and Observability Repair Loop review_signal must be covered [{$token}]";
            }
        }

        foreach ([
            'repair loop review_signal',
            'AP55',
        ] as $token) {
            if (! str_contains($domainDocs, $token) && ! str_contains($kernelDocs, $token)) {
                $violations[] = "docs/engineering-knowledge-base: docs must describe Repair Loop review_signal [{$token}]";
            }
        }

        return $violations;
    }

    /**
     * @return array<int,string>
     */
    private function scanRepairLoopMcpTool(): array
    {
        $mcpPath = app_path('Services/Ai/AtlasOpenBrainMcpService.php');
        $testPath = base_path('tests/Feature/Ai/AtlasOpenBrainMcpServiceTest.php');
        $docsPath = base_path('docs/engineering-knowledge-base/atlas-ai-kernel-architecture.md');
        $memoryDocsPath = base_path('docs/engineering-knowledge-base/atlas-ai-memory-context-core-open-brain.md');
        $violations = [];

        $reportToolsPath = app_path('Services/Ai/OpenBrainMcp/ReportTools.php');

        $mcp = PeeledSource::read($mcpPath);
        $reportTools = PeeledSource::read($reportToolsPath);
        $test = File::exists($testPath) ? File::get($testPath) : '';
        $docs = $this->primitives->kernelDocumentationCorpus();
        $memoryDocs = File::exists($memoryDocsPath) ? File::get($memoryDocsPath) : '';

        // Façade keeps the tools() schema + dispatch; the handler was relocated under
        // GOD-DEBULK D3 to OpenBrainMcp/ReportTools (invariant unchanged).
        foreach ([
            "'name' => 'atlas_repair_loop_report'",
            "'atlas_repair_loop_report' => \$this->toolResponse(\$id, \$this->reportTools->repairLoopReport(\$arguments))",
            "'writes' => false",
        ] as $token) {
            if (! str_contains($mcp, $token)) {
                $violations[] = "app/Services/Ai/AtlasOpenBrainMcpService.php: MCP must expose read-only atlas_repair_loop_report [{$token}]";
            }
        }

        foreach ([
            'public function repairLoopReport(array $arguments): array',
            '$this->ledgerReplay->repairReportForWindow(now()->subHours($hours), null, $filters)',
            "'kernel_repair' => \$report",
        ] as $token) {
            if (! str_contains($reportTools, $token)) {
                $violations[] = "app/Services/Ai/OpenBrainMcp/ReportTools.php: MCP must expose read-only atlas_repair_loop_report [{$token}]";
            }
        }

        foreach ([
            'test_repair_loop_report_tool_exposes_replay_read_model',
            "'name' => 'atlas_repair_loop_report'",
            "data_get(\$structured, 'kernel_repair.review_signal.status')",
            'open_reviewable_repair_loop_human_review_proposal',
        ] as $token) {
            if (! str_contains($test, $token)) {
                $violations[] = "tests/Feature/Ai/AtlasOpenBrainMcpServiceTest.php: MCP Repair Loop report tool must be covered [{$token}]";
            }
        }

        foreach ([
            'atlas_repair_loop_report',
            'AP59',
        ] as $token) {
            if (! str_contains($docs, $token) && ! str_contains($memoryDocs, $token)) {
                $violations[] = "docs/engineering-knowledge-base: docs must describe MCP Repair Loop report tool [{$token}]";
            }
        }

        return $violations;
    }

    /**
     * @return array<int,string>
     */
    private function scanRepairLoopUnavailableReviewSignal(): array
    {
        $replayPath = app_path('Services/Ai/Kernel/Evidence/AtlasLedgerReplayService.php');
        $commandTestPath = base_path('tests/Feature/Ai/AtlasAiRepairReportCommandTest.php');
        $apiTestPath = base_path('tests/Feature/Ai/AtlasAiRepairReportApiTest.php');
        $docsPath = base_path('docs/engineering-knowledge-base/atlas-ai-kernel-architecture.md');
        $violations = [];

        $replay = PeeledSource::read($replayPath);
        $commandTest = File::exists($commandTestPath) ? File::get($commandTestPath) : '';
        $apiTest = File::exists($apiTestPath) ? File::get($apiTestPath) : '';
        $docs = $this->primitives->kernelDocumentationCorpus();

        foreach ([
            "...array_diff_key(\$this->repairEventSummary(collect()), ['events' => true])",
            "'recommended_action' => 'wait_for_repair_loop_evidence'",
        ] as $token) {
            if (! str_contains($replay, $token)) {
                $violations[] = "app/Services/Ai/Kernel/Evidence/AtlasLedgerReplayService.php: Repair Loop unavailable payload must preserve canonical review_signal [{$token}]";
            }
        }

        foreach ([
            "data_get(\$payload, 'kernel_repair.review_signal.status')",
            "data_get(\$payload, 'kernel_repair.review_signal.recommended_action')",
            'wait_for_repair_loop_evidence',
        ] as $token) {
            if (! str_contains($commandTest, $token)) {
                $violations[] = "tests/Feature/Ai/AtlasAiRepairReportCommandTest.php: Repair Loop CLI unavailable review_signal must be covered [{$token}]";
            }
        }

        foreach ([
            "assertJsonPath('kernel_repair.review_signal.status', 'unknown')",
            "assertJsonPath('kernel_repair.review_signal.recommended_action', 'wait_for_repair_loop_evidence')",
        ] as $token) {
            if (! str_contains($apiTest, $token)) {
                $violations[] = "tests/Feature/Ai/AtlasAiRepairReportApiTest.php: Repair Loop API unavailable review_signal must be covered [{$token}]";
            }
        }

        foreach ([
            'repair loop unavailable review_signal',
            'AP60',
        ] as $token) {
            if (! str_contains($docs, $token)) {
                $violations[] = "docs/engineering-knowledge-base/atlas-ai-kernel-architecture.md: docs must describe Repair Loop unavailable review_signal parity [{$token}]";
            }
        }

        return $violations;
    }
}
