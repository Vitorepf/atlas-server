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
 * Repair prompt projection composition and failure excerpts.
 *
 * Extracted verbatim from PipelineRunExecutor (godfile split, GOD-DEBULK
 * 2026-07-22). Behavior unchanged; cross-family calls route through the
 * sibling sections injected below.
 */
final class RepairProjectionSection
{
    public function __construct(
        private readonly ResolverSupport $resolver,
    ) {}

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
    public function extractFailureExcerpt(VerificationGateResult $result): string
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
     *      "Previous attempt failed" header so the failure-excerpt structure
     *      VAL-M2-008 locks is preserved, while the composed body underneath
     *      carries strictly richer guard-rail content.
     *
     * M1 (provider-agnostic): this returns a full {@see ProviderPromptProjection}
     * (not a hermes-only prompt string) so the repair loop can re-invoke ANY
     * locked provider through the same executeLockedProvider dispatch. The
     * composed projection carries the Repair Capsule / Primary Error / Stop
     * Conditions sections and does NOT assume hermes transport; Claude consumes
     * it via SonnetClaudeCliAdapter, Codex/Cursor/Hermes via their invocation
     * drivers — all read rendered_prompt_text from the projection.
     *
     * The loop's same-signature-twice detection in execute() continues to
     * compare the FailureSignatureHasher signature of the raw failure
     * excerpt; that signature equals the capsule's failure_signature
     * (both go through hasher->normalize() then FailureCapsule::signatureOf).
     *
     * E1 repair-loop feedback (VAL-E1-006, VAL-E1-007, VAL-E1-013,
     * VAL-CROSS-006): when the E1 intent-falsification probe is active and
     * the diff misses the intent, the probe reason is fed as a SEPARATE field
     * ($intentProbeReason) and rendered in a DEDICATED `## Intent Not Yet
     * Addressed` section. CRITICAL: $failureExcerpt is NEVER mutated by the
     * probe reason — it feeds the FailureSignatureHasher for same-signature-
     * twice anti-spin and must stay byte-identical regardless of the probe.
     * The probe reason lives in its own prompt section so the regenerated
     * attempt can act on it (convergence) without destabilizing the failure
     * signature. When $intentProbeReason is '' (probe off, or intent
     * addressed), the dedicated section is omitted entirely (conditional-empty
     * pattern: byte-identical to the pre-feedback baseline).
     */
    public function buildComposedRepairProjection(
        ProviderPromptProjection $promptProjection,
        LightTaskContract $taskContract,
        VerificationGateResult $verificationResult,
        ScopeGuardReceipt $scopeReceipt,
        DiffParseResult $diffResult,
        string $failureExcerpt,
        int $repairAttempt,
        int $repairCap,
        string $intentProbeReason = '',
        string $weakOutputHint = '',
    ): ProviderPromptProjection {
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
        // before the Repair Capsule section so the failure-excerpt structure
        // VAL-M2-008 locks is preserved, without duplicating the original
        // prompt body.
        //
        // E1 repair-loop feedback (VAL-E1-006, VAL-E1-007, VAL-E1-013,
        // VAL-CROSS-006): the probe reason ($intentProbeReason) is rendered
        // as a SEPARATE dedicated section right after the failure-excerpt
        // marker, BEFORE the Repair Capsule. CRITICAL: it is NEVER folded
        // into $failureExcerpt (which is hashed by FailureSignatureHasher
        // for same-signature-twice anti-spin). When empty (probe off, or
        // intent addressed), the section is omitted entirely so the prompt
        // is byte-identical to the pre-feedback baseline (conditional-empty
        // pattern, mirroring `## Known Failure Modes`).
        $marker = "--- REPAIR REQUIRED ({$gate}) ---\n"
            ."Previous attempt failed. Error output:\n{$failureExcerpt}\n";
        $intentSection = $this->renderIntentProbeSection($intentProbeReason)
            .$this->renderWeakOutputSection($weakOutputHint);
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

            $finalText = $cappedPreCapsule
                .$marker
                .$intentSection
                .substr($composed, $capsulePos);
        } else {
            // Fallback (defensive): append the marker + composed body tail if
            // the capsule header was not found (composition contract changed).
            $finalText = mb_substr($promptProjection->renderedPromptText, 0, 20_000)
                ."\n\n".$marker
                .$intentSection
                .$composed;
        }

        // Rebuild the composed projection with the marker-injected rendered
        // text and recomputed hashes, so every provider receives a valid,
        // sendable ProviderPromptProjection (isSendable() requires a non-empty
        // rendered_prompt_hash). The sections / quality checks / provider-safe
        // flag are inherited verbatim from the composed projection; only the
        // rendered text (and its two hashes) change. The marker injection is
        // purely additive text — it never weakens the composer's guard rails.
        return $this->rebuildProjectionWithRenderedText($repairProjection, $finalText);
    }

    /**
     * Rebuild a {@see ProviderPromptProjection} with a new rendered_prompt_text
     * and recomputed rendered_prompt_hash + prompt_projection_hash.
     *
     * Used by the repair loop to inject the REPAIR REQUIRED marker into the
     * composed projection's rendered text without touching the sections,
     * quality checks, or provider-safe flag the composer already established.
     * The resulting projection is sendable (QualityChecks::allPassing() is
     * inherited) so any locked provider's runtime driver accepts it.
     */
    public function rebuildProjectionWithRenderedText(
        ProviderPromptProjection $projection,
        string $renderedText,
    ): ProviderPromptProjection {
        $payload = $projection->toCanonicalArray();
        $payload['rendered_prompt_text'] = $renderedText;
        $payload['rendered_prompt_hash'] = hash('sha256', $renderedText);
        // hash() excludes prompt_projection_hash from its own digest, so build
        // a skeleton with a placeholder then recompute the canonical hash.
        $payload['prompt_projection_hash'] = 'pending';
        $skeleton = ProviderPromptProjection::fromArray($payload);
        $payload['prompt_projection_hash'] = $skeleton->hash();

        return ProviderPromptProjection::fromArray($payload);
    }

    /**
     * E1 repair-loop feedback (VAL-E1-006, VAL-E1-007, VAL-E1-013,
     * VAL-CROSS-006): render the intent-probe reason as a DEDICATED prompt
     * section, separate from $failureExcerpt.
     *
     * Conditional-empty pattern (mirrors `## Known Failure Modes`): when the
     * reason is empty (probe off, or intent addressed), the section is
     * omitted entirely so the rendered prompt is byte-identical to the
     * pre-feedback baseline. When non-empty, the section carries the probe
     * reason as a live input the regenerated attempt can act on (the verb
     * set the diff failed to implement + the intent subject), positioned
     * AFTER the REPAIR REQUIRED failure-excerpt marker and BEFORE the
     * Repair Capsule so the failure-excerpt structure VAL-M2-008 locks is
     * preserved while the probe feedback is strictly additive.
     *
     * CRITICAL: this section NEVER mutates $failureExcerpt. The reason lives
     * in its own block so FailureSignatureHasher (which hashes only the
     * excerpt) produces the same signature regardless of the probe — the
     * cap=3 same-signature-twice anti-spin still fires on a genuinely stuck
     * repair (VAL-E1-008).
     */
    /**
     * Weak-output feedback ({@see DevWeakOutputDetector}): a dedicated repair-prompt
     * section naming WHY the previous output was structurally weak (truncated diff,
     * out-of-scope file, placeholder body, restated code) and what to re-emphasize.
     * Conditional-empty: returns '' when there is no hint, so the composed prompt is
     * byte-identical to the pre-detector baseline (mirrors the intent-probe section,
     * and like it the hint is NEVER folded into the hashed failure excerpt).
     */
    public function renderWeakOutputSection(string $weakOutputHint): string
    {
        $hint = trim($weakOutputHint);
        if ($hint === '') {
            return '';
        }

        return "\n## Previous Output Was Structurally Weak\n"
            .$hint."\n\n";
    }

    public function renderIntentProbeSection(string $intentProbeReason): string
    {
        $reason = trim($intentProbeReason);
        if ($reason === '') {
            // Conditional-empty: byte-identical to the pre-feedback baseline.
            return "\n";
        }

        return "\n## Intent Not Yet Addressed\n"
            .$reason."\n\n";
    }

    /**
     * E1 repair-loop feedback (VAL-E1-006, VAL-E1-013, VAL-CROSS-006):
     * resolve the intent-probe reason to feed into the M2 repair prompt as a
     * SEPARATE field (a live input the regenerated attempt can act on).
     *
     * Returns '' when:
     *   - e1.mode is off (the probe is never consulted; byte-identical to
     *     pre-E1, VAL-CROSS-010); OR
     *   - the probe does NOT fire (the diff traceably implements the intent,
     *     VAL-E1-005 — no reason to feed forward); OR
     *   - the contract carries no recognized intent verb (nothing to probe).
     *
     * When non-empty, the reason references the unaddressed intent verbs and
     * the intent subject so the next repair iteration knows WHAT to implement
     * (convergence, VAL-E1-013). The reason is sourced from the
     * E2-established basis (LightTaskContract::intentVerbs + intentText),
     * model-irrelevant, and deterministic.
     *
     * The reason is NEVER folded into $failureExcerpt (which is hashed by
     * FailureSignatureHasher for same-signature-twice anti-spin). It flows
     * through its own dedicated prompt section via
     * {@see renderIntentProbeSection()} so the failure signature stays
     * byte-identical regardless of the probe (VAL-E1-007, VAL-E1-008).
     */
    public function resolveIntentProbeReasonForRepair(
        LightTaskContract $taskContract,
        DiffParseResult $diffResult,
    ): string {
        // off mode: byte-identical to pre-E1 (no probe, no reason).
        if ($this->resolver->resolveE1Config()->isOff()) {
            return '';
        }

        return (new IntentFalsificationProbe)->probeReason($taskContract, $diffResult);
    }
}
