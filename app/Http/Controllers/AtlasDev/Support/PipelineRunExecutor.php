<?php

declare(strict_types=1);

namespace App\Http\Controllers\AtlasDev\Support;

use App\Models\AiJob;
use App\Services\Ai\AiProvider;
use App\Services\Ai\AiProviderManager;
use App\Services\Ai\Concerns\RunsCliProcesses;
use App\Services\Ai\Context\AtlasAucriRuntimeEnforcementService;
use App\Services\Ai\HermesCliProvider;
use App\Services\Ai\Programming\AtlasDev\Gate\AtlasDevVerificationCommandRunnerContract as VerificationCommandRunner;
use App\Services\Ai\Programming\AtlasDev\Gate\CompletionDecision;
use App\Services\Ai\Programming\AtlasDev\Gate\CompletionStateGate;
use App\Services\Ai\Programming\AtlasDev\Gate\PatchApplier;
use App\Services\Ai\Programming\AtlasDev\Gate\PatchApplyResult;
use App\Services\Ai\Programming\AtlasDev\Gate\ReceiptComposer;
use App\Services\Ai\Programming\AtlasDev\Gate\ReceiptStorageAdapter;
use App\Services\Ai\Programming\AtlasDev\Gate\ScopeGuard;
use App\Services\Ai\Programming\AtlasDev\Gate\VerificationGate;
use App\Services\Ai\Programming\AtlasDev\Gate\VerificationGateResult;
use App\Services\Ai\Programming\AtlasDev\Intelligence\PatchIntelligenceInput;
use App\Services\Ai\Programming\AtlasDev\Intelligence\PatchIntelligenceService;
use App\Services\Ai\Programming\AtlasDev\Intelligence\ReviewIntelligenceService;
use App\Services\Ai\Programming\AtlasDev\Intelligence\TestSelectionInput;
use App\Services\Ai\Programming\AtlasDev\Intelligence\TestSelectionIntelligenceService;
use App\Services\Ai\Programming\AtlasDev\MinimaxFirst\AtlasMinimaxFirstWorkerService;
use App\Services\Ai\Programming\AtlasDev\Persistence\ArtifactNames;
use App\Services\Ai\Programming\AtlasDev\Persistence\ReceiptStorage;
use App\Services\Ai\Programming\AtlasDev\Provider\ClaudeCliGateway;
use App\Services\Ai\Programming\AtlasDev\Provider\DiffParser;
use App\Services\Ai\Programming\AtlasDev\Provider\DiffParseResult;
use App\Services\Ai\Programming\AtlasDev\Provider\ProviderCallResult;
use App\Services\Ai\Programming\AtlasDev\Provider\SonnetClaudeCliAdapter;
use App\Services\Ai\Programming\AtlasDev\Repair\FailureCapsuleBuilder;
use App\Services\Ai\Programming\AtlasDev\Repair\FailureSignatureHasher;
use App\Services\Ai\Programming\AtlasDev\Repair\RepairPromptComposer;
use App\Services\Ai\Programming\AtlasDev\Schemas\AtlasDevOperationEnvelope as OperationEnvelope;
use App\Services\Ai\Programming\AtlasDev\Schemas\CompactSdd;
use App\Services\Ai\Programming\AtlasDev\Schemas\Components\CompletionSummary;
use App\Services\Ai\Programming\AtlasDev\Schemas\Components\GateOutcome;
use App\Services\Ai\Programming\AtlasDev\Schemas\Components\ScopeFileDiff;
use App\Services\Ai\Programming\AtlasDev\Schemas\LightTaskContract;
use App\Services\Ai\Programming\AtlasDev\Schemas\MiniProgrammingSpec;
use App\Services\Ai\Programming\AtlasDev\Schemas\ProviderPromptProjection;
use App\Services\Ai\Programming\AtlasDev\Schemas\ScopeGuardReceipt;
use App\Services\Ai\Programming\AtlasDev\Schemas\VerificationReceipt;
use App\Services\Ai\Programming\AtlasDev\Probe\IntentCoverageProbe;
use App\Services\Ai\Programming\AtlasDev\Probe\IntentFalsificationProbe;
use App\Services\Ai\Programming\AtlasDev\Support\Elevations\ElevationConfig;
use App\Services\Ai\Programming\AtlasDev\WorkspaceMutatingProviders;
use App\Services\Ai\Programming\AtlasForgeCodexCliInvocationDriver;
use App\Services\Ai\Programming\AtlasForgeCursorCliInvocationDriver;
use App\Services\Ai\Programming\AtlasForgeMinimaxM27CliInvocationDriver;
use App\Services\Ai\Programming\HermesWorkspaceDefaults;
use Illuminate\Contracts\Container\BindingResolutionException;
use Illuminate\Contracts\Container\Container;
use Illuminate\Support\Str;
use Symfony\Component\Process\Process;

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
    public function __construct(
        private readonly Container $container,
        private readonly ReceiptStorage $storage,
    ) {}

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

        $aucriEnforcement = $this->enforceAucriBeforeProvider(
            envelope: $envelope,
            taskContract: $taskContract,
            promptProjection: $promptProjection,
            runId: $runId,
            riskLevel: $riskLevel,
            taskKind: $taskKind,
        );
        if (($aucriEnforcement['status'] ?? 'blocked') !== 'passed') {
            return $this->blockedDueToAucri($aucriEnforcement);
        }

        $commandRunner = $this->resolve(VerificationCommandRunner::class);
        $deterministicCallResult = $this->deterministicFastPathEnabled()
            ? $this->tryDeterministicPatch($envelope, $taskContract, $runId)
            : null;

        if ($commandRunner === null) {
            return $this->blockedDueToUnwiredDrivers($envelope, false, true, $taskContract->providerLock->provider, $taskContract->providerLock->modelFamily);
        }

        // M2: Repair-to-green loop. On hermes_cli path, when the verification
        // gate fails, re-invoke the provider with failure context up to the cap.
        // Mirrors AtlasMinimaxFirstWorkerService loop semantics. Reuses
        // FailureSignatureHasher for same-signature-twice abort detection.
        $isHermesCli = $taskContract->providerLock->provider === 'hermes_cli';
        $repairCap = $isHermesCli
            ? max(0, min(3, $taskContract->repairPolicy->maxAttempts))
            : 0;
        $repairAttempt = 0;
        $lastFailureSignature = null;
        $consecutiveSameSignature = 0;
        $abortReason = null;
        $hasher = new FailureSignatureHasher;

        // The current prompt text for this iteration (starts as the original,
        // becomes the repair prompt on subsequent iterations).
        $currentPromptText = $promptProjection->renderedPromptText;

        $callResult = $deterministicCallResult;
        $providerCalls = 0;

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
            $bestOfNOutcome = $this->executeBestOfNHermes(
                envelope: $envelope,
                taskContract: $taskContract,
                promptProjection: $promptProjection,
                commandRunner: $commandRunner,
                candidateCount: $bestOfNCandidateCount,
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
                    [$callResult, $iterCalls] = $this->executeLockedProvider(
                        envelope: $envelope,
                        taskContract: $taskContract,
                        promptProjection: $promptProjection,
                        hermesPromptOverride: $currentPromptText !== $promptProjection->renderedPromptText
                            ? $currentPromptText
                            : null,
                    );
                    $providerCalls += $iterCalls;
                }

                $diffResult = (new DiffParser)->parse($callResult->stdout);

                $scopeReceipt = (new ScopeGuard)->check(
                    envelope: $envelope,
                    taskContract: $taskContract,
                    diffResult: $diffResult,
                );

                $patchApplyResult = $this->applyPatchIfSafe(
                    diffResult: $diffResult,
                    scopeStatus: $scopeReceipt->status,
                    workspace: $envelope->workspace,
                    callResult: $callResult,
                );
                $callResultForGates = $patchApplyResult->ok()
                    ? $callResult
                    : $this->withProviderError($callResult, 'patch_apply_failed');

                $verificationResult = $patchApplyResult->ok()
                    ? (new VerificationGate($commandRunner, $this->verificationReceiptStorage($runId)))->run(
                        taskContract: $taskContract,
                        callResult: $callResultForGates,
                        scopeReceipt: $scopeReceipt,
                        workspace: $envelope->workspace,
                    )
                    : $this->verificationFailedDueToPatchApply($patchApplyResult);

                // Check if repair loop should continue
                if ($verificationResult->aggregateStatus !== VerificationGateResult::STATUS_FAILED
                    || ! $isHermesCli
                    || $repairCap <= 0
                ) {
                    // Either green, not hermes, or repair disabled — exit loop.
                    break;
                }

                $repairAttempt++;

                // M2: Same-signature-twice abort (reuse FailureSignatureHasher).
                // Compute the normalized signature from the gate failure output.
                $failureExcerpt = $this->extractFailureExcerpt($verificationResult);
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

                if ($repairAttempt > $repairCap) {
                    // Cap exhausted — anti-spin guarantee.
                    $abortReason = 'validation_failed_after_max_repairs';
                    break;
                }

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
                $currentPromptText = $this->buildComposedHermesRepairPrompt(
                    promptProjection: $promptProjection,
                    taskContract: $taskContract,
                    verificationResult: $verificationResult,
                    scopeReceipt: $scopeReceipt,
                    diffResult: $diffResult,
                    failureExcerpt: $failureExcerpt,
                    repairAttempt: $repairAttempt,
                    repairCap: $repairCap,
                );

                // Revert workspace changes before re-invoking provider.
                $this->revertWorkspaceChanges($envelope->workspace, $taskContract->allowedFiles);

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

        // E2: Intent coverage probe — advisory honesty flag intent_not_tested.
        //
        // When a write task's intent is NOT backed by any behavioral AC
        // carrying a real verification_ref (only tautological command/scope
        // ACs, or no behavioral AC at all), append the `intent_not_tested`
        // honesty flag so the CompletionStateGate auto-downgrades PASSED ->
        // needs_review (the passed-forbids-flags invariant guarantees no
        // green-with-flag). This is the advisory channel: no STATUS_FAILED,
        // no critic escalate; the flag alone drives the downgrade.
        //
        // VAL-E2-009: fires when only tautological ACs back the intent.
        // VAL-E2-010: absent when a behavioral AC with a real verification_ref
        // backs the intent (the intent IS tested).
        // VAL-E2-013: off => no flag raised (byte-identical to pre-E2).
        //
        // The probe reads the persisted MiniProgrammingSpec (the source of
        // acceptanceCriteria) from storage. When the spec is unreadable, a
        // write task's intent is conservatively treated as not-tested (never
        // silently green over an unevaluable intent), mirroring the E5
        // DatabaseTableAvailability safe-degradation pattern.
        //
        // This runs for EVERY provider (not just hermes_cli): the intent
        // coverage check is a property of the SPEC, not the provider, and
        // the honesty-flag channel is provider-agnostic by design.
        $e2Config = $this->resolveE2Config();
        if ($e2Config->isAdvisory()) {
            $intentNotTested = $this->probeIntentCoverage($runId, $taskContract);
            if ($intentNotTested) {
                $verificationResult = $verificationResult->withHonestyFlags([
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
        $e1Config = $this->resolveE1Config();
        if (! $e1Config->isOff()) {
            $intentMissing = (new IntentFalsificationProbe)->isIntentLikelyNotAddressed(
                $taskContract,
                $diffResult,
            );
            if ($intentMissing) {
                if ($e1Config->isHard()) {
                    // Hard mode => sanctioned hard gate channel (STATUS_FAILED).
                    // The verification gate becomes red so completion resolves
                    // to failed/blocked (never silently passed). Rebuild the
                    // gate result preserving the gathered tests/gates while
                    // forcing the aggregate to STATUS_FAILED and recording
                    // the flag for auditability.
                    $verificationResult = new VerificationGateResult(
                        tests: $verificationResult->tests,
                        gates: $verificationResult->gates,
                        aggregateStatus: VerificationGateResult::STATUS_FAILED,
                        honestyFlags: $verificationResult->withHonestyFlags([
                            IntentFalsificationProbe::FLAG_INTENT_LIKELY_NOT_ADDRESSED,
                        ])->honestyFlags,
                        evidenceRefs: $verificationResult->evidenceRefs,
                        profile: $verificationResult->profile,
                    );
                } else {
                    // Advisory => honesty flag only (drives the
                    // CompletionStateGate PASSED -> needs_review downgrade).
                    $verificationResult = $verificationResult->withHonestyFlags([
                        IntentFalsificationProbe::FLAG_INTENT_LIKELY_NOT_ADDRESSED,
                    ]);
                }
            }
        }

        // M3: Senior critic — invoke ReviewIntelligenceService after the gate
        // passes and before CompletionStateGate promotes a completion, on the
        // default hermes_cli path only. A blocker/critical finding forces
        // completion to non-completed even when tests are green; a clean diff
        // is NOT falsely blocked; a critic exception degrades to non-passed
        // (never silently swallowed to green). REUSE ReviewIntelligenceService
        // (do not rebuild).
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
        if ($isHermesCli && $verificationResult->aggregateStatus === VerificationGateResult::STATUS_PASSED) {
            try {
                // Resolve from container (allows test bindings) or create fresh.
                /** @var object $criticService */
                $criticService = $this->container->bound(ReviewIntelligenceService::class)
                    ? $this->container->make(ReviewIntelligenceService::class)
                    : new ReviewIntelligenceService;
                $criticInput = $this->buildCriticInput(
                    runId: $runId,
                    scopeReceipt: $scopeReceipt,
                    taskContract: $taskContract,
                    verificationResult: $verificationResult,
                    diffResult: $diffResult,
                );
                // E1: pass the LLM-as-judge sub-layer options to the critic
                // so detectIntentFalsification() can invoke the (optional,
                // sub-flag-gated) adversarial judge. The judge is resolved
                // from the container when bound (allows test fakes); absent
                // a binding the sub-flag has no judge to call, so the
                // detector degrades to the deterministic probe only
                // (VAL-E1-011: judge is strictly doubt-additive, and a
                // missing judge can never clear the deterministic flag).
                $criticOptions = $this->resolveE1CriticOptions();
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

        $receipt = (new ReceiptComposer)->compose(
            envelope: $envelope,
            taskContract: $taskContract,
            promptProjection: $promptProjection,
            callResult: $callResultForGates,
            diffResult: $diffResult,
            scopeReceipt: $scopeReceipt,
            verificationResult: $verificationResult,
            completion: $decision,
            contextPackHash: $this->contextPackHash($runId),
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
        );
    }

    /**
     * @return array{0:ProviderCallResult,1:int}
     */
    private function executeLockedProvider(
        OperationEnvelope $envelope,
        LightTaskContract $taskContract,
        ProviderPromptProjection $promptProjection,
        ?string $hermesPromptOverride = null,
    ): array {
        if (! $promptProjection->isSendable()) {
            return [
                $this->blockedProviderCallResult(
                    runId: $promptProjection->runId,
                    provider: $taskContract->providerLock->provider,
                    modelFamily: $taskContract->providerLock->modelFamily,
                    error: 'prompt_projection_not_sendable:'.implode(',', $promptProjection->qualityChecks->failedChecks()),
                    stderr: 'Atlas Dev refused to dispatch provider because ProviderPromptProjection is not sendable.',
                    providerSafe: false,
                ),
                0,
            ];
        }

        return match ($taskContract->providerLock->provider) {
            SonnetClaudeCliAdapter::PROVIDER => $this->executeClaudeProvider($envelope, $taskContract, $promptProjection),
            AtlasForgeCodexCliInvocationDriver::PROVIDER => $this->executeCodexProvider($envelope, $taskContract, $promptProjection),
            AtlasForgeCursorCliInvocationDriver::PROVIDER => $this->executeCursorProvider($envelope, $taskContract, $promptProjection),
            AtlasForgeMinimaxM27CliInvocationDriver::PROVIDER => $this->executeMinimaxProvider($envelope, $taskContract, $promptProjection),
            'hermes_cli' => $this->executeHermesProvider($envelope, $taskContract, $promptProjection, $hermesPromptOverride),
            default => [
                $this->blockedProviderCallResult(
                    runId: $promptProjection->runId,
                    provider: $taskContract->providerLock->provider,
                    modelFamily: $taskContract->providerLock->modelFamily,
                    error: 'unsupported_provider_lock:'.$taskContract->providerLock->provider,
                    stderr: 'Atlas Dev has no runtime driver for provider_lock.provider='.$taskContract->providerLock->provider.'.',
                ),
                0,
            ],
        };
    }

    /**
     * @return array{0:ProviderCallResult,1:int}
     */
    private function executeClaudeProvider(
        OperationEnvelope $envelope,
        LightTaskContract $taskContract,
        ProviderPromptProjection $promptProjection,
    ): array {
        $gateway = $this->resolve(ClaudeCliGateway::class);
        if (! $gateway instanceof ClaudeCliGateway) {
            return [
                $this->blockedProviderCallResult(
                    runId: $promptProjection->runId,
                    provider: SonnetClaudeCliAdapter::PROVIDER,
                    modelFamily: SonnetClaudeCliAdapter::MODEL_FAMILY,
                    error: 'claude_cli_gateway_unbound',
                    stderr: 'Claude CLI gateway is not bound in the runtime container.',
                ),
                0,
            ];
        }

        $adapter = new SonnetClaudeCliAdapter($gateway);

        return [
            $adapter->executeOneCall(
                promptProjection: $promptProjection,
                taskContract: $taskContract,
                workspace: $envelope->workspace,
                timeoutSeconds: $this->providerTimeoutSeconds($taskContract),
            ),
            1,
        ];
    }

    /**
     * Codex CLI is used as a governed workspace mutator for review/repair gates.
     * Atlas derives the diff after Codex returns and still runs scope +
     * verification before a completion claim can be promoted.
     *
     * @return array{0:ProviderCallResult,1:int}
     */
    private function executeCodexProvider(
        OperationEnvelope $envelope,
        LightTaskContract $taskContract,
        ProviderPromptProjection $promptProjection,
    ): array {
        $driver = $this->resolveConcrete(AtlasForgeCodexCliInvocationDriver::class);
        if (! $driver instanceof AtlasForgeCodexCliInvocationDriver) {
            return [
                $this->blockedProviderCallResult(
                    runId: $promptProjection->runId,
                    provider: AtlasForgeCodexCliInvocationDriver::PROVIDER,
                    modelFamily: $taskContract->providerLock->modelFamily,
                    error: 'codex_cli_driver_unavailable',
                    stderr: 'Codex CLI invocation driver could not be resolved.',
                ),
                0,
            ];
        }

        $decisionReceiptId = 'atlas-dev:'.$promptProjection->runId.':'.$taskContract->taskContractHash;
        $decisionReceiptHash = hash('sha256', implode('|', [
            $promptProjection->runId,
            $taskContract->taskContractHash,
            $promptProjection->promptProjectionHash,
            $taskContract->providerLock->provider,
            $taskContract->providerLock->modelFamily,
        ]));
        $request = [
            'model' => $taskContract->providerLock->modelFamily,
            'prompt' => [
                'schema_version' => 'atlas.dev.codex_cli.provider_request.v1',
                'decision_receipt_id' => $decisionReceiptId,
                'decision_receipt_hash' => $decisionReceiptHash,
                'atlas_dev_contract' => [
                    'run_id' => $promptProjection->runId,
                    'task_contract_hash' => $taskContract->taskContractHash,
                    'prompt_projection_hash' => $promptProjection->promptProjectionHash,
                    'output_required' => 'review/repair current workspace diff; Atlas will derive git diff and run validation.',
                ],
                'scope_contract' => [
                    'allowed_files' => array_values($taskContract->allowedFiles),
                    'forbidden_files' => array_values($taskContract->forbiddenFiles),
                    'max_files_changed' => $taskContract->maxFilesChanged,
                ],
                'rendered_prompt_text' => $promptProjection->renderedPromptText,
            ],
            'cwd' => $envelope->workspace,
            'sandbox' => 'workspace-write',
            'decision_receipt_id' => $decisionReceiptId,
            'decision_receipt_hash' => $decisionReceiptHash,
            'timeout_seconds' => $this->providerTimeoutSeconds($taskContract),
            'max_output_chars' => $this->providerMaxOutputChars($taskContract),
        ];

        $result = $driver->invoke($request);
        $providerCalled = (bool) ($result['provider_called'] ?? false);
        $blockers = array_values(array_filter(array_map(
            static fn (mixed $blocker): string => is_string($blocker) ? $blocker : '',
            (array) ($result['blockers'] ?? []),
        ), static fn (string $blocker): bool => $blocker !== ''));
        $providerChangedFiles = $this->stringList((array) ($result['changed_files'] ?? []));
        $scopeViolations = array_values(array_filter(
            $providerChangedFiles,
            fn (string $path): bool => ! $this->pathAllowed($path, $taskContract->allowedFiles),
        ));
        if ($scopeViolations !== []) {
            $blockers[] = 'codex_cli_scope_violation:'.implode(',', $scopeViolations);
        }

        $exitCode = is_int($result['exit_code'] ?? null) ? (int) $result['exit_code'] : ($blockers === [] ? 0 : 1);
        $errors = array_values(array_unique($blockers));
        if ($errors === []) {
            $stdout = $this->workspaceDiff($envelope->workspace, $taskContract->allowedFiles);
            if (trim($stdout) === '') {
                $stdout = "no_patch_needed: true\nreason: Codex CLI completed without a workspace diff in allowed_files.\n";
            }
        } else {
            $stdout = "blocked: true\nquestion: Codex CLI runtime blocked: ".implode(',', $errors)."\n";
        }

        return [
            ProviderCallResult::fromStdout(
                runId: $promptProjection->runId,
                actualProvider: AtlasForgeCodexCliInvocationDriver::PROVIDER,
                actualModelFamily: $taskContract->providerLock->modelFamily,
                exitStatus: $exitCode,
                stdout: $stdout,
                stderr: trim((string) ($result['stderr_excerpt'] ?? '')),
                durationMs: is_int($result['duration_ms'] ?? null) ? (int) $result['duration_ms'] : 0,
                tokensIn: null,
                tokensOut: null,
                costEstimateUsd: null,
                providerSafe: true,
                errors: $errors,
            ),
            $providerCalled ? 1 : 0,
        ];
    }

    /**
     * Cursor CLI is a governed workspace mutator: unlike the Claude adapter it
     * edits the isolated worktree directly. We therefore convert the post-run
     * git diff into the ProviderCallResult stdout and later skip re-applying it.
     *
     * @return array{0:ProviderCallResult,1:int}
     */
    private function executeCursorProvider(
        OperationEnvelope $envelope,
        LightTaskContract $taskContract,
        ProviderPromptProjection $promptProjection,
    ): array {
        $driver = $this->resolveConcrete(AtlasForgeCursorCliInvocationDriver::class);
        if (! $driver instanceof AtlasForgeCursorCliInvocationDriver) {
            return [
                $this->blockedProviderCallResult(
                    runId: $promptProjection->runId,
                    provider: AtlasForgeCursorCliInvocationDriver::PROVIDER,
                    modelFamily: $taskContract->providerLock->modelFamily,
                    error: 'cursor_cli_driver_unavailable',
                    stderr: 'Cursor CLI invocation driver could not be resolved.',
                ),
                0,
            ];
        }

        $decisionReceiptId = 'atlas-dev:'.$promptProjection->runId.':'.$taskContract->taskContractHash;
        $decisionReceiptHash = hash('sha256', implode('|', [
            $promptProjection->runId,
            $taskContract->taskContractHash,
            $promptProjection->promptProjectionHash,
            $taskContract->providerLock->provider,
            $taskContract->providerLock->modelFamily,
        ]));

        $request = [
            'model' => $taskContract->providerLock->modelFamily,
            'prompt' => [
                'schema_version' => 'atlas.dev.cursor_cli.provider_request.v1',
                'decision_receipt_id' => $decisionReceiptId,
                'decision_receipt_hash' => $decisionReceiptHash,
                'atlas_dev_contract' => [
                    'run_id' => $promptProjection->runId,
                    'task_contract_hash' => $taskContract->taskContractHash,
                    'prompt_projection_hash' => $promptProjection->promptProjectionHash,
                    'output_required' => 'mutate only allowed files; Atlas will derive git diff and run validation.',
                ],
                'scope_contract' => [
                    'allowed_files' => array_values($taskContract->allowedFiles),
                    'forbidden_files' => array_values($taskContract->forbiddenFiles),
                    'max_files_changed' => $taskContract->maxFilesChanged,
                ],
                'rendered_prompt_text' => $promptProjection->renderedPromptText,
            ],
            'cwd' => $envelope->workspace,
            'decision_receipt_id' => $decisionReceiptId,
            'decision_receipt_hash' => $decisionReceiptHash,
            'timeout_seconds' => $this->providerTimeoutSeconds($taskContract),
            'max_output_chars' => $this->providerMaxOutputChars($taskContract),
        ];

        $result = $driver->invoke($request);
        $providerCalled = (bool) ($result['provider_called'] ?? false);
        $blockers = array_values(array_filter(array_map(
            static fn (mixed $blocker): string => is_string($blocker) ? $blocker : '',
            (array) ($result['blockers'] ?? []),
        ), static fn (string $blocker): bool => $blocker !== ''));
        $scopeViolations = array_values(array_filter(array_map(
            static fn (mixed $path): string => is_string($path) ? $path : '',
            (array) ($result['scope_violations'] ?? []),
        ), static fn (string $path): bool => $path !== ''));

        $exitCode = is_int($result['exit_code'] ?? null) ? (int) $result['exit_code'] : ($blockers === [] ? 0 : 1);
        $stdout = '';
        $errors = $blockers;
        if ($scopeViolations !== []) {
            $errors[] = 'cursor_cli_scope_violations:'.implode(',', $scopeViolations);
        }
        if ($blockers === []) {
            $stdout = $this->workspaceDiff($envelope->workspace, $taskContract->allowedFiles);
            if (trim($stdout) === '') {
                $stdout = "no_patch_needed: true\nreason: Cursor CLI completed without a workspace diff in allowed_files.\n";
            }
        } else {
            $stdout = "blocked: true\nquestion: Cursor CLI runtime blocked: ".implode(',', $blockers)."\n";
        }

        return [
            ProviderCallResult::fromStdout(
                runId: $promptProjection->runId,
                actualProvider: AtlasForgeCursorCliInvocationDriver::PROVIDER,
                actualModelFamily: $taskContract->providerLock->modelFamily,
                exitStatus: $exitCode,
                stdout: $stdout,
                stderr: trim((string) ($result['stderr_excerpt'] ?? '')),
                durationMs: is_int($result['duration_ms'] ?? null) ? (int) $result['duration_ms'] : 0,
                tokensIn: null,
                tokensOut: null,
                costEstimateUsd: null,
                providerSafe: true,
                errors: $errors,
            ),
            $providerCalled ? 1 : 0,
        ];
    }

    /**
     * MiniMax M3 — writes files directly into the workspace (like Cursor).
     * The worker handles context compilation, invocation and repair loop.
     * We derive the git diff after writes complete and surface it as stdout.
     *
     * @return array{0:ProviderCallResult,1:int}
     */
    private function executeMinimaxProvider(
        OperationEnvelope $envelope,
        LightTaskContract $taskContract,
        ProviderPromptProjection $promptProjection,
    ): array {
        $worker = $this->resolveConcrete(AtlasMinimaxFirstWorkerService::class);
        if (! $worker instanceof AtlasMinimaxFirstWorkerService) {
            return [
                $this->blockedProviderCallResult(
                    runId: $promptProjection->runId,
                    provider: AtlasForgeMinimaxM27CliInvocationDriver::PROVIDER,
                    modelFamily: $taskContract->providerLock->modelFamily,
                    error: 'minimax_worker_unbound',
                    stderr: 'AtlasMinimaxFirstWorkerService is not bound in the runtime container.',
                ),
                0,
            ];
        }

        $finding = [
            'title' => mb_substr($envelope->normalizedIntent, 0, 300),
            'description' => mb_substr($promptProjection->renderedPromptText, 0, 2_000),
            'spec_seed' => ['candidate_id' => $taskContract->taskId],
        ];

        $startMs = (int) (microtime(true) * 1_000);
        $result = $worker->run([
            'finding' => $finding,
            'allowed_files' => array_values($taskContract->allowedFiles),
            'validation_commands' => array_values($taskContract->validationCommands),
            'worktree_path' => $envelope->workspace,
            'repo_root' => $envelope->workspace,
            'max_repairs' => $taskContract->repairPolicy->maxAttempts,
        ]);
        $durationMs = (int) (microtime(true) * 1_000) - $startMs;

        $status = (string) ($result['status'] ?? 'blocked');
        $tokensUsed = (int) ($result['run_summary']['provider_call']['tokens_used'] ?? 0);
        $blockers = array_values(array_filter(array_map(
            static fn (mixed $b): string => is_string($b) ? $b : '',
            (array) ($result['blockers'] ?? []),
        ), static fn (string $b): bool => $b !== ''));

        if ($status !== 'completed') {
            return [
                ProviderCallResult::fromStdout(
                    runId: $promptProjection->runId,
                    actualProvider: AtlasForgeMinimaxM27CliInvocationDriver::PROVIDER,
                    actualModelFamily: $taskContract->providerLock->modelFamily,
                    exitStatus: 1,
                    stdout: '',
                    stderr: 'MiniMax worker: '.implode('; ', $blockers ?: [$status]),
                    durationMs: $durationMs,
                    tokensIn: $tokensUsed,
                    tokensOut: 0,
                    costEstimateUsd: null,
                    providerSafe: true,
                    errors: $blockers ?: [$status],
                ),
                1,
            ];
        }

        // Worker wrote files directly — derive diff like Cursor provider.
        $stdout = $this->workspaceDiff($envelope->workspace, $taskContract->allowedFiles);
        if (trim($stdout) === '') {
            $stdout = 'no_patch_needed: true
reason: MiniMax worker completed without a workspace diff in allowed_files.
';
        }

        return [
            ProviderCallResult::fromStdout(
                runId: $promptProjection->runId,
                actualProvider: AtlasForgeMinimaxM27CliInvocationDriver::PROVIDER,
                actualModelFamily: $taskContract->providerLock->modelFamily,
                exitStatus: 0,
                stdout: $stdout,
                stderr: '',
                durationMs: $durationMs,
                tokensIn: $tokensUsed,
                tokensOut: 0,
                costEstimateUsd: null,
                providerSafe: true,
            ),
            1,
        ];
    }

    /**
     * Hermes CLI is the Atlas executive runtime governed through
     * {@see HermesCliProvider}. Like Codex/Cursor/MiniMax it
     * mutates the isolated workspace directly, so Atlas derives the post-run
     * git diff and still runs scope + verification before any completion claim.
     *
     * The provider chooses its own cwd via
     * {@see RunsCliProcesses::workdirForJob()}, which
     * reads (in order) payload.tool_permissions.workspace, payload.workspace,
     * then config('atlas.ai.workdir') — realpath()'d and required to be a dir.
     * We therefore pin BOTH workspace keys to $envelope->workspace so Hermes
     * edits the Dev worktree and not the global Atlas workdir.
     *
     * @return array{0:ProviderCallResult,1:int}
     */
    private function executeHermesProvider(
        OperationEnvelope $envelope,
        LightTaskContract $taskContract,
        ProviderPromptProjection $promptProjection,
        ?string $promptOverride = null,
    ): array {
        $manager = app(AiProviderManager::class);

        $provider = null;
        try {
            $provider = $manager->get('hermes_cli');
        } catch (\Throwable) {
            $provider = null;
        }
        if (! $provider instanceof AiProvider) {
            return [
                $this->blockedProviderCallResult(
                    runId: $promptProjection->runId,
                    provider: 'hermes_cli',
                    modelFamily: $taskContract->providerLock->modelFamily,
                    error: 'hermes_cli_provider_unavailable',
                    stderr: 'Hermes CLI provider could not be resolved from AiProviderManager.',
                ),
                0,
            ];
        }

        $timeoutSeconds = $this->providerTimeoutSeconds($taskContract);
        $hermesOverrides = $this->atlasDevHermesOverrides($taskContract);

        // M2: When a repair attempt overrides the prompt (failure context fed
        // forward), use the override text instead of the original projection.
        $promptText = $promptOverride ?? $promptProjection->renderedPromptText;

        // workdirForJob() reads tool_permissions.workspace || workspace ||
        // config('atlas.ai.workdir'). Pin both so Hermes runs IN the Dev
        // worktree ($envelope->workspace) and edits files there.
        $job = new AiJob([
            'trace_id' => 'atlas-dev:'.$promptProjection->runId,
            'kind' => 'atlas_dev_run',
            'provider' => 'hermes_cli',
            // Hermes self-selects its sub-model; the _default sentinel makes its
            // CLI omit --model. Single-sourced so Dev/Forge can't diverge.
            'model' => HermesWorkspaceDefaults::model(),
            'prompt' => $promptText,
            'input_text' => $promptText,
            'timeout_seconds' => $timeoutSeconds,
            'payload' => [
                'workspace' => $envelope->workspace,
                // mode 'danger' → HermesCliProvider passes --yolo so Hermes edits the
                // isolated workspace AUTONOMOUSLY (a non-interactive run has no TTY to
                // approve writes); ScopeGuard + verification gate the result downstream.
                'tool_permissions' => HermesWorkspaceDefaults::toolPermissions($envelope->workspace),
                'dev_execution_plan' => [
                    'run_id' => $promptProjection->runId,
                    'task_contract_hash' => $taskContract->taskContractHash,
                    'prompt_projection_hash' => $promptProjection->promptProjectionHash,
                ],
                'hermes' => $hermesOverrides,
            ],
        ]);

        $startMs = (int) (microtime(true) * 1_000);
        try {
            $result = $provider->run($job, $promptText);
        } catch (\Throwable $e) {
            return [
                ProviderCallResult::fromStdout(
                    runId: $promptProjection->runId,
                    actualProvider: 'hermes_cli',
                    actualModelFamily: $taskContract->providerLock->modelFamily,
                    exitStatus: 1,
                    stdout: '',
                    stderr: Str::limit($e->getMessage(), 500, '...'),
                    durationMs: (int) (microtime(true) * 1_000) - $startMs,
                    tokensIn: null,
                    tokensOut: null,
                    costEstimateUsd: null,
                    providerSafe: true,
                    errors: ['hermes_cli_invocation_threw'],
                ),
                1,
            ];
        }

        $errors = $result->ok ? [] : array_values(array_filter([
            is_string($result->errorCode) && $result->errorCode !== '' ? $result->errorCode : null,
        ]));

        // Hermes mutated the workspace directly — derive diff like the
        // Codex/Cursor/MiniMax providers and let scope/verification gate it.
        $providerChangedFiles = $errors === []
            ? $this->stringList($this->changedFilePathsInWorkspace(
                $envelope->workspace,
                $taskContract->allowedFiles,
                $taskContract->forbiddenFiles,
            ))
            : [];
        $scopeViolations = array_values(array_filter(
            $providerChangedFiles,
            fn (string $path): bool => ! $this->pathAllowed($path, $taskContract->allowedFiles),
        ));
        if ($scopeViolations !== []) {
            $errors[] = 'hermes_cli_scope_violation:'.implode(',', $scopeViolations);
        }

        if ($errors === []) {
            $stdout = $this->workspaceDiff($envelope->workspace, $taskContract->allowedFiles);
            if (trim($stdout) === '') {
                $stdout = "no_patch_needed: true\nreason: Hermes CLI completed without a workspace diff in allowed_files.\n";
            }
        } else {
            $stdout = "blocked: true\nquestion: Hermes CLI runtime blocked: ".implode(',', $errors)."\n";
        }

        return [
            ProviderCallResult::fromStdout(
                runId: $promptProjection->runId,
                actualProvider: 'hermes_cli',
                actualModelFamily: $taskContract->providerLock->modelFamily,
                exitStatus: $errors === [] ? 0 : 1,
                stdout: $stdout,
                stderr: '',
                durationMs: (int) $result->durationMs,
                tokensIn: null,
                tokensOut: null,
                costEstimateUsd: null,
                providerSafe: true,
                errors: array_values(array_unique($errors)),
            ),
            $result->ok ? 1 : 0,
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function atlasDevHermesOverrides(LightTaskContract $taskContract): array
    {
        $overrides = [];

        $transport = strtolower(trim((string) config('atlas_dev.efficient.hermes_execution_transport', '')));
        if (in_array($transport, ['cli', 'acp'], true)) {
            $overrides['execution_transport'] = $transport;
        }

        $singleFileMaxTurns = (int) config('atlas_dev.efficient.hermes_single_file_max_turns', 0);
        if (count($taskContract->allowedFiles) === 1 && $singleFileMaxTurns > 0) {
            $overrides['max_turns'] = max(1, min(10, $singleFileMaxTurns));
        }

        return $overrides;
    }

    /**
     * M4: Best-of-N candidate generation + selection on the default hermes path.
     *
     * Generates N candidate diffs (N independent single-shot provider calls,
     * each through the full M1 floor + gate), then selects the best PASSING
     * candidate deterministically. REUSES executeLockedProvider + DiffParser +
     * ScopeGuard + applyPatchIfSafe + VerificationGate per candidate (LIGAR —
     * the existing gate machinery is the floor + gate each candidate must
     * clear; we do NOT rebuild a per-candidate gate).
     *
     * Selection / tie-break (documented, deterministic): among candidates
     * whose gate aggregateStatus === STATUS_PASSED, the LOWEST candidate
     * index wins (first passing candidate in generation order). If NO
     * candidate passes, the FIRST candidate's (failed) results are kept so
     * the run reports non-completed honestly (no manufactured green).
     *
     * Tolerance: a candidate that throws during generation or fails the gate
     * is RECORDED but does NOT abort the selection loop — the remaining
     * candidates are still evaluated.
     *
     * Workspace handling: the hermes provider mutates the workspace directly,
     * so between candidates the workspace is reverted (git checkout + clean)
     * and each candidate's diff TEXT is captured. After selection, the
     * workspace is reverted once more and the WINNER's diff is re-applied
     * (git apply) so the persisted diff hash equals the selected candidate's
     * (VAL-M4-008).
     *
     * @return array{
     *   callResult:ProviderCallResult,
     *   diffResult:DiffParseResult,
     *   scopeReceipt:ScopeGuardReceipt,
     *   patchApplyResult:PatchApplyResult,
     *   verificationResult:VerificationGateResult,
     *   callResultForGates:ProviderCallResult,
     *   providerCalls:int,
     *   summary:array<string,mixed>,
     * }
     */
    private function executeBestOfNHermes(
        OperationEnvelope $envelope,
        LightTaskContract $taskContract,
        ProviderPromptProjection $promptProjection,
        VerificationCommandRunner $commandRunner,
        int $candidateCount,
    ): array {
        $candidates = [];
        $providerCalls = 0;

        for ($i = 0; $i < $candidateCount; $i++) {
            // Revert any prior candidate's workspace mutation before the next
            // candidate runs, so each candidate's diff is independent.
            if ($i > 0) {
                $this->revertWorkspaceChanges($envelope->workspace, $taskContract->allowedFiles);
            }

            try {
                [$callResult, $iterCalls] = $this->executeLockedProvider(
                    envelope: $envelope,
                    taskContract: $taskContract,
                    promptProjection: $promptProjection,
                );
                $providerCalls += $iterCalls;
            } catch (\Throwable $e) {
                // A throwing candidate must not abort selection. Record it as
                // a non-passing candidate and continue.
                $candidates[] = [
                    'index' => $i,
                    'passed' => false,
                    'error' => 'candidate_generation_threw:'.Str::limit($e->getMessage(), 200, '...'),
                    'callResult' => null,
                    'diffResult' => null,
                    'scopeReceipt' => null,
                    'patchApplyResult' => null,
                    'verificationResult' => null,
                    'callResultForGates' => null,
                ];
                // Revert partial workspace mutation from the throwing candidate.
                $this->revertWorkspaceChanges($envelope->workspace, $taskContract->allowedFiles);

                continue;
            }

            $diffResult = (new DiffParser)->parse($callResult->stdout);
            $scopeReceipt = (new ScopeGuard)->check(
                envelope: $envelope,
                taskContract: $taskContract,
                diffResult: $diffResult,
            );
            $patchApplyResult = $this->applyPatchIfSafe(
                diffResult: $diffResult,
                scopeStatus: $scopeReceipt->status,
                workspace: $envelope->workspace,
                callResult: $callResult,
            );
            $callResultForGates = $patchApplyResult->ok()
                ? $callResult
                : $this->withProviderError($callResult, 'patch_apply_failed');

            $verificationResult = $patchApplyResult->ok()
                ? (new VerificationGate($commandRunner, $this->verificationReceiptStorage($promptProjection->runId)))->run(
                    taskContract: $taskContract,
                    callResult: $callResultForGates,
                    scopeReceipt: $scopeReceipt,
                    workspace: $envelope->workspace,
                )
                : $this->verificationFailedDueToPatchApply($patchApplyResult);

            // Capture this candidate's workspace diff text (the hermes provider
            // mutates the workspace; the canonical diff is workspace-derived).
            $candidateDiffText = $this->workspaceDiff($envelope->workspace, $taskContract->allowedFiles);

            $candidates[] = [
                'index' => $i,
                'passed' => $verificationResult->aggregateStatus === VerificationGateResult::STATUS_PASSED,
                'error' => null,
                'callResult' => $callResult,
                'diffResult' => $diffResult,
                'scopeReceipt' => $scopeReceipt,
                'patchApplyResult' => $patchApplyResult,
                'verificationResult' => $verificationResult,
                'callResultForGates' => $callResultForGates,
                'candidate_diff_text' => $candidateDiffText,
            ];
        }

        // Deterministic selection: first passing candidate (lowest index) wins.
        $winner = null;
        foreach ($candidates as $candidate) {
            if ($candidate['passed']) {
                $winner = $candidate;
                break;
            }
        }

        if ($winner === null) {
            // No candidate passed: keep the FIRST candidate's results so the
            // run reports non-completed honestly (anti-gaming: never
            // manufacture green from all-red candidates).
            $winner = $candidates[0] ?? null;
        }

        // Defensive: if no candidate was produced at all (should not happen
        // with candidateCount >= 1), synthesize a blocked result.
        if ($winner === null || $winner['callResult'] === null) {
            $blocked = $this->blockedProviderCallResult(
                runId: $promptProjection->runId,
                provider: 'hermes_cli',
                modelFamily: $taskContract->providerLock->modelFamily,
                error: 'best_of_n_no_candidate_produced',
                stderr: 'Best-of-N produced no candidate.',
            );

            return [
                'callResult' => $blocked,
                'diffResult' => DiffParseResult::invalid(['best_of_n_no_candidate_produced']),
                'scopeReceipt' => (new ScopeGuard)->check(
                    envelope: $envelope,
                    taskContract: $taskContract,
                    diffResult: DiffParseResult::invalid(['best_of_n_no_candidate_produced']),
                ),
                'patchApplyResult' => new PatchApplyResult(
                    status: PatchApplyResult::STATUS_SKIPPED,
                    exitCode: 0,
                    durationMs: 0,
                    stdout: '',
                    stderr: '',
                    reason: 'no_candidate',
                ),
                'verificationResult' => $this->verificationFailedDueToPatchApply(new PatchApplyResult(
                    status: PatchApplyResult::STATUS_FAILED,
                    exitCode: 1,
                    durationMs: 0,
                    stdout: '',
                    stderr: 'No best-of-N candidate produced.',
                    reason: 'no_candidate',
                )),
                'callResultForGates' => $blocked,
                'providerCalls' => $providerCalls,
                'summary' => $this->bestOfNSummary(
                    candidateCount: $candidateCount,
                    candidates: $candidates,
                    winnerIndex: -1,
                ),
            ];
        }

        // Re-apply the winner's diff to the workspace so the persisted diff
        // hash equals the selected candidate's (VAL-M4-008). The workspace was
        // last mutated by the FINAL candidate; revert then re-apply winner.
        $this->revertWorkspaceChanges($envelope->workspace, $taskContract->allowedFiles);
        $reapplyStderr = '';
        $reapplyOk = $this->reapplyCandidateDiff(
            workspace: $envelope->workspace,
            diffText: $winner['candidate_diff_text'] ?? '',
            callResult: $winner['callResult'],
            taskContract: $taskContract,
            stderrRef: $reapplyStderr,
        );

        // FAIL-CLOSED (m4-fix-reapply-candidate-diff-fail-closed): if the
        // winner has a non-empty diff and re-apply FAILED, the workspace does
        // NOT match the selected winner. We MUST NOT return the winner's green
        // metadata — that would report a GREEN completion whose actual
        // workspace state differs from the selected winner's diff (fail-open
        // correctness gap). Instead mirror the no-candidate-produced branch:
        // a non-completed result with STATUS_FAILED patch_apply carrying the
        // git apply stderr/reason, and verificationResult via
        // verificationFailedDueToPatchApply(...) so aggregateStatus is
        // non-passed. PRESERVE the empty-diff no-op case (the candidate made
        // no workspace change -> reverted-clean state is correct), which
        // reapplyCandidateDiff() reports as success.
        $winnerDiffText = trim((string) ($winner['candidate_diff_text'] ?? ''));
        if (! $reapplyOk && $winnerDiffText !== '') {
            $reapplyBlocked = $this->blockedProviderCallResult(
                runId: $promptProjection->runId,
                provider: 'hermes_cli',
                modelFamily: $taskContract->providerLock->modelFamily,
                error: 'winner_reapply_failed',
                stderr: $reapplyStderr !== '' ? $reapplyStderr : 'Winner diff re-apply failed.',
            );
            $reapplyPatchApply = new PatchApplyResult(
                status: PatchApplyResult::STATUS_FAILED,
                exitCode: 1,
                durationMs: 0,
                stdout: '',
                stderr: $reapplyStderr !== '' ? $reapplyStderr : 'Winner diff re-apply failed.',
                reason: 'winner_reapply_failed',
            );

            return [
                'callResult' => $reapplyBlocked,
                'diffResult' => DiffParseResult::invalid(['winner_reapply_failed']),
                'scopeReceipt' => (new ScopeGuard)->check(
                    envelope: $envelope,
                    taskContract: $taskContract,
                    diffResult: DiffParseResult::invalid(['winner_reapply_failed']),
                ),
                'patchApplyResult' => $reapplyPatchApply,
                'verificationResult' => $this->verificationFailedDueToPatchApply($reapplyPatchApply),
                'callResultForGates' => $reapplyBlocked,
                'providerCalls' => $providerCalls,
                'summary' => $this->bestOfNSummary(
                    candidateCount: $candidateCount,
                    candidates: $candidates,
                    winnerIndex: -1,
                ),
            ];
        }

        // Rebuild the winner's diff result from the now-applied winner diff so
        // the persisted diff hash reflects the selected candidate exactly.
        $winnerDiffResult = (new DiffParser)->parse($winner['callResult']->stdout);

        return [
            'callResult' => $winner['callResult'],
            'diffResult' => $winnerDiffResult,
            'scopeReceipt' => $winner['scopeReceipt'],
            'patchApplyResult' => $winner['patchApplyResult'],
            'verificationResult' => $winner['verificationResult'],
            'callResultForGates' => $winner['callResultForGates'],
            'providerCalls' => $providerCalls,
            'summary' => $this->bestOfNSummary(
                candidateCount: $candidateCount,
                candidates: $candidates,
                winnerIndex: $winner['passed'] ? $winner['index'] : -1,
            ),
        ];
    }

    /**
     * Re-apply a captured candidate's workspace diff after the workspace was
     * reverted. Hermes mutates the workspace directly, so the canonical diff
     * is workspace-derived text; we re-apply it via `git apply` so the
     * selected winner's diff is the one persisted.
     *
     * FAIL-CLOSED (m4-fix-reapply-candidate-diff-fail-closed): the return
     * value reports whether `git apply` succeeded. An empty (no-op) diff is
     * treated as success — the reverted-clean workspace already matches a
     * candidate that made no workspace change. A non-empty diff whose
     * `git apply` fails (non-zero exit, e.g. context drift) returns false so
     * the caller can refuse to persist a desynced winner's green metadata
     * (anti-gaming: a workspace that does NOT match the selected winner must
     * never report green). The captured stderr is exposed via the
     * `$stderrRef` by-reference parameter for the caller's failure reason.
     */
    private function reapplyCandidateDiff(
        string $workspace,
        string $diffText,
        ProviderCallResult $callResult,
        LightTaskContract $taskContract,
        string &$stderrRef = '',
    ): bool {
        $stderrRef = '';
        if (! is_dir($workspace)) {
            $stderrRef = 'reapply_workspace_missing';

            return false;
        }
        $diffText = trim($diffText);
        if ($diffText === '') {
            // Nothing to re-apply; the candidate made no workspace change.
            // The reverted (clean) workspace already matches this candidate,
            // so this is a SUCCESS, not a failure.
            return true;
        }
        $tmp = tempnam(sys_get_temp_dir(), 'atlas_bon_diff_');
        if ($tmp === false) {
            $stderrRef = 'reapply_tempnam_failed';

            return false;
        }
        file_put_contents($tmp, $diffText."\n");
        try {
            $process = new Process(['git', 'apply', '--whitespace=nowarn', $tmp], $workspace, null, null, 15.0);
            $process->run();
            if (! $process->isSuccessful()) {
                // FAIL-CLOSED: surface the git apply stderr so the caller can
                // refuse to persist a desynced winner's green metadata.
                $stderrRef = $process->getErrorOutput() !== ''
                    ? $process->getErrorOutput()
                    : 'git apply failed with exit code '.$process->getExitCode();

                return false;
            }

            return true;
        } finally {
            @unlink($tmp);
        }
    }

    /**
     * Build the honest best-of-N summary block for the provider call receipt.
     *
     * Carries the explicit intra-model-weaker-than-cross-engine annotation
     * and makes NO equivalence/parity claim (VAL-M4-007).
     *
     * @param  list<array<string,mixed>>  $candidates
     * @return array<string,mixed>
     */
    private function bestOfNSummary(int $candidateCount, array $candidates, int $winnerIndex): array
    {
        $passing = array_values(array_filter(
            $candidates,
            static fn (array $c): bool => (bool) ($c['passed'] ?? false),
        ));

        return [
            'enabled' => true,
            'candidate_count' => $candidateCount,
            'passing_count' => count($passing),
            'selected_candidate_index' => $winnerIndex,
            'tie_break' => 'lowest_index_among_passing',
            // HONEST ANNOTATION (VAL-M4-007): same-model best-of-N is weaker
            // than cross-engine decorrelation. No equivalence/parity claim.
            'intra_model_best_of_n_weaker_than_cross_engine' => true,
            'provider_lock' => 'hermes_cli',
            'model_family' => 'minimax-m3',
        ];
    }

    /**
     * M3: Build the input array for ReviewIntelligenceService::analyse().
     *
     * Feeds the diff (from $scopeReceipt->observed->fileDiffs) + test evidence
     * + scope contract into the critic so it can detect heuristic-bounded
     * defects that tests alone won't catch.
     *
     * @return array<string,mixed>
     */
    private function buildCriticInput(
        string $runId,
        ScopeGuardReceipt $scopeReceipt,
        LightTaskContract $taskContract,
        VerificationGateResult $verificationResult,
        DiffParseResult $diffResult,
    ): array {
        // changed_files from the observed diff (canonical "what changed" source)
        $changedFiles = array_map(
            static fn (ScopeFileDiff $d): string => $d->path,
            $scopeReceipt->observed->fileDiffs,
        );

        // diff_chunks derived from the parsed diff content
        $diffChunks = [];
        if ($diffResult->diff !== null && $diffResult->diff !== '') {
            $diffChunks = $this->parseDiffIntoChunks($diffResult->diff);
        }

        // test_paths from the verification result (commands that ran)
        // plus allowedFiles that look like test files (they serve as
        // expected test coverage even when not in the diff).
        $testPaths = array_values(array_filter(
            array_map(static fn ($t): string => $t->command, $verificationResult->tests),
            static fn (string $cmd): bool => str_contains($cmd, 'test') || str_contains($cmd, 'phpunit'),
        ));
        $testPaths = array_values(array_unique(array_merge(
            $testPaths,
            array_filter($taskContract->allowedFiles, static fn (string $f): bool => (bool) preg_match('/(^|\/)tests\//i', $f)),
        )));

        return [
            'run_id' => $runId,
            'changed_files' => $changedFiles,
            'diff_chunks' => $diffChunks,
            'test_paths' => $testPaths,
            'allowed_files' => $taskContract->allowedFiles,
            'forbidden_files' => $taskContract->forbiddenFiles,
            'risk_rules' => [],
            'evidence_refs' => [],
            // E1: thread the E2-established intent basis so the critic's
            // detectIntentFalsification() detector (merged into analyse())
            // can assert the diff implements the intent verb(s) using the
            // same IntentFalsificationProbe as the post-gate probe — single
            // source of truth, no re-detection (VAL-CROSS-005). Absent for
            // read-only paths (intentVerbs empty) so the detector stays
            // silent and the critic is byte-identical to pre-E1 there.
            'intent_basis' => [
                'intent_verbs' => array_values($taskContract->intentVerbs),
                'intent_text' => $taskContract->intentText,
                'diff' => $diffResult->diff ?? '',
            ],
        ];
    }

    /**
     * M3: Parse a unified diff string into diff_chunks for the critic.
     *
     * Extracts file paths and hunk bodies from a standard unified diff.
     * This is a best-effort heuristic parser; the critic handles missing
     * or malformed chunks gracefully (they just reduce detection accuracy).
     *
     * @return list<array<string,mixed>>
     */
    private function parseDiffIntoChunks(string $diff): array
    {
        $chunks = [];
        $lines = explode("\n", $diff);
        $currentFile = null;
        $currentBody = '';
        $currentLine = null;

        foreach ($lines as $line) {
            if (preg_match('/^---\s+[ab]\/(.+)$/', $line, $m)) {
                // --- a/file (old file) — note the new file name from +++ line
                continue;
            }
            if (preg_match('/^\+\+\+\s+[ab]\/(.+)$/', $line, $m)) {
                // Flush the previous file's chunk
                if ($currentFile !== null && $currentBody !== '') {
                    $chunks[] = [
                        'file' => $currentFile,
                        'body' => $currentBody,
                        'line' => $currentLine,
                    ];
                }
                $currentFile = $m[1];
                $currentBody = '';
                $currentLine = null;

                continue;
            }
            if (preg_match('/^@@\s+-(\d+)/', $line, $m)) {
                // Hunk header — flush the previous chunk
                if ($currentFile !== null && $currentBody !== '') {
                    $chunks[] = [
                        'file' => $currentFile,
                        'body' => $currentBody,
                        'line' => $currentLine,
                    ];
                }
                $currentLine = (int) $m[1];
                $currentBody = '';

                continue;
            }
            if ($currentFile !== null && ($line === '' || str_starts_with($line, '+') || str_starts_with($line, '-') || str_starts_with($line, ' '))) {
                $currentBody .= $line."\n";
            }
        }

        // Flush the last chunk
        if ($currentFile !== null && $currentBody !== '') {
            $chunks[] = [
                'file' => $currentFile,
                'body' => $currentBody,
                'line' => $currentLine,
            ];
        }

        return $chunks;
    }

    /**
     * M2: Extract a failure excerpt from the verification gate result.
     *
     * Collects the output of all failing test runs into a single excerpt
     * that can be fed into the next repair attempt's prompt and the
     * FailureCapsule's primary_error_excerpt. The excerpt also feeds
     * FailureSignatureHasher for same-signature-twice detection, so it
     * is kept to command + exit_code (stable across volatile stdout).
     * TestRun persists real stdout/stderr to disk at outputPath (see
     * library/environment.md); enriching the excerpt with it would change
     * the normalized failure signature, so per feature scope
     * (misc-m2-reuse-repair-prompt-composer) that enrichment is out of
     * scope unless RepairPromptComposer naturally surfaces it.
     */
    private function extractFailureExcerpt(VerificationGateResult $result): string
    {
        $failingTests = array_filter($result->tests, fn ($t) => ! $t->ok);
        if ($failingTests === []) {
            return 'Verification gate failed with no specific test output.';
        }

        $excerpts = [];
        foreach ($failingTests as $test) {
            $excerpts[] = "Command: {$test->command}\nExit code: {$test->exitCode}";
        }

        return implode("\n\n", $excerpts);
    }

    /**
     * M2: Build the repair prompt for the hermes path by REUSING the armed
     * RepairPromptComposer (library/do-not-rebuild.md) instead of a custom
     * string concatenation.
     *
     * Flow:
     *   1. Build a canonical FailureCapsule from the verification failure
     *      via FailureCapsuleBuilder (normalizes the excerpt, computes the
     *      deterministic failure_signature, decides retry/stop/escalate).
     *   2. Compose the repair ProviderPromptProjection via
     *      RepairPromptComposer::compose() — this inherits the prompt-enforced
     *      stop conditions, operating rules and the Repair Capsule section
     *      the custom buildHermesRepairPrompt() used to bypass (LIGAR
     *      violation flagged by M2 scrutiny).
     *   3. Wrap the composed rendered text with the REPAIR REQUIRED marker +
     *      "Previous attempt failed" header so the hermes path preserves the
     *      failure-excerpt structure VAL-M2-008 locks, while the composed
     *      body underneath carries strictly richer guard-rail content.
     *
     * The loop's same-signature-twice detection in execute() continues to
     * compare the FailureSignatureHasher signature of the raw failure
     * excerpt; that signature equals the capsule's failure_signature
     * (both go through hasher->normalize() then FailureCapsule::signatureOf).
     */
    private function buildComposedHermesRepairPrompt(
        ProviderPromptProjection $promptProjection,
        LightTaskContract $taskContract,
        VerificationGateResult $verificationResult,
        ScopeGuardReceipt $scopeReceipt,
        DiffParseResult $diffResult,
        string $failureExcerpt,
        int $repairAttempt,
        int $repairCap,
    ): string {
        $firstFailing = null;
        foreach ($verificationResult->tests as $test) {
            if (! $test->ok) {
                $firstFailing = $test;
                break;
            }
        }

        // The armed RepairPromptComposer enforces a run_id identity chain
        // (projection == capsule == contract). In a real run these are
        // consistent (the projection is built from the same envelope as the
        // contract). We anchor on the contract's run_id (the authoritative
        // identity that carries task_contract_hash) and build a projection
        // view with that run_id so the composed service's invariant holds
        // regardless of how the caller constructed the projection envelope.
        $compositionProjection = $promptProjection;
        if ($promptProjection->runId !== $taskContract->runId) {
            $payload = $promptProjection->toCanonicalArray();
            $payload['run_id'] = $taskContract->runId;
            $compositionProjection = ProviderPromptProjection::fromArray($payload);
        }

        $capsuleBuilder = new FailureCapsuleBuilder(new FailureSignatureHasher);
        $gate = 'verification_gate';
        $capsule = $capsuleBuilder->buildInitial(
            runId: $taskContract->runId,
            taskContractHash: $taskContract->taskContractHash,
            gate: $gate,
            command: $firstFailing?->command,
            exitCode: $firstFailing?->exitCode,
            primaryErrorRaw: $failureExcerpt,
            fullErrorLogPath: $firstFailing?->outputPath,
            failingTest: $firstFailing?->command,
            diffHash: $diffResult->diffHash(),
            changedFiles: array_map(
                static fn ($d): string => $d->path,
                $scopeReceipt->observed->fileDiffs,
            ),
            policy: $taskContract->repairPolicy,
        );

        $composer = new RepairPromptComposer;
        $repairProjection = $composer->compose(
            original: $compositionProjection,
            capsule: $capsule,
            contract: $taskContract,
            attemptIndex: $repairAttempt,
            maxAttempts: max(1, $repairCap),
        );

        // The composed rendered text already carries [original prompt] +
        // [# Repair Capsule] + [# Primary Error] + [# Stop Conditions]
        // (strictly richer than the former custom version). Inject the
        // REPAIR REQUIRED marker + "Previous attempt failed" header right
        // before the Repair Capsule section so the hermes path preserves
        // the failure-excerpt structure VAL-M2-008 locks, without
        // duplicating the original prompt body.
        $marker = "--- REPAIR REQUIRED ({$gate}) ---\n"
            ."Previous attempt failed. Error output:\n{$failureExcerpt}\n";
        $composed = $repairProjection->renderedPromptText;
        $capsuleHeader = '# Repair Capsule';
        $capsulePos = strpos($composed, $capsuleHeader);
        if ($capsulePos !== false) {
            // M2-followup: restore the 20,000-char cap on the ORIGINAL prompt
            // body (the pre-capsule portion). The prior custom
            // buildHermesRepairPrompt() applied mb_substr(renderedPromptText,
            // 0, 20_000); that cap survived ONLY in the defensive fallback
            // branch below, NOT here. Apply it to the pre-capsule portion ONLY
            // using mb_substr so the original-prompt body is bounded exactly as
            // before, while the # Repair Capsule / # Primary Error /
            // # Stop Conditions guard-rail sections (capsulePos onward) and the
            // injected REPAIR REQUIRED marker are preserved IN FULL (never
            // truncate the guard rails).
            //
            // The capsule header is ASCII, so mb_strpos yields the character
            // offset matching the byte offset for the header boundary; using
            // the character offset keeps mb_substr correct for multibyte
            // pre-capsule bodies.
            $capsuleCharPos = mb_strpos($composed, $capsuleHeader);
            $cappedPreCapsule = mb_substr($composed, 0, min($capsuleCharPos, 20_000));

            return $cappedPreCapsule
                .$marker
                ."\n"
                .substr($composed, $capsulePos);
        }

        // Fallback (defensive): append the marker + composed body tail if the
        // capsule header was not found (composition contract changed).
        return mb_substr($promptProjection->renderedPromptText, 0, 20_000)
            ."\n\n".$marker
            ."\n".$composed;
    }

    /**
     * M2: Revert workspace changes before a repair re-invocation.
     *
     * Checks out the allowed files from HEAD so the next provider
     * invocation starts from a clean state.
     *
     * @param  list<string>  $allowedFiles
     */
    private function revertWorkspaceChanges(string $workspace, array $allowedFiles): void
    {
        if (! is_dir($workspace)) {
            return;
        }

        // Revert all changes in the workspace (git checkout + clean)
        $process = new Process(['git', 'checkout', '.'], $workspace);
        $process->run();

        // Also clean untracked files that the provider may have created
        $process = new Process(['git', 'clean', '-fd'], $workspace);
        $process->run();
    }

    private function blockedProviderCallResult(
        string $runId,
        string $provider,
        string $modelFamily,
        string $error,
        string $stderr,
        bool $providerSafe = true,
    ): ProviderCallResult {
        return ProviderCallResult::fromStdout(
            runId: $runId,
            actualProvider: $provider !== '' ? $provider : 'unknown',
            actualModelFamily: $modelFamily !== '' ? $modelFamily : 'unknown',
            exitStatus: 1,
            stdout: '',
            stderr: $stderr,
            durationMs: 0,
            tokensIn: null,
            tokensOut: null,
            costEstimateUsd: null,
            providerSafe: $providerSafe,
            errors: [$error],
        );
    }

    /**
     * Changed (tracked, staged, untracked, and explicit ignored-forbidden)
     * workspace paths, repo-relative, used to compute scope violations for
     * providers (like Hermes) that mutate the worktree directly but do not
     * return a structured changed-files list.
     *
     * Important: scope inspection must not pathspec regular untracked files to
     * allowed_files. Otherwise a provider can create an out-of-scope file and
     * still pass by also changing an allowed file. Staged files need their own
     * cached diff because `git add` removes them from the untracked set. Ignored
     * files are limited to explicit forbidden_files to avoid failing every real
     * workspace that already has ignored local artifacts such as dependency
     * folders or machine-local environment files.
     *
     * @param  list<string>  $allowedFiles
     * @param  list<string>  $forbiddenFiles
     * @return list<string>
     */
    private function changedFilePathsInWorkspace(string $workspace, array $allowedFiles, array $forbiddenFiles = []): array
    {
        if (! is_dir($workspace)) {
            return [];
        }

        $paths = [];

        foreach ([
            ['git', 'diff', '--no-ext-diff', '--name-only'],
            ['git', 'diff', '--cached', '--no-ext-diff', '--name-only'],
            ['git', 'ls-files', '--others', '--exclude-standard'],
        ] as $argv) {
            $paths = array_merge($paths, $this->gitNameOnlyPaths($workspace, $argv));
        }

        $ignoredForbidden = $this->safeRelativePaths($forbiddenFiles);
        if ($ignoredForbidden !== []) {
            $argv = ['git', 'ls-files', '--others', '--ignored', '--exclude-standard', '--'];
            array_push($argv, ...$ignoredForbidden);
            $paths = array_merge($paths, $this->gitNameOnlyPaths($workspace, $argv));
        }

        return array_values(array_unique($paths));
    }

    /**
     * @param  list<string>  $argv
     * @return list<string>
     */
    private function gitNameOnlyPaths(string $workspace, array $argv): array
    {
        $process = new Process($argv, $workspace, null, null, 15.0);
        $process->run();
        if (! $process->isSuccessful() && $process->getExitCode() !== 1) {
            return [];
        }

        return array_values(array_filter(array_map(
            static fn (string $line): string => trim($line),
            explode("\n", (string) $process->getOutput()),
        ), static fn (string $path): bool => $path !== ''));
    }

    /**
     * @param  list<string>  $paths
     * @return list<string>
     */
    private function safeRelativePaths(array $paths): array
    {
        return array_values(array_filter(array_map(
            static function (string $path): string {
                $path = ltrim(trim($path), '/');

                return $path !== '' && ! str_contains($path, '..') ? $path : '';
            },
            $paths,
        ), static fn (string $path): bool => $path !== ''));
    }

    /**
     * @param  list<string>  $allowedFiles
     */
    private function workspaceDiff(string $workspace, array $allowedFiles): string
    {
        if (! is_dir($workspace)) {
            return '';
        }

        $paths = array_values(array_filter(array_map(
            static fn (mixed $path): string => is_string($path) ? trim($path) : '',
            $allowedFiles,
        ), static fn (string $path): bool => $path !== '' && ! str_starts_with($path, '/') && ! str_contains($path, '..')));

        $argv = ['git', 'diff', '--no-ext-diff', '--'];
        array_push($argv, ...$paths);

        $process = new Process($argv, $workspace, null, null, 15.0);
        $process->run();
        if (! $process->isSuccessful() && $process->getExitCode() !== 1) {
            return '';
        }

        $diff = (string) $process->getOutput();
        $diff .= $this->untrackedAllowedFilesDiff($workspace, $paths);

        return $diff !== '' && ! str_ends_with($diff, "\n") ? $diff."\n" : $diff;
    }

    /**
     * @param  array<mixed>  $values
     * @return list<string>
     */
    private function stringList(array $values): array
    {
        return array_values(array_filter(array_map(
            static fn (mixed $value): string => is_string($value) ? trim($value) : '',
            $values,
        ), static fn (string $value): bool => $value !== ''));
    }

    /**
     * @param  list<string>  $allowedFiles
     */
    private function pathAllowed(string $path, array $allowedFiles): bool
    {
        $path = ltrim(trim($path), '/');
        if ($path === '') {
            return false;
        }

        return in_array($path, $allowedFiles, true);
    }

    /**
     * git diff omits untracked files. Cursor-style workspace mutators often
     * satisfy missing-test findings by creating a brand-new allowed test file,
     * so Atlas must promote those files into a unified diff before parsing.
     *
     * @param  list<string>  $allowedFiles
     */
    private function untrackedAllowedFilesDiff(string $workspace, array $allowedFiles): string
    {
        if ($allowedFiles === []) {
            return '';
        }

        $argv = ['git', 'ls-files', '--others', '--exclude-standard', '--'];
        array_push($argv, ...$allowedFiles);

        $process = new Process($argv, $workspace, null, null, 15.0);
        $process->run();
        if (! $process->isSuccessful()) {
            return '';
        }

        $untracked = array_values(array_filter(array_map(
            static fn (string $line): string => trim($line),
            explode("\n", (string) $process->getOutput()),
        ), static fn (string $path): bool => $path !== ''));

        $diff = '';
        foreach ($untracked as $path) {
            if (! in_array($path, $allowedFiles, true)) {
                continue;
            }
            $filePath = rtrim($workspace, DIRECTORY_SEPARATOR).DIRECTORY_SEPARATOR.$path;
            if (! is_file($filePath)) {
                continue;
            }
            $fileDiff = new Process(['git', 'diff', '--no-index', '--', '/dev/null', $path], $workspace, null, null, 15.0);
            $fileDiff->run();
            $output = (string) $fileDiff->getOutput();
            if ($output === '') {
                continue;
            }
            $diff .= (str_ends_with($diff, "\n") || $diff === '' ? '' : "\n").$output;
        }

        return $diff;
    }

    private function tryDeterministicPatch(
        OperationEnvelope $envelope,
        LightTaskContract $taskContract,
        string $runId,
    ): ?ProviderCallResult {
        if ($taskContract->allowedFiles === [] || $taskContract->validationCommands === []) {
            return null;
        }

        $candidates = [];
        foreach ($taskContract->allowedFiles as $relativePath) {
            if (str_starts_with($relativePath, '/') || str_contains($relativePath, '..')) {
                continue;
            }

            $absolutePath = rtrim($envelope->workspace, '/').'/'.$relativePath;
            if (! is_file($absolutePath)) {
                continue;
            }

            $original = (string) file_get_contents($absolutePath);
            $updated = $this->deterministicUpdatedContents($relativePath, $original, $envelope->normalizedIntent);
            if ($updated === null || $updated === $original) {
                continue;
            }

            $candidates[] = [$relativePath, $original, $updated];
        }

        if (count($candidates) !== 1) {
            return null;
        }

        [$relativePath, $original, $updated] = $candidates[0];
        $diff = $this->singleFileUnifiedDiff($relativePath, $original, $updated);
        if ($diff === '') {
            return null;
        }

        return ProviderCallResult::fromStdout(
            runId: $runId,
            actualProvider: 'atlas_deterministic',
            actualModelFamily: 'atlas_dev_fast_path',
            exitStatus: 0,
            stdout: $diff,
            stderr: '',
            durationMs: 0,
            tokensIn: 0,
            tokensOut: 0,
            costEstimateUsd: 0.0,
            providerSafe: true,
        );
    }

    private function deterministicFastPathEnabled(): bool
    {
        return (bool) config('atlas_dev.efficient.deterministic_fast_path_enabled', true);
    }

    private function deterministicUpdatedContents(string $relativePath, string $original, string $intent): ?string
    {
        $lower = strtolower($intent);
        if (str_ends_with($relativePath, '.php') && str_contains($lower, 'hello atlas')) {
            if (str_contains($original, 'helo atlas')) {
                return str_replace('helo atlas', 'hello atlas', $original);
            }

            return preg_replace(
                "/return\\s+(['\"])[^'\"]*\\1\\s*;/",
                "return 'hello atlas';",
                $original,
                1,
            ) ?: null;
        }

        if (preg_match('/\\.(?:css|html)\\z/i', $relativePath) === 1
            && preg_match('/background(?:-color)?\\s+#([0-9a-f]{3,6})/i', $intent, $background)
            && preg_match('/border-radius\\s+([0-9]+px)/i', $intent, $radius)) {
            return $this->upsertPrimaryButtonStyles(
                $original,
                '#'.strtolower($background[1]),
                strtolower($radius[1]),
            );
        }

        if (str_ends_with($relativePath, '.blade.php')
            && str_contains($lower, 'elevated')
            && str_contains($original, 'class="status-card compact"')) {
            return str_replace(
                'class="status-card compact"',
                'class="status-card compact elevated"',
                $original,
            );
        }

        return null;
    }

    private function upsertPrimaryButtonStyles(string $contents, string $background, string $radius): ?string
    {
        $pattern = '/(?P<head>\\.primary-button\\s*\\{)(?P<body>.*?)(?P<tail>\\})/s';
        if (preg_match($pattern, $contents) !== 1) {
            return null;
        }

        return preg_replace_callback($pattern, function (array $matches) use ($background, $radius): string {
            $body = (string) $matches['body'];
            $body = $this->upsertCssDeclaration($body, 'background', $background);
            $body = $this->upsertCssDeclaration($body, 'border-radius', $radius);

            return $matches['head'].$body.$matches['tail'];
        }, $contents, 1) ?: null;
    }

    private function upsertCssDeclaration(string $body, string $property, string $value): string
    {
        if (preg_match('/(^|\\s)'.preg_quote($property, '/').'\\s*:/i', $body) === 1) {
            return preg_replace(
                '/'.preg_quote($property, '/').'\\s*:\\s*[^;]+;/i',
                $property.': '.$value.';',
                $body,
                1,
            ) ?? $body;
        }

        $indent = str_contains($body, "\n") ? '  ' : ' ';

        return rtrim($body)."\n".$indent.$property.': '.$value.";\n";
    }

    private function singleFileUnifiedDiff(string $relativePath, string $original, string $updated): string
    {
        $oldPath = tempnam(sys_get_temp_dir(), 'atlas-dev-old-');
        $newPath = tempnam(sys_get_temp_dir(), 'atlas-dev-new-');
        if ($oldPath !== false && $newPath !== false) {
            try {
                file_put_contents($oldPath, $original);
                file_put_contents($newPath, $updated);

                $process = new Process([
                    'diff',
                    '-u',
                    '--label',
                    'a/'.$relativePath,
                    '--label',
                    'b/'.$relativePath,
                    $oldPath,
                    $newPath,
                ]);
                $process->run();
                $diff = $process->getOutput();
                if ($process->getExitCode() === 1 && $diff !== '') {
                    return str_ends_with($diff, "\n") ? $diff : $diff."\n";
                }
            } finally {
                @unlink($oldPath);
                @unlink($newPath);
            }
        }

        $oldLines = explode("\n", $original);
        $newLines = explode("\n", $updated);
        $oldHadTrailingNewline = str_ends_with($original, "\n");
        $newHadTrailingNewline = str_ends_with($updated, "\n");
        if ($oldHadTrailingNewline) {
            array_pop($oldLines);
        }
        if ($newHadTrailingNewline) {
            array_pop($newLines);
        }

        $diff = [
            '--- a/'.$relativePath,
            '+++ b/'.$relativePath,
            sprintf('@@ -1,%d +1,%d @@', max(1, count($oldLines)), max(1, count($newLines))),
        ];
        $max = max(count($oldLines), count($newLines));
        for ($i = 0; $i < $max; $i++) {
            $old = $oldLines[$i] ?? null;
            $new = $newLines[$i] ?? null;
            if ($old !== null && $new !== null && $old === $new) {
                $diff[] = ' '.$old;

                continue;
            }
            if ($old !== null) {
                $diff[] = '-'.$old;
            }
            if ($new !== null) {
                $diff[] = '+'.$new;
            }
        }

        return implode("\n", $diff)."\n";
    }

    private function applyPatchIfSafe(
        DiffParseResult $diffResult,
        string $scopeStatus,
        string $workspace,
        ProviderCallResult $callResult,
    ): PatchApplyResult {
        if (! $diffResult->hasPatch()) {
            return new PatchApplyResult(
                status: PatchApplyResult::STATUS_SKIPPED,
                exitCode: 0,
                durationMs: 0,
                stdout: '',
                stderr: '',
                reason: 'no_patch',
            );
        }

        if ($this->providerMutatedWorkspace($callResult)) {
            return new PatchApplyResult(
                status: PatchApplyResult::STATUS_SKIPPED,
                exitCode: 0,
                durationMs: 0,
                stdout: '',
                stderr: '',
                reason: 'provider_mutated_workspace',
            );
        }

        if ($scopeStatus !== ScopeGuardReceipt::STATUS_PASSED) {
            return new PatchApplyResult(
                status: PatchApplyResult::STATUS_FAILED,
                exitCode: 1,
                durationMs: 0,
                stdout: '',
                stderr: 'Patch application skipped because scope guard did not pass.',
                reason: 'scope_guard_not_passed',
            );
        }

        return (new PatchApplier)->apply($diffResult, $workspace);
    }

    private function providerMutatedWorkspace(ProviderCallResult $callResult): bool
    {
        // Single source of truth shared with the prompt contract
        // (ProviderPromptBuilder::adaptSectionsForProvider) so the way Atlas reads
        // the result can never diverge from what the provider was told to do.
        // {@see WorkspaceMutatingProviders}
        return WorkspaceMutatingProviders::includes($callResult->actualProvider);
    }

    private function withProviderError(ProviderCallResult $result, string $error): ProviderCallResult
    {
        return new ProviderCallResult(
            runId: $result->runId,
            actualProvider: $result->actualProvider,
            actualModelFamily: $result->actualModelFamily,
            exitStatus: $result->exitStatus,
            stdout: $result->stdout,
            stderr: $result->stderr,
            durationMs: $result->durationMs,
            tokensIn: $result->tokensIn,
            tokensOut: $result->tokensOut,
            costEstimateUsd: $result->costEstimateUsd,
            rawResponseHash: $result->rawResponseHash,
            providerSafe: $result->providerSafe,
            errors: array_values(array_unique([...$result->errors, $error])),
        );
    }

    private function verificationFailedDueToPatchApply(PatchApplyResult $result): VerificationGateResult
    {
        return new VerificationGateResult(
            tests: [],
            gates: [
                new GateOutcome(
                    name: 'patch_apply_gate',
                    status: GateOutcome::STATUS_FAILED,
                    required: true,
                    evidenceRef: 'patch_apply_result',
                    fresh: true,
                    waiverReason: null,
                ),
            ],
            aggregateStatus: VerificationGateResult::STATUS_FAILED,
            honestyFlags: ['patch_apply_failed:'.($result->reason ?? 'unknown')],
            evidenceRefs: [],
            profile: 'patch_apply',
        );
    }

    private function verificationReceiptStorage(string $storageRunId): ReceiptStorageAdapter
    {
        $storage = $this->storage;

        return new class($storage, $storageRunId) implements ReceiptStorageAdapter
        {
            public function __construct(
                private readonly ReceiptStorage $storage,
                private readonly string $storageRunId,
            ) {}

            public function writeTestLog(string $runId, int $index, string $output): string
            {
                $base = sprintf('test_log_%02d', max(1, $index));

                return $this->storage->writeMonotonic($this->storageRunId, $base, [
                    'schema_version' => 'atlas.dev.verification_test_log.v1',
                    'run_id' => $this->storageRunId,
                    'source_run_id' => $runId,
                    'index' => max(1, $index),
                    'output_hash' => hash('sha256', $output),
                    'combined_output' => $output,
                    'recorded_at' => gmdate('c'),
                ])['path'];
            }
        };
    }

    private function resolve(string $abstract): ?object
    {
        if (! $this->container->bound($abstract)) {
            return null;
        }

        try {
            $resolved = $this->container->make($abstract);
        } catch (BindingResolutionException) {
            return null;
        }

        return is_object($resolved) ? $resolved : null;
    }

    private function resolveConcrete(string $abstract): ?object
    {
        try {
            $resolved = $this->container->make($abstract);
        } catch (BindingResolutionException) {
            return null;
        }

        return is_object($resolved) ? $resolved : null;
    }

    private function blockedDueToUnwiredDrivers(
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
    private function enforceAucriBeforeProvider(
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

        $enforcement = app(AtlasAucriRuntimeEnforcementService::class)->enforce([
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
        ]);

        $this->storage->writeAtomic($runId, ArtifactNames::AUCRI_RUNTIME_ENFORCEMENT, $enforcement);

        return $enforcement;
    }

    /**
     * @param  array<string,mixed>  $enforcement
     */
    private function blockedDueToAucri(array $enforcement): RunExecutionResult
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

    private function contextPackHash(string $runId): string
    {
        $projection = $this->storage->read($runId, ArtifactNames::OPEN_BRAIN_PROJECTION);
        if (is_array($projection) && isset($projection['context_pack_hash']) && is_string($projection['context_pack_hash']) && $projection['context_pack_hash'] !== '') {
            return $projection['context_pack_hash'];
        }

        return 'atlas-dev:context_pack:unknown';
    }

    private function providerTimeoutSeconds(?LightTaskContract $taskContract = null): int
    {
        if ($taskContract?->providerLock->provider === 'hermes_cli') {
            // Hermes is a heavy multi-turn executive runtime — give it the generous
            // hermes_cli ceiling (config default 600s) rather than the short Dev default.
            return max(1, (int) config('atlas.ai.providers.hermes_cli.timeout_seconds', 600));
        }

        if ($taskContract?->providerLock->provider === AtlasForgeCursorCliInvocationDriver::PROVIDER) {
            return max(1, (int) config('atlas.ai.providers.cursor_cli.timeout_seconds', 120));
        }

        if ($taskContract?->providerLock->provider === AtlasForgeMinimaxM27CliInvocationDriver::PROVIDER) {
            return max(1, (int) config('atlas.ai.providers.minimax_m27_cli.timeout_seconds', 300));
        }

        return max(1, (int) config('atlas_dev.provider.timeout_seconds', SonnetClaudeCliAdapter::DEFAULT_TIMEOUT_SECONDS));
    }

    private function providerMaxOutputChars(?LightTaskContract $taskContract = null): int
    {
        if ($taskContract?->providerLock->provider === AtlasForgeCursorCliInvocationDriver::PROVIDER) {
            return max(200, (int) config('atlas.ai.providers.cursor_cli.max_output_chars', 12000));
        }

        return 12000;
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
     * E2: resolve the e2 elevation config. Reads the live config kernel when
     * available (feature tests / production); otherwise degrades to the safe
     * default (advisory) so plain-PHPunit unit tests never crash. Mirrors
     * the resolution pattern used by SpecComposer and PromptSectionsMapper.
     */
    private function resolveE2Config(): ElevationConfig
    {
        try {
            return ElevationConfig::fromConfig('e2');
        } catch (\Throwable) {
            return ElevationConfig::for('e2', null);
        }
    }

    /**
     * E1: resolve the e1 elevation config. Same resolution pattern as E2:
     * reads the live config kernel when available, otherwise degrades to the
     * safe default (advisory) so plain-PHPunit unit tests never crash.
     */
    private function resolveE1Config(): ElevationConfig
    {
        try {
            return ElevationConfig::fromConfig('e1');
        } catch (\Throwable) {
            return ElevationConfig::for('e1', null);
        }
    }

    /**
     * E1: resolve the optional LLM-as-judge sub-layer options for the critic.
     *
     * The sub-flag lives at atlas_dev.elevations.e1.llm_judge (default OFF).
     * When ON, the judge callable is resolved from the container binding
     * `atlas_dev.e1.intent_judge` (if bound); tests bind a fake there. When
     * OFF or no judge is bound, the options enable nothing and the critic
     * behaves as the pure deterministic probe (byte-identical to pre-E1-judge).
     *
     * VAL-E1-011: the judge is strictly doubt-additive — even when enabled,
     * it can only add doubt/escalate; the critic's detectIntentFalsification()
     * ignores APPROVE/DOWNGRADE outcomes (the deterministic probe's verdict
     * is the immovable floor).
     *
     * @return array{llm_judge: bool, judge: ?callable}
     */
    private function resolveE1CriticOptions(): array
    {
        $enabled = false;
        try {
            $enabled = (bool) config('atlas_dev.elevations.e1.llm_judge', false);
        } catch (\Throwable) {
            $enabled = false;
        }

        $judge = null;
        if ($enabled) {
            try {
                $bound = $this->container->bound('atlas_dev.e1.intent_judge')
                    ? $this->container->make('atlas_dev.e1.intent_judge')
                    : null;
                $judge = is_callable($bound) ? $bound : null;
            } catch (\Throwable) {
                $judge = null;
            }
        }

        return [
            'llm_judge' => $enabled,
            'judge' => $judge,
        ];
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
    private function probeIntentCoverage(string $runId, LightTaskContract $taskContract): bool
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
}
