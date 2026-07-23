<?php

declare(strict_types=1);

namespace App\Http\Controllers\AtlasDev\Support\PipelineRun;

use App\Http\Controllers\AtlasDev\Support\RunExecutionResult;
use App\Models\AiJob;
use App\Services\Ai\Aemor\AtlasEngineeringOutcomeRecorder;
use App\Services\Ai\AiProvider;
use App\Services\Ai\AiProviderManager;
use App\Services\Ai\AtlasDecide\AtlasDecideLiveOutcomeFeedbackService;
use App\Services\Ai\Concerns\RunsCliProcesses;
use App\Services\Ai\Context\AtlasCanonicalContextRef;
use App\Services\Ai\Context\AtlasContextRuntime;
use App\Services\Ai\Context\AtlasDeliveredPackLedger;
use App\Services\Ai\Context\AtlasRetrievalFeedbackLoopService;
use App\Services\Ai\EngineeringKernel\Adapters\AtlasDevGateAdapter;
use App\Services\Ai\EngineeringKernel\RegressionLock\RegressionLockLedger;
use App\Services\Ai\EngineeringKernel\TrustLevel;
use App\Services\Ai\Governance\GovernanceConsultSkipCounter;
use App\Services\Ai\Governance\ProviderGovernanceConsult;
use App\Services\Ai\Governance\ProviderGovernanceCoverageLedger;
use App\Services\Ai\HermesCliProvider;
use App\Services\Ai\Programming\AtlasDev\Differential\CandidateDivergenceGate;
use App\Services\Ai\Programming\AtlasDev\Differential\DifferentialTestingService;
use App\Services\Ai\Programming\AtlasDev\Differential\Shadow\PhpSubprocessShadowDiffHarness;
use App\Services\Ai\Programming\AtlasDev\Differential\Shadow\ShadowDiffGate;
use App\Services\Ai\Programming\AtlasDev\Differential\Shadow\ShadowDiffHarness;
use App\Services\Ai\Programming\AtlasDev\Differential\Shadow\ShadowDiffService;
use App\Services\Ai\Programming\AtlasDev\Gate\AtlasDevVerificationCommandRunnerContract as VerificationCommandRunner;
use App\Services\Ai\Programming\AtlasDev\Gate\CompletionDecision;
use App\Services\Ai\Programming\AtlasDev\Gate\CompletionStateGate;
use App\Services\Ai\Programming\AtlasDev\Gate\DevWeakOutputDetector;
use App\Services\Ai\Programming\AtlasDev\Gate\PatchApplier;
use App\Services\Ai\Programming\AtlasDev\Gate\PatchApplyResult;
use App\Services\Ai\Programming\AtlasDev\Gate\ReceiptComposer;
use App\Services\Ai\Programming\AtlasDev\Gate\ReceiptStorageAdapter;
use App\Services\Ai\Programming\AtlasDev\Gate\ScopeGuard;
use App\Services\Ai\Programming\AtlasDev\Gate\VerificationGate;
use App\Services\Ai\Programming\AtlasDev\Gate\VerificationGateResult;
use App\Services\Ai\Programming\AtlasDev\Gate\WorktreeBaseline;
use App\Services\Ai\Programming\AtlasDev\Intelligence\PatchIntelligenceInput;
use App\Services\Ai\Programming\AtlasDev\Intelligence\PatchIntelligenceService;
use App\Services\Ai\Programming\AtlasDev\Intelligence\ReviewIntelligenceService;
use App\Services\Ai\Programming\AtlasDev\Intelligence\TestSelectionInput;
use App\Services\Ai\Programming\AtlasDev\Intelligence\TestSelectionIntelligenceService;
use App\Services\Ai\Programming\AtlasDev\MinimaxFirst\AtlasMinimaxFirstWorkerService;
use App\Services\Ai\Programming\AtlasDev\Mutation\MutationScoreGate;
use App\Services\Ai\Programming\AtlasDev\Mutation\MutationScoreVerdict;
use App\Services\Ai\Programming\AtlasDev\Mutation\MutationTestingAdapter;
use App\Services\Ai\Programming\AtlasDev\Mutation\MutationTestingResult;
use App\Services\Ai\Programming\AtlasDev\Mutation\SymfonyMutationCommandRunner;
use App\Services\Ai\Programming\AtlasDev\Persistence\ArtifactNames;
use App\Services\Ai\Programming\AtlasDev\Persistence\ReceiptStorage;
use App\Services\Ai\Programming\AtlasDev\Probe\IntentCoverageProbe;
use App\Services\Ai\Programming\AtlasDev\Probe\IntentFalsificationProbe;
use App\Services\Ai\Programming\AtlasDev\Probe\SpecConstitutionVerdict;
use App\Services\Ai\Programming\AtlasDev\Probe\SpecDrivenConstitutionGate;
use App\Services\Ai\Programming\AtlasDev\PromptProjection\ProviderPromptBuilder;
use App\Services\Ai\Programming\AtlasDev\Provider\ClaudeCliGateway;
use App\Services\Ai\Programming\AtlasDev\Provider\DiffParser;
use App\Services\Ai\Programming\AtlasDev\Provider\DiffParseResult;
use App\Services\Ai\Programming\AtlasDev\Provider\ProviderCallResult;
use App\Services\Ai\Programming\AtlasDev\Provider\SonnetClaudeCliAdapter;
use App\Services\Ai\Programming\AtlasDev\Regression\CallerTestSelectionService;
use App\Services\Ai\Programming\AtlasDev\Regression\RegressionBaselineCache;
use App\Services\Ai\Programming\AtlasDev\Regression\RegressionBaselineGate;
use App\Services\Ai\Programming\AtlasDev\Regression\RegressionBaselineService;
use App\Services\Ai\Programming\AtlasDev\Regression\VerificationRegressionBaselineRunner;
use App\Services\Ai\Programming\AtlasDev\Repair\FailureCapsuleBuilder;
use App\Services\Ai\Programming\AtlasDev\Repair\FailureSignatureHasher;
use App\Services\Ai\Programming\AtlasDev\Repair\RepairPromptComposer;
use App\Services\Ai\Programming\AtlasDev\RuntimeIntelligence\DevFailureCapsuleRuntimeService;
use App\Services\Ai\Programming\AtlasDev\RuntimeIntelligence\DevTaskPacketRuntimeService;
use App\Services\Ai\Programming\AtlasDev\Schemas\AtlasDevOperationEnvelope as OperationEnvelope;
use App\Services\Ai\Programming\AtlasDev\Schemas\CompactSdd;
use App\Services\Ai\Programming\AtlasDev\Schemas\Components\CompletionSummary;
use App\Services\Ai\Programming\AtlasDev\Schemas\Components\GateOutcome;
use App\Services\Ai\Programming\AtlasDev\Schemas\Components\ScopeFileDiff;
use App\Services\Ai\Programming\AtlasDev\Schemas\Components\ScopePreExistingChange;
use App\Services\Ai\Programming\AtlasDev\Schemas\LightTaskContract;
use App\Services\Ai\Programming\AtlasDev\Schemas\MiniProgrammingSpec;
use App\Services\Ai\Programming\AtlasDev\Schemas\ProviderPromptProjection;
use App\Services\Ai\Programming\AtlasDev\Schemas\ScopeGuardReceipt;
use App\Services\Ai\Programming\AtlasDev\Schemas\VerificationReceipt;
use App\Services\Ai\Programming\AtlasDev\Support\Elevations\ElevationConfig;
use App\Services\Ai\Programming\AtlasDev\Support\WorkspaceOriginIdentity;
use App\Services\Ai\Programming\AtlasDev\WorkspaceMutatingProviders;
use App\Services\Ai\Programming\AtlasForgeCodexCliInvocationDriver;
use App\Services\Ai\Programming\AtlasForgeCursorCliInvocationDriver;
use App\Services\Ai\Programming\AtlasForgeMinimaxM27CliInvocationDriver;
use App\Services\Ai\Programming\HermesWorkspaceDefaults;
use App\Services\Ai\SoftwareCompanyStewardship\AreaFocusLoop\ForgeAuthority\AwisExecutionGatePort;
use Illuminate\Contracts\Container\BindingResolutionException;
use Throwable;
use Illuminate\Contracts\Container\Container;
use Illuminate\Support\Str;
use Symfony\Component\Process\Process;

/**
 * AWIS/AUCRI enforcement, governance consult accounting and sovereign dev floor.
 *
 * Extracted verbatim from PipelineRunExecutor (godfile split, GOD-DEBULK
 * 2026-07-22). Behavior unchanged; cross-family calls route through the
 * sibling sections injected below.
 */
final class GovernanceSection
{
    public function __construct(
        private readonly Container $container,
        private readonly ReceiptStorage $storage,
        private readonly ResolverSupport $resolver,
    ) {}

    /**
     * MULTX-05 (partial, no enforce flip): record a fail-open governance skip.
     *
     * Provider-safe: no prompt, no context, no raw command — only surface,
     * executor, provider and a pinned reason enum. Fail-open by construction:
     * counter errors NEVER propagate.
     */
    public function recordGovernanceConsultSkipped(
        string $provider,
        string $surface,
        string $executor,
        string $reason,
    ): void {
        try {
            $counter = $this->resolver->resolve(GovernanceConsultSkipCounter::class);
            if (! $counter instanceof GovernanceConsultSkipCounter) {
                $counter = GovernanceConsultSkipCounter::fromConfig();
            }
            $counter->record([
                'surface' => $surface,
                'executor' => $executor,
                'provider' => $provider,
                'reason' => $reason,
            ]);
        } catch (\Throwable) {
            // Fail-open: the runtime path must never break for a bookkeeping miss.
        }
    }

    public function blockedDueToUnwiredDrivers(
        OperationEnvelope $envelope,
        bool $gatewayMissing,
        bool $commandRunnerMissing,
        string $provider = 'claude_cli',
        string $modelFamily = 'sonnet',
    ): RunExecutionResult {
        $reasons = [];
        if ($gatewayMissing) {
            $reasons[] = 'claude_cli_gateway_unbound';
        }
        if ($commandRunnerMissing) {
            $reasons[] = 'verification_command_runner_unbound';
        }

        return new RunExecutionResult(
            completionState: 'blocked',
            scopeGuardStatus: 'skipped',
            verificationStatus: 'skipped',
            persistedReceiptPaths: [],
            providerCallSummary: [
                'provider' => $provider,
                'model_family' => $modelFamily,
                'provider_calls' => 0,
                'exit_code' => 0,
                'duration_ms' => 0,
                'tokens_in' => null,
                'tokens_out' => null,
                'estimated_cost_usd' => null,
                'error_codes' => $reasons,
                'raw_response_hash' => null,
                'stdout_bytes' => 0,
                'stderr_bytes' => 0,
            ],
            diffParseSummary: null,
        );
    }

    /**
     * @return array<string,mixed>
     */
    public function enforceAucriBeforeProvider(
        OperationEnvelope $envelope,
        LightTaskContract $taskContract,
        ProviderPromptProjection $promptProjection,
        string $runId,
        string $riskLevel,
        string $taskKind,
    ): array {
        $segments = [
            [
                'kind' => 'decision',
                'ref' => 'atlas_dev:task_contract:'.$taskContract->taskContractHash,
                'tokens' => 700,
                'priority' => 1.0,
                'must_keep' => true,
                'content' => 'Atlas Dev task contract must govern provider execution.',
            ],
            [
                'kind' => 'constraint',
                'ref' => 'atlas_dev:scope:'.$envelope->workspaceHash,
                'tokens' => 650,
                'priority' => 0.98,
                'must_keep' => true,
                'content' => implode('|', [
                    'max_files_changed='.$taskContract->maxFilesChanged,
                    'allowed_files='.implode(',', $taskContract->allowedFiles),
                    'blocked_actions='.implode(',', $taskContract->blockedActions),
                ]),
            ],
            [
                'kind' => 'evidence',
                'ref' => 'atlas_dev:prompt_projection:'.$promptProjection->promptProjectionHash,
                'tokens' => max(500, min(6000, (int) ceil(strlen($promptProjection->renderedPromptText) / 4))),
                'priority' => 0.94,
                'must_keep' => true,
                'content' => $promptProjection->renderedPromptText,
            ],
        ];

        $enforcement = app(AtlasContextRuntime::class)->certifyEnforcement([
            'flow_id' => 'atlas_dev',
            'domain' => 'programming',
            'task_type' => $taskKind,
            'risk_level' => $riskLevel,
            'provider' => $taskContract->providerLock->provider,
            'provider_target' => 'external',
            'objective' => $envelope->normalizedIntent,
            'rendered_prompt_text' => $promptProjection->renderedPromptText,
            'source_refs' => [
                ['ref' => 'operation_envelope:'.$envelope->envelopeHash],
                ['ref' => 'task_contract:'.$taskContract->taskContractHash],
                ['ref' => 'prompt_projection:'.$promptProjection->promptProjectionHash],
            ],
            'required_sources' => [
                'operation_envelope:'.$envelope->envelopeHash,
                'task_contract:'.$taskContract->taskContractHash,
                'prompt_projection:'.$promptProjection->promptProjectionHash,
            ],
            'segments' => $segments,
            'task' => 'execute_provider_patch',
            'strict_retrieval_gate' => (bool) config('atlas.programming.strict_retrieval_gate', true),
        ]);

        $this->storage->writeAtomic($runId, ArtifactNames::AUCRI_RUNTIME_ENFORCEMENT, $enforcement);

        return $enforcement;
    }

    /**
     * @return array{status:string,blockers?:list<string>,awis_execution_gate?:array<string,mixed>|null}
     */
    public function enforceAwisBeforeMutativeExecution(
        OperationEnvelope $envelope,
        LightTaskContract $taskContract,
    ): array {
        $task = $envelope->normalizedIntent !== ''
            ? $envelope->normalizedIntent
            : ($taskContract->intentText !== '' ? $taskContract->intentText : $taskContract->taskId);

        try {
            $awisGate = app(AwisExecutionGatePort::class)->gate(
                workspace: $envelope->workspace,
                mode: 'dev',
                task: $task,
            );
        } catch (Throwable) {
            return [
                'status' => 'blocked',
                'blockers' => ['awis_execution_gate_failed_closed'],
                'awis_execution_gate' => [
                    'allowed' => false,
                    'status' => 'blocked',
                    'mode' => 'dev',
                    'error' => 'awis_execution_gate_exception',
                ],
            ];
        }

        if (! (bool) ($awisGate['allowed'] ?? false)) {
            return [
                'status' => 'blocked',
                'blockers' => array_values((array) ($awisGate['blockers'] ?? ['awis_execution_gate_blocked'])),
                'awis_execution_gate' => $awisGate,
            ];
        }

        return [
            'status' => 'passed',
            'awis_execution_gate' => $awisGate,
        ];
    }

    /**
     * @param  array<string,mixed>  $enforcement
     */
    public function blockedDueToAwis(array $enforcement, LightTaskContract $taskContract): RunExecutionResult
    {
        return new RunExecutionResult(
            completionState: 'blocked',
            scopeGuardStatus: 'skipped',
            verificationStatus: 'skipped',
            persistedReceiptPaths: [],
            providerCallSummary: [
                'provider' => $taskContract->providerLock->provider,
                'model_family' => $taskContract->providerLock->modelFamily,
                'provider_calls' => 0,
                'exit_code' => 0,
                'duration_ms' => 0,
                'tokens_in' => null,
                'tokens_out' => null,
                'estimated_cost_usd' => null,
                'error_codes' => array_values((array) ($enforcement['blockers'] ?? ['awis_execution_gate_blocked'])),
                'raw_response_hash' => null,
                'stdout_bytes' => 0,
                'stderr_bytes' => 0,
            ],
            diffParseSummary: null,
        );
    }

    /**
     * @param  array<string,mixed>  $enforcement
     */
    public function blockedDueToAucri(array $enforcement): RunExecutionResult
    {
        return new RunExecutionResult(
            completionState: 'blocked',
            scopeGuardStatus: 'skipped',
            verificationStatus: 'skipped',
            persistedReceiptPaths: [],
            providerCallSummary: [
                'provider' => 'claude_cli',
                'model_family' => 'sonnet',
                'provider_calls' => 0,
                'exit_code' => 0,
                'duration_ms' => 0,
                'tokens_in' => null,
                'tokens_out' => null,
                'estimated_cost_usd' => null,
                'error_codes' => array_values((array) ($enforcement['blockers'] ?? ['aucri_runtime_enforcement_blocked'])),
                'raw_response_hash' => null,
                'stdout_bytes' => 0,
                'stderr_bytes' => 0,
            ],
            diffParseSummary: null,
        );
    }

    public function contextPackHash(string $runId): string
    {
        $projection = $this->storage->read($runId, ArtifactNames::OPEN_BRAIN_PROJECTION);
        if (is_array($projection) && isset($projection['context_pack_hash']) && is_string($projection['context_pack_hash']) && $projection['context_pack_hash'] !== '') {
            return $projection['context_pack_hash'];
        }

        return 'atlas-dev:context_pack:unknown';
    }

    /**
     * @return array{estimated_chars:?int,total_budget_chars:?int}
     */
    public function contextPackBudgetForUtility(string $contextPackHash): array
    {
        try {
            $entry = AtlasDeliveredPackLedger::fromConfig()->lookup($contextPackHash);
            $budgets = is_array($entry) ? (array) ($entry['budgets'] ?? []) : [];
            $estimated = is_numeric($budgets['estimated_chars'] ?? null) ? (int) $budgets['estimated_chars'] : null;
            $total = is_numeric($budgets['total_chars'] ?? null)
                ? (int) $budgets['total_chars']
                : (is_numeric($budgets['requested_total_chars'] ?? null) ? (int) $budgets['requested_total_chars'] : null);

            return [
                'estimated_chars' => $estimated !== null && $estimated > 0 ? $estimated : null,
                'total_budget_chars' => $total !== null && $total > 0 ? $total : null,
            ];
        } catch (Throwable) {
            return ['estimated_chars' => null, 'total_budget_chars' => null];
        }
    }

    /**
     * E2: probe whether the write task's intent is NOT backed by any
     * behavioral AC with a real verification_ref. Reads the persisted
     * MiniProgrammingSpec (the source of acceptanceCriteria) from storage.
     * Returns true when the intent is untested (flag should fire); false
     * when the intent is tested OR the task is not a write task (empty
     * intent_text). When the spec is unreadable, a write task's intent is
     * conservatively treated as not-tested (never silently green over an
     * unevaluable intent).
     */
    public function probeIntentCoverage(string $runId, LightTaskContract $taskContract): bool
    {
        $miniSpec = null;
        try {
            $payload = $this->storage->read($runId, ArtifactNames::MINI_PROGRAMMING_SPEC);
            if (is_array($payload)) {
                $miniSpec = MiniProgrammingSpec::fromArray($payload);
            }
        } catch (\Throwable) {
            // Degrade to "untested" for a write task (the probe will return
            // true for a write task when miniSpec is null, mirroring safe
            // degradation: never silently green over an unevaluable intent).
        }

        return (new IntentCoverageProbe)->isIntentNotTested($taskContract, $miniSpec);
    }

    /**
     * E6: evaluate the diff's touched files against the task's
     * MiniProgrammingSpec (the task's constitution). Loads the persisted
     * spec from storage and runs the {@see SpecDrivenConstitutionGate}.
     *
     * Honest ceiling (VAL-M2-033): three distinct outcomes —
     *   - Spec not persisted (storage->read returns null): no spec declared
     *     => no-op verdict (VAL-M2-034). The gate is never invoked.
     *   - Spec persisted but corrupt/unreadable (storage->read throws, or
     *     fromArray throws): unevaluable verdict. The executor routes this
     *     through the advisory/hard channels with the spec_unevaluable flag
     *     (advisory => needs_review; hard => failed). Never a silent pass.
     *   - Spec loaded and gate ran: the gate's verdict (no-op / pass /
     *     tripped). If the gate itself throws, unevaluable (never a crash).
     *
     * @param  ScopeGuardReceipt  $scopeReceipt  the scope guard receipt
     *                                           carrying the observed file diffs (touched file paths).
     * @param  list<string>  $satisfiedVerificationRefs  the verification
     *                                                   commands the run
     *                                                   actually executed
     *                                                   AND passed
     *                                                   (TestRun.command
     *                                                   where ok===true).
     *                                                   Threaded from the
     *                                                   call site so E6 can
     *                                                   check behavioral AC
     *                                                   verification_ref
     *                                                   satisfaction
     *                                                   (VAL-M2-021).
     */
    public function evaluateSpecConstitution(
        string $runId,
        ScopeGuardReceipt $scopeReceipt,
        array $satisfiedVerificationRefs = [],
    ): SpecConstitutionVerdict {
        // Load the persisted MiniProgrammingSpec. storage->read returns null
        // when the file does not exist (no spec declared => no-op, VAL-M2-
        // 034) and throws when the file exists but is corrupt (unevaluable,
        // VAL-M2-033).
        try {
            $payload = $this->storage->read($runId, ArtifactNames::MINI_PROGRAMMING_SPEC);
        } catch (\Throwable $e) {
            return SpecConstitutionVerdict::unevaluable(
                'e6: mini_programming_spec could not be read: '.$e->getMessage(),
            );
        }

        // No spec file persisted => no spec declared => no-op (VAL-M2-034).
        if (! is_array($payload)) {
            return SpecConstitutionVerdict::noOp();
        }

        // Parse the spec. If fromArray throws (corrupt structure), the spec
        // is unevaluable (VAL-M2-033 honest ceiling — never a silent green).
        try {
            $miniSpec = MiniProgrammingSpec::fromArray($payload);
        } catch (\Throwable $e) {
            return SpecConstitutionVerdict::unevaluable(
                'e6: mini_programming_spec could not be parsed: '.$e->getMessage(),
            );
        }

        // Gather the touched file paths from the scope receipt (the
        // authoritative source — what ScopeGuard observed in the workspace).
        $touchedFilePaths = array_map(
            static fn (ScopeFileDiff $diff): string => $diff->path,
            $scopeReceipt->observed->fileDiffs,
        );

        // Run the gate. If the gate itself throws (unexpected), the check is
        // unevaluable (VAL-M2-033 — never a crash, never a silent green).
        try {
            return (new SpecDrivenConstitutionGate)->evaluate(
                $miniSpec,
                $touchedFilePaths,
                $satisfiedVerificationRefs,
            );
        } catch (\Throwable $e) {
            return SpecConstitutionVerdict::unevaluable(
                'e6: spec constitution evaluation errored: '.$e->getMessage(),
            );
        }
    }

    /**
     * Obra 5 / DEV-04 — sovereign honesty floor over a completed Dev delivery.
     * Observe-mode always records the verdict; enforce-mode downgrades passed completions
     * the floor refuses to promote.
     *
     * @param  array<string,mixed>  $repairEvidence
     * @return array{0:CompletionDecision,1:?array<string,mixed>}
     */
    public function applySovereignDevFloor(
        CompletionDecision $decision,
        VerificationGateResult $verificationResult,
        ScopeGuardReceipt $scopeReceipt,
        ?MutationTestingResult $mutationTestingResult,
        ?MutationScoreVerdict $mutationScoreVerdict,
        array $repairEvidence,
        string $runId,
    ): array {
        $enforcing = (bool) config('atlas.programming.sovereign_floor_enforced', true);
        $changedFiles = array_map(
            static fn (ScopeFileDiff $diff): string => $diff->path,
            $scopeReceipt->observed->fileDiffs,
        );
        $commands = [];
        foreach ($verificationResult->tests as $test) {
            $commands[] = $test->command;
        }

        $evidence = [
            'changed_files' => $changedFiles,
            'status' => $decision->status === CompletionSummary::STATUS_PASSED ? 'success' : 'failed',
            'execution' => [
                'commands' => $commands,
                'claimed_status' => $verificationResult->aggregateStatus,
                'tests_run' => count($verificationResult->tests),
                'assertions_executed' => 0,
                'selected_tests' => $commands,
                'artifacts' => ['verification_receipt:'.$runId],
            ],
            'repair' => (int) ($repairEvidence['attempts'] ?? 0) > 0 ? $repairEvidence : [],
        ];
        if ($mutationScoreVerdict instanceof MutationScoreVerdict) {
            $evidence['mutation_verdict'] = $mutationScoreVerdict;
            $evidence['mutants_generated'] = (int) ($mutationTestingResult?->rawCounts['totalMutantsCount'] ?? 0);
        }

        try {
            $adapter = $this->container->bound(AtlasDevGateAdapter::class)
                ? $this->container->make(AtlasDevGateAdapter::class)
                : new AtlasDevGateAdapter;
            $verdict = $adapter->certifyDevDelivery($evidence, TrustLevel::Dev);
        } catch (\Throwable $e) {
            $receipt = [
                'schema_version' => 'atlas.dev.sovereign_floor.v1',
                'mode' => $enforcing ? 'enforce' : 'observe',
                'promoted' => false,
                'error' => $e->getMessage(),
            ];

            return [$decision, $receipt];
        }

        $receipt = [
            'schema_version' => 'atlas.dev.sovereign_floor.v1',
            'mode' => $enforcing ? 'enforce' : 'observe',
            'promoted' => $verdict->promoted(),
            'status' => $verdict->status,
            'blockers' => $verdict->blockers,
            'receipt_ref' => $verdict->receiptRef,
        ];

        if ($enforcing
            && $decision->status === CompletionSummary::STATUS_PASSED
            && ! $verdict->promoted()) {
            $flags = array_values(array_unique(array_merge(
                $decision->honestyFlags,
                array_map(static fn (string $b): string => 'sovereign_floor:'.$b, $verdict->blockers),
            )));
            $decision = new CompletionDecision(
                status: CompletionSummary::STATUS_NEEDS_REVIEW,
                honestyFlags: $flags,
                residualRisks: array_values(array_unique(array_merge(
                    $decision->residualRisks,
                    ['sovereign_floor_not_promoted'],
                ))),
                reasons: array_values(array_merge(
                    $decision->reasons,
                    ['sovereign_floor:delivery_not_promoted'],
                )),
            );
        }

        return [$decision, $receipt];
    }
}
