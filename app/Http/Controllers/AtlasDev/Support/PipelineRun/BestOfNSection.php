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
use App\Services\Ai\ExecutionAuthority\AwisExecutionGatePort;
use Illuminate\Contracts\Container\BindingResolutionException;
use Throwable;
use Illuminate\Contracts\Container\Container;
use Illuminate\Support\Str;
use Symfony\Component\Process\Process;

/**
 * Best-of-N hermes candidate generation, restore and summary.
 *
 * Extracted verbatim from PipelineRunExecutor (godfile split, GOD-DEBULK
 * 2026-07-22). Behavior unchanged; cross-family calls route through the
 * sibling sections injected below.
 */
final class BestOfNSection
{
    public function __construct(
        private readonly WorkspaceGitSupport $workspaceGit,
        private readonly ProviderResultSupport $providerResult,
        private readonly ResolverSupport $resolver,
        private readonly DeterministicPatchSection $deterministicPatch,
        private readonly ProviderExecutionSection $providerExecution,
    ) {}

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
     * so between candidates the workspace is restored to the captured
     * operator-owned baseline and each candidate's diff TEXT is captured.
     * After selection, the workspace is restored once more and the WINNER's
     * diff is re-applied (git apply) so the persisted diff hash equals the
     * selected candidate's (VAL-M4-008).
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
    public function executeBestOfNHermes(
        OperationEnvelope $envelope,
        LightTaskContract $taskContract,
        ProviderPromptProjection $promptProjection,
        VerificationCommandRunner $commandRunner,
        int $candidateCount,
        array $workspaceBaseline,
        ?RegressionBaselineCache $regressionBaseline = null,
        ?ElevationConfig $e5Config = null,
        ?ElevationConfig $e4Config = null,
    ): array {
        $candidates = [];
        $providerCalls = 0;

        // VAL-E5-013: when E5 is active, every candidate's regression is
        // diffed against the SAME clean pre-patch baseline (captured once
        // before the loop). We compute the per-candidate regression set here
        // and include it in the summary as evidence. The baseline is shared
        // (not recaptured per candidate); an earlier candidate's applied
        // patch NEVER contaminates a later candidate's baseline because the
        // cache is immutable and captured before any candidate runs.
        $e5Active = $e5Config !== null
            && ! $e5Config->isOff()
            && $regressionBaseline !== null;

        for ($i = 0; $i < $candidateCount; $i++) {
            // Revert any prior candidate's workspace mutation before the next
            // candidate runs, so each candidate's diff is independent.
            if ($i > 0) {
                $restoreError = $this->workspaceGit->revertWorkspaceChanges($envelope->workspace, $taskContract->allowedFiles, $workspaceBaseline);
                if ($restoreError !== null) {
                    return $this->bestOfNWorkspaceRestoreFailed(
                        envelope: $envelope,
                        promptProjection: $promptProjection,
                        taskContract: $taskContract,
                        candidateCount: $candidateCount,
                        candidates: $candidates,
                        providerCalls: $providerCalls,
                        regressionBaseline: $regressionBaseline,
                        e5Active: $e5Active,
                        reason: $restoreError,
                    );
                }
            }

            try {
                [$callResult, $iterCalls] = $this->providerExecution->executeLockedProvider(
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
                    'e5_regressions' => [],
                ];
                // Revert partial workspace mutation from the throwing candidate.
                $restoreError = $this->workspaceGit->revertWorkspaceChanges($envelope->workspace, $taskContract->allowedFiles, $workspaceBaseline);
                if ($restoreError !== null) {
                    return $this->bestOfNWorkspaceRestoreFailed(
                        envelope: $envelope,
                        promptProjection: $promptProjection,
                        taskContract: $taskContract,
                        candidateCount: $candidateCount,
                        candidates: $candidates,
                        providerCalls: $providerCalls,
                        regressionBaseline: $regressionBaseline,
                        e5Active: $e5Active,
                        reason: $restoreError,
                    );
                }

                continue;
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
                ? (new VerificationGate($commandRunner, $this->providerResult->verificationReceiptStorage($promptProjection->runId)))->run(
                    taskContract: $taskContract,
                    callResult: $callResultForGates,
                    scopeReceipt: $scopeReceipt,
                    workspace: $envelope->workspace,
                    codeGraph: $this->resolver->resolveCallerTestCodeGraph($scopeReceipt, $envelope->workspace),
                )
                : $this->providerResult->verificationFailedDueToPatchApply($patchApplyResult);

            // Capture this candidate's workspace diff text (the hermes provider
            // mutates the workspace; the canonical diff is workspace-derived).
            $candidateDiffText = $this->workspaceGit->workspaceDiff($envelope->workspace, $taskContract->allowedFiles);

            // VAL-E5-013: compute this candidate's regression set against the
            // shared pre-patch baseline (captured once before the loop). This
            // proves every candidate is diffed against the SAME clean baseline
            // regardless of candidate order, and no candidate's applied patch
            // contaminates a later candidate's baseline (the cache is immutable).
            // The regression set is recorded for evidence; the verdict is
            // applied to the WINNER through the post-gate E5 block.
            $candidateRegressions = [];
            if ($e5Active) {
                $baselineService = $this->resolver->resolveRegressionBaselineService($commandRunner);
                if ($baselineService !== null) {
                    $candidateRegressions = $baselineService->computeRegressions(
                        $regressionBaseline,
                        $verificationResult->tests,
                    );
                }
            }

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
                'e5_regressions' => $candidateRegressions,
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
            $blocked = $this->providerResult->blockedProviderCallResult(
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
                    baseline: $workspaceBaseline['scope'],
                ),
                'patchApplyResult' => new PatchApplyResult(
                    status: PatchApplyResult::STATUS_SKIPPED,
                    exitCode: 0,
                    durationMs: 0,
                    stdout: '',
                    stderr: '',
                    reason: 'no_candidate',
                ),
                'verificationResult' => $this->providerResult->verificationFailedDueToPatchApply(new PatchApplyResult(
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
                    regressionBaseline: $regressionBaseline,
                    e5Active: $e5Active,
                ),
            ];
        }

        // Re-apply the winner's diff to the workspace so the persisted diff
        // hash equals the selected candidate's (VAL-M4-008). The workspace was
        // last mutated by the FINAL candidate; revert then re-apply winner.
        $restoreError = $this->workspaceGit->revertWorkspaceChanges($envelope->workspace, $taskContract->allowedFiles, $workspaceBaseline);
        if ($restoreError !== null) {
            return $this->bestOfNWorkspaceRestoreFailed(
                envelope: $envelope,
                promptProjection: $promptProjection,
                taskContract: $taskContract,
                candidateCount: $candidateCount,
                candidates: $candidates,
                providerCalls: $providerCalls,
                regressionBaseline: $regressionBaseline,
                e5Active: $e5Active,
                reason: $restoreError,
            );
        }
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
            // Forense: persiste o diff exato que o git apply recusou (o run
            // dev-1783066505769 perdeu a evidência — sem o patch não há como
            // diagnosticar o "corrupt patch"). Best-effort, nunca falha o run.
            try {
                $forensic = storage_path('atlas-dev/receipts/'.$promptProjection->runId.'/best_of_n_winner_reapply_failed.patch');
                if (is_dir(dirname($forensic))) {
                    @file_put_contents($forensic, $winnerDiffText."\n\n--- git apply stderr ---\n".$reapplyStderr."\n");
                }
            } catch (\Throwable) {
                // fail-open
            }
            $reapplyBlocked = $this->providerResult->blockedProviderCallResult(
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
                    baseline: $workspaceBaseline['scope'],
                ),
                'patchApplyResult' => $reapplyPatchApply,
                'verificationResult' => $this->providerResult->verificationFailedDueToPatchApply($reapplyPatchApply),
                'callResultForGates' => $reapplyBlocked,
                'providerCalls' => $providerCalls,
                'summary' => $this->bestOfNSummary(
                    candidateCount: $candidateCount,
                    candidates: $candidates,
                    winnerIndex: -1,
                    regressionBaseline: $regressionBaseline,
                    e5Active: $e5Active,
                ),
            ];
        }

        // Rebuild the winner's diff result from the now-applied winner diff so
        // the persisted diff hash reflects the selected candidate exactly.
        $winnerDiffResult = (new DiffParser)->parse($winner['callResult']->stdout);

        // E4: DifferentialTestingService -- compare all N candidates and route
        // the divergence verdict through the sanctioned channels.
        //
        // VAL-E4-001: agreeing candidates => high confidence, no flag, passed
        // allowed. VAL-E4-002: divergent candidates => candidate_divergence
        // flag carrying the divergent diffs as evidence. VAL-E4-003: advisory
        // divergence => needs_review (never silently accepted). VAL-E4-010:
        // off => byte-identical (no comparison, no flag); advisory => flag +
        // needs_review; hard => STATUS_FAILED on divergence.
        //
        // The comparison runs AFTER the winner is selected and re-applied so
        // the verdict applies to the WINNER's verificationResult (the one that
        // flows through the shared post-gate block + CompletionStateGate).
        // Channels (no third way): advisory => honesty flag only; hard =>
        // STATUS_FAILED gate channel. Off => byte-identical no-op (the service
        // is not even invoked).
        $winnerVerificationResult = $winner['verificationResult'];
        $e4SummaryData = null;
        if ($e4Config !== null && ! $e4Config->isOff()) {
            $diffService = $this->resolver->resolveDifferentialTestingService();
            $diffResult4 = $diffService->compare($candidates);
            $diffGate = new CandidateDivergenceGate($e4Config);
            $e4Verdict = $diffGate->evaluate($diffResult4);

            // Divergência TEXTUAL entre candidatos LLM é o estado NORMAL do
            // best-of-N (dois refactors independentes nunca são byte-idênticos
            // — fire test 03/07: todo N=2 real virava needs_review/failed, o
            // amplificador nunca fechava passed). Quando o VENCEDOR passou a
            // verificação completa, a divergência vira INFORMAÇÃO no summary
            // (auditável, nunca silenciosa); o flag só derruba o run quando
            // NENHUM candidato passou (divergência + falha geral = sinal real
            // de instabilidade). Comparação byte-a-byte era doutrina do mundo
            // determinístico.
            $winnerPassed = $winnerVerificationResult->aggregateStatus === VerificationGateResult::STATUS_PASSED;
            if ($e4Verdict->tripped && ! $winnerPassed) {
                $winnerVerificationResult = $this->resolver->routeElevationVerdict(
                    $winnerVerificationResult,
                    $e4Config,
                    $e4Verdict->honestyFlags,
                );
            }

            // Build the E4 summary fragment for the best-of-N summary. On
            // agreement or skip, the flag is null so the summary stays clean
            // (no evidence keys), and only the boolean agreement indicator is
            // added. On divergence, the flag + divergent diffs are carried.
            $e4SummaryData = [
                'agreed' => $diffResult4->agreed,
                'divergent_diffs' => $e4Verdict->tripped
                    ? array_map(
                        static fn (array $d): array => [
                            'index' => $d['index'],
                            'diff' => mb_substr($d['diff'], 0, 2000),
                        ],
                        $e4Verdict->divergentDiffs,
                    )
                    : [],
                'flag' => $e4Verdict->tripped
                    ? $e4Verdict->honestyFlags[0] ?? null
                    : null,
            ];
        }

        return [
            'callResult' => $winner['callResult'],
            'diffResult' => $winnerDiffResult,
            'scopeReceipt' => $winner['scopeReceipt'],
            'patchApplyResult' => $winner['patchApplyResult'],
            'verificationResult' => $winnerVerificationResult,
            'callResultForGates' => $winner['callResultForGates'],
            'providerCalls' => $providerCalls,
            'summary' => $this->bestOfNSummary(
                candidateCount: $candidateCount,
                candidates: $candidates,
                winnerIndex: $winner['passed'] ? $winner['index'] : -1,
                regressionBaseline: $regressionBaseline,
                e5Active: $e5Active,
                e4Summary: $e4SummaryData,
            ),
        ];
    }

    /**
     * @param  list<array<string,mixed>>  $candidates
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
    public function bestOfNWorkspaceRestoreFailed(
        OperationEnvelope $envelope,
        ProviderPromptProjection $promptProjection,
        LightTaskContract $taskContract,
        int $candidateCount,
        array $candidates,
        int $providerCalls,
        ?RegressionBaselineCache $regressionBaseline,
        bool $e5Active,
        string $reason,
    ): array {
        $blocked = $this->providerResult->blockedProviderCallResult(
            runId: $promptProjection->runId,
            provider: 'hermes_cli',
            modelFamily: $taskContract->providerLock->modelFamily,
            error: 'workspace_baseline_restore_refused',
            stderr: 'Workspace baseline restore refused: '.$reason,
        );
        $patchApply = new PatchApplyResult(
            status: PatchApplyResult::STATUS_FAILED,
            exitCode: 1,
            durationMs: 0,
            stdout: '',
            stderr: 'Workspace baseline restore refused: '.$reason,
            reason: 'workspace_baseline_restore_refused',
        );
        $diffResult = DiffParseResult::invalid(['workspace_baseline_restore_refused']);

        return [
            'callResult' => $blocked,
            'diffResult' => $diffResult,
            'scopeReceipt' => (new ScopeGuard)->check(
                envelope: $envelope,
                taskContract: $taskContract,
                diffResult: $diffResult,
            ),
            'patchApplyResult' => $patchApply,
            'verificationResult' => $this->providerResult->verificationFailedDueToPatchApply($patchApply),
            'callResultForGates' => $blocked,
            'providerCalls' => $providerCalls,
            'summary' => $this->bestOfNSummary(
                candidateCount: $candidateCount,
                candidates: $candidates,
                winnerIndex: -1,
                regressionBaseline: $regressionBaseline,
                e5Active: $e5Active,
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
    public function reapplyCandidateDiff(
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
            // Escada de tolerância (fire test 03/07: winner real falhou
            // "corrupt patch" no apply puro): --recount recomputa os
            // contadores de hunk; --3way usa os blobs. Qualquer sucesso
            // produz o MESMO conteúdo final do diff; falha total continua
            // fail-closed (nunca green de workspace dessincronizado).
            $lastError = '';
            foreach ([
                ['git', 'apply', '--whitespace=nowarn', $tmp],
                ['git', 'apply', '--whitespace=nowarn', '--recount', $tmp],
                ['git', 'apply', '--whitespace=nowarn', '--3way', $tmp],
            ] as $argv) {
                $process = new Process($argv, $workspace, null, null, 15.0);
                $process->run();
                if ($process->isSuccessful()) {
                    return true;
                }
                $lastError = $process->getErrorOutput() !== ''
                    ? $process->getErrorOutput()
                    : 'git apply failed with exit code '.$process->getExitCode();
            }
            $stderrRef = $lastError;

            return false;
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
     * VAL-E5-013: when E5 is active, the summary carries the shared pre-patch
     * baseline hash and per-candidate regression evidence, proving every
     * candidate was diffed against the SAME clean baseline.
     *
     * VAL-E4-002: when E4 is active, the summary carries the candidate
     * divergence verdict and the divergent diffs as evidence.
     *
     * @param  list<array<string,mixed>>  $candidates
     * @param  ?array{agreed: bool, divergent_diffs: list<array{index: int, diff: string}>, flag: ?string}  $e4Summary
     * @return array<string,mixed>
     */
    public function bestOfNSummary(
        int $candidateCount,
        array $candidates,
        int $winnerIndex,
        ?RegressionBaselineCache $regressionBaseline = null,
        bool $e5Active = false,
        ?array $e4Summary = null,
    ): array {
        $passing = array_values(array_filter(
            $candidates,
            static fn (array $c): bool => (bool) ($c['passed'] ?? false),
        ));

        $summary = [
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

        // VAL-E5-013: include the shared baseline hash + per-candidate
        // regression evidence when E5 is active. Every candidate references
        // the same baseline hash, proving the baseline was not recaptured
        // per candidate (no contamination).
        if ($e5Active && $regressionBaseline !== null) {
            $summary['e5_shared_baseline_hash'] = $regressionBaseline->contentHash;
            $summary['e5_baseline_capture_order'] = $regressionBaseline->captureOrder;

            $candidateEvidence = [];
            foreach ($candidates as $candidate) {
                $regressions = $candidate['e5_regressions'] ?? [];
                $candidateEvidence[] = [
                    'index' => $candidate['index'],
                    'passed' => $candidate['passed'],
                    'e5_baseline_hash' => $regressionBaseline->contentHash,
                    'e5_regression_count' => count($regressions),
                    'e5_regressions' => array_values($regressions),
                ];
            }
            $summary['candidates'] = $candidateEvidence;
        }

        // VAL-E4-002: include the candidate divergence verdict + divergent
        // diffs as evidence when E4 is active. On agreement or off-mode the
        // $e4Summary is null so the summary stays byte-identical to pre-E4.
        if ($e4Summary !== null) {
            $summary['e4_candidate_agreed'] = $e4Summary['agreed'];
            if ($e4Summary['flag'] !== null) {
                $summary['e4_flag'] = $e4Summary['flag'];
            }
            if ($e4Summary['divergent_diffs'] !== []) {
                $summary['e4_divergent_diffs'] = $e4Summary['divergent_diffs'];
            }
        }

        return $summary;
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
    public function buildCriticInput(
        string $runId,
        ScopeGuardReceipt $scopeReceipt,
        LightTaskContract $taskContract,
        VerificationGateResult $verificationResult,
        DiffParseResult $diffResult,
        bool $intentWitnessed = false,
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
            // RED→GREEN WITNESS: quando o repair é comportamentalmente
            // testemunhado (baseline vermelho → verificação verde), o intent
            // FOI endereçado por prova de execução — verbs vazios usam o
            // caminho silencioso documentado acima (detector mudo), evitando
            // que o critic re-flague pelo mesmo probe heurístico que o
            // executor já isentou.
            'intent_basis' => [
                'intent_verbs' => $intentWitnessed ? [] : array_values($taskContract->intentVerbs),
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
    public function parseDiffIntoChunks(string $diff): array
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
}
