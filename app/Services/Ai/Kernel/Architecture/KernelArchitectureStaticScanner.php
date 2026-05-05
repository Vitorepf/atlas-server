<?php

namespace App\Services\Ai\Kernel\Architecture;

use Illuminate\Support\Facades\File;

class KernelArchitectureStaticScanner
{
    /**
     * @return array{
     *   ok:bool,
     *   ap1_surface_provider_bypass:array{valid:bool,violations:array<int,string>},
     *   ap2_surface_context_bypass:array{valid:bool,violations:array<int,string>},
     *   ap6_decision_receipt_propagation:array{valid:bool,violations:array<int,string>},
     *   ap13_decision_receipt_runtime_guard:array{valid:bool,violations:array<int,string>},
     *   ap12_provider_driver_identity_bypass:array{valid:bool,violations:array<int,string>},
     *   ap14_tool_tier_hot_path:array{valid:bool,violations:array<int,string>},
     *   ap15_provider_memory_privacy:array{valid:bool,violations:array<int,string>},
     *   ap16_slo_observability:array{valid:bool,violations:array<int,string>},
     *   ap17_kernel_pipeline_contract:array{valid:bool,violations:array<int,string>},
     *   ap18_repair_loop_contract:array{valid:bool,violations:array<int,string>}
     * }
     */
    public function complianceReport(): array
    {
        $surfaceProviderBypass = $this->scanPhpFilesForForbiddenTokens(app_path('Services/Ai/Surface'), [
            'App\\Services\\Ai\\Kernel\\Provider\\ProviderDriver',
            'App\\Services\\Ai\\Provider\\Drivers\\',
            'App\\Services\\Ai\\ClaudeCliProvider',
            'App\\Services\\Ai\\CodexCliProvider',
            'App\\Services\\Ai\\GeminiCliProvider',
            'App\\Services\\Ai\\AiGatewayService',
            'App\\Services\\Ai\\AiWorker',
            'ProviderDriverRegistry',
            'ClaudeCliProvider',
            'CodexCliProvider',
            'GeminiCliProvider',
            'provider->execute(',
            'prepareRequest(',
        ]);

        $surfaceContextBypass = $this->scanPhpFilesForForbiddenTokens(app_path('Services/Ai/Surface'), [
            'App\\Services\\Ai\\AiContextPackBuilder',
            'App\\Services\\Ai\\AtlasOpenBrainContextInjectionService',
            'App\\Services\\Ai\\AtlasMemoryRegistryService',
            'App\\Services\\Ai\\EngineeringContextPackService',
            'App\\Services\\Engineering\\EngineeringContextPackService',
            'ContextPackBuilder',
            'OpenBrainContextInjection',
            'AtlasMemoryRegistry',
            'EngineeringContextPack',
            'new ContextPack',
            'context_pack',
            'contextPack',
            'context_refs',
            'contextRefs',
            'memory_refs',
            'memoryRefs',
        ]);

        $providerDriverBypass = $this->scanPhpFilesForForbiddenTokens(
            app_path('Services/Ai/Provider/Drivers'),
            [
                'new ClaudeCliProvider',
                'new CodexCliProvider',
                'new GeminiCliProvider',
                'app(ClaudeCliProvider',
                'app(CodexCliProvider',
                'app(GeminiCliProvider',
                'provider_real_execution_allowed\' => true',
                'provider_real_execution_allowed" => true',
            ],
            [
                app_path('Services/Ai/Provider/Drivers/ProviderDriverRegistry.php'),
            ],
        );
        $decisionReceiptPropagation = $this->scanGatewayDecisionReceiptPropagation();
        $decisionReceiptRuntimeGuard = $this->scanWorkerDecisionReceiptRuntimeGuard();
        $toolTierHotPath = $this->scanToolTierHotPathPolicy();
        $providerMemoryPrivacy = $this->scanProviderMemoryPrivacy();
        $sloObservability = $this->scanSloObservability();
        $kernelPipelineContract = $this->scanKernelPipelineContract();
        $repairLoopContract = $this->scanRepairLoopContract();

        return [
            'ok' => $surfaceProviderBypass === []
                && $surfaceContextBypass === []
                && $decisionReceiptPropagation === []
                && $decisionReceiptRuntimeGuard === []
                && $providerDriverBypass === []
                && $toolTierHotPath === []
                && $providerMemoryPrivacy === []
                && $sloObservability === []
                && $kernelPipelineContract === []
                && $repairLoopContract === [],
            'ap1_surface_provider_bypass' => [
                'valid' => $surfaceProviderBypass === [],
                'violations' => $surfaceProviderBypass,
            ],
            'ap2_surface_context_bypass' => [
                'valid' => $surfaceContextBypass === [],
                'violations' => $surfaceContextBypass,
            ],
            'ap6_decision_receipt_propagation' => [
                'valid' => $decisionReceiptPropagation === [],
                'violations' => $decisionReceiptPropagation,
            ],
            'ap13_decision_receipt_runtime_guard' => [
                'valid' => $decisionReceiptRuntimeGuard === [],
                'violations' => $decisionReceiptRuntimeGuard,
            ],
            'ap12_provider_driver_identity_bypass' => [
                'valid' => $providerDriverBypass === [],
                'violations' => $providerDriverBypass,
            ],
            'ap14_tool_tier_hot_path' => [
                'valid' => $toolTierHotPath === [],
                'violations' => $toolTierHotPath,
            ],
            'ap15_provider_memory_privacy' => [
                'valid' => $providerMemoryPrivacy === [],
                'violations' => $providerMemoryPrivacy,
            ],
            'ap16_slo_observability' => [
                'valid' => $sloObservability === [],
                'violations' => $sloObservability,
            ],
            'ap17_kernel_pipeline_contract' => [
                'valid' => $kernelPipelineContract === [],
                'violations' => $kernelPipelineContract,
            ],
            'ap18_repair_loop_contract' => [
                'valid' => $repairLoopContract === [],
                'violations' => $repairLoopContract,
            ],
        ];
    }

    /**
     * @param  array<int,string>  $tokens
     * @param  array<int,string>  $ignoredPaths
     * @return array<int,string>
     */
    private function scanPhpFilesForForbiddenTokens(string $directory, array $tokens, array $ignoredPaths = []): array
    {
        if (! File::isDirectory($directory)) {
            return ["missing directory [{$directory}]"];
        }

        $violations = [];
        $ignored = array_flip(array_map(fn (string $path): string => realpath($path) ?: $path, $ignoredPaths));

        foreach (File::allFiles($directory) as $file) {
            if ($file->getExtension() !== 'php') {
                continue;
            }

            $path = $file->getRealPath() ?: $file->getPathname();
            if (isset($ignored[$path])) {
                continue;
            }

            $contents = File::get($path);
            foreach ($tokens as $token) {
                if (str_contains($contents, $token)) {
                    $violations[] = str_replace(base_path().DIRECTORY_SEPARATOR, '', $path).": forbidden token [{$token}]";
                }
            }
        }

        sort($violations);

        return $violations;
    }

    /**
     * @return array<int,string>
     */
    private function scanGatewayDecisionReceiptPropagation(): array
    {
        $path = app_path('Services/Ai/AiGatewayService.php');
        if (! File::exists($path)) {
            return ["missing gateway [{$path}]"];
        }

        $contents = File::get($path);
        $checks = [
            'gateway emits a trace-level receipt' => "decisionReceiptForTrace(",
            'trace metadata persists the receipt' => "'decision_receipt' => \$decisionReceipt",
            'scout job accepts the receipt as an explicit parameter' => "array \$decisionReceipt",
            'scout job is called with the same receipt' => "decisionReceipt: \$decisionReceipt",
        ];

        $violations = [];
        foreach ($checks as $label => $token) {
            if (! str_contains($contents, $token)) {
                $violations[] = "app/Services/Ai/AiGatewayService.php: missing {$label} [{$token}]";
            }
        }

        $receiptWrites = substr_count($contents, "'decision_receipt' => \$decisionReceipt");
        if ($receiptWrites < 5) {
            $violations[] = "app/Services/Ai/AiGatewayService.php: expected receipt propagation to trace, primary job, council jobs and scout jobs; found {$receiptWrites} writes";
        }

        return $violations;
    }

    /**
     * @return array<int,string>
     */
    private function scanWorkerDecisionReceiptRuntimeGuard(): array
    {
        $path = app_path('Services/Ai/AiWorker.php');
        if (! File::exists($path)) {
            return ["missing worker [{$path}]"];
        }

        $contents = File::get($path);
        $guardPath = app_path('Services/Ai/Kernel/Decision/DecisionReceiptRuntimeGuard.php');
        $guardContents = File::exists($guardPath) ? File::get($guardPath) : '';
        $checks = [
            'worker delegates receipt validation to the kernel guard' => 'DecisionReceiptRuntimeGuard',
            'worker evaluates receipt before provider lookup' => "violationForJob(\$job, \$providerKey, \$job->model)",
            'worker persists receipt metadata through the kernel guard' => 'receiptForJob($job)',
            'kernel guard class exists' => 'class DecisionReceiptRuntimeGuard',
            'worker blocks expired receipts' => 'decision_receipt_expired',
            'worker blocks dry-run receipts' => 'decision_receipt_dry_run',
            'worker blocks invalid receipts' => 'decision_receipt_invalid',
            'worker blocks provider mismatches' => 'decision_receipt_provider_mismatch',
            'worker blocks model mismatches' => 'decision_receipt_model_mismatch',
            'kernel guard validates runtime provider' => 'providerSelectionViolation(',
            'worker parses expires_at as immutable time' => 'CarbonImmutable::parse($expiresAt)',
            'worker marks receipt blocks as no provider call' => "'decision_receipt_expired'",
        ];

        $violations = [];
        foreach ($checks as $label => $token) {
            if (! str_contains($contents, $token) && ! str_contains($guardContents, $token)) {
                $violations[] = "app/Services/Ai/AiWorker.php: missing {$label} [{$token}]";
            }
        }

        if ($guardContents === '') {
            $violations[] = 'app/Services/Ai/Kernel/Decision/DecisionReceiptRuntimeGuard.php: missing dedicated runtime guard';
        }

        $guardPosition = strpos($contents, 'violationForJob($job, $providerKey, $job->model)');
        $providerLookupPosition = strpos($contents, '$provider = $this->providers->get($providerKey);');
        if ($guardPosition === false || $providerLookupPosition === false || $guardPosition > $providerLookupPosition) {
            $violations[] = 'app/Services/Ai/AiWorker.php: decision receipt runtime guard must run before provider lookup/execution';
        }

        return $violations;
    }

    /**
     * @return array<int,string>
     */
    private function scanProviderMemoryPrivacy(): array
    {
        $privacyPath = app_path('Services/Ai/AtlasMemoryPrivacyService.php');
        $projectionPath = app_path('Services/Ai/AtlasProviderProjectionService.php');
        $openBrainPath = app_path('Services/Ai/AtlasHybridMemoryRetrievalService.php');

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
            'providerDecision honors explicit metadata block' => "metadataExternalAiAllowed !== false",
            'providerTitle redacts raw title fallback' => 'AtlasSecurity::redactString((string) $entry->title)',
            'providerSummary redacts raw summary fallback' => 'AtlasSecurity::redactString((string) $entry->summary)',
            'providerBody redacts raw body fallback' => 'AtlasSecurity::redactString((string) $entry->body)',
        ];

        foreach ($checks as $label => $token) {
            if (! str_contains($privacy, $token)) {
                $violations[] = "app/Services/Ai/AtlasMemoryPrivacyService.php: missing {$label} [{$token}]";
            }
        }

        if (! str_contains($projection, 'providerDecision($entry)')) {
            $violations[] = 'app/Services/Ai/AtlasProviderProjectionService.php: provider projections must filter memory through AtlasMemoryPrivacyService::providerDecision';
        }

        if (! str_contains($projection, 'recordProviderMemoryBlocked($entry')) {
            $violations[] = 'app/Services/Ai/AtlasProviderProjectionService.php: provider projections must record blocked memory decisions to the Evidence Ledger';
        }

        if (! str_contains($openBrain, 'providerAllowed($entry)')) {
            $violations[] = 'app/Services/Ai/AtlasHybridMemoryRetrievalService.php: Open Brain provider context must filter memory through AtlasMemoryPrivacyService::providerAllowed';
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
    private function scanToolTierHotPathPolicy(): array
    {
        $gatewayPath = app_path('Services/Ai/AiGatewayService.php');
        $policyPath = app_path('Services/Tools/AtlasToolPolicyEngine.php');
        $violations = [];

        if (! File::exists($gatewayPath)) {
            $violations[] = "missing gateway [{$gatewayPath}]";
        }

        if (! File::exists($policyPath)) {
            $violations[] = "missing tool policy engine [{$policyPath}]";
        }

        $gateway = File::exists($gatewayPath) ? File::get($gatewayPath) : '';
        $policy = File::exists($policyPath) ? File::get($policyPath) : '';

        $gatewayChecks = [
            'gateway records requested tool execution tier' => 'requested_execution_tier',
            'gateway records contract max execution tier' => 'max_execution_tier',
            'gateway records hot path flag' => 'hot_path',
            'gateway blocks T2/T3 hot path requests' => 'execution_tier_hot_path_blocked',
            'gateway blocks tiers above contract' => 'execution_tier_above_contract',
            'gateway compares tier weights' => 'executionTierWeight(',
            'gateway records policy contract blocks to ledger' => "recordPolicyContractBlocked('programming.tools'",
        ];

        foreach ($gatewayChecks as $label => $token) {
            if (! str_contains($gateway, $token)) {
                $violations[] = "app/Services/Ai/AiGatewayService.php: missing {$label} [{$token}]";
            }
        }

        $policyChecks = [
            'tool policy reads max execution tier' => 'max_execution_tier',
            'tool policy blocks tier above budget' => 'execution_tier_above_policy_budget',
            'tool policy compares execution tier weight' => 'tierWeight($executionTier) > $this->tierWeight($maxExecutionTier)',
        ];

        foreach ($policyChecks as $label => $token) {
            if (! str_contains($policy, $token)) {
                $violations[] = "app/Services/Tools/AtlasToolPolicyEngine.php: missing {$label} [{$token}]";
            }
        }

        return $violations;
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
        $contextPath = app_path('Services/Ai/AiContextPackBuilder.php');
        $toolGatePath = app_path('Services/Tools/AtlasToolGateService.php');
        $workerPath = app_path('Services/Ai/AiWorker.php');
        $surfaceAdapterPath = app_path('Services/Ai/Surface/Adapters/BaseSurfaceAdapter.php');
        $learningPromotionPath = app_path('Services/Ai/AtlasMemoryLearningPromotionService.php');
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
            $violations[] = 'app/Services/Ai/AiContextPackBuilder.php: context pack composition must be instrumented with KernelSloProbe stage context.compose';
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
            $violations[] = 'app/Services/Ai/AtlasMemoryLearningPromotionService.php: memory learning projection must be instrumented with KernelSloProbe stage learning.project';
        }

        if (! str_contains($replay, 'repairReportForWindow(') || ! str_contains($replay, 'repairReportForEnvelope(')) {
            $violations[] = 'app/Services/Ai/Kernel/Evidence/AtlasLedgerReplayService.php: repair evidence must have envelope and window projections for observability/curator';
        }

        if (! str_contains($observability, "'kernel_slo' => \$ledgerReplay->sloReportForWindow(\$since)") || ! str_contains($observability, "'kernel_repair' => \$ledgerReplay->repairReportForWindow(\$since)")) {
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

        $forbidden = $this->scanPhpFilesForForbiddenTokens($pipelinePath, [
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

        $stagePath = $pipelinePath.DIRECTORY_SEPARATOR.'KernelPipelineStage.php';
        $pipelinePathname = $pipelinePath.DIRECTORY_SEPARATOR.'ScaffoldAtlasKernelPipeline.php';
        $contractPath = $pipelinePath.DIRECTORY_SEPARATOR.'AtlasKernelPipeline.php';
        $stageContents = File::exists($stagePath) ? File::get($stagePath) : '';
        $pipelineContents = File::exists($pipelinePathname) ? File::get($pipelinePathname) : '';
        $contractContents = File::exists($contractPath) ? File::get($contractPath) : '';

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

        return $violations;
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

        foreach ($this->scanPhpFilesForForbiddenTokens($repairPath, [
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
        $orchestrator = File::exists($orchestratorPath) ? File::get($orchestratorPath) : '';

        foreach ([
            'public function plan(RepairRequest $request): RepairDecision',
            'public function attempt(RepairRequest $request): RepairResult',
            'public function complianceReport(): array',
            'LedgerEventType::RepairInitiated',
            'LedgerEventType::RepairCompleted',
            'executed: false',
            'RepairReason::ExecutionBlockedByDryRun',
            'RepairReason::ExecutionNotImplementedContractFoundationOnly',
            "'execution_enabled' => false",
        ] as $token) {
            if (! str_contains($orchestrator, $token)) {
                $violations[] = "app/Services/Ai/Kernel/Repair/AtlasRepairOrchestrator.php: repair loop must remain scaffold-safe [{$token}]";
            }
        }

        $commandPath = app_path('Console/Commands/AtlasAiRepairCommand.php');
        $controllerPath = app_path('Http/Controllers/AtlasAiRepairController.php');
        $routesPath = base_path('routes/api.php');
        $command = File::exists($commandPath) ? File::get($commandPath) : '';
        $controller = File::exists($controllerPath) ? File::get($controllerPath) : '';
        $routes = File::exists($routesPath) ? File::get($routesPath) : '';

        foreach ([
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
        ] as $token) {
            if (! str_contains($command, $token)) {
                $violations[] = "app/Console/Commands/AtlasAiRepairCommand.php: missing safe repair CLI contract [{$token}]";
            }
        }

        foreach ([
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
        ] as $token) {
            if (! str_contains($controller, $token)) {
                $violations[] = "app/Http/Controllers/AtlasAiRepairController.php: missing safe repair API contract [{$token}]";
            }
        }

        if (! str_contains($routes, 'AtlasAiRepairController') || ! str_contains($routes, "Route::post('/ai/repair', AtlasAiRepairController::class);")) {
            $violations[] = 'routes/api.php: POST /ai/repair must be registered inside the atlas.token API group';
        }

        $workerPath = app_path('Services/Ai/AiWorker.php');
        $worker = File::exists($workerPath) ? File::get($workerPath) : '';
        foreach ([
            'AtlasRepairOrchestrator',
            'RepairRequestFactory',
            'nativeProgrammingRepairKernelDecision(',
            'nativeProgrammingRepairEvidenceRefs(',
            '$this->repairOrchestrator->plan($request)',
            "'kernel_repair' => \$kernelRepairDecision?->toArray()",
            "'kernel_decision' => \$kernelRepairDecision?->toArray()",
            'kernel_repair_contract_blocks',
        ] as $token) {
            if (! str_contains($worker, $token)) {
                $violations[] = "app/Services/Ai/AiWorker.php: native programming repair must pass through kernel repair contract [{$token}]";
            }
        }

        $programmingPath = app_path('Services/Ai/Programming/AtlasProgrammingOrchestrator.php');
        $programming = File::exists($programmingPath) ? File::get($programmingPath) : '';
        foreach ([
            'RepairStrategy',
            "\$plan['repair_execution_contract'] = \$this->repairExecutionContract(\$plan);",
            "'kernel_repair_contract' => [",
            "'orchestrator' => 'AtlasRepairOrchestrator'",
            "'request_factory' => 'RepairRequestFactory'",
            "'decision_required_before_enqueue' => true",
            "'blocks_when_kernel_blocks' => true",
            "'allowed_strategies' => RepairStrategy::values()",
            "'requires_evidence_for_heavy_repair' => true",
        ] as $token) {
            if (! str_contains($programming, $token)) {
                $violations[] = "app/Services/Ai/Programming/AtlasProgrammingOrchestrator.php: programming repair contract must declare kernel repair policy [{$token}]";
            }
        }

        $harnessPath = app_path('Services/Engineering/EngineeringHarnessExecutionService.php');
        $harness = File::exists($harnessPath) ? File::get($harnessPath) : '';
        foreach ([
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
        ] as $token) {
            if (! str_contains($harness, $token)) {
                $violations[] = "app/Services/Engineering/EngineeringHarnessExecutionService.php: engineering harness failures must attach kernel repair decisions [{$token}]";
            }
        }

        $ledgerPath = app_path('Services/Ai/Kernel/Evidence/AtlasEvidenceLedger.php');
        $ledger = File::exists($ledgerPath) ? File::get($ledgerPath) : '';
        foreach ([
            'public function recordRepairDecision(RepairDecision $decision',
            'public function recordRepairResult(RepairResult $result',
            'LedgerEventType::RepairInitiated',
            'LedgerEventType::RepairCompleted',
            "'emitter_stage' => \$context['emitter_stage'] ?? 'atlas.repair'",
            "'causation_id' => \$context['causation_id'] ?? data_get(\$result->decision->evidencePayload, 'decision_hash')",
            "'repair_executed' => false",
        ] as $token) {
            if (! str_contains($ledger, $token)) {
                $violations[] = "app/Services/Ai/Kernel/Evidence/AtlasEvidenceLedger.php: repair decisions must be recordable as canonical evidence [{$token}]";
            }
        }

        $replayPath = app_path('Services/Ai/Kernel/Evidence/AtlasLedgerReplayService.php');
        $ledgerCommandPath = app_path('Console/Commands/AtlasAiLedgerCommand.php');
        $ledgerControllerPath = app_path('Http/Controllers/AtlasAiLedgerController.php');
        $repairReportCommandPath = app_path('Console/Commands/AtlasAiRepairReportCommand.php');
        $repairReportControllerPath = app_path('Http/Controllers/AtlasAiRepairReportController.php');
        $selfImprovementCommandPath = app_path('Console/Commands/AtlasAiSelfImproveCommand.php');
        $selfImprovementSchedulePath = app_path('Services/Ai/SelfImprovement/AtlasSelfImprovementScheduleService.php');
        $selfImprovementScheduleControllerPath = app_path('Http/Controllers/AtlasAiSelfImprovementScheduleController.php');
        $selfImprovementScheduleHealthControllerPath = app_path('Http/Controllers/AtlasAiSelfImprovementScheduleHealthController.php');
        $bootstrapPath = base_path('bootstrap/app.php');
        $replay = File::exists($replayPath) ? File::get($replayPath) : '';
        $ledgerCommand = File::exists($ledgerCommandPath) ? File::get($ledgerCommandPath) : '';
        $ledgerController = File::exists($ledgerControllerPath) ? File::get($ledgerControllerPath) : '';
        $repairReportCommand = File::exists($repairReportCommandPath) ? File::get($repairReportCommandPath) : '';
        $repairReportController = File::exists($repairReportControllerPath) ? File::get($repairReportControllerPath) : '';
        $selfImprovementCommand = File::exists($selfImprovementCommandPath) ? File::get($selfImprovementCommandPath) : '';
        $selfImprovementSchedule = File::exists($selfImprovementSchedulePath) ? File::get($selfImprovementSchedulePath) : '';
        $selfImprovementScheduleController = File::exists($selfImprovementScheduleControllerPath) ? File::get($selfImprovementScheduleControllerPath) : '';
        $selfImprovementScheduleHealthController = File::exists($selfImprovementScheduleHealthControllerPath) ? File::get($selfImprovementScheduleHealthControllerPath) : '';
        $bootstrap = File::exists($bootstrapPath) ? File::get($bootstrapPath) : '';
        foreach ([
            'public function repairReportForEnvelope(string $envelopeId): array',
            'public function repairReportForWindow(CarbonInterface $since, ?CarbonInterface $until = null, array $filters = []): array',
            'normalizedRepairFilters(',
            'matchesRepairFilters(',
            'LedgerEventType::RepairInitiated',
            'LedgerEventType::RepairCompleted',
            'requires_human_review',
            'repairEventFromEvent(',
        ] as $token) {
            if (! str_contains($replay, $token)) {
                $violations[] = "app/Services/Ai/Kernel/Evidence/AtlasLedgerReplayService.php: repair events must be projectable from ledger replay [{$token}]";
            }
        }
        foreach ([
            '{--repair : Include Repair Loop summary for the envelope}',
            "repairReportForEnvelope(\$envelopeId)",
            "\$payload['repair']",
        ] as $token) {
            if (! str_contains($ledgerCommand, $token)) {
                $violations[] = "app/Console/Commands/AtlasAiLedgerCommand.php: ledger CLI must expose repair replay summary [{$token}]";
            }
        }
        foreach ([
            "'repair' => ['nullable', 'boolean']",
            "repairReportForEnvelope(\$envelopeId)",
            "\$payload['repair']",
        ] as $token) {
            if (! str_contains($ledgerController, $token)) {
                $violations[] = "app/Http/Controllers/AtlasAiLedgerController.php: ledger API must expose repair replay summary [{$token}]";
            }
        }
        foreach ([
            'atlas:ai:repair-report',
            'repairReportForWindow(',
            '{--status= : Filter by repair decision status}',
            '{--strategy= : Filter by repair strategy}',
            '{--failure-domain= : Filter by failure domain}',
            "'kernel_repair' => \$report",
            'ledger_unavailable',
        ] as $token) {
            if (! str_contains($repairReportCommand, $token)) {
                $violations[] = "app/Console/Commands/AtlasAiRepairReportCommand.php: dedicated Repair Loop report CLI must expose window projection [{$token}]";
            }
        }
        foreach ([
            'AtlasAiRepairReportController',
            'repairReportForWindow(',
            "'status' => ['nullable', 'string', 'max:80']",
            "'strategy' => ['nullable', 'string', 'max:120']",
            "'failure_domain' => ['nullable', 'string', 'max:160']",
            "'kernel_repair' => \$report",
            'ledger_unavailable',
        ] as $token) {
            if (! str_contains($repairReportController, $token)) {
                $violations[] = "app/Http/Controllers/AtlasAiRepairReportController.php: dedicated Repair Loop report API must expose window projection [{$token}]";
            }
        }
        if (! str_contains($routes, 'AtlasAiRepairReportController') || ! str_contains($routes, "Route::get('/ai/repair/report', AtlasAiRepairReportController::class);")) {
            $violations[] = 'routes/api.php: GET /ai/repair/report must be registered inside the atlas.token API group';
        }

        foreach ([
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
        ] as $token) {
            if (! str_contains($selfImprovementCommand, $token)) {
                $violations[] = "app/Console/Commands/AtlasAiSelfImproveCommand.php: Self-Improvement schedule plan must be inspectable from CLI [{$token}]";
            }
        }
        foreach ([
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
            'SUPPORTED_FLOWS',
        ] as $token) {
            if (! str_contains($selfImprovementSchedule, $token)) {
                $violations[] = "app/Services/Ai/SelfImprovement/AtlasSelfImprovementScheduleService.php: recurring Self-Improvement schedule must be centralized and include Repair Loop review by default [{$token}]";
            }
        }
        foreach ([
            'AtlasAiSelfImprovementScheduleController',
            'AtlasSelfImprovementScheduleService',
            'schedulePlan()',
        ] as $token) {
            if (! str_contains($selfImprovementScheduleController, $token)) {
                $violations[] = "app/Http/Controllers/AtlasAiSelfImprovementScheduleController.php: Self-Improvement schedule plan must be inspectable from API [{$token}]";
            }
        }
        if (! str_contains($routes, 'AtlasAiSelfImprovementScheduleController') || ! str_contains($routes, "Route::get('/ai/self-improvement/schedule', AtlasAiSelfImprovementScheduleController::class);")) {
            $violations[] = 'routes/api.php: GET /ai/self-improvement/schedule must be registered inside the atlas.token API group';
        }
        foreach ([
            'AtlasAiSelfImprovementScheduleHealthController',
            'AtlasSelfImprovementScheduleService',
            'scheduleHealth()',
        ] as $token) {
            if (! str_contains($selfImprovementScheduleHealthController, $token)) {
                $violations[] = "app/Http/Controllers/AtlasAiSelfImprovementScheduleHealthController.php: Self-Improvement schedule health must be inspectable from API [{$token}]";
            }
        }
        if (! str_contains($routes, 'AtlasAiSelfImprovementScheduleHealthController') || ! str_contains($routes, "Route::get('/ai/self-improvement/schedule/health', AtlasAiSelfImprovementScheduleHealthController::class);")) {
            $violations[] = 'routes/api.php: GET /ai/self-improvement/schedule/health must be registered inside the atlas.token API group';
        }
        foreach ([
            'AtlasSelfImprovementScheduleService::class',
            'scheduledCommands()',
            "->dailyAt(\$selfImprovementCommand['time'])",
            "->timezone(\$selfImprovementCommand['timezone'])",
            '->withoutOverlapping()',
        ] as $token) {
            if (! str_contains($bootstrap, $token)) {
                $violations[] = "bootstrap/app.php: recurring Self-Improvement scheduler registration must use the centralized schedule contract [{$token}]";
            }
        }

        $selfImprovementPath = app_path('Services/Ai/SelfImprovement/AtlasSelfImprovementRuntime.php');
        $selfImprovement = File::exists($selfImprovementPath) ? File::get($selfImprovementPath) : '';
        foreach ([
            'repairLoopFindings(',
            'repairReportForWindow(',
            'normalizedRepairFilters(',
            "'self-improvement:repair-loop:'",
            'requires_human_review',
            'RepairInitiated',
            'RepairCompleted',
        ] as $token) {
            if (! str_contains($selfImprovement, $token)) {
                $violations[] = "app/Services/Ai/SelfImprovement/AtlasSelfImprovementRuntime.php: Self-Improvement must consume Repair Loop replay evidence [{$token}]";
            }
        }

        return $violations;
    }
}
