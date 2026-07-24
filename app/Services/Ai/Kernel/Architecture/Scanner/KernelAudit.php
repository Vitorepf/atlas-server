<?php

namespace App\Services\Ai\Kernel\Architecture\Scanner;

use Illuminate\Support\Facades\File;

class KernelAudit
{
    public function __construct(private ScanPrimitivesSupport $primitives) {}

    /**
     * @return array<string,callable(): array<int,string>>
     */
    public function checks(): array
    {
        return [
            'ap17_kernel_pipeline_contract' => fn (): array => $this->scanKernelPipelineContract(),
            'ap28_kernel_model_selection_contract_factory' => fn (): array => $this->scanKernelModelSelectionContractFactory(),
            'ap36_kernel_pipeline_health_read_model' => fn (): array => $this->scanKernelPipelineHealthReadModel(),
            'ap54_kernel_pipeline_review_signal' => fn (): array => $this->scanKernelPipelineReviewSignal(),
            'ap58_kernel_pipeline_mcp_tool' => fn (): array => $this->scanKernelPipelineMcpTool(),
        ];
    }

    /**
     * @return array<int,string>
     */
    private function scanKernelPipelineContract(): array
    {
        $pipelinePath = app_path('Services/Ai/Kernel/Pipeline');
        $requiredFiles = [
            'AtlasKernelPipeline.php',
            'KernelPipelineContract.php',
            'KernelPipelineStage.php',
            'PipelineInput.php',
            'PipelineStageDefinition.php',
            'PipelineStageResult.php',
            'PipelineExecutionResult.php',
            'ScaffoldAtlasKernelPipeline.php',
            'KernelPipelineAuditService.php',
            'KernelPipelineDevPlanBuilder.php',
            'KernelPipelinePlanGuard.php',
            'KernelPipelinePlanViolation.php',
            'KernelPipelineRuntimeGuard.php',
        ];
        $violations = [];

        if (! File::isDirectory($pipelinePath)) {
            return ["missing kernel pipeline directory [{$pipelinePath}]"];
        }

        foreach ($requiredFiles as $file) {
            if (! File::exists($pipelinePath.DIRECTORY_SEPARATOR.$file)) {
                $violations[] = "app/Services/Ai/Kernel/Pipeline/{$file}: missing pipeline contract file";
            }
        }

        $forbidden = $this->primitives->scanPhpFilesForForbiddenTokens($pipelinePath, [
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
        ]);

        foreach ($forbidden as $violation) {
            $violations[] = $violation;
        }

        $directLedgerWriters = [
            ...$this->primitives->scanPhpFilesForForbiddenTokens(app_path('Console/Commands'), [
                'recordKernelPipelineAccepted(',
                'recordKernelPipelineRejected(',
            ]),
            ...$this->primitives->scanPhpFilesForForbiddenTokens(app_path('Http/Controllers'), [
                'recordKernelPipelineAccepted(',
                'recordKernelPipelineRejected(',
            ]),
        ];

        foreach ($directLedgerWriters as $violation) {
            $violations[] = 'Kernel Pipeline accepted/rejected events must be emitted through KernelPipelineAuditService: '.$violation;
        }

        $stagePath = $pipelinePath.DIRECTORY_SEPARATOR.'KernelPipelineStage.php';
        $pipelinePathname = $pipelinePath.DIRECTORY_SEPARATOR.'ScaffoldAtlasKernelPipeline.php';
        $auditPath = $pipelinePath.DIRECTORY_SEPARATOR.'KernelPipelineAuditService.php';
        $devPlanBuilderPath = $pipelinePath.DIRECTORY_SEPARATOR.'KernelPipelineDevPlanBuilder.php';
        $contractPath = $pipelinePath.DIRECTORY_SEPARATOR.'AtlasKernelPipeline.php';
        $guardPath = $pipelinePath.DIRECTORY_SEPARATOR.'KernelPipelinePlanGuard.php';
        $runtimeGuardPath = $pipelinePath.DIRECTORY_SEPARATOR.'KernelPipelineRuntimeGuard.php';
        $ledgerPath = app_path('Services/Ai/Kernel/Evidence/AtlasEvidenceLedger.php');
        $replayPath = app_path('Services/Ai/Kernel/Evidence/AtlasLedgerReplayService.php');
        $atlasCliDevCommandPath = app_path('Console/Commands/AtlasCliDevCommand.php');
        $aiChatCommandPath = app_path('Console/Commands/AiChatCommand.php');
        $pipelineCommandPath = app_path('Console/Commands/AtlasAiPipelineCommand.php');
        $pipelineControllerPath = app_path('Http/Controllers/AtlasAiPipelineController.php');
        $ledgerCommandPath = app_path('Console/Commands/AtlasAiLedgerCommand.php');
        $ledgerControllerPath = app_path('Http/Controllers/AtlasAiLedgerController.php');
        $ledgerReportPath = app_path('Services/Ai/Kernel/Evidence/KernelLedgerEnvelopeReportService.php');
        $ledgerReportPath = app_path('Services/Ai/Kernel/Evidence/KernelLedgerEnvelopeReportService.php');
        $pipelineReportCommandPath = app_path('Console/Commands/AtlasAiKernelPipelineReportCommand.php');
        $pipelineReportControllerPath = app_path('Http/Controllers/AtlasAiKernelPipelineReportController.php');
        $observabilityPath = app_path('Http/Controllers/AiObservabilityController.php');
        $selfImprovementPath = app_path('Services/Ai/SelfImprovement/AtlasSelfImprovementRuntime.php');
        $routesPath = base_path('routes/api.php');
        $stageContents = File::exists($stagePath) ? File::get($stagePath) : '';
        $pipelineContents = File::exists($pipelinePathname) ? File::get($pipelinePathname) : '';
        $auditContents = File::exists($auditPath) ? File::get($auditPath) : '';
        $devPlanBuilderContents = File::exists($devPlanBuilderPath) ? File::get($devPlanBuilderPath) : '';
        $contractContents = File::exists($contractPath) ? File::get($contractPath) : '';
        $guardContents = File::exists($guardPath) ? File::get($guardPath) : '';
        $runtimeGuardContents = File::exists($runtimeGuardPath) ? File::get($runtimeGuardPath) : '';
        $ledgerContents = File::exists($ledgerPath) ? File::get($ledgerPath) : '';
        $replayContents = File::exists($replayPath) ? File::get($replayPath) : '';
        $atlasCliDevCommandContents = File::exists($atlasCliDevCommandPath) ? File::get($atlasCliDevCommandPath) : '';
        $aiChatCommandContents = File::exists($aiChatCommandPath) ? File::get($aiChatCommandPath) : '';
        $pipelineCommandContents = File::exists($pipelineCommandPath) ? File::get($pipelineCommandPath) : '';
        $pipelineControllerContents = File::exists($pipelineControllerPath) ? File::get($pipelineControllerPath) : '';
        $ledgerCommandContents = File::exists($ledgerCommandPath) ? File::get($ledgerCommandPath) : '';
        $ledgerControllerContents = File::exists($ledgerControllerPath) ? File::get($ledgerControllerPath) : '';
        $ledgerReportContents = File::exists($ledgerReportPath) ? File::get($ledgerReportPath) : '';
        $pipelineReportCommandContents = File::exists($pipelineReportCommandPath) ? File::get($pipelineReportCommandPath) : '';
        $pipelineReportControllerContents = File::exists($pipelineReportControllerPath) ? File::get($pipelineReportControllerPath) : '';
        $observabilityContents = File::exists($observabilityPath) ? File::get($observabilityPath) : '';
        $selfImprovementContents = File::exists($selfImprovementPath) ? File::get($selfImprovementPath) : '';
        $routesContents = File::exists($routesPath) ? File::get($routesPath) : '';

        foreach (['Input', 'OperationEnvelope', 'Intent', 'Decide', 'DecisionReceipt', 'Domain', 'Context', 'Policy', 'Runtime', 'Gate', 'Repair', 'Evidence', 'Learning', 'Output'] as $case) {
            if (! str_contains($stageContents, "case {$case}")) {
                $violations[] = "app/Services/Ai/Kernel/Pipeline/KernelPipelineStage.php: missing canonical stage case [{$case}]";
            }
        }

        foreach (['stages(): array', 'plan(PipelineInput $input): array', 'execute(PipelineInput $input): PipelineExecutionResult', 'complianceReport(): array'] as $contract) {
            if (! str_contains($contractContents, $contract)) {
                $violations[] = "app/Services/Ai/Kernel/Pipeline/AtlasKernelPipeline.php: missing contract method [{$contract}]";
            }
        }

        foreach ([
            'public static function programmingSurfaces(): array',
            'public static function programmingFlows(): array',
            'public static function programmingInputModes(): array',
            'public static function programmingCommands(): array',
            'public static function surfaceContractSources(): array',
            'public static function requiredSurfaceContract(string $source): array',
            "'atlas_cli_forge'",
        ] as $token) {
            if (! str_contains(File::exists($pipelinePath.DIRECTORY_SEPARATOR.'KernelPipelineContract.php') ? File::get($pipelinePath.DIRECTORY_SEPARATOR.'KernelPipelineContract.php') : '', $token)) {
                $violations[] = "app/Services/Ai/Kernel/Pipeline/KernelPipelineContract.php: programming pipeline allowlists must live in the kernel contract [{$token}]";
            }
        }

        foreach ([
            "'provider_execution_allowed' => false",
            "'runtime_execution_allowed' => false",
            'providerExecutionAttempted: false',
            "'slot_flow_valid'",
            "'schema_version' => KernelPipelineContract::SCHEMA_VERSION",
            "'execution_guards'",
            'KernelPipelineContract::executionGuards($input->dryRun)',
            'planHash:',
        ] as $token) {
            if (! str_contains($pipelineContents, $token)) {
                $violations[] = "app/Services/Ai/Kernel/Pipeline/ScaffoldAtlasKernelPipeline.php: scaffold pipeline must keep real execution disabled [{$token}]";
            }
        }

        foreach ([
            'KernelPipelinePlanViolation',
            'KernelPipelineContract::canonicalFlowHash()',
            'KernelPipelineStage::orderedValues()',
            'KernelPipelineContract::programmingSurfaces()',
            'KernelPipelineContract::programmingFlows()',
            'KernelPipelineContract::programmingInputModes()',
            'KernelPipelineContract::programmingCommands()',
            'KernelPipelineContract::surfaceContractSources()',
            'validateSurfaceContract(',
            'validatePlanAndContract(',
            'assertValidPlanAndContract(',
            'kernel_pipeline_contract.required must be true.',
            "'provider_execution_allowed'",
            "'runtime_execution_allowed'",
        ] as $token) {
            if (! str_contains($guardContents, $token)) {
                $violations[] = "app/Services/Ai/Kernel/Pipeline/KernelPipelinePlanGuard.php: surface kernel pipeline plans must fail closed before enqueue [{$token}]";
            }
        }

        foreach ([
            'class KernelPipelineRuntimeGuard',
            'public function violationForJob(AiJob $job): ?array',
            'pipelinePlanForJob(',
            'surfaceContractForJob(',
            'auditablePlanForJob(',
            'auditContextForJob(',
            'requiresKernelPipelineContract(',
            '$this->guard->validatePlanAndContract($plan, $contract)',
            "'error_code' => 'kernel_pipeline_contract_violation'",
            "'source' => 'KernelPipelineRuntimeGuard'",
            "'emitter_stage' => 'atlas.ai_worker.kernel_pipeline_runtime_guard'",
        ] as $token) {
            if (! str_contains($runtimeGuardContents, $token)) {
                $violations[] = "app/Services/Ai/Kernel/Pipeline/KernelPipelineRuntimeGuard.php: worker runtime must fail closed for invalid dev/forge kernel pipeline contracts [{$token}]";
            }
        }

        foreach ([
            'public function recordKernelPipelineAccepted(array $plan',
            'public function recordKernelPipelineRejected(array $plan',
            'LedgerEventType::KernelPipelineAccepted',
            'LedgerEventType::KernelPipelineRejected',
            'recordKernelPipelineContract(',
            "'violations' => array_values(",
            "'surface_contract' => [",
        ] as $token) {
            if (! str_contains($ledgerContents, $token)) {
                $violations[] = "app/Services/Ai/Kernel/Evidence/AtlasEvidenceLedger.php: kernel pipeline accept/reject must be canonical ledger events [{$token}]";
            }
        }

        foreach ([
            'public function kernelPipelineReportForEnvelope(string $envelopeId): array',
            'public function kernelPipelineReportForWindow(CarbonInterface $since, ?CarbonInterface $until = null, array $filters = []): array',
            'kernelPipelineEventFromEvent(',
            'normalizedKernelPipelineFilters(',
            'matchesKernelPipelineFilters(',
            'LedgerEventType::KernelPipelineAccepted',
            'LedgerEventType::KernelPipelineRejected',
            'has_rejections',
            'surface_contract_source_counts',
            'emitter_stage_counts',
            'surface_contract_source',
        ] as $token) {
            if (! str_contains($replayContents, $token)) {
                $violations[] = "app/Services/Ai/Kernel/Evidence/AtlasLedgerReplayService.php: kernel pipeline events must be projectable from ledger replay [{$token}]";
            }
        }

        foreach ([
            'class KernelPipelineAuditService',
            'public function recordScaffoldExecution(',
            'public function recordAcceptedPlan(array $plan',
            'public function recordRejectedPlan(array $plan',
            'PipelineExecutionResult $result',
            'return $this->recordAcceptedPlan($result->auditPlan',
            '$this->ledger->recordKernelPipelineAccepted(',
            '$this->ledger->recordKernelPipelineRejected(',
            "'emitter_stage' => \$emitterStage",
            'public function eventPayload(?AtlasLedgerEvent $event): ?array',
            'contextForPlan(',
        ] as $token) {
            if (! str_contains($auditContents, $token)) {
                $violations[] = "app/Services/Ai/Kernel/Pipeline/KernelPipelineAuditService.php: scaffold execution auditing must live in kernel service [{$token}]";
            }
        }

        foreach ([
            'class KernelPipelineDevPlanBuilder',
            'public function attachProgrammingPlan(',
            'ScaffoldAtlasKernelPipeline $pipeline',
            'KernelPipelinePlanGuard $guard',
            'PipelineInput::fromArray(',
            "'domain' => 'programming'",
            "KernelPipelineContract::requiredSurfaceContract('KernelPipelineDevPlanBuilder')",
            'public function compactPlan(array $plan',
            '$this->guard->assertValidPlanAndContract($devPlan',
        ] as $token) {
            if (! str_contains($devPlanBuilderContents, $token)) {
                $violations[] = "app/Services/Ai/Kernel/Pipeline/KernelPipelineDevPlanBuilder.php: dev/forge surfaces must share compact Kernel Pipeline plan builder [{$token}]";
            }
        }

        foreach ([
            'KernelPipelineAuditService',
            'KernelPipelineDevPlanBuilder',
            'recordRejectedPlan(',
            'recordAcceptedPlan(',
            'assertValidPlanAndContract($pipelinePlan',
            "'emitter_stage' => 'atlas.ai_chat.kernel_pipeline_guard'",
        ] as $token) {
            if (! str_contains($aiChatCommandContents, $token)) {
                $violations[] = "app/Console/Commands/AiChatCommand.php: dev chat kernel pipeline guard must audit through KernelPipelineAuditService [{$token}]";
            }
        }

        foreach ([
            'KernelPipelineAuditService',
            '$audit->recordScaffoldExecution($result)',
            "'ledger_event' => \$audit->eventPayload(\$ledgerEvent)",
        ] as $token) {
            if (! str_contains($pipelineCommandContents, $token)) {
                $violations[] = "app/Console/Commands/AtlasAiPipelineCommand.php: scaffold execution must write a Kernel Pipeline accepted event [{$token}]";
            }
        }

        foreach ([
            'KernelPipelineAuditService',
            '$audit->recordScaffoldExecution($result)',
            "'ledger_event' => \$audit->eventPayload(\$ledgerEvent)",
        ] as $token) {
            if (! str_contains($pipelineControllerContents, $token)) {
                $violations[] = "app/Http/Controllers/AtlasAiPipelineController.php: scaffold execution must write a Kernel Pipeline accepted event [{$token}]";
            }
        }

        foreach ([
            'KernelPipelineRuntimeGuard',
            'KernelPipelineAuditService',
            '$this->kernelPipelines->violationForJob($job)',
            '$this->kernelPipelines->auditablePlanForJob($job, $kernelPipelineViolation)',
            '$this->kernelPipelines->auditContextForJob($job)',
            'recordAcceptedKernelPipelineRuntimeContract(',
            'kernel_pipeline_contract_blocked',
            "'kernel_pipeline_contract_enforcement' => \$kernelPipelineViolation",
        ] as $token) {
            if (! str_contains(File::exists(app_path('Services/Ai/AiWorker.php')) ? File::get(app_path('Services/Ai/AiWorker.php')) : '', $token)) {
                $violations[] = "app/Services/Ai/AiWorker.php: worker must enforce Kernel Pipeline contract before provider runtime [{$token}]";
            }
        }

        foreach ([
            'App\\Services\\Ai\\Kernel\\Pipeline\\ScaffoldAtlasKernelPipeline',
            'App\\Services\\Ai\\Kernel\\Pipeline\\PipelineInput',
        ] as $token) {
            foreach ([
                'app/Console/Commands/AtlasCliDevCommand.php' => $atlasCliDevCommandContents,
                'app/Console/Commands/AiChatCommand.php' => $aiChatCommandContents,
            ] as $path => $contents) {
                if (str_contains($contents, $token)) {
                    $violations[] = "{$path}: dev/chat surfaces must build compact kernel pipeline plans through KernelPipelineDevPlanBuilder [{$token}]";
                }
            }
        }

        foreach ([
            '{--kernel : Include Kernel Pipeline contract summary for the envelope}',
            'KernelLedgerEnvelopeReportService $reports',
            'includeKernel: (bool) $this->option(\'kernel\')',
        ] as $token) {
            if (! str_contains($ledgerCommandContents, $token)) {
                $violations[] = "app/Console/Commands/AtlasAiLedgerCommand.php: ledger CLI must expose kernel pipeline replay summary [{$token}]";
            }
        }

        foreach ([
            "'kernel' => ['nullable', 'boolean']",
            'KernelLedgerEnvelopeReportService $reports',
            'includeKernel: (bool) ($filters[\'kernel\'] ?? false)',
        ] as $token) {
            if (! str_contains($ledgerControllerContents, $token)) {
                $violations[] = "app/Http/Controllers/AtlasAiLedgerController.php: ledger API must expose kernel pipeline replay summary [{$token}]";
            }
        }

        foreach ([
            'kernelPipelineReportForEnvelope($envelopeId)',
            "\$payload['kernel_pipeline']",
        ] as $token) {
            if (! str_contains($ledgerReportContents, $token)) {
                $violations[] = "app/Services/Ai/Kernel/Evidence/KernelLedgerEnvelopeReportService.php: shared ledger report must expose kernel pipeline replay summary [{$token}]";
            }
        }

        foreach ([
            'atlas:ai:kernel-pipeline-report',
            'kernelPipelineReportForWindow(',
            '{--status= : Filter by pipeline contract status}',
            '{--surface= : Filter by surface id}',
            '{--input-mode= : Filter by input mode}',
            '{--contract-source= : Filter by surface contract source}',
            'emitter_stage_counts',
            "'kernel_pipeline' => \$report",
            'ledger_unavailable',
        ] as $token) {
            if (! str_contains($pipelineReportCommandContents, $token)) {
                $violations[] = "app/Console/Commands/AtlasAiKernelPipelineReportCommand.php: dedicated Kernel Pipeline report CLI must expose window projection [{$token}]";
            }
        }

        foreach ([
            'AtlasAiKernelPipelineReportController',
            'kernelPipelineReportForWindow(',
            "'status' => ['nullable', 'string', 'max:80']",
            "'surface_id' => ['nullable', 'string', 'max:120']",
            "'input_mode' => ['nullable', 'string', 'max:120']",
            "'contract_source' => ['nullable', 'string', 'max:120']",
            "'kernel_pipeline' => \$report",
            'ledger_unavailable',
        ] as $token) {
            if (! str_contains($pipelineReportControllerContents, $token)) {
                $violations[] = "app/Http/Controllers/AtlasAiKernelPipelineReportController.php: dedicated Kernel Pipeline report API must expose window projection [{$token}]";
            }
        }

        if (! str_contains($routesContents, 'AtlasAiKernelPipelineReportController')
            || ! str_contains($routesContents, "Route::get('/ai/kernel-pipeline/report', AtlasAiKernelPipelineReportController::class)")) {
            $violations[] = 'routes/api.php: GET /ai/kernel-pipeline/report must be registered inside the atlas.token API group';
        }

        if (! str_contains($observabilityContents, '$kernelPipeline = $ledgerReplay->kernelPipelineReportForWindow($since)') || ! str_contains($observabilityContents, "'kernel_pipeline' => \$kernelPipeline")) {
            $violations[] = 'app/Http/Controllers/AiObservabilityController.php: observability payload must expose kernel_pipeline read model';
        }

        foreach ([
            'kernelPipelineFindings(',
            'kernelPipelineReportForWindow(',
            "'self-improvement:kernel-pipeline:'",
            'KernelPipelineAccepted',
            'KernelPipelineRejected',
            'has_rejections',
            'emitter_stage_counts',
        ] as $token) {
            if (! str_contains($selfImprovementContents, $token)) {
                $violations[] = "app/Services/Ai/SelfImprovement/AtlasSelfImprovementRuntime.php: Self-Improvement must consume Kernel Pipeline replay evidence [{$token}]";
            }
        }

        return $violations;
    }

    /**
     * @return array<int,string>
     */
    private function scanKernelModelSelectionContractFactory(): array
    {
        $factoryPath = app_path('Services/Ai/Kernel/Decision/ModelSelectionContractFactory.php');
        $testPath = base_path('tests/Unit/Ai/Kernel/ModelSelectionContractFactoryTest.php');
        $devPath = app_path('Console/Commands/AtlasCliDevCommand.php');
        $chatPath = app_path('Console/Commands/AiChatCommand.php');

        $factory = File::exists($factoryPath) ? File::get($factoryPath) : '';
        $test = File::exists($testPath) ? File::get($testPath) : '';
        $dev = File::exists($devPath) ? File::get($devPath) : '';
        $chat = File::exists($chatPath) ? File::get($chatPath) : '';

        $violations = [];

        if (! File::exists($factoryPath)) {
            $violations[] = "missing model selection contract factory [{$factoryPath}]";
        }

        foreach ([
            'final class ModelSelectionContractFactory',
            "public const AUTHORITY = 'atlas_decide'",
            'public const AVAILABLE_SELECTION_MODES = [',
            'public function forCliDev(?string $provider, ?array $modelSelection, ?string $modelOverride, bool $fairMode, array $context = []): array',
            'public function forAiChat(?string $provider, ?array $modelSelection, ?string $modelOverride, bool $fairMode, array $context = []): array',
            "'schema_version' => \$schemaVersion",
            "'surface' => \$surface",
            "'authority' => self::AUTHORITY",
            "'selection_mode' => \$manual ? 'manual_override' : 'auto_best_allowed'",
            "'available_selection_modes' => self::AVAILABLE_SELECTION_MODES",
            "'operator_requested_provider' => \$provider ?: 'auto'",
            "'requested_model' => \$modelOverride",
            "'specialist_profile' => \$specialistProfile",
            'specialistProfile(array $context)',
        ] as $token) {
            if (! str_contains($factory, $token)) {
                $violations[] = "app/Services/Ai/Kernel/Decision/ModelSelectionContractFactory.php: kernel must own the shared model selection contract shape [{$token}]";
            }
        }

        foreach ([
            'test_cli_dev_contract_defaults_to_auto_best_allowed_under_decide_authority',
            'test_ai_chat_contract_preserves_manual_provider_model_and_alias',
            'test_contract_carries_specialist_profile_without_changing_decide_authority',
            'test_fair_mode_is_audited_as_manual_override_without_provider',
            "'atlas.cli_dev.model_selection_contract.v1'",
            "'atlas.ai_chat.model_selection_contract.v1'",
            "'atlas_decide'",
            "'auto_best_allowed'",
            "'manual_override'",
        ] as $token) {
            if (! str_contains($test, $token)) {
                $violations[] = "tests/Unit/Ai/Kernel/ModelSelectionContractFactoryTest.php: shared model selection contract factory needs explicit coverage [{$token}]";
            }
        }

        foreach (["'schema_version' => 'atlas.cli_dev.model_selection_contract.v1'", "'schema_version' => 'atlas.ai_chat.model_selection_contract.v1'"] as $token) {
            if (str_contains($dev, $token) || str_contains($chat, $token)) {
                $violations[] = "surface commands must not inline model selection contract schema; use ModelSelectionContractFactory [{$token}]";
            }
        }

        return $violations;
    }

    /**
     * @return array<int,string>
     */
    private function scanKernelPipelineHealthReadModel(): array
    {
        $replayPath = app_path('Services/Ai/Kernel/Evidence/AtlasLedgerReplayService.php');
        $ledgerCommandPath = app_path('Console/Commands/AtlasAiLedgerCommand.php');
        $pipelineReportCommandPath = app_path('Console/Commands/AtlasAiKernelPipelineReportCommand.php');
        $observabilityTestPath = base_path('tests/Feature/Ai/AiObservabilityKernelSloTest.php');
        $selfImprovementPath = app_path('Services/Ai/SelfImprovement/AtlasSelfImprovementRuntime.php');
        $selfImprovementTestPath = base_path('tests/Feature/Ai/AtlasSelfImprovementRuntimeTest.php');
        $docsPath = base_path('docs/engineering-knowledge-base/atlas-ai-kernel-architecture.md');
        $violations = [];

        $replay = File::exists($replayPath) ? File::get($replayPath) : '';
        $ledgerCommand = File::exists($ledgerCommandPath) ? File::get($ledgerCommandPath) : '';
        $pipelineReportCommand = File::exists($pipelineReportCommandPath) ? File::get($pipelineReportCommandPath) : '';
        $observabilityTest = File::exists($observabilityTestPath) ? File::get($observabilityTestPath) : '';
        $selfImprovement = File::exists($selfImprovementPath) ? File::get($selfImprovementPath) : '';
        $selfImprovementTest = File::exists($selfImprovementTestPath) ? File::get($selfImprovementTestPath) : '';
        $docs = $this->primitives->kernelDocumentationCorpus();

        foreach ([
            '$health = $this->kernelPipelineHealth($eventCount, $acceptedCount, $rejectedCount)',
            "'health' => \$health",
            'private function kernelPipelineHealth(int $eventCount, int $acceptedCount, int $rejectedCount): array',
            "'rejection_rate' => \$rejectionRate",
            "'review_required' => in_array(\$status, ['warning', 'breach'], true)",
            "'warning_rejection_rate' => \$warningThreshold",
            "'breach_rejection_rate' => \$breachThreshold",
            "'no_kernel_pipeline_events_in_window'",
            "'kernel_pipeline_rejection_rate_above_breach_threshold'",
        ] as $token) {
            if (! str_contains($replay, $token)) {
                $violations[] = "app/Services/Ai/Kernel/Evidence/AtlasLedgerReplayService.php: Kernel Pipeline replay must publish deterministic health [{$token}]";
            }
        }

        foreach ([
            "data_get(\$kernel, 'health.status', 'unknown')",
            "data_get(\$kernel, 'health.rejection_rate', 0)",
        ] as $token) {
            if (! str_contains($ledgerCommand, $token)) {
                $violations[] = "app/Console/Commands/AtlasAiLedgerCommand.php: ledger CLI must display Kernel Pipeline health [{$token}]";
            }
        }

        foreach ([
            "data_get(\$pipeline, 'health.status', 'unknown')",
            "data_get(\$pipeline, 'health.rejection_rate', 0)",
            "data_get(\$pipeline, 'health.review_required', false)",
        ] as $token) {
            if (! str_contains($pipelineReportCommand, $token)) {
                $violations[] = "app/Console/Commands/AtlasAiKernelPipelineReportCommand.php: dedicated Kernel Pipeline report must display health [{$token}]";
            }
        }

        foreach ([
            "assertJsonPath('kernel_pipeline.health.status', 'breach')",
            "assertJsonPath('kernel_pipeline.health.review_required', true)",
        ] as $token) {
            if (! str_contains($observabilityTest, $token)) {
                $violations[] = "tests/Feature/Ai/AiObservabilityKernelSloTest.php: observability test must lock Kernel Pipeline health payload [{$token}]";
            }
        }

        if (! str_contains($selfImprovement, "'health' => \$report['health'] ?? []")) {
            $violations[] = 'app/Services/Ai/SelfImprovement/AtlasSelfImprovementRuntime.php: Self-Improvement findings must carry Kernel Pipeline health metadata';
        }

        foreach ([
            "data_get(\$finding, 'metadata.health.status')",
            "data_get(\$finding, 'metadata.health.review_required')",
        ] as $token) {
            if (! str_contains($selfImprovementTest, $token)) {
                $violations[] = "tests/Feature/Ai/AtlasSelfImprovementRuntimeTest.php: Self-Improvement test must lock Kernel Pipeline health propagation [{$token}]";
            }
        }

        foreach ([
            'health e deterministico',
            '`rejection_rate`, thresholds, reasons e',
        ] as $token) {
            if (! str_contains($docs, $token)) {
                $violations[] = "docs/engineering-knowledge-base/atlas-ai-kernel-architecture.md: canonical docs must describe Kernel Pipeline health [{$token}]";
            }
        }

        return $violations;
    }

    /**
     * @return array<int,string>
     */
    private function scanKernelPipelineReviewSignal(): array
    {
        $replayPath = app_path('Services/Ai/Kernel/Evidence/AtlasLedgerReplayService.php');
        $runtimePath = app_path('Services/Ai/SelfImprovement/AtlasSelfImprovementRuntime.php');
        $commandPath = app_path('Console/Commands/AtlasAiKernelPipelineReportCommand.php');
        $unitTestPath = base_path('tests/Unit/Ai/Kernel/LedgerReplayServiceTest.php');
        $runtimeTestPath = base_path('tests/Feature/Ai/AtlasSelfImprovementRuntimeTest.php');
        $commandTestPath = base_path('tests/Feature/Ai/AtlasAiKernelPipelineReportCommandTest.php');
        $apiTestPath = base_path('tests/Feature/Ai/AtlasAiKernelPipelineReportApiTest.php');
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
            '$reviewSignal = $this->kernelPipelineReviewSignal($health, $events->pluck(\'violations\')->flatten()->filter()->countBy()->all())',
            "'review_signal' => \$reviewSignal",
            'private function kernelPipelineReviewSignal(array $health, array $violationCounts): array',
            "'recommended_action' => 'open_reviewable_kernel_pipeline_contract_proposal'",
        ] as $token) {
            if (! str_contains($replay, $token)) {
                $violations[] = "app/Services/Ai/Kernel/Evidence/AtlasLedgerReplayService.php: Kernel Pipeline replay must publish canonical review_signal [{$token}]";
            }
        }

        foreach ([
            "'review_signal' => \$report['review_signal'] ?? []",
        ] as $token) {
            if (! str_contains($runtime, $token)) {
                $violations[] = "app/Services/Ai/SelfImprovement/AtlasSelfImprovementRuntime.php: Self-Improvement must propagate Kernel Pipeline review_signal [{$token}]";
            }
        }

        foreach ([
            "data_get(\$pipeline, 'review_signal.status'",
            "data_get(\$pipeline, 'review_signal.severity'",
            "data_get(\$pipeline, 'review_signal.recommended_action'",
        ] as $token) {
            if (! str_contains($command, $token)) {
                $violations[] = "app/Console/Commands/AtlasAiKernelPipelineReportCommand.php: Kernel Pipeline human report must render review_signal [{$token}]";
            }
        }

        foreach ([
            "data_get(\$report, 'review_signal.status')",
            "data_get(\$report, 'review_signal.severity')",
            "data_get(\$report, 'review_signal.recommended_action')",
        ] as $token) {
            if (! str_contains($unitTest, $token)) {
                $violations[] = "tests/Unit/Ai/Kernel/LedgerReplayServiceTest.php: Kernel Pipeline review_signal must be covered [{$token}]";
            }
        }

        foreach ([
            "data_get(\$finding, 'metadata.review_signal.status')",
            "data_get(\$finding, 'metadata.review_signal.severity')",
            "data_get(\$finding, 'metadata.review_signal.recommended_action')",
        ] as $token) {
            if (! str_contains($runtimeTest, $token)) {
                $violations[] = "tests/Feature/Ai/AtlasSelfImprovementRuntimeTest.php: Curator Kernel Pipeline review_signal propagation must be covered [{$token}]";
            }
        }

        foreach ([
            "data_get(\$payload, 'kernel_pipeline.review_signal.status')",
            'test_command_human_output_includes_kernel_pipeline_review_signal',
        ] as $token) {
            if (! str_contains($commandTest, $token)) {
                $violations[] = "tests/Feature/Ai/AtlasAiKernelPipelineReportCommandTest.php: CLI Kernel Pipeline review_signal must be covered [{$token}]";
            }
        }

        foreach ([
            "kernel_pipeline.review_signal.status', 'breach'",
            "kernel_pipeline.review_signal.severity', 'high'",
            "kernel_pipeline.review_signal.recommended_action', 'open_reviewable_kernel_pipeline_contract_proposal'",
        ] as $token) {
            if (! str_contains($apiTest, $token) || ! str_contains($observabilityTest, $token)) {
                $violations[] = "tests/Feature/Ai: API and Observability Kernel Pipeline review_signal must be covered [{$token}]";
            }
        }

        foreach ([
            'kernel pipeline review_signal',
            'AP54',
        ] as $token) {
            if (! str_contains($domainDocs, $token) && ! str_contains($kernelDocs, $token)) {
                $violations[] = "docs/engineering-knowledge-base: docs must describe Kernel Pipeline review_signal [{$token}]";
            }
        }

        return $violations;
    }

    /**
     * @return array<int,string>
     */
    private function scanKernelPipelineMcpTool(): array
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

        // Façade keeps the tools() schema + dispatch; the handler was relocated under
        // GOD-DEBULK D3 to OpenBrainMcp/ReportTools (invariant unchanged).
        foreach ([
            "'name' => 'atlas_kernel_pipeline_report'",
            "'atlas_kernel_pipeline_report' => \$this->toolResponse(\$id, \$this->reportTools->kernelPipelineReport(\$arguments))",
            "'writes' => false",
        ] as $token) {
            if (! str_contains($mcp, $token)) {
                $violations[] = "app/Services/Ai/AtlasOpenBrainMcpService.php: MCP must expose read-only atlas_kernel_pipeline_report [{$token}]";
            }
        }

        foreach ([
            'public function kernelPipelineReport(array $arguments): array',
            '$this->ledgerReplay->kernelPipelineReportForWindow(now()->subHours($hours), null, $filters)',
            "'kernel_pipeline' => \$report",
        ] as $token) {
            if (! str_contains($reportTools, $token)) {
                $violations[] = "app/Services/Ai/OpenBrainMcp/ReportTools.php: MCP must expose read-only atlas_kernel_pipeline_report [{$token}]";
            }
        }

        foreach ([
            'test_kernel_pipeline_report_tool_exposes_replay_read_model',
            "'name' => 'atlas_kernel_pipeline_report'",
            "data_get(\$structured, 'kernel_pipeline.review_signal.status')",
            'open_reviewable_kernel_pipeline_contract_proposal',
        ] as $token) {
            if (! str_contains($test, $token)) {
                $violations[] = "tests/Feature/Ai/AtlasOpenBrainMcpServiceTest.php: MCP Kernel Pipeline report tool must be covered [{$token}]";
            }
        }

        foreach ([
            'atlas_kernel_pipeline_report',
            'AP58',
        ] as $token) {
            if (! str_contains($docs, $token) && ! str_contains($memoryDocs, $token)) {
                $violations[] = "docs/engineering-knowledge-base: docs must describe MCP Kernel Pipeline report tool [{$token}]";
            }
        }

        return $violations;
    }
}
