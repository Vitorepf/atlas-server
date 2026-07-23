<?php

declare(strict_types=1);

namespace App\Http\Controllers\AtlasDev\Support;

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
use App\Http\Controllers\AtlasDev\Support\PipelineRun\WorkspaceGitSupport;
use App\Http\Controllers\AtlasDev\Support\PipelineRun\ProviderResultSupport;
use App\Http\Controllers\AtlasDev\Support\PipelineRun\ResolverSupport;
use App\Http\Controllers\AtlasDev\Support\PipelineRun\GovernanceSection;
use App\Http\Controllers\AtlasDev\Support\PipelineRun\DeterministicPatchSection;
use App\Http\Controllers\AtlasDev\Support\PipelineRun\ProviderExecutionSection;
use App\Http\Controllers\AtlasDev\Support\PipelineRun\RepairProjectionSection;
use App\Http\Controllers\AtlasDev\Support\PipelineRun\BestOfNSection;
/**
 * Default HTTP-side {@see RunExecutor} that ties the core run-path services
 * end-to-end. Core (Pipeline/Gate/Provider) stays surface-agnostic; this
 * class is the surface boundary that knows how to drive them.
 *
 * Production wiring requires {@see ClaudeCliGateway} and {@see VerificationCommandRunner}
 * bindings in the container. When either is missing the executor returns a
 * blocked execution result instead of crashing, signalling that the surface
 * (Desktop/CLI/App) should treat the run as ready-but-not-runnable until
 * those drivers are configured.
 *
 * Tests bind a fake gateway + fake command runner via the service container
 * before hitting the HTTP route; this executor honours those bindings.
 */
final class PipelineRunExecutor implements RunExecutor
{
    public const POST_EXECUTION_UTILITY_FORMULA_VERSION = 'atlas.dev.post_execution_utility.v1';

    private readonly PipelineRun\WorkspaceGitSupport $workspaceGit;
    private readonly PipelineRun\ProviderResultSupport $providerResult;
    private readonly PipelineRun\ResolverSupport $resolver;
    private readonly PipelineRun\GovernanceSection $governance;
    private readonly PipelineRun\DeterministicPatchSection $deterministicPatch;
    private readonly PipelineRun\ProviderExecutionSection $providerExecution;
    private readonly PipelineRun\RepairProjectionSection $repairProjection;
    private readonly PipelineRun\BestOfNSection $bestOfN;

    public function __construct(
        private readonly Container $container,
        private readonly ReceiptStorage $storage,
    ) {
        // Godfile split (GOD-DEBULK 2026-07-22): family sections wired as a DAG.
        $this->workspaceGit = new PipelineRun\WorkspaceGitSupport();
        $this->providerResult = new PipelineRun\ProviderResultSupport($storage);
        $this->resolver = new PipelineRun\ResolverSupport($container, $this->workspaceGit);
        $this->governance = new PipelineRun\GovernanceSection($container, $storage, $this->resolver);
        $this->deterministicPatch = new PipelineRun\DeterministicPatchSection($this->providerResult);
        $this->providerExecution = new PipelineRun\ProviderExecutionSection(
            $this->workspaceGit,
            $this->providerResult,
            $this->resolver,
            $this->governance,
        );
        $this->repairProjection = new PipelineRun\RepairProjectionSection($this->resolver);
        $this->bestOfN = new PipelineRun\BestOfNSection(
            $this->workspaceGit,
            $this->providerResult,
            $this->resolver,
            $this->deterministicPatch,
            $this->providerExecution,
        );
    }

    /**
     * COM-11 frozen post-execution utility formula v1.
     *
     * Utility is a deterministic measurement, not a goal-shaped score:
     *   - no delivered refs means no measurement;
     *   - any unresolved missed source or non-passing verified outcome zeros utility;
     *   - otherwise utility is 100 * used_ratio * budget_consumed_ratio.
     *
     * The budget factor prevents inflating utility by delivering tiny packs:
     * when the delivered-pack ledger exposes estimated_chars/total_chars, the
     * used ratio is scaled by the fraction of the requested budget actually
     * consumed; unknown budget data is treated as 1.0 for backward-compatible
     * attribution over older packs.
     *
     * @return array{post_execution_utility:int,formula_version:string,used_ratio:float,budget_consumed_ratio:float}|null
     */
    public static function postExecutionUtilityMeasurement(
        int $usedCount,
        int $deliveredCount,
        int $unresolvedMissedCount,
        string $outcomeStatus,
        ?int $estimatedChars = null,
        ?int $totalBudgetChars = null,
    ): ?array {
        if ($deliveredCount <= 0) {
            return null;
        }

        $usedRatio = max(0.0, min(1.0, $usedCount / max(1, $deliveredCount)));
        $budgetConsumedRatio = 1.0;
        if ($estimatedChars !== null && $estimatedChars > 0 && $totalBudgetChars !== null && $totalBudgetChars > 0) {
            $budgetConsumedRatio = max(0.0, min(1.0, $estimatedChars / $totalBudgetChars));
        }

        $utility = 0;
        if ($unresolvedMissedCount <= 0 && $outcomeStatus === 'passed') {
            $utility = (int) round(100 * $usedRatio * $budgetConsumedRatio);
        }

        return [
            'post_execution_utility' => max(0, min(100, $utility)),
            'formula_version' => self::POST_EXECUTION_UTILITY_FORMULA_VERSION,
            'used_ratio' => round($usedRatio, 4),
            'budget_consumed_ratio' => round($budgetConsumedRatio, 4),
        ];
    }

    public function execute(
        OperationEnvelope $envelope,
        LightTaskContract $taskContract,
        ProviderPromptProjection $promptProjection,
        string $runId,
        ?string $expectedCompactSddHash = null,
    ): RunExecutionResult {
        // F-03: derive task_kind / risk_level from the persisted CompactSDD
        // BEFORE the provider is invoked. If it is missing, invalid, or its
        // canonical hash no longer matches the value pinned at Plan time, we
        // fail closed (CompactSddUnavailableException → 422) instead of
        // wasting a provider call on a run we cannot honestly attest.
        [$taskKind, $riskLevel] = $this->resolveTaskKindAndRiskLevel($runId, $expectedCompactSddHash);

        $awisEnforcement = $this->governance->enforceAwisBeforeMutativeExecution(
            envelope: $envelope,
            taskContract: $taskContract,
        );
        if (($awisEnforcement['status'] ?? 'blocked') !== 'passed') {
            return $this->governance->blockedDueToAwis($awisEnforcement, $taskContract);
        }

        $aucriEnforcement = $this->governance->enforceAucriBeforeProvider(
            envelope: $envelope,
            taskContract: $taskContract,
            promptProjection: $promptProjection,
            runId: $runId,
            riskLevel: $riskLevel,
            taskKind: $taskKind,
        );
        if (($aucriEnforcement['status'] ?? 'blocked') !== 'passed') {
            return $this->governance->blockedDueToAucri($aucriEnforcement);
        }

        $commandRunner = $this->resolver->resolve(VerificationCommandRunner::class);
        $deterministicCallResult = $this->deterministicPatch->deterministicFastPathEnabled()
            ? $this->deterministicPatch->tryDeterministicPatch($envelope, $taskContract, $runId)
            : null;

        if ($commandRunner === null) {
            return $this->governance->blockedDueToUnwiredDrivers($envelope, false, true, $taskContract->providerLock->provider, $taskContract->providerLock->modelFamily);
        }

        $workspaceBaseline = $this->workspaceGit->captureWorkspaceBaseline($envelope->workspace, $taskContract->allowedFiles);

        // M2: Repair-to-green loop. When the verification gate fails,
        // re-invoke the SAME locked provider with failure context up to the cap.
        // Mirrors AtlasMinimaxFirstWorkerService loop semantics. Reuses
        // FailureSignatureHasher for same-signature-twice abort detection.
        //
        // M1 (provider-agnostic): the repair cap now derives from the repair
        // policy for ANY locked provider (claude/codex/cursor/gemini/hermes),
        // not a hardcoded 0 for non-hermes. The cap formula min(3, maxAttempts)
        // is reused unchanged from the former hermes-only branch; max(0, ...)
        // keeps a zero-policy honest (no repair). The same-signature-twice and
        // cap-exhaustion anti-spin guards below apply to every provider.
        $isHermesCli = $taskContract->providerLock->provider === 'hermes_cli';
        $repairCap = max(0, min(3, $taskContract->repairPolicy->maxAttempts));
        $repairAttempt = 0;
        $lastFailureSignature = null;
        $consecutiveSameSignature = 0;
        $abortReason = null;
        // Whether any iteration saw a RED verification gate. Distinguishes a
        // failure-repair chain (test-witnessed behavior change => E4
        // repair-witness exemption applies) from a weak-green repair chain
        // (gate never failed => E4 must still run in full).
        $sawFailedGate = false;
        $hasher = new FailureSignatureHasher;

        // The current prompt projection for this iteration (starts as the
        // original, becomes the composed repair projection on subsequent
        // iterations). Every locked provider consumes $promptProjection directly
        // (Claude via SonnetClaudeCliAdapter, Codex/Cursor/Hermes via their
        // invocation drivers), so passing the repair projection here routes the
        // repair prompt to ANY provider through the same executeLockedProvider
        // dispatch — no hermes-only transport assumption.
        $currentPromptProjection = $promptProjection;

        $callResult = $deterministicCallResult;
        $providerCalls = 0;

        // E5: Pre-Patch Regression Baseline -- capture ONCE on the clean tree.
        //
        // VAL-E5-001: before the generated patch is applied, run the scoped
        // suite and persist a baseline cache (test identifier -> pass/fail)
        // reflecting the pre-patch/HEAD state. The cache is captured here --
        // AFTER the command runner is resolved (non-null) but BEFORE any
        // provider call, repair loop, or best-of-N invocation -- so the
        // workspace is at HEAD (the clean tree).
        //
        // VAL-E5-012: the baseline is captured ONCE and reused byte-identical
        // across all M2 repair iterations (never recaptured on a patched/
        // polluted tree). The $regressionBaseline variable holds the immutable
        // cache; the post-gate E5 block below diffs the final verification
        // result's tests against this original iteration-0 baseline.
        //
        // The scoped suite is the task contract's validation commands (the
        // caller-specified test commands the verification gate will run post-
        // patch). The floor commands (impacted tests from the diff) are a
        // separate concern handled by the caller-test selection feature; the
        // regression baseline for validation commands is still valuable: it
        // distinguishes "this validation test was passing before and now fails"
        // (regression) from "this validation test was already failing" (pre-
        // existing).
        //
        // VAL-E5-011 / VAL-CROSS-010: off mode => the baseline is NOT captured
        // (no commands run), no regression check happens, and the run is byte-
        // identical to pre-E5. The `if (! $e5Config->isOff())` guard skips the
        // capture entirely; the post-gate block checks the same guard before
        // computing regressions.
        $regressionBaseline = null;
        $e5Config = $this->resolver->resolveE5Config();
        if (! $e5Config->isOff()) {
            $baselineService = $this->resolver->resolveRegressionBaselineService($commandRunner);
            if ($baselineService !== null) {
                $regressionBaseline = $baselineService->captureBaseline(
                    runId: $runId,
                    commands: $taskContract->validationCommands,
                    workspace: $envelope->workspace,
                    captureOrder: 0,
                );
            }
        }

        // E4: resolve the e4 elevation config once so the best-of-N path can
        // compare candidates and route the divergence verdict through the
        // sanctioned channels. off => byte-identical (no comparison, no flag).
        $e4Config = $this->resolver->resolveE4Config();

        // M4: Best-of-N (MiniMax-only) on the default hermes path.
        //
        // When N>1 and the locked runtime is hermes_cli, generate N candidate
        // diffs in one synchronous run, run EACH through the M1 floor + gate
        // (N independent gate evaluations, none skipped), and select the best
        // PASSING candidate deterministically. A losing/failing/throwing
        // candidate must NOT abort selection; if NO candidate passes the run
        // reports non-completed (no manufactured green).
        //
        // REUSES the existing executeLockedProvider + DiffParser + ScopeGuard +
        // applyPatchIfSafe + VerificationGate pipeline per candidate (LIGAR — do
        // not rebuild). The candidate-selection STRUCTURE is adapted from the
        // AutonomousEvolution best_of_n tier (AtlasLoopEscalationLadder) and
        // loop lever #1, kept MiniMax-only (no engine swap).
        //
        // DETERMINISTIC TIE-BREAK (documented): among candidates whose gate =
        // STATUS_PASSED, the LOWEST candidate index wins (first passing
        // candidate in generation order). Identical fixtures therefore select
        // the identical winner across runs (VAL-M4-003).
        //
        // N=1 (or best-of-N disabled) preserves the pre-M4 single-call path:
        // the code falls through to the M2 repair loop below unchanged
        // (VAL-M4-009).
        //
        // HONEST CEILING: same-model best-of-N (MiniMax-M3 × N) is WEAKER than
        // cross-engine decorrelation. The receipt carries an explicit honesty
        // annotation and makes NO equivalence/parity claim (VAL-M4-007).
        $bestOfNCandidateCount = $isHermesCli
            ? max(1, (int) config('atlas_dev.best_of_n.candidate_count', 1))
            : 1;
        $bestOfNEnabled = $isHermesCli
            && $bestOfNCandidateCount > 1
            && $deterministicCallResult === null;
        $bestOfNSummary = null;

        $bestOfNran = false;
        if ($bestOfNEnabled) {
            $bestOfNOutcome = $this->bestOfN->executeBestOfNHermes(
                envelope: $envelope,
                taskContract: $taskContract,
                promptProjection: $promptProjection,
                commandRunner: $commandRunner,
                candidateCount: $bestOfNCandidateCount,
                workspaceBaseline: $workspaceBaseline,
                regressionBaseline: $regressionBaseline,
                e5Config: $e5Config,
                e4Config: $e4Config,
            );
            $callResult = $bestOfNOutcome['callResult'];
            $diffResult = $bestOfNOutcome['diffResult'];
            $scopeReceipt = $bestOfNOutcome['scopeReceipt'];
            $patchApplyResult = $bestOfNOutcome['patchApplyResult'];
            $verificationResult = $bestOfNOutcome['verificationResult'];
            $callResultForGates = $bestOfNOutcome['callResultForGates'];
            $providerCalls = $bestOfNOutcome['providerCalls'];
            $bestOfNSummary = $bestOfNOutcome['summary'];
            // Best-of-N candidates are single-shot (no repair per candidate);
            // skip the M2 repair loop. The winner flows through M3 critic +
            // completion below as normal.
            $bestOfNran = true;
        }

        if (! $bestOfNran) {
            do {
                if ($callResult === null) {
                    try {
                        [$callResult, $iterCalls] = $this->providerExecution->executeLockedProvider(
                            envelope: $envelope,
                            taskContract: $taskContract,
                            promptProjection: $currentPromptProjection,
                            // Hermes honors an explicit prompt-text override on
                            // top of the projection; for non-hermes the
                            // projection's rendered text IS the repair prompt.
                            // On a repair iteration the projection already
                            // carries the composed repair text, so the override
                            // is the same string (kept for hermes byte-identity).
                            hermesPromptOverride: $currentPromptProjection->renderedPromptText !== $promptProjection->renderedPromptText
                                ? $currentPromptProjection->renderedPromptText
                                : null,
                        );
                    } catch (\Throwable $e) {
                        // VAL-M1-017: a provider re-invocation (repair
                        // iteration) whose runtime driver throws — e.g. an
                        // unbound gateway, a CLI crash, or a transport error —
                        // degrades honestly to a blocked call result instead of
                        // crashing the run. The loop stays bounded by the repair
                        // cap; the verification gate + CompletionStateGate
                        // resolve the final state to failed/blocked (never a
                        // false passed). Hermes already catches internally
                        // (executeHermesProvider); this net covers the
                        // non-hermes drivers that propagate throws.
                        $callResult = $this->providerResult->blockedProviderCallResult(
                            runId: $promptProjection->runId,
                            provider: $taskContract->providerLock->provider,
                            modelFamily: $taskContract->providerLock->modelFamily,
                            error: 'provider_invocation_threw',
                            stderr: Str::limit($e->getMessage(), 500, '...'),
                        );
                        $iterCalls = 0;
                    }
                    $providerCalls += $iterCalls;
                }

                $diffResult = (new DiffParser)->parse($callResult->stdout);

                $scopeReceipt = (new ScopeGuard)->check(
                    envelope: $envelope,
                    taskContract: $taskContract,
                    diffResult: $diffResult,
                    baseline: $workspaceBaseline['scope'],
                );

                $patchApplyResult = $this->deterministicPatch->applyPatchIfSafe(
                    diffResult: $diffResult,
                    scopeStatus: $scopeReceipt->status,
                    workspace: $envelope->workspace,
                    callResult: $callResult,
                );
                $callResultForGates = $patchApplyResult->ok()
                    ? $callResult
                    : $this->providerResult->withProviderError($callResult, 'patch_apply_failed');

                $verificationResult = $patchApplyResult->ok()
                    ? (new VerificationGate($commandRunner, $this->providerResult->verificationReceiptStorage($runId)))->run(
                        taskContract: $taskContract,
                        callResult: $callResultForGates,
                        scopeReceipt: $scopeReceipt,
                        workspace: $envelope->workspace,
                        codeGraph: $this->resolver->resolveCallerTestCodeGraph($scopeReceipt, $envelope->workspace),
                    )
                    : $this->providerResult->verificationFailedDueToPatchApply($patchApplyResult);

                // W1 repair-on-weak-green: a PASSED gate whose applied diff
                // still carries placeholder markers (TODO/FIXME, ellipsis
                // body, fake always-true assertion in the ADDED lines) gets a
                // repair attempt BEFORE the post-gate W1 probe flags it for a
                // human. Previously a weak-green run exited the loop
                // immediately and went straight to needs_review (advisory) /
                // failed (hard) with zero self-fix attempts — repair only
                // fired on a red gate. Gated on the same weak_output
                // elevation mode (off => byte-identical exit) and on the
                // repair cap; the same-signature-twice anti-spin below covers
                // the chain (a model that keeps returning the same
                // placeholder aborts after 2).
                $weakGreenSignals = [];
                if ($repairCap > 0
                    && $verificationResult->aggregateStatus === VerificationGateResult::STATUS_PASSED
                    && $diffResult->hasPatch()
                    && ! $this->resolver->resolveWeakOutputConfig()->isOff()
                ) {
                    $weakGreenInspection = (new DevWeakOutputDetector)->inspectAppliedDiff((string) $diffResult->diff);
                    if ($weakGreenInspection['weak']) {
                        $weakGreenSignals = $weakGreenInspection['signals'];
                    }
                }

                // no_patch on a WRITE task is the dominant real failure class
                // (18/56 of the audited capsule corpus): the provider completes
                // without a diff and the run previously exited with ZERO repair
                // attempts (no_patch_needed is not a FAILED gate). Route it
                // through the SAME weak-green repair channel — one signal, the
                // existing hint/anti-spin/cap machinery does the rest. Read
                // kinds (question/review) legitimately produce no patch and are
                // exempt; the weak_output elevation off-switch keeps the old
                // exit byte-identical.
                if ($weakGreenSignals === []
                    && $repairCap > 0
                    && $diffResult->isNoPatchNeeded()
                    && in_array($taskKind, ['patch', 'repair', 'frontend', 'risky'], true)
                    && ! $this->resolver->resolveWeakOutputConfig()->isOff()
                ) {
                    $weakGreenSignals = [[
                        'id' => 'no_patch_on_write_task',
                        'detail' => 'provider completed without a diff for a '.$taskKind.' task — emit a concrete unified diff for the allowed files',
                    ]];
                }

                // Check if repair loop should continue
                if (($verificationResult->aggregateStatus !== VerificationGateResult::STATUS_FAILED
                        && $weakGreenSignals === [])
                    || $repairCap <= 0
                ) {
                    // Either genuinely green or repair disabled — exit loop.
                    // (M1: the former `! $isHermesCli` clause is gone — repair
                    // fires for any locked provider whose policy allows it.)
                    break;
                }

                if ($verificationResult->aggregateStatus === VerificationGateResult::STATUS_FAILED) {
                    $sawFailedGate = true;
                }

                // M2: Same-signature-twice abort (reuse FailureSignatureHasher).
                // Compute the normalized signature from the gate failure output.
                // On a weak-green iteration the gate has no failure output, so
                // the signature basis is the weak-output signal set — a model
                // that returns the same placeholder twice repeats the
                // signature and trips the anti-spin abort.
                $failureExcerpt = $weakGreenSignals !== []
                    ? 'weak_output_on_green_gate: '.implode('; ', array_map(
                        static fn (array $s): string => $s['id'].' — '.$s['detail'],
                        $weakGreenSignals,
                    ))
                    : $this->repairProjection->extractFailureExcerpt($verificationResult);
                $currentSignature = $hasher->signature('verification_gate', $failureExcerpt);

                if ($taskContract->repairPolicy->abortOnSameSignatureTwice
                    && $currentSignature === $lastFailureSignature
                ) {
                    $consecutiveSameSignature++;
                    if ($consecutiveSameSignature >= 2) {
                        $abortReason = 'same_signature_twice';
                        break;
                    }
                } else {
                    $consecutiveSameSignature = 1;
                }
                $lastFailureSignature = $currentSignature;

                if ($repairAttempt >= $repairCap) {
                    // Cap exhausted — anti-spin guarantee.
                    $abortReason = 'validation_failed_after_max_repairs';
                    break;
                }
                $repairAttempt++;

                // M2: Build repair prompt with failure context fed forward.
                // REUSES the armed RepairPromptComposer (listed in
                // library/do-not-rebuild.md) plus FailureCapsuleBuilder to produce
                // the repair projection. This inherits the composed guard rails
                // (prompt-enforced stop conditions, operating rules, Repair Capsule
                // section with the normalized failure signature) instead of the
                // bypassed custom buildHermesRepairPrompt() that lost them
                // (LIGAR violation flagged by M2 scrutiny). The REPAIR REQUIRED
                // marker + "Previous attempt failed" header are preserved so the
                // hermes path keeps the failure-excerpt structure VAL-M2-008 locks.
                //
                // E1 repair-loop feedback (VAL-E1-006, VAL-E1-013,
                // VAL-CROSS-006): when the E1 intent-falsification probe is
                // active and the diff misses the intent, the probe reason is
                // fed as a SEPARATE field into the repair prompt (a live input
                // the regenerated attempt can act on). CRITICAL: the reason is
                // NOT folded into $failureExcerpt — that would change the
                // FailureSignatureHasher output and break the cap=3 anti-spin.
                // The reason lives in its own dedicated prompt section.
                $intentProbeReason = $this->repairProjection->resolveIntentProbeReasonForRepair(
                    $taskContract,
                    $diffResult,
                );
                // Weak-output feedback: a pure inspection of the raw provider stdout
                // (truncated diff, out-of-scope file, placeholder, no real change lines).
                // Like the intent probe, the hint is a SEPARATE prompt section — never
                // folded into $failureExcerpt, so the signature-based anti-spin is intact.
                $weakOutput = (new DevWeakOutputDetector)->inspect((string) $callResult?->stdout, [
                    'allowed_files' => $taskContract->allowedFiles,
                ]);
                $weakOutputHint = (bool) $weakOutput['weak'] ? (string) $weakOutput['repair_hint'] : '';
                if ($weakOutputHint === '' && $weakGreenSignals !== []) {
                    // Weak-green iteration on a workspace-mutating provider:
                    // the signal lives in the applied diff (or in the absence
                    // of one), not in the provider stdout, so the stdout
                    // inspection above misses it. Feed the actual signal as
                    // the repair hint — a no-patch signal must not get
                    // placeholder advice.
                    $weakOutputHint = 'signal='.(string) $weakGreenSignals[0]['id']
                        .': '.(string) $weakGreenSignals[0]['detail'];
                }
                $currentPromptProjection = $this->repairProjection->buildComposedRepairProjection(
                    promptProjection: $promptProjection,
                    taskContract: $taskContract,
                    verificationResult: $verificationResult,
                    scopeReceipt: $scopeReceipt,
                    diffResult: $diffResult,
                    failureExcerpt: $failureExcerpt,
                    repairAttempt: $repairAttempt,
                    repairCap: $repairCap,
                    intentProbeReason: $intentProbeReason,
                    weakOutputHint: $weakOutputHint,
                );

                // Restore the pre-run operator baseline before re-invoking the
                // provider. Never check out from HEAD here: allowed_files may
                // already contain operator WIP that the retry loop does not own.
                $restoreError = $this->workspaceGit->revertWorkspaceChanges(
                    $envelope->workspace,
                    $taskContract->allowedFiles,
                    $workspaceBaseline,
                );
                if ($restoreError !== null) {
                    $abortReason = 'workspace_baseline_restore_refused';
                    $callResultForGates = $this->providerResult->withProviderError(
                        $callResultForGates,
                        'workspace_baseline_restore_refused:'.$restoreError,
                    );
                    break;
                }

                // Reset for next iteration — provider will be called again.
                $callResult = null;
            } while (true);
        } // end if (! $bestOfNran)

        // Persist artifacts after the loop exits (final attempt's results).
        $persisted = [];
        $persisted[ArtifactNames::PROVIDER_CALL_RESULT] = $this->storage->writeAtomic(
            $runId,
            ArtifactNames::PROVIDER_CALL_RESULT,
            $callResult->toCanonicalArray(),
        );
        $persisted[ArtifactNames::DIFF_PARSE_RESULT] = $this->storage->writeAtomic(
            $runId,
            ArtifactNames::DIFF_PARSE_RESULT,
            $diffResult->toCanonicalArray(),
        );

        // E2: Intent coverage probe — behavioral-AC backing check.
        //
        // When a write task's intent is NOT backed by any behavioral AC
        // carrying a real verification_ref (only tautological command/scope
        // ACs, or no behavioral AC at all), the `intent_not_tested` honesty
        // flag fires so the CompletionStateGate auto-downgrades PASSED ->
        // needs_review (the passed-forbids-flags invariant guarantees no
        // green-with-flag). In hard mode the gate is rebuilt to STATUS_FAILED
        // (completion `failed`, NOT the advisory `needs_review`) while
        // preserving the flag for auditability.
        //
        // VAL-E2-009: fires when only tautological ACs back the intent.
        // VAL-E2-010: absent when a behavioral AC with a real verification_ref
        // backs the intent (the intent IS tested).
        // VAL-E2-013: off => no flag raised (byte-identical to pre-E2).
        // VAL-M2-006: hard trip => STATUS_FAILED (the NEW hard branch, absent
        //             pre-M2 which wired only isAdvisory). The flag is retained.
        // VAL-M2-007: hard does NOT false-fail when a behavioral AC with a
        //             real verification_ref backs the intent (probe clears).
        //
        // Channels (no third way):
        //   - off      => no surfacing at all (byte-identical to pre-E2).
        //   - advisory => honesty flag only (drives the downgrade, never
        //                 STATUS_FAILED for the flag alone).
        //   - hard     => STATUS_FAILED gate (sanctioned hard channel).
        //
        // The probe reads the persisted MiniProgrammingSpec (the source of
        // acceptanceCriteria) from storage. When the spec is unreadable, a
        // write task's intent is conservatively treated as not-tested (never
        // silently green over an unevaluable intent), mirroring the E5
        // DatabaseTableAvailability safe-degradation pattern.
        //
        // This runs for EVERY provider (not just hermes_cli): the intent
        // coverage check is a property of the SPEC, not the provider, and
        // the honesty-flag / STATUS_FAILED channels are provider-agnostic by
        // design.
        // TRANSFORMAÇÃO TESTEMUNHADA: refator/simplificação/otimização
        // preserva comportamento por definição — não existe teste novo
        // red→green para "a refatoração aconteceu". Diff produzido + gate
        // verde (suite + lint escopado) É a testemunha do intent; exigir AC
        // comportamental aqui false-failava todo refactor perfeito (fire test
        // 03/07 no repo real). Objetivos não-transformação seguem sob E2 pleno.
        $transformationWitnessed = ProviderPromptBuilder::isTransformationObjective($envelope->normalizedIntent)
            && $verificationResult->aggregateStatus === VerificationGateResult::STATUS_PASSED
            && $diffResult->hasPatch();

        $e2Config = $this->resolver->resolveE2Config();
        if (! $e2Config->isOff() && ! $transformationWitnessed) {
            $intentNotTested = $this->governance->probeIntentCoverage($runId, $taskContract);
            if ($intentNotTested) {
                $verificationResult = $this->resolver->routeElevationVerdict($verificationResult, $e2Config, [
                    IntentCoverageProbe::FLAG_INTENT_NOT_TESTED,
                ]);
            }
        }

        // E1: Intent-falsification probe — deterministic post-gate check.
        //
        // Runs AFTER the verification gate (alongside the E2 probe above) and
        // asserts at least one ADDED line of the diff implements the
        // E2-established intent verb set (LightTaskContract::intentVerbs,
        // populated by IntentActionExtractor). When none does, the diff is
        // flagged `intent_likely_not_addressed` EVEN ON A GREEN GATE so the
        // CompletionStateGate auto-downgrades PASSED -> needs_review (advisory)
        // or the gate is forced to STATUS_FAILED (hard). There is no silent
        // green over an intent-missing diff (VAL-E1-003, VAL-E1-012).
        //
        // The probe is deterministic and model-irrelevant: it scans ALL hunks
        // of a many-file diff (position-independent, VAL-E1-015) against the
        // persisted intentVerbs basis — it does NOT re-detect the verbs
        // (VAL-CROSS-005: E1 checks against the E2-established intent). An
        // empty/no-patch write task (verbs present, no added lines) fires the
        // flag (VAL-E1-014: never silently green over an unaddressed intent).
        //
        // Channels (no third way):
        //   - off      => no surfacing at all (byte-identical to pre-E1).
        //   - advisory => honesty flag only (drives the downgrade, never
        //                 STATUS_FAILED for the flag alone).
        //   - hard     => STATUS_FAILED gate (sanctioned hard channel).
        //
        // The probe runs for EVERY provider (not just hermes_cli): the diff
        // is a property of the write task, not the provider, and the honesty-
        // flag / STATUS_FAILED channels are provider-agnostic by design. This
        // also covers the best-of-N winner path (VAL-CROSS-015) which flows
        // through the same post-gate block.
        // RED→GREEN WITNESS (repair): o baseline E5 pré-patch registrou pelo
        // menos um comando de validação FALHANDO e a verificação final passou.
        // Para task_kind=repair isso é a prova comportamental de que o intent
        // foi endereçado — o teste que definia o bug virou verde. Sem isto,
        // um micro-fix perfeito ("$a - $b" → "$a + $b") era flagado por E1
        // (linhas adicionadas sem subject tokens) e por E4 (mudar comportamento
        // É o fix) — falso-positivo real do teste de fogo E2E de 03/07.
        // A testemunha exige o vermelho pré-patch: um repair com baseline
        // todo-verde (arquivo certo, comportamento errado — fixture VAL-M2-002)
        // NÃO é testemunhado e segue sob E1/E4 plenos.
        $redToGreenWitnessed = $taskKind === 'repair'
            && $verificationResult->aggregateStatus === VerificationGateResult::STATUS_PASSED
            && $regressionBaseline !== null
            && in_array(false, $regressionBaseline->results, true);

        // TRANSFORMAÇÃO também isenta E1: simplificação/refator REMOVE ou
        // move linhas — exigir subject tokens nas linhas ADICIONADAS é a
        // doutrina errada (lote real 03/07, B2: simplificação genuína com
        // suite verde hard-failou intent_likely_not_addressed). Diff presente
        // + gate verde é a testemunha, como no E2.
        $e1Config = $this->resolver->resolveE1Config();
        if (! $e1Config->isOff() && ! $redToGreenWitnessed && ! $transformationWitnessed) {
            $intentMissing = (new IntentFalsificationProbe)->isIntentLikelyNotAddressed(
                $taskContract,
                $diffResult,
            );
            if ($intentMissing) {
                $verificationResult = $this->resolver->routeElevationVerdict($verificationResult, $e1Config, [
                    IntentFalsificationProbe::FLAG_INTENT_LIKELY_NOT_ADDRESSED,
                ]);
            }
        }

        // W1: Weak-output probe on the FINAL applied diff — deterministic
        // post-gate check, same tri-state channel contract as E1/E2/E3.
        //
        // The DevWeakOutputDetector already runs INSIDE the M2 repair loop,
        // but only as a repair-prompt hint on a FAILED gate. A weak output
        // that PASSES verification (a TODO/FIXME placeholder or a fake
        // always-true assertion inside the applied added lines, with tests
        // passing vacuously) previously exited silently green. This block
        // closes that false-green corner: inspectAppliedDiff() scans only the
        // added lines of the final diff for placeholder markers and routes
        // the verdict through the sanctioned channels — advisory => honesty
        // flag `weak_output_detected` (CompletionStateGate downgrades
        // PASSED -> needs_review); hard => STATUS_FAILED gate rebuild; off =>
        // byte-identical no-op. Scope escapes / ignored verification commands
        // are NOT re-checked here: ScopeGuard owns scope and the gate ran the
        // command for real. Like E1/E2/E3 this is provider-agnostic and also
        // covers the best-of-N winner path through the same post-gate block.
        $weakOutputConfig = $this->resolver->resolveWeakOutputConfig();
        if (! $weakOutputConfig->isOff() && $diffResult->hasPatch()) {
            $appliedDiffInspection = (new DevWeakOutputDetector)->inspectAppliedDiff((string) $diffResult->diff);
            if ($appliedDiffInspection['weak']) {
                $verificationResult = $this->resolver->routeElevationVerdict($verificationResult, $weakOutputConfig, [
                    DevWeakOutputDetector::FLAG_WEAK_OUTPUT_DETECTED,
                ]);

                // weak_output -> memória: the signal previously died in the
                // run_summary — the NEXT run touching the same area never saw
                // it. Persist a failure capsule (failure_class=weak_output)
                // anchored to a workspace-slug task packet so
                // DevFailureCapsulePromptInjector surfaces it as a
                // known_failure_mode in the next prompt for this area.
                // Fail-open: learning must never break the run.
                try {
                    $packet = app(DevTaskPacketRuntimeService::class)->persist([
                        'run_id' => $runId,
                        'task_id' => 'pipeline-'.$runId,
                        'objective' => $envelope->normalizedIntent,
                        'workspace_slug' => WorkspaceOriginIdentity::slug($envelope->workspace),
                        'allowed_files' => $taskContract->allowedFiles,
                        'source' => 'pipeline_run_executor',
                    ]);
                    app(DevFailureCapsuleRuntimeService::class)->persist([
                        'run_id' => $runId,
                        'task_id' => 'pipeline-'.$runId,
                        'failing_gate' => 'weak_output_probe',
                        'failure_class' => 'weak_output',
                        'error_excerpt' => implode('; ', array_map(
                            static fn (array $s): string => $s['id'].' — '.$s['detail'],
                            $appliedDiffInspection['signals'],
                        )),
                        'changed_files' => array_map(
                            static fn (ScopeFileDiff $diff): string => $diff->path,
                            $scopeReceipt->observed->fileDiffs,
                        ),
                    ], $packet);
                } catch (\Throwable) {
                    // fail-open
                }
            }
        }

        // E3: Mutation-score gate — reads the REAL infection-reported MSI and
        // routes the verdict through the sanctioned channels.
        //
        // Runs AFTER the verification gate (alongside the E2/E1 probes above)
        // and consumes a MutationTestingResult produced by the
        // MutationTestingAdapter. The adapter computes the SCOPED infection
        // invocation (touched test files + their covered source only, never
        // the full ~3592-file suite — VAL-E3-001) and parses the real MSI
        // from the infection summary JSON. This block applies the threshold
        // and routes the verdict: advisory => honesty flag
        // `mutation_score_below_threshold` (PASSED -> needs_review downgrade,
        // never green, VAL-E3-002/009); hard => STATUS_FAILED gate channel
        // (never just downgrades, VAL-E3-003); off => byte-identical no-op
        // (infection not even invoked, VAL-E3-010).
        //
        // The verdict is a PURE function of the real reported MSI vs the
        // configured threshold (VAL-E3-007): no self-declared score. The
        // adapter refuses to fabricate an MSI over a failed/unevaluable run
        // (VAL-E3-011 honest ceiling); the gate then fails-closed in hard
        // mode / appends a flag in advisory so an unevaluable MSI never
        // silently greens.
        //
        // Like E1/E2, this runs for EVERY provider (the diff is a property
        // of the write task, not the provider) and covers the best-of-N
        // winner path through the same post-gate block (VAL-CROSS-015). A
        // patch with no touched test files (or a skipped result) is a
        // documented no-op (VAL-E3-008) — never a false fail.
        $e3Config = $this->resolver->resolveE3Config();
        $mutationTestingResult = null;
        $mutationScoreVerdict = null;
        if (! $e3Config->isOff()) {
            $adapter = $this->resolver->resolveMutationTestingAdapter($envelope->workspace);
            $touchedFiles = array_map(
                static fn (ScopeFileDiff $diff): string => $diff->path,
                $scopeReceipt->observed->fileDiffs,
            );
            $mutationTestingResult = $adapter->run($runId, $touchedFiles);
            $gate = MutationScoreGate::fromConfig($e3Config);
            $mutationScoreVerdict = $gate->evaluate($mutationTestingResult);
            $verdict = $mutationScoreVerdict;

            if ($verdict->tripped) {
                $verificationResult = $this->resolver->routeElevationVerdict($verificationResult, $e3Config, $verdict->honestyFlags);
            }
        }

        // E5: Pre-Patch Regression Baseline -- diff the post-patch results
        // against the iteration-0 baseline and route the verdict through the
        // sanctioned channels.
        //
        // VAL-E5-002: a test that passed pre-patch and fails post-patch is
        // classified as a regression. VAL-E5-003: in hard mode the regression
        // propagates as STATUS_FAILED so completion resolves to failed. VAL-E5-
        // 004: a pre-existing failure (failed-before, failed-after) is NOT a
        // regression. VAL-E5-005: a failing->passing transition is a fix, not
        // a regression.
        //
        // VAL-E5-012: the baseline was captured ONCE before the repair loop
        // (above) on the clean tree. This block diffs the FINAL iteration's
        // verification result against that original baseline. The baseline
        // cache is immutable; its contentHash is stable across all iterations.
        //
        // Channels (no third way):
        //   - off      => no surfacing (byte-identical to pre-E5; the baseline
        //                 was not captured, so there is nothing to diff).
        //   - advisory => honesty flag only (drives the downgrade, never
        //                 STATUS_FAILED for the flag alone).
        //   - hard     => STATUS_FAILED gate (sanctioned hard channel).
        //
        // Like E1/E2/E3, this runs for EVERY provider (the regression check is
        // a property of the test results, not the provider) and covers the
        // best-of-N winner path through the same post-gate block.
        if (! $e5Config->isOff() && $regressionBaseline !== null) {
            $baselineService = $this->resolver->resolveRegressionBaselineService($commandRunner);
            if ($baselineService !== null) {
                $regressionResult = $baselineService->buildResult(
                    baseline: $regressionBaseline,
                    postPatchTests: $verificationResult->tests,
                );
                $regressionGate = new RegressionBaselineGate($e5Config);
                $regressionVerdict = $regressionGate->evaluate($regressionResult);

                if ($regressionVerdict->tripped) {
                    $verificationResult = $this->resolver->routeElevationVerdict($verificationResult, $e5Config, $regressionVerdict->honestyFlags);
                }
            }
        }

        // E4: Shadow-diff for pure functions -- run old vs new implementation
        // on the same probe inputs and diff outputs; divergence =>
        // shadow_diff_regression (catches regressions a unit test misses).
        //
        // VAL-E4-005: a pure-function change diverging on an input NOT covered
        // by unit tests (suite stays green) is caught here: shadow-diff runs
        // old vs new on a generated probe set, observes divergence, and raises
        // shadow_diff_regression DESPITE the green gate. The honesty flag
        // drives CompletionStateGate PASSED -> needs_review (advisory) or the
        // gate is forced to STATUS_FAILED (hard). There is no silent green
        // over a pure-function regression.
        //
        // VAL-E4-006: shadow_diff_regression is a regression verdict, never
        // silently passed. Advisory => needs_review; hard => STATUS_FAILED.
        //
        // VAL-E4-007: conservative pure-function detection -- impure code
        // (I/O, DB writes, mutation of external/global state, randomness,
        // time) is NOT classified pure. No shadow-diff runs, no false flag.
        //
        // VAL-E4-008: a behavior-preserving pure refactor (identical outputs
        // across all probed inputs) raises NO shadow_diff_regression.
        //
        // VAL-E4-011: a newly-added function with no prior implementation is
        // skipped with an explicit reason (no baseline to diff). Never a
        // crash, never a fabricated divergence.
        //
        // Channels (no third way): advisory => honesty flag only; hard =>
        // STATUS_FAILED gate channel. Off => byte-identical no-op (the
        // ShadowDiffService is not even resolved/invoked, so no subprocess
        // spawns and no git reads happen).
        //
        // Like E1/E2/E3/E5, this runs for EVERY provider (the diff is a
        // property of the write task, not the provider) and covers the
        // best-of-N winner path through the same post-gate block
        // (VAL-CROSS-015). A patch with no PHP files, no functions, all-
        // impure, or all-newly-added is a documented no-op (VAL-E4-007 /
        // VAL-E4-011) -- never a false fail.
        //
        // REPAIR-WITNESS EXEMPTION: a run that converged VIA the M2 repair
        // loop ($repairAttempt > 0, aggregate green) changed behavior ON
        // PURPOSE — the previously-failing verification command now passes,
        // so the behavior change is test-witnessed, not silent. E4's trip
        // condition ("a pure symbol diverged") is the DEFINITION of a
        // successful pure-function fix, so evaluating it here false-failed
        // every converged pure-function repair (breaking the frozen
        // VAL-M2-002/007 repair-to-green contract once e4's default went
        // hard). E4 still runs in full on first-attempt green runs — the
        // refactor/behavior-preservation lane it was designed for.
        //
        // The exemption requires the chain to have SEEN a red gate
        // ($sawFailedGate): a weak-green repair chain (W1 placeholder on a
        // gate that never failed) is NOT test-witnessed, so E4 still runs in
        // full there.
        // RED→GREEN WITNESS EXEMPTION (extensão da doutrina acima): um
        // task_kind=repair cujo baseline E5 pré-patch tinha comando de
        // validação VERMELHO e cuja verificação final passou é o mesmo mérito
        // do repair convergido — o contrato de um repair É mudar comportamento,
        // e o red→green é a testemunha. Sem isto, todo bug-fix perfeito de
        // função pura ("a-b"→"a+b", teste que define o comportamento certo
        // VERDE) era flagado shadow_diff_regression (falso-positivo real do
        // teste de fogo E2E de 03/07). Um repair de baseline todo-verde NÃO é
        // testemunhado (VAL-M2-009 continua disparando); kinds refactor/patch
        // (preservação de comportamento) continuam sob E4 pleno.
        if (! $e4Config->isOff()
            && ! $redToGreenWitnessed
            && ! ($repairAttempt > 0 && $sawFailedGate && $verificationResult->aggregateStatus === VerificationGateResult::STATUS_PASSED)) {
            $shadowDiffService = $this->resolver->resolveShadowDiffService();
            if ($shadowDiffService !== null) {
                // Gather the touched PHP files from the scope receipt. Only
                // .php files are candidates (the extractor only parses PHP).
                $changedPhpFiles = array_values(array_filter(
                    array_map(
                        static fn (ScopeFileDiff $d): string => $d->path,
                        $scopeReceipt->observed->fileDiffs,
                    ),
                    static fn (string $p): bool => str_ends_with($p, '.php'),
                ));

                $shadowResult = $shadowDiffService->evaluate(
                    workspace: $envelope->workspace,
                    changedPhpFiles: $changedPhpFiles,
                );
                $shadowGate = new ShadowDiffGate($e4Config);
                $shadowVerdict = $shadowGate->evaluate($shadowResult);

                if ($shadowVerdict->tripped) {
                    $verificationResult = $this->resolver->routeElevationVerdict($verificationResult, $e4Config, $shadowVerdict->honestyFlags);
                }
            }
        }

        // E6: Spec-Driven Constitution Gate — validates the diff's touched
        // files against the task's MiniProgrammingSpec (the task's
        // constitution): acceptance criteria honored, non-goals respected,
        // forbidden files respected.
        //
        // This is the SEMANTIC scope check, distinct from the ScopeGuard
        // (which mechanically enforces the task contract's allowed_files).
        // E6 catches out-of-spec behavior the ScopeGuard does not — e.g. a
        // diff that touches a forbidden_file declared in the spec while
        // staying within the contract's allowed_files mechanically
        // (VAL-M2-024).
        //
        // The gate is deterministic: it inspects the spec's declared
        // boundaries (forbiddenFiles, nonGoals, allowedFiles, expectedFiles,
        // acceptanceCriteria) against the touched file paths from the scope
        // receipt. It does NOT attempt line-level semantic analysis.
        //
        // Channels (no third way, no silent green):
        //   - off      => the gate is not invoked (byte-identical to pre-E6).
        //   - advisory => honesty flag only (PASSED -> needs_review downgrade).
        //   - hard     => STATUS_FAILED gate channel (completion `failed`).
        //
        // Honest ceiling (VAL-M2-033): when the spec is unreadable/corrupt or
        // the evaluation errors, the gate surfaces an unevaluable verdict
        // (advisory => needs_review with spec_unevaluable flag; hard =>
        // failed). Never a silent pass, never a crash.
        //
        // No-op (VAL-M2-034): when the task declares no spec (not persisted)
        // or the spec has no constitution to validate (no acceptance
        // criteria, non-goals, forbidden files, or expected behavior), E6
        // surfaces nothing — a spec-less task is never false-flagged.
        //
        // Like E1-E5, this runs for EVERY provider (the spec is a property
        // of the task, not the provider) and covers the best-of-N winner
        // path through the same post-gate block (VAL-CROSS-015).
        $e6Config = $this->resolver->resolveE6Config();
        if (! $e6Config->isOff()) {
            $e6Verdict = $this->governance->evaluateSpecConstitution(
                runId: $runId,
                scopeReceipt: $scopeReceipt,
                satisfiedVerificationRefs: $this->resolver->satisfiedVerificationRefs($verificationResult),
            );

            if ($e6Verdict->isUnevaluable) {
                // Honest ceiling (VAL-M2-033): an unevaluable spec-constitution
                // check never silently greens. Advisory => honesty flag
                // (-> needs_review); hard => STATUS_FAILED (-> failed).
                $verificationResult = $this->resolver->routeElevationVerdict($verificationResult, $e6Config, $e6Verdict->honestyFlags);
            } elseif ($e6Verdict->tripped) {
                // Spec/constitution violation (VAL-M2-021/022/024).
                $verificationResult = $this->resolver->routeElevationVerdict($verificationResult, $e6Config, $e6Verdict->honestyFlags);
            }
            // else: no-op or pass — no flag, no STATUS_FAILED (byte-identical
            // to pre-E6 for this run on the E6 axis).
        }

        // M3: Senior critic — invoke ReviewIntelligenceService after the gate
        // passes and before CompletionStateGate promotes a completion, for ANY
        // locked provider. A blocker/critical finding forces completion to
        // non-completed even when tests are green; a clean diff is NOT falsely
        // blocked; a critic exception degrades to non-passed (never silently
        // swallowed to green). REUSE ReviewIntelligenceService (do not rebuild).
        //
        // M1 (provider-agnostic closeout, VAL-M1-010/011/012/013/016/018):
        // the former `$isHermesCli` clause is gone — the critic now fires for
        // every locked provider (claude/codex/cursor/gemini/hermes) after a
        // passed gate, including a run repaired-to-green (the repair loop and
        // this block compose: a passed gate reached via repair still invokes
        // the critic before `passed`). Quality must not depend on a specific
        // provider (invariant 4). The only remaining `isHermesCli` usages in
        // this executor are the best-of-N block (M4-exempt), NOT the critic.
        //
        // CONTRACT GUARD (VAL-M3-001): the critic runs EXACTLY ONCE after a
        // PASSED gate, NOT merely a not-failed gate. VerificationGateResult's
        // aggregateStatus has exactly three possible values after the repair
        // loop exits: STATUS_PASSED, STATUS_FAILED, STATUS_NEEDS_REVIEW. The
        // prior guard (!== STATUS_FAILED) was BROADER than the contract: it
        // also fired on STATUS_NEEDS_REVIEW (e.g. a doc-only diff with no
        // validationCommands and no no_test_reason → test_skipped_no_reason),
        // sending diffs to the critic on a non-passed gate. The contract
        // wording is "after a PASSED gate" — so the guard now keys on the
        // explicit STATUS_PASSED state. A needs_review / skipped gate no
        // longer invokes the critic; the completion decision still reflects
        // the needs_review verification status via CompletionStateGate.
        $reviewReceipt = null;
        $criticException = null;
        $criticAnalysed = false;
        if ($verificationResult->aggregateStatus === VerificationGateResult::STATUS_PASSED) {
            try {
                // Resolve from container (allows test bindings) or create fresh.
                /** @var object $criticService */
                $criticService = $this->container->bound(ReviewIntelligenceService::class)
                    ? $this->container->make(ReviewIntelligenceService::class)
                    : new ReviewIntelligenceService;
                $criticInput = $this->bestOfN->buildCriticInput(
                    runId: $runId,
                    scopeReceipt: $scopeReceipt,
                    taskContract: $taskContract,
                    verificationResult: $verificationResult,
                    diffResult: $diffResult,
                    intentWitnessed: $redToGreenWitnessed || $transformationWitnessed,
                );
                // E1: pass the LLM-as-judge sub-layer options to the critic
                // so detectIntentFalsification() can invoke the (optional,
                // sub-flag-gated) adversarial judge. The judge is resolved
                // from the container when bound (allows test fakes); absent
                // a binding the sub-flag has no judge to call, so the
                // detector degrades to the deterministic probe only
                // (VAL-E1-011: judge is strictly doubt-additive, and a
                // missing judge can never clear the deterministic flag).
                $criticOptions = $this->resolver->resolveE1CriticOptions();
                $reviewReceipt = $criticService->analyse($criticInput, $criticOptions);
                $criticAnalysed = true;
            } catch (\Throwable $e) {
                // Critic exception degrades to non-passed (never swallowed to green).
                $criticException = $e;
            }
        }

        $decision = (new CompletionStateGate)->decide(
            taskContract: $taskContract,
            scopeReceipt: $scopeReceipt,
            verificationResult: $verificationResult,
            callResult: $callResultForGates,
            diffResult: $diffResult,
            reviewReceipt: $reviewReceipt,
            criticException: $criticException,
        );

        // M2: When the repair loop aborted or exhausted, add the abort reason
        // to the decision's honesty flags so the receipt carries it honestly.
        if ($abortReason !== null && $decision->status !== CompletionSummary::STATUS_PASSED) {
            $decision = new CompletionDecision(
                status: $decision->status,
                honestyFlags: array_values(array_unique(array_merge(
                    $decision->honestyFlags,
                    ['repair_loop_terminated:'.$abortReason],
                ))),
                residualRisks: $decision->residualRisks,
                reasons: array_values(array_merge(
                    $decision->reasons,
                    ['repair_abort:'.$abortReason],
                    $repairAttempt > 0 ? ["repair_attempts:{$repairAttempt}"] : [],
                )),
            );
        }

        // OBRA #4 S1+S2 — evidence for a Dev repair delivery. Build this
        // BEFORE the sovereign floor certifies the delivery so certification
        // sees the replay proof / regression-lock ref instead of only a bare
        // attempt count.
        $repairEvidence = ['attempts' => $repairAttempt];
        if ($repairAttempt > 0 && $verificationResult->aggregateStatus === VerificationGateResult::STATUS_PASSED) {
            $devFailureSignature = (string) ($lastFailureSignature ?? '');
            $repairEvidence['replay_proof'] = [
                'original_failure_ref' => $devFailureSignature !== '' ? $devFailureSignature : 'verification_gate',
                'replayed' => true,
                'passed' => true,
            ];
            if ($devFailureSignature !== '') {
                try {
                    $lockLedger = new RegressionLockLedger;
                    $lockEntry = $lockLedger->has($devFailureSignature) ?? $lockLedger->lock([
                        'failure_signature' => $devFailureSignature,
                        'origin' => 'atlas_dev_m2',
                        'failing_case' => 'verification_gate',
                        'locked_test_ref' => 'existing_suite_verification_commands',
                    ]);
                    $repairEvidence['regression_lock_ref'] = (string) ($lockEntry['lock_ref'] ?? '');
                } catch (\Throwable) {
                    // Best-effort: absence of the lock is charged by the floor.
                }
            }
        }

        [$decision, $sovereignDevFloorReceipt] = $this->governance->applySovereignDevFloor(
            $decision,
            $verificationResult,
            $scopeReceipt,
            $mutationTestingResult,
            $mutationScoreVerdict,
            $repairEvidence,
            $runId,
        );

        $receipt = (new ReceiptComposer)->compose(
            envelope: $envelope,
            taskContract: $taskContract,
            promptProjection: $promptProjection,
            callResult: $callResultForGates,
            diffResult: $diffResult,
            scopeReceipt: $scopeReceipt,
            verificationResult: $verificationResult,
            completion: $decision,
            contextPackHash: $this->governance->contextPackHash($runId),
            taskKind: $taskKind,
            riskLevel: $riskLevel,
            modelLabel: $callResult->actualProvider.':'.$callResult->actualModelFamily,
        );

        $persisted[ArtifactNames::PATCH_APPLY_RESULT] = $this->storage->writeAtomic(
            $runId,
            ArtifactNames::PATCH_APPLY_RESULT,
            $patchApplyResult->toArray(),
        );
        $persisted[ArtifactNames::SCOPE_GUARD_RECEIPT] = $this->storage->writeAtomic(
            $runId,
            ArtifactNames::SCOPE_GUARD_RECEIPT,
            $scopeReceipt->toCanonicalArray(),
        );
        $persisted[ArtifactNames::VERIFICATION_RECEIPT] = $this->storage->writeAtomic(
            $runId,
            ArtifactNames::VERIFICATION_RECEIPT,
            $receipt->toCanonicalArray(),
        );
        if ($sovereignDevFloorReceipt !== null) {
            $persisted[ArtifactNames::SOVEREIGN_DEV_FLOOR_RECEIPT] = $this->storage->writeAtomic(
                $runId,
                ArtifactNames::SOVEREIGN_DEV_FLOOR_RECEIPT,
                $sovereignDevFloorReceipt,
            );
        }

        // Patch/Test Intelligence — derives risk, blast radius, focused tests
        // and rollback hints from the scope-guard observation. Strictly
        // additive: failures are swallowed so they cannot regress the gate
        // outcome the rest of the pipeline already produced.
        try {
            $patchIntelligence = (new PatchIntelligenceService)->analyze(new PatchIntelligenceInput(
                runId: $runId,
                taskContractHash: $scopeReceipt->taskContractHash,
                expectedFiles: $taskContract->allowedFiles,
                changedFiles: $scopeReceipt->observed->fileDiffs,
                userPreExistingChanges: $scopeReceipt->userPreExistingChanges,
                evidenceRefs: [],
            ));
            $persisted[ArtifactNames::PATCH_INTELLIGENCE_RECEIPT] = $this->storage->writeAtomic(
                $runId,
                ArtifactNames::PATCH_INTELLIGENCE_RECEIPT,
                $patchIntelligence->toCanonicalArray(),
            );

            $testSelection = (new TestSelectionIntelligenceService)->select(new TestSelectionInput(
                runId: $runId,
                taskContractHash: $scopeReceipt->taskContractHash,
                changedFiles: array_map(
                    static fn ($diff): string => $diff->path,
                    $scopeReceipt->observed->fileDiffs,
                ),
                expectedTests: $taskContract->validationCommands,
                riskLevel: $patchIntelligence->riskLevel,
                evidenceRefs: [],
            ));
            $persisted[ArtifactNames::TEST_SELECTION_RECEIPT] = $this->storage->writeAtomic(
                $runId,
                ArtifactNames::TEST_SELECTION_RECEIPT,
                $testSelection->toCanonicalArray(),
            );
        } catch (\Throwable) {
            // Patch/test intelligence is advisory. Swallow so it never
            // shadows the canonical receipts already persisted above.
        }

        // ADML live outcome feedback (write side): the SpecComposer consults the
        // (programming, task_kind) route before choosing a provider, but nothing fed
        // real Dev outcomes back — every route stayed insufficient_evidence /
        // free_to_choose forever. Record the completed run's real result so route
        // stats and degradation signals run on evidence. Skipped in unit tests
        // unless a test binds an explicit instance (never pollute the live ledger
        // with fixture runs). Fail-open: routing evidence must never break the run.
        // no_patch_needed NÃO entra no ledger de rota: o provider julgou
        // corretamente que não havia o que mudar — nem sucesso nem falha de
        // MÚSCULO. Gravar como failure envenenava a taxa da janela com runs
        // sem trabalho (lote de evidência 03/07).
        $admlRecordable = $receipt->completion->status !== CompletionSummary::STATUS_NO_PATCH_NEEDED;
        if ($admlRecordable && (! app()->runningUnitTests() || app()->bound(AtlasDecideLiveOutcomeFeedbackService::class))) {
            try {
                app(AtlasDecideLiveOutcomeFeedbackService::class)->record([
                    'task_category' => 'programming',
                    'role' => $taskKind !== '' ? $taskKind : 'atlas_dev_fast_path',
                    'provider' => $callResult->actualProvider,
                    'model' => $callResult->actualModelFamily,
                    'result' => $receipt->completion->status === CompletionSummary::STATUS_PASSED
                        ? AtlasDecideLiveOutcomeFeedbackService::RESULT_SUCCESS
                        : AtlasDecideLiveOutcomeFeedbackService::RESULT_FAILURE,
                    // proven_real (Goal 2): a PASSED completion here is backed by the real
                    // verification gate — the ONLY success the Learning Loop's activation regime
                    // may weight. Anything short of a real gate pass is not proven.
                    'proven_real' => $receipt->completion->status === CompletionSummary::STATUS_PASSED,
                    'latency_ms' => $callResultForGates->durationMs,
                    // Custo real: hermes_cli é LOCAL (custo marginal zero) —
                    // null deixava a camada cost-outcome do ADML sem evidência
                    // ("cost_outcome_no_relevant_cost_outcome_evidence") e a
                    // recomendação nunca amadurecia. Providers pagos sem
                    // estimativa continuam null (nunca fabricar custo).
                    'cost_usd' => $callResultForGates->costEstimateUsd
                        ?? ($callResult->actualProvider === 'hermes_cli' ? 0.0 : null),
                    // Qualidade = juízo real dos gates do run (não
                    // self-declared): passed=1.0, needs_review=0.5, resto=0.0.
                    'quality_score' => match ($receipt->completion->status) {
                        CompletionSummary::STATUS_PASSED => 1.0,
                        CompletionSummary::STATUS_NEEDS_REVIEW => 0.5,
                        default => 0.0,
                    },
                    'input_tokens' => $callResultForGates->tokensIn,
                    'output_tokens' => $callResultForGates->tokensOut,
                    'actor' => 'atlas_dev_pipeline',
                ]);
            } catch (\Throwable) {
                // fail-open
            }
        }

        // OUTC-01(a): engineering outcome spine — AEMOR episode+outcome anchored on REAL gate
        // results (author≠judge). Gated by atlas.aemor; fail-open; skipped in unit tests unless
        // an explicit recorder instance is bound (same guard as ADML write side above).
        $aemorRunOutcomeId = null;
        if ($admlRecordable && (! app()->runningUnitTests() || app()->bound(AtlasEngineeringOutcomeRecorder::class))) {
            try {
                $passed = $receipt->completion->status === CompletionSummary::STATUS_PASSED;
                $evidenceRefs = array_values(array_filter([
                    'verification_receipt:'.$runId,
                    'scope_guard:'.$scopeReceipt->taskContractHash,
                    count($verificationResult->tests) > 0 ? 'tests_run:'.count($verificationResult->tests) : null,
                ]));
                $aemorResult = app(AtlasEngineeringOutcomeRecorder::class)->record([
                    'executor' => 'dev',
                    'objective' => $envelope->normalizedIntent !== '' ? $envelope->normalizedIntent : 'Atlas Dev pipeline run',
                    'workspace' => $envelope->workspace,
                    'surface_id' => $taskKind !== '' ? $taskKind : 'atlas_dev_pipeline',
                    'scope_type' => 'engineering_run',
                    'scope_id' => $runId,
                    'run_id' => $runId,
                    'task_id' => $runId,
                    'decision_id' => 'verification_receipt:'.$runId,
                    'decision_receipt_id' => 'verification_receipt:'.$runId,
                    'provider' => $callResult->actualProvider,
                    'status' => $passed ? 'succeeded' : ($receipt->completion->status === CompletionSummary::STATUS_NEEDS_REVIEW ? 'blocked' : 'failed'),
                    'summary' => 'Dev pipeline finished with '.$receipt->completion->status.' (verification='.$verificationResult->aggregateStatus.').',
                    'evidence_refs' => $evidenceRefs,
                    'metrics' => [
                        'tests_passed' => $passed,
                        'attribution_reviewed' => true,
                        'verification_status' => $verificationResult->aggregateStatus,
                        'server_verified' => $passed,
                    ],
                    'verified' => $passed,
                    'provider_calls_made' => $providerCalls > 0,
                    'learning_claim' => $passed
                        ? 'Dev pipeline landings that pass verification keep decision and retrieval receipts joinable for flywheel measurement.'
                        : '',
                ]);
                $aemorRunOutcomeId = trim((string) data_get($aemorResult, 'spine.ai_run_outcome.id', ''));
                if ($aemorRunOutcomeId === '') {
                    $aemorRunOutcomeId = null;
                }
            } catch (\Throwable) {
                // fail-open: outcome spine must never break the run
            }
        }

        // Context-pack ROI feedback (write side): AOBG requests used/noise/missed
        // after every pack and no internal flow ever answered — the retrieval
        // ranker never learned what was noise. Mechanical attribution from run
        // evidence:
        //   - diffed path refs match altered files;
        //   - cited refs match provider report/log mentions (files read, memory
        //     refs by COM-01 id/hash);
        //   - noise is only path refs that are BOTH untouched and uncited.
        // Unattributed memory refs stay OUT of noise (FEE-06). Same unit-test
        // guard as the ADML block. Fail-open.
        if (! app()->runningUnitTests() || app()->bound(AtlasRetrievalFeedbackLoopService::class)) {
            try {
                $projection = $this->storage->read($runId, ArtifactNames::OPEN_BRAIN_PROJECTION);
                $pathRefs = [];
                $memoryRefs = [];
                foreach (['code_refs', 'knowledge_refs'] as $bucket) {
                    foreach ((array) (is_array($projection) ? ($projection[$bucket] ?? []) : []) as $ref) {
                        $r = is_array($ref) ? (string) ($ref['ref'] ?? '') : '';
                        if ($r !== '') {
                            $pathRefs[] = $r;
                        }
                    }
                }
                foreach ((array) (is_array($projection) ? ($projection['memory_refs'] ?? []) : []) as $ref) {
                    $r = is_array($ref) ? (string) ($ref['ref'] ?? '') : '';
                    if ($r !== '') {
                        $memoryRefs[] = $r;
                    }
                }
                $delivered = array_values(array_merge($pathRefs, $memoryRefs));
                if ($delivered !== []) {
                    $changedPaths = array_map(
                        static fn (ScopeFileDiff $diff): string => $diff->path,
                        $scopeReceipt->observed->fileDiffs,
                    );
                    $reportLogCorpus = implode("\n", array_filter([
                        (string) $callResult->stdout,
                        (string) $callResult->stderr,
                    ]));
                    $diffedPath = array_values(array_filter($pathRefs, static function (string $ref) use ($changedPaths): bool {
                        foreach ($changedPaths as $path) {
                            if ($path !== '' && (str_contains($ref, $path) || str_contains($path, $ref))) {
                                return true;
                            }
                        }

                        return false;
                    }));
                    $citedPath = array_values(array_filter($pathRefs, static function (string $ref) use ($reportLogCorpus): bool {
                        return $ref !== '' && $reportLogCorpus !== '' && str_contains($reportLogCorpus, $ref);
                    }));
                    $citedMemory = array_values(array_filter(
                        $memoryRefs,
                        static fn (string $ref): bool => AtlasCanonicalContextRef::isMentionedInText($ref, $reportLogCorpus),
                    ));
                    $passed = $receipt->completion->status === CompletionSummary::STATUS_PASSED;
                    $usedPath = $passed ? AtlasCanonicalContextRef::uniqueStrings(array_merge($diffedPath, $citedPath)) : [];
                    $usedMemory = $passed ? $citedMemory : [];
                    $used = AtlasCanonicalContextRef::uniqueStrings(array_merge($usedPath, $usedMemory));
                    $attributionQuality = 'unmeasured';
                    if ($passed && ($citedPath !== [] || $citedMemory !== [])) {
                        $attributionQuality = 'cited';
                    } elseif ($passed && $diffedPath !== []) {
                        $attributionQuality = 'diffed';
                    }

                    $outcomeStatus = $passed
                        ? 'passed'
                        : ($receipt->completion->status === CompletionSummary::STATUS_NEEDS_REVIEW ? 'partial' : 'failed');
                    $contextPackHash = $this->governance->contextPackHash($runId);
                    $budget = $this->governance->contextPackBudgetForUtility($contextPackHash);
                    $utilityMeasurement = self::postExecutionUtilityMeasurement(
                        usedCount: count($used),
                        deliveredCount: count($delivered),
                        unresolvedMissedCount: 0,
                        outcomeStatus: $outcomeStatus,
                        estimatedChars: $budget['estimated_chars'],
                        totalBudgetChars: $budget['total_budget_chars'],
                    );

                    $feedbackInput = [
                        'objective' => $envelope->normalizedIntent,
                        'workspace' => $envelope->workspace,
                        'task_type' => $taskKind !== '' ? $taskKind : 'dev',
                        'domain' => 'atlas',
                        'risk_level' => strtolower($riskLevel),
                        'outcome_status' => $outcomeStatus,
                        'context_pack_hash' => $contextPackHash,
                        'retrieval_receipt_id' => $contextPackHash,
                        'delivered_context_refs' => $delivered,
                        'used_context_refs' => $used,
                        'noise_context_refs' => $passed ? array_values(array_diff($pathRefs, $usedPath)) : [],
                        'attribution_quality' => $utilityMeasurement !== null && $used !== [] ? 'gate_verified' : $attributionQuality,
                        'flow_id' => 'atlas.dev',
                        'record' => true,
                    ];
                    if ($aemorRunOutcomeId !== null) {
                        $feedbackInput['run_outcome_id'] = $aemorRunOutcomeId;
                    }
                    if ($utilityMeasurement !== null) {
                        $feedbackInput['post_execution_utility'] = $utilityMeasurement['post_execution_utility'];
                        $feedbackInput['post_execution_utility_formula_version'] = $utilityMeasurement['formula_version'];
                    }

                    app(AtlasRetrievalFeedbackLoopService::class)->capture($feedbackInput);
                }
            } catch (\Throwable) {
                // fail-open
            }
        }

        return new RunExecutionResult(
            completionState: $receipt->completion->status,
            scopeGuardStatus: $scopeReceipt->status,
            verificationStatus: $verificationResult->aggregateStatus,
            persistedReceiptPaths: $persisted,
            providerCallSummary: [
                'provider' => $callResult->actualProvider,
                'model_family' => $callResult->actualModelFamily,
                'provider_calls' => $providerCalls,
                'repair_attempts' => $repairAttempt,
                'repair' => $repairEvidence,
                'repair_abort_reason' => $abortReason,
                'exit_code' => $callResultForGates->exitStatus,
                'duration_ms' => $callResultForGates->durationMs,
                'tokens_in' => $callResultForGates->tokensIn,
                'tokens_out' => $callResultForGates->tokensOut,
                'estimated_cost_usd' => $callResultForGates->costEstimateUsd,
                'error_codes' => array_values($callResultForGates->errors),
                'raw_response_hash' => $callResult->rawResponseHash,
                'stdout_bytes' => strlen($callResult->stdout),
                'stderr_bytes' => strlen($callResult->stderr),
                'critic_status' => $criticAnalysed && $reviewReceipt !== null ? $reviewReceipt->status : null,
                'critic_exception' => $criticException?->getMessage(),
                'critic_findings_count' => $reviewReceipt !== null ? count($reviewReceipt->findings) : null,
                ...$bestOfNSummary !== null ? ['best_of_n' => $bestOfNSummary] : [],
            ],
            diffParseSummary: $diffResult->toSummaryArray(),
            verificationReceiptHash: $receipt->receiptHash,
            scopeGuardReceiptHash: $scopeReceipt->receiptHash,
            diffHash: $diffResult->diffHash(),
            // VAL-M2-030: surface the CompletionStateGate decision reasons
            // (incl. the specific elevation trip condition when a hard gate
            // forced STATUS_FAILED) on the run result so the CLI `--json`
            // receipt names WHICH gate tripped and WHY.
            reasons: array_values($decision->reasons),
        );
    }

    /**
     * F-03 fail-closed read of the persisted CompactSDD.
     *
     * The VerificationReceipt's task_kind / risk_level MUST mirror the
     * CompactSDD produced during planning. Surfaces never get to influence
     * them — surface_context.composer_task ("dev"/"debug"/"review") uses a
     * different vocabulary and would silently corrupt the receipt if used
     * as task_kind.
     *
     * If compact_sdd.json is missing, carries values outside the receipt's
     * allowed enums, or no longer matches the hash pinned by the persisted
     * MiniProgrammingSpec, we throw {@see CompactSddUnavailableException}. The
     * RunController maps that to HTTP 422 with a typed error code, and the
     * provider is never invoked — no wasted call on an unattestable run.
     *
     * @return array{0:string,1:string}
     */
    private function resolveTaskKindAndRiskLevel(string $runId, ?string $expectedCompactSddHash = null): array
    {
        $compactSdd = $this->storage->read($runId, ArtifactNames::COMPACT_SDD);
        if (! is_array($compactSdd)) {
            throw CompactSddUnavailableException::missing($runId);
        }

        $taskKind = $compactSdd['task_kind'] ?? null;
        if (! is_string($taskKind) || ! in_array($taskKind, VerificationReceipt::ALLOWED_TASK_KINDS, true)) {
            $observed = is_string($taskKind) ? $taskKind : gettype($taskKind);
            throw CompactSddUnavailableException::invalid(
                $runId,
                "task_kind '{$observed}' is not in VerificationReceipt::ALLOWED_TASK_KINDS",
            );
        }

        $riskLevel = $compactSdd['risk_level'] ?? null;
        if (! is_string($riskLevel) || ! in_array($riskLevel, VerificationReceipt::ALLOWED_RISK_LEVELS, true)) {
            $observed = is_string($riskLevel) ? $riskLevel : gettype($riskLevel);
            throw CompactSddUnavailableException::invalid(
                $runId,
                "risk_level '{$observed}' is not in VerificationReceipt::ALLOWED_RISK_LEVELS",
            );
        }

        $this->assertCompactSddHashPinned($runId, $compactSdd, $expectedCompactSddHash);

        return [$taskKind, $riskLevel];
    }

    /**
     * Three-layer integrity check on the on-disk CompactSDD:
     *
     *   1. **Self-hash**: the embedded `compact_sdd_hash` field must match the
     *      recomputed canonical hash of the same payload (catches tampering
     *      where the attacker forgot to recompute, OR didn't have the source
     *      data needed to recompute consistently).
     *   2. **Disk pin**: MiniProgrammingSpec was written by the orchestrator
     *      with the same `compact_sdd_hash`. The two on-disk artifacts must
     *      agree (catches single-file tampering).
     *   3. **Server-side pin**: when a confirmation_token row was issued at
     *      Plan time, it stored the canonical hash too. The token row is HMAC-
     *      keyed (only the server can forge it). The disk hash MUST match
     *      this server-side pin — this is what stops a coordinated rewrite
     *      of compact_sdd.json + mini_programming_spec.json.
     *
     * The third check is gated on `$expectedCompactSddHash` being provided.
     * The Run endpoint always passes it (sourced from
     * {@see ConfirmationTokenResult::$expectedCompactSddHash}); legacy / test
     * paths that bypass the controller can supply null and rely on the two
     * disk-only layers.
     *
     * @param  array<string, mixed>  $compactSdd
     */
    private function assertCompactSddHashPinned(
        string $runId,
        array $compactSdd,
        ?string $expectedCompactSddHash = null,
    ): void {
        $declaredHash = $compactSdd['compact_sdd_hash'] ?? null;
        if (! is_string($declaredHash) || $declaredHash === '') {
            throw CompactSddUnavailableException::invalid($runId, 'compact_sdd_hash is missing');
        }

        try {
            $dto = CompactSdd::fromArray($compactSdd);
        } catch (\Throwable $e) {
            throw CompactSddUnavailableException::invalid($runId, 'compact_sdd cannot be reconstructed: '.$e->getMessage());
        }

        $computedHash = $dto->hash();
        if (! hash_equals($declaredHash, $computedHash)) {
            throw CompactSddUnavailableException::tampered(
                $runId,
                'compact_sdd_hash does not match the current compact_sdd payload',
            );
        }

        // F-03 server-side pin: defeats the case where an attacker rewrites
        // BOTH compact_sdd.json AND mini_programming_spec.json with a fresh
        // hash. The expected hash here came from the HMAC-keyed token row,
        // which the attacker cannot forge without APP_KEY.
        if ($expectedCompactSddHash !== null && $expectedCompactSddHash !== '') {
            if (! hash_equals($expectedCompactSddHash, $declaredHash)) {
                throw CompactSddUnavailableException::tampered(
                    $runId,
                    'compact_sdd_hash does not match the server-side pin issued at plan time',
                );
            }
        }

        $miniSpec = $this->storage->read($runId, ArtifactNames::MINI_PROGRAMMING_SPEC);
        $pinnedHash = is_array($miniSpec) ? ($miniSpec['compact_sdd_hash'] ?? null) : null;
        if (! is_string($pinnedHash) || $pinnedHash === '') {
            throw CompactSddUnavailableException::tampered(
                $runId,
                'mini_programming_spec compact_sdd_hash pin is missing',
            );
        }

        if (! hash_equals($pinnedHash, $declaredHash)) {
            throw CompactSddUnavailableException::tampered(
                $runId,
                'compact_sdd_hash does not match the hash pinned by mini_programming_spec',
            );
        }
    }

    /**
     * Thin delegating shim kept on the façade for reflection-based tests
     * (RepairToGreenTest invokes it on the executor instance). Real
     * implementation: {@see PipelineRun\WorkspaceGitSupport::revertWorkspaceChanges()}.
     *
     * @param  list<string>  $allowedFiles
     * @param  array<string,array{status:string,contents:string|null}>  $baseline
     */
    private function revertWorkspaceChanges(string $workspace, array $allowedFiles, array $baseline): ?string
    {
        return $this->workspaceGit->revertWorkspaceChanges($workspace, $allowedFiles, $baseline);
    }

    /**
     * Thin delegating shim kept on the façade for the structural pin in
     * E6ConstitutionGateTest (method_exists on PipelineRunExecutor). Real
     * implementation: {@see PipelineRun\ResolverSupport::resolveE6Config()}.
     */
    private function resolveE6Config(): ElevationConfig
    {
        return $this->resolver->resolveE6Config();
    }
}
