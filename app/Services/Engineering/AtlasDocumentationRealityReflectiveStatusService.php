<?php

declare(strict_types=1);

namespace App\Services\Engineering;

use App\Services\Ai\AgenticEngineeringOs\Maturity\AtlasImplementationTruthService;

/**
 * L-inf (ONE promoted fragment: R2 EPISTEMIC HUMILITY) — the REFLECTIVE SELF-STATUS.
 *
 * L0/L1/L2 ask the truth about the CODE ("does the code match the doc? will it?
 * did it work in the world?"). L-inf turns the lens on the ADRS ITSELF and asks the
 * one question the mother doc names as the top of the ladder:
 *
 *   "What do I NOT know about myself? Where might my own model be wrong?
 *    What is my uncertainty — calibrated and declared?"
 *
 * This service promotes EXACTLY ONE measurable fragment of that asymptote — R2,
 * epistemic humility — and NOTHING more. It does NOT claim the asymptote. L-inf is
 * a PERMANENT COMPASS, never a sprint, never "done"; this fragment is one rung of
 * the reflection made runtime, with proof and declared uncertainty, while the whole
 * remains north-star (see atlas-documentation-reality-reflective-self-model.md).
 *
 * THE SUPREME DRIFT this fragment exists to make STRUCTURALLY IMPOSSIBLE
 * (reflective-self-model.md:122-127, :150-151, :169, :193 — "o sistema confiantemente
 * errado sobre si proprio" / "toda afirmacao do sistema sobre si mesmo carrega
 * incerteza calibrada"): a self-claim WITHOUT calibrated uncertainty. Every claim
 * this service emits carries a CALIBRATED confidence (high|medium|low, justified)
 * AND — for any non-high confidence OR any completion-flavored claim
 * ("complete/done/10/10") — at least one DECLARED blind_spot. The headline that
 * answers "is the ADRS doc<->runtime 10/10?" is NEVER a bare verdict and ALWAYS
 * carries a non-empty declared_blind_spots list. The invariant is enforced in code
 * (a LogicException, like P3/O1): a self-claim without calibrated uncertainty cannot
 * be produced — the supreme drift is unreachable by construction.
 *
 * COMPOSITION (does NOT re-derive truth): the status is COMPOSED from the real
 * ladder collaborators, read-only:
 *   - AtlasImplementationTruthService::coverage() — the doc<->runtime measure
 *     (coverage_pct / score_out_of_10) AND, crucially, its KNOWN blind spot: it
 *     measures what CLAIMS runtime, not whether the claim-SET is complete.
 *   - AtlasDocumentationRealityOutcomeGroundingService::gradeAll() — the L2-O1
 *     outcome_grounded count, which proves (from real data, never asserted) the
 *     blind spot "0 = no world validation yet".
 *   - AtlasDocumentationRealitySystemService::report() — the L0 block-readiness
 *     status that grounds the L0 claim.
 * Each collaborator is degrade-safe; a missing read model lowers confidence and adds
 * a blind_spot, it never fabricates certainty.
 *
 * CRITICAL SAFETY: strictly READ-ONLY. It composes existing read-only reports; it
 * writes NOTHING, executes nothing, mutates nothing. R1 (causal self-model) and R3
 * (self-improving modeling) are NOT built — they are LATER fragments of the same
 * asymptote and are explicitly out of scope; their absence is itself a declared
 * blind spot in the headline.
 *
 * @see docs/engineering-knowledge-base/atlas-documentation-reality-reflective-status-fragment.md
 * @see docs/engineering-knowledge-base/atlas-documentation-reality-reflective-self-model.md
 */
class AtlasDocumentationRealityReflectiveStatusService
{
    public const SCHEMA = 'atlas.documentation_reality.reflective_status.v1';

    /** Calibrated confidence levels. There is no fourth — "certain" is deliberately absent. */
    public const CONFIDENCE_HIGH = 'high';

    public const CONFIDENCE_MEDIUM = 'medium';

    public const CONFIDENCE_LOW = 'low';

    /**
     * Tokens that make a claim "completion-flavored". A claim whose prose contains any
     * of these is asserting closeness-to-done and MUST carry a blind_spot even when
     * its confidence is high — closeness-to-done is precisely where the supreme drift
     * (confidently wrong about itself) hides. The matcher is case-insensitive.
     *
     * @var array<int,string>
     */
    private const COMPLETION_TOKENS = ['complete', 'completo', 'done', 'concluido', 'concluído', '10/10', 'finished', 'finalizado'];

    public function __construct(
        private readonly AtlasImplementationTruthService $truth,
        private readonly AtlasDocumentationRealityOutcomeGroundingService $outcomeGrounding,
        private readonly AtlasDocumentationRealitySystemService $system,
    ) {}

    /**
     * Compose the reflective self-status: one calibrated, blind-spot-bearing claim per
     * rung/capability (L0, L1-P1/P2/P3, L2-O1/O2/O3), then a HEADLINE that answers
     * "is the ADRS doc<->runtime 10/10?" WITHOUT a bare verdict — always with declared
     * blind spots. Read-only end to end; the envelope is hashed for tamper-evidence.
     *
     * @return array<string,mixed>
     */
    public function selfAssessment(): array
    {
        // Compose the real measures, degrade-safe. A failure to read a collaborator is
        // NOT a reason to claim certainty — it LOWERS confidence and adds a blind spot.
        $coverage = $this->safeCoverage();
        $outcome = $this->safeOutcome();
        $l0 = $this->safeL0();

        $claims = $this->claims($coverage, $outcome, $l0);

        // INVARIANT (enforced in code): no claim may exist without calibrated
        // uncertainty. Validate EVERY claim before it can be emitted — a violation is
        // the supreme drift and must be structurally impossible, so we fail loud.
        foreach ($claims as $claim) {
            $this->assertClaimCarriesCalibratedUncertainty($claim);
        }

        $headline = $this->headline($coverage, $outcome, $claims);

        $envelope = [
            'schema_version' => self::SCHEMA,
            'level' => 'L-inf (one promoted fragment: R2 epistemic humility)',
            'fragment' => 'R2_epistemic_humility',
            // HARD guarantees about what this fragment IS and IS NOT. These are the
            // load-bearing flags: this is ONE fragment, the asymptote is never claimed.
            'is_one_fragment_not_asymptote' => true,
            'linf_complete' => false,
            'claims' => $claims,
            'headline' => $headline,
            'claim_policy' => $this->claimPolicy(),
            'writes' => false,
        ];

        return $this->finalize($envelope);
    }

    /**
     * Build one claim per rung/capability. Confidence is CALIBRATED against the real
     * composed signals (not asserted): a rung whose evidence we can read at this
     * moment earns higher confidence than one we cannot; a completion-flavored or
     * non-high claim ALWAYS carries a blind_spot. The real, un-hidden blind spots are
     * attached here — first-increments-only, outcome_grounded=0, claim-set-completeness.
     *
     * @param  array<string,mixed>  $coverage
     * @param  array<string,mixed>  $outcome
     * @param  array<string,mixed>  $l0
     * @return array<int,array<string,mixed>>
     */
    private function claims(array $coverage, array $outcome, array $l0): array
    {
        $l0Status = $this->str($l0['status'] ?? null) ?? 'unknown';
        $l0Readable = $l0['readable'] ?? false;
        $outcomeGroundedCount = (int) ($outcome['outcome_grounded_count'] ?? 0);
        $outcomeSourceAvailable = (bool) ($outcome['source_available'] ?? false);
        $coverageReadable = $coverage['readable'] ?? false;

        $claims = [];

        // L0 — immune (write-gate + block readiness). Confidence high ONLY if we could
        // actually read the L0 report and it is ready; otherwise calibrate down.
        $claims[] = $this->claim(
            rung: 'L0',
            claim: $l0Readable
                ? "L0 (immune / write-gate) is wired and its block-readiness report is reachable; status: {$l0Status}."
                : 'L0 (immune / write-gate) exists, but its block-readiness report could not be read this run.',
            confidence: $l0Readable && $l0Status === 'ready' ? self::CONFIDENCE_HIGH : self::CONFIDENCE_MEDIUM,
            blindSpots: array_values(array_filter([
                'L0 readiness measures the ADRS docs/blocks that declare evidence, not every possible write path through the system.',
                "This confidence rests on the report's status field ('{$l0Status}'); I do not independently re-verify that each underlying sub-check actually ran this run.",
                $l0Readable ? null : 'The L0 report was unreadable this run, so this claim is calibrated down rather than asserted.',
            ])),
            evidenceRef: 'AtlasDocumentationRealitySystemService::report (atlas:documentation-reality score)',
        );

        // L1 — generative leap. P1/P2/P3 are FIRST increments only (proposers /
        // simulator), NOT the full self-healing/auto-synthesis. That is the headline
        // blind spot, restated per pillar so no claim looks more finished than it is.
        $claims[] = $this->claim(
            rung: 'L1-P1',
            claim: 'L1-P1 (predictive simulator) is built as a FIRST increment: it predicts duplication/drift/owner before a write; it does not yet model every failure mode.',
            confidence: self::CONFIDENCE_MEDIUM,
            blindSpots: [
                'First increment only: prediction covers duplication/drift/owner/blast-radius, not the full space of pre-write failure modes.',
            ],
            evidenceRef: 'AtlasSoftwareTwinRuntimeService::simulate (atlas:documentation-reality intent-advisory composes it)',
        );

        $claims[] = $this->claim(
            rung: 'L1-P2',
            claim: 'L1-P2 (self-healing repair) is built as a FIRST increment: it PROPOSES conservative doc-side repairs for drift; it never applies them and never touches code.',
            confidence: self::CONFIDENCE_MEDIUM,
            blindSpots: [
                'First increment only: it is a proposer — a human/gate applies repairs; auto-application is a later, unbuilt increment.',
            ],
            evidenceRef: 'AtlasDocumentationRealityRepairProposerService (atlas:documentation-reality-repair-proposals)',
        );

        $claims[] = $this->claim(
            rung: 'L1-P3',
            claim: 'L1-P3 (self-immunizing antibody) is built as a FIRST increment: from an escaped failure it proposes a detector + reproducing-test outline; it never installs a gate or generates code.',
            confidence: self::CONFIDENCE_MEDIUM,
            blindSpots: [
                'First increment only: antibody auto-synthesis onto disk is deliberately out of scope; a human writes the reproducing test and gate.',
            ],
            evidenceRef: 'AtlasDocumentationRealityAntibodyProposerService (atlas:documentation-reality-antibody-proposals)',
        );

        // L2 — outcome-grounded leap. O1 is REAL and its number is composed live: the
        // outcome_grounded count PROVES (from data, not assertion) the blind spot that
        // there is NO world validation yet when the count is 0 / the source is absent.
        $claims[] = $this->claim(
            rung: 'L2-O1',
            claim: $outcomeGroundedCount > 0
                ? "L2-O1 (outcome-grounding scorer) is built and currently grounds {$outcomeGroundedCount} capability(ies) on a REAL outcome signal."
                : 'L2-O1 (outcome-grounding scorer) is built, but outcome_grounded is currently 0 — NO capability has a real world-outcome signal yet, so external truth is unvalidated.',
            confidence: self::CONFIDENCE_MEDIUM,
            blindSpots: array_values(array_filter([
                'First increment only: O1 grades whether a real outcome signal LINKS to a capability; it does not yet measure outcome quality over time.',
                $outcomeGroundedCount === 0
                    ? 'outcome_grounded = 0 right now: the ladder is internally true (drift-checked) but NOT yet validated in the world.'
                    : null,
                $outcomeSourceAvailable ? null : 'The outcome-signal source was unavailable this run, so no world outcome could be observed — never fabricated.',
            ])),
            evidenceRef: 'AtlasDocumentationRealityOutcomeGroundingService::gradeAll (atlas:documentation-reality-outcome-grounding)',
        );

        $claims[] = $this->claim(
            rung: 'L2-O2',
            claim: 'L2-O2 (intent co-formation) is built as a FIRST increment and is strictly ADVISORY + human-gated: it surfaces considerations/leverage questions, never a decision, and never overrides the operator.',
            confidence: self::CONFIDENCE_MEDIUM,
            blindSpots: [
                'First increment only: advisory opinion from structural signals; it does not know the operator objective unless told, and never decides.',
            ],
            evidenceRef: 'AtlasDocumentationRealityIntentAdvisoryService (atlas:documentation-reality-intent-advisory)',
        );

        $claims[] = $this->claim(
            rung: 'L2-O3',
            claim: 'L2-O3 (multi-estate compounding) is built as a FIRST increment: it PROPOSES propagating an abstract antibody pattern across estates; sovereignty classes sensitive/secret/cyber never leave the machine and it never auto-propagates.',
            confidence: self::CONFIDENCE_MEDIUM,
            blindSpots: [
                'First increment only: a read-only proposer; it never transmits cross-machine and installs nothing.',
            ],
            evidenceRef: 'AtlasDocumentationRealityMultiEstateCompoundingService (atlas:documentation-reality-multi-estate)',
        );

        // The doc<->runtime coverage claim — the measured headline number, stated WITH
        // its own blind spot (it measures what CLAIMS runtime, not claim-set completeness).
        $claims[] = $this->claim(
            rung: 'L-inf/R2-coverage',
            claim: $coverageReadable
                ? $this->coverageProse($coverage)
                : 'The doc<->runtime coverage measure could not be read this run; no coverage number is asserted.',
            confidence: $coverageReadable ? self::CONFIDENCE_MEDIUM : self::CONFIDENCE_LOW,
            blindSpots: array_values(array_filter([
                'Coverage measures what CLAIMS runtime (state partial/verified or status active/building) and has resolvable evidence_refs — NOT whether the SET of claims is complete.',
                'A high coverage number means the claims made are backed; it does NOT mean every capability that SHOULD be claimed has a doc.',
                $coverageReadable ? null : 'The coverage read model was unavailable this run, so confidence is low rather than asserted.',
            ])),
            evidenceRef: 'AtlasImplementationTruthService::coverage (atlas:aaeos:maturity)',
        );

        return $claims;
    }

    /**
     * The HEADLINE answering "is the ADRS doc<->runtime 10/10?". By contract this is
     * NEVER a bare number/verdict: it is prose that STATES the measure AND its own
     * blind spots, exactly the canonical L-inf answer from the mother doc. The
     * declared_blind_spots list is ALWAYS non-empty and includes the real ones:
     * first-increments-only, outcome_grounded=0, and claim-set-incompleteness.
     *
     * @param  array<string,mixed>  $coverage
     * @param  array<string,mixed>  $outcome
     * @param  array<int,array<string,mixed>>  $claims
     * @return array<string,mixed>
     */
    private function headline(array $coverage, array $outcome, array $claims): array
    {
        $coverageReadable = $coverage['readable'] ?? false;
        $outcomeGroundedCount = (int) ($outcome['outcome_grounded_count'] ?? 0);

        $measureClause = $coverageReadable
            ? sprintf(
                'On the measure I HAVE — doc<->runtime coverage — I am at %s/10 (%d%%: %d of %d docs that claim runtime are backed by resolvable evidence)',
                $this->numStr($coverage['score_out_of_10'] ?? null),
                (int) ($coverage['coverage_pct'] ?? 0),
                (int) ($coverage['verifiably_backed'] ?? 0),
                (int) ($coverage['claims_runtime'] ?? 0),
            )
            : 'I cannot read my doc<->runtime coverage measure this run, so I will not assert a number';

        $assessment = $measureClause
            .'. But "10/10" is NOT something I can claim about myself. '
            .'Known blind spot: my coverage measures what CLAIMS runtime, not whether the SET of claims is complete — a backed claim-set can still be missing claims. '
            .($outcomeGroundedCount === 0
                ? 'And outcome_grounded is currently 0: the ladder is internally true (drift-checked) but NOT yet validated in the world. '
                : "And only {$outcomeGroundedCount} capability(ies) carry a real world-outcome signal, so external validation is partial. ")
            .'The L1/L2 rungs are FIRST INCREMENTS only (later increments are unbuilt), and this reflective answer is itself ONE fragment (R2) of L-inf — not the asymptote, which remains a permanent compass, never done.';

        $declaredBlindSpots = [
            'These are FIRST INCREMENTS only: L1-P1/P2/P3 and L2-O1/O2/O3 are first steps (proposers/scorer/simulator), not the full generative/outcome-grounded capability — later increments are unbuilt.',
            $outcomeGroundedCount === 0
                ? 'L2-O1 outcome_grounded = 0: there is NO world validation yet — internal truth (drift zero) is not external truth.'
                : "L2-O1 outcome_grounded = {$outcomeGroundedCount}: world validation exists but is partial, not comprehensive.",
            'Coverage measures what CLAIMS runtime, not whether the claim-SET is complete: I can be fully backed on what I assert and still be silent about what I have not yet documented.',
            'This reflective status models my own STATUS, not all of reality; it is ONE measurable fragment (R2 epistemic humility) of L-inf, never the whole asymptote.',
            'Even this humility guard has a limit: it enforces that every self-claim DECLARES calibrated uncertainty — it cannot guarantee the claims are CORRECT, only that none are asserted as confident certainty without a declared limit.',
        ];

        if (! $coverageReadable) {
            $declaredBlindSpots[] = 'My coverage read model was unavailable this run, so even the measure I report is degraded — I declare that rather than assert a clean number.';
        }

        $headline = [
            'question' => 'Is the ADRS doc<->runtime 10/10?',
            'assessment' => $assessment,
            'confidence' => $coverageReadable ? self::CONFIDENCE_MEDIUM : self::CONFIDENCE_LOW,
            'is_bare_verdict' => false,
            'declared_blind_spots' => array_values($declaredBlindSpots),
        ];

        // INVARIANT: the headline ALWAYS carries declared blind spots and is NEVER a
        // bare 10/10. A headline without declared blind spots is the supreme drift.
        if (($headline['declared_blind_spots'] ?? []) === []) {
            throw new \LogicException('Invariant violation: the reflective headline must always carry non-empty declared_blind_spots — a bare self-verdict is the supreme drift.');
        }

        return $headline;
    }

    /**
     * Build ONE claim and validate it on the spot. confidence is required; for any
     * non-high confidence OR any completion-flavored claim, blind_spots MUST be
     * non-empty — the build path itself cannot produce an uncalibrated self-claim.
     *
     * @param  array<int,string>  $blindSpots
     * @return array<string,mixed>
     */
    private function claim(string $rung, string $claim, string $confidence, array $blindSpots, string $evidenceRef): array
    {
        $built = [
            'rung' => $rung,
            'claim' => $claim,
            'confidence' => $confidence,
            'blind_spots' => array_values(array_filter($blindSpots, static fn (string $b): bool => trim($b) !== '')),
            'evidence_ref' => $evidenceRef,
        ];

        // Validate immediately so an uncalibrated claim can NEVER leave this method.
        $this->assertClaimCarriesCalibratedUncertainty($built);

        return $built;
    }

    /**
     * THE SUPREME-DRIFT GUARD, in code. A self-claim is valid ONLY when it carries a
     * calibrated confidence AND — when that confidence is not high, OR the claim is
     * completion-flavored — at least one declared blind_spot. Anything else is a
     * self-claim without calibrated uncertainty: the supreme drift, made impossible by
     * this LogicException (mirrors the P3/O1 invariant guards).
     *
     * @param  array<string,mixed>  $claim
     */
    private function assertClaimCarriesCalibratedUncertainty(array $claim): void
    {
        $confidence = $this->str($claim['confidence'] ?? null);
        $rung = $this->str($claim['rung'] ?? null) ?? '(unknown rung)';

        if (! in_array($confidence, [self::CONFIDENCE_HIGH, self::CONFIDENCE_MEDIUM, self::CONFIDENCE_LOW], true)) {
            throw new \LogicException("Invariant violation: self-claim for {$rung} has no calibrated confidence — a self-claim without calibrated uncertainty is the supreme drift.");
        }

        $blindSpots = array_values(array_filter(
            (array) ($claim['blind_spots'] ?? []),
            static fn (mixed $b): bool => is_string($b) && trim($b) !== '',
        ));

        // EVERY self-claim — INCLUDING high confidence — must declare at least one
        // blind spot. The mother doc is absolute: "toda afirmacao do sistema sobre si
        // mesmo carrega incerteza calibrada". Even the most certain rung names a known
        // limit; a self-claim with NO declared limit is the supreme drift. (Completion-
        // flavored prose, detected by isCompletionFlavored(), is merely the highest-risk
        // case of a rule that now admits no high-confidence exception.)
        if ($blindSpots === []) {
            $flavor = $this->isCompletionFlavored($this->str($claim['claim'] ?? null) ?? '') ? ' (completion-flavored)' : '';
            throw new \LogicException("Invariant violation: self-claim for {$rung} (confidence={$confidence}){$flavor} must declare at least one blind_spot — ANY self-claim about itself without a declared limit is the supreme drift.");
        }
    }

    /**
     * A claim is completion-flavored when its prose asserts closeness-to-done. Such a
     * claim must carry a blind_spot even at high confidence — closeness-to-done is
     * exactly where "confidently wrong about itself" hides.
     */
    private function isCompletionFlavored(string $claim): bool
    {
        $haystack = mb_strtolower($claim);
        foreach (self::COMPLETION_TOKENS as $token) {
            if ($token !== '' && str_contains($haystack, $token)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  array<string,mixed>  $coverage
     */
    private function coverageProse(array $coverage): string
    {
        return sprintf(
            'doc<->runtime coverage is %s/10 (%d%%): %d of %d docs that claim runtime are backed by resolvable evidence_refs (%d claim runtime without evidence).',
            $this->numStr($coverage['score_out_of_10'] ?? null),
            (int) ($coverage['coverage_pct'] ?? 0),
            (int) ($coverage['verifiably_backed'] ?? 0),
            (int) ($coverage['claims_runtime'] ?? 0),
            (int) ($coverage['unverifiable_claims'] ?? 0),
        );
    }

    /**
     * Read the doc<->runtime coverage measure, degrade-safe. On any failure we return
     * readable=false rather than a fabricated number — a missing measure lowers
     * confidence and adds a blind spot, it never invents certainty.
     *
     * @return array<string,mixed>
     */
    private function safeCoverage(): array
    {
        try {
            $coverage = $this->truth->coverage();

            return [
                'readable' => true,
                'coverage_pct' => (int) ($coverage['coverage_pct'] ?? 0),
                'score_out_of_10' => $coverage['score_out_of_10'] ?? null,
                'claims_runtime' => (int) ($coverage['claims_runtime'] ?? 0),
                'verifiably_backed' => (int) ($coverage['verifiably_backed'] ?? 0),
                'unverifiable_claims' => (int) ($coverage['unverifiable_claims'] ?? 0),
            ];
        } catch (\Throwable) {
            return ['readable' => false];
        }
    }

    /**
     * Read the L2-O1 outcome-grounding count, degrade-safe. A failure to read the
     * scorer yields outcome_grounded_count=0 with source_available=false — honest, and
     * the headline declares the resulting blind spot.
     *
     * @return array<string,mixed>
     */
    private function safeOutcome(): array
    {
        try {
            $graded = $this->outcomeGrounding->gradeAll();

            return [
                'readable' => true,
                'outcome_grounded_count' => (int) data_get($graded, 'summary.outcome_grounded_count', 0),
                'source_available' => (bool) data_get($graded, 'summary.outcome_signal_source_available', false),
            ];
        } catch (\Throwable) {
            return ['readable' => false, 'outcome_grounded_count' => 0, 'source_available' => false];
        }
    }

    /**
     * Read the L0 block-readiness status, degrade-safe.
     *
     * @return array<string,mixed>
     */
    private function safeL0(): array
    {
        try {
            $report = $this->system->report();

            return [
                'readable' => true,
                'status' => $this->str($report['status'] ?? null) ?? 'unknown',
            ];
        } catch (\Throwable) {
            return ['readable' => false, 'status' => 'unknown'];
        }
    }

    /**
     * The read-only/epistemic claim policy. every_claim_carries_calibrated_uncertainty
     * and never_confidently_wrong_about_itself are the load-bearing flags: they assert,
     * in the envelope itself, that the supreme drift is structurally prevented.
     *
     * @return array<string,bool>
     */
    private function claimPolicy(): array
    {
        return [
            'read_only' => true,
            'writes' => false,
            'executes' => false,
            'mutates' => false,
            'every_claim_carries_calibrated_uncertainty' => true,
            'never_confidently_wrong_about_itself' => true,
            'is_one_linf_fragment_not_the_asymptote' => true,
            'models_own_status_not_all_reality' => true,
        ];
    }

    /**
     * Hash the read-only envelope for tamper-evidence, excluding the volatile
     * generated_at the command adds and the hash field itself.
     *
     * @param  array<string,mixed>  $envelope
     * @return array<string,mixed>
     */
    private function finalize(array $envelope): array
    {
        $hashPayload = $envelope;
        unset($hashPayload['generated_at'], $hashPayload['reflective_status_hash']);
        $envelope['reflective_status_hash'] = hash(
            'sha256',
            json_encode($hashPayload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
        );

        return $envelope;
    }

    private function numStr(mixed $value): string
    {
        if (! is_numeric($value)) {
            return 'n/a';
        }

        // Keep one decimal like score_out_of_10 (e.g. 9.5, 10.0) without trailing noise.
        return rtrim(rtrim(number_format((float) $value, 1, '.', ''), '0'), '.') ?: '0';
    }

    private function str(mixed $value): ?string
    {
        if (! is_scalar($value)) {
            return null;
        }

        $value = trim((string) $value);

        return $value === '' ? null : $value;
    }
}
