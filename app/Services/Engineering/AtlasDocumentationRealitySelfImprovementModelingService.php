<?php

declare(strict_types=1);

namespace App\Services\Engineering;

use LogicException;

/**
 * L-inf (ONE promoted fragment: R3 SELF-IMPROVING MODELING / meta-learning) — the
 * proposal-only maintainer of the self-model's OWN evolution ladder and next rung.
 *
 * R2 (epistemic humility) and R1 (causal self-model) are already promoted, one at a
 * time (reflective-self-model.md:38/171 — "So fragmentos mensuraveis da reflexao podem
 * virar runtime, um por vez, com prova e incerteza declarada; o todo permanece norte").
 * R3 is the THIRD and final fragment of the asymptote named at reflective-self-model.md
 * :148 ("melhora a propria capacidade de modelar (meta-aprendizado); mantem a propria
 * escada de evolucao e o proprio proximo degrau") and :159 ("esta pergunta expoe um
 * limite de modelagem? entao melhora o modelo").
 *
 * WHAT R3 DOES: it watches the self-model's OWN DECLARED LIMITS — the blind_spots,
 * the inference=inferred links, and the low-confidence links that R1 and R2 actually
 * EMIT — and, for the ones that are MODELING limits, it PROPOSES the next measurable
 * improvement to the self-model (the next rung of the model's own evolution ladder).
 *
 * COMPOSITION (reflective-self-model.md:157-159 — R1 responds, R2 attaches humility,
 * R3 registers whether a question exposes a modeling limit). This service NEVER
 * re-derives anything; it INJECTS and CALLS the real fragments, read-only:
 *   - AtlasDocumentationRealityCausalSelfModelService::explainAll() (R1) — every
 *     why-link's {inference, uncertainty.blind_spot, confidence} + each chain's
 *     calibration.blind_spots are R1's declared limits.
 *   - AtlasDocumentationRealityReflectiveStatusService::selfAssessment() (R2) — every
 *     claim's blind_spots + the headline's declared_blind_spots are R2's declared limits.
 * Each fragment is degrade-safe; a failure to read one degrades to {available:false}
 * with a calibrated note — it NEVER fabricates a limit.
 *
 * THE CENTRAL DISTINCTION (and the deepest guard): DATA-limit vs MODELING-limit.
 *   - A DATA-limit needs EXTERNAL REALITY to resolve — e.g. O1 outcome_grounded=0 (no
 *     world signal yet), "not yet validated in the world", an inferred reading of an
 *     ABSENCE of an outcome signal. R3 must NOT propose to "fix" a data-limit, because
 *     proposing to manufacture missing external data = proposing FABRICATION (the
 *     cardinal O1/ADRS sin). A data-limit is honestly surfaced as awaiting external
 *     reality, with proposed_next_rung=null and NO build/synthesis proposal.
 *   - A MODELING-limit is where the self-MODEL could be improved REGARDLESS of data —
 *     e.g. "the causal link is inferred not proven because the model does not trace
 *     actual causation" -> propose a better tracing mechanism; "coverage measures
 *     claims-runtime not claim-SET completeness" -> propose a claim-set completeness
 *     measure; "resolution is existence-only, not behavioral" -> propose a behavioral
 *     check. ONLY modeling-limits earn a proposed_next_rung.
 *
 * ABSOLUTE GUARDS (the cardinal sins of R3, enforced in code):
 *   - PROPOSAL-ONLY. R3 NEVER self-modifies, auto-builds, writes, or executes its own
 *     proposal — runaway self-modification is the deepest risk. Every proposal carries
 *     is_proposal:true, auto_applied:false, would_self_modify:false. It NEVER claims it
 *     HAS improved itself; only that it PROPOSES how a human could.
 *   - NEVER claim linf_complete (HARD false). NEVER fabricate a limit — every proposal
 *     is GROUNDED in a real declared limit that R1 or R2 actually emitted (limit_ref
 *     quotes the real blind_spot). Declared uncertainty on EVERY proposal.
 *   - assertProposalCalibratedAndGrounded() (mirrors R1/R2's supreme-drift guard)
 *     throws LogicException if a proposal lacks a non-empty grounded limit_ref or a
 *     non-empty uncertainty.blind_spot, OR if a data_limit carries a build/next_rung
 *     proposal — structurally preventing R3 from ever proposing to fabricate data.
 *
 * CRITICAL SAFETY: strictly READ-ONLY and PROPOSAL-ONLY. It composes existing
 * read-only reports; it writes NOTHING, executes nothing, mutates nothing, and applies
 * nothing. linf_complete stays HARD false; the mother reflective-self-model doc is
 * UNCHANGED and remains north_star.
 *
 * @see docs/engineering-knowledge-base/atlas-documentation-reality-self-improvement-modeling-fragment.md
 * @see docs/engineering-knowledge-base/atlas-documentation-reality-reflective-self-model.md
 */
class AtlasDocumentationRealitySelfImprovementModelingService
{
    public const SCHEMA = 'atlas.documentation_reality.self_improvement_modeling.v1';

    /** A declared limit is either improvable by the model alone, or it awaits the world. */
    public const CLASSIFICATION_MODELING_LIMIT = 'modeling_limit';

    public const CLASSIFICATION_DATA_LIMIT = 'data_limit';

    /** Calibrated confidence levels. As in R1/R2 there is no fourth — "certain" is absent. */
    public const CONFIDENCE_HIGH = 'high';

    public const CONFIDENCE_MEDIUM = 'medium';

    public const CONFIDENCE_LOW = 'low';

    /**
     * The honest awaits-clause for a data-limit. A data-limit is NEVER given a build
     * proposal — proposing to manufacture missing external data is fabrication.
     */
    public const AWAITS_EXTERNAL_REALITY = 'external reality (not a modeling improvement)';

    /**
     * Phrases that mark a declared limit as a DATA-limit: it can only be resolved by an
     * EXTERNAL-WORLD signal that does not exist yet, so R3 must NOT propose to "fix" it
     * (that would be proposing to fabricate data). Matched case-insensitively against
     * the declared blind_spot prose. These are taken from the REAL limits R1/R2 emit
     * (e.g. "NOT yet validated in the world", "outcome_grounded = 0", "Absence of an
     * outcome signal is NOT proof ...", "no real world-outcome signal").
     *
     * @var array<int,string>
     */
    private const DATA_LIMIT_MARKERS = [
        'not yet validated in the world',
        'not validated in the world',
        'no world validation yet',
        'no world-outcome signal',
        'world-outcome signal',
        'outcome_grounded = 0',
        'outcome_grounded is currently 0',
        'absence of an outcome signal',
        'absence of a signal',
        'no real outcome signal',
        'never proven world-causation',
        'world validation',
        'external truth is unvalidated',
        'external validation',
        'no green run',
        'no green-run',
        'used without a logged',
        // A world-outcome dependency is DECISIVE regardless of negation phrasing: any
        // limit that turns on validation-in-the-world or outcome QUALITY over time can
        // only be closed by external data, so it is a data-limit even when phrased
        // WITHOUT a literal "not" (e.g. "existence-only until validated in the world")
        // or when it also names a model word (e.g. "does not yet measure outcome quality
        // over time"). Structural enforcement of the doc's "world-signal is decisive"
        // rule, so the boundary never rests on a single branch's prose.
        'validated in the world',
        'outcome quality',
        'quality over time',
        'measure outcome quality',
    ];

    /**
     * Phrases that mark a declared limit as a MODELING-limit: the self-MODEL itself
     * could be improved regardless of external data. Matched case-insensitively. These
     * are taken from the REAL limits R1/R2 emit (e.g. "does NOT prove the claim-SET is
     * complete", "resolution is existence-only", "does not ... behaviorally correct",
     * an inferred reading the model does not itself prove as causation). When a limit
     * matches a modeling marker AND a data marker, the modeling reading wins ONLY if it
     * names a model-side improvement the data-limit does not require — handled by
     * classify(), which treats a world-signal dependency as decisive for data_limit.
     *
     * @var array<int,string>
     */
    private const MODELING_LIMIT_MARKERS = [
        'claim-set is complete',
        'claim-set is incomplete',
        'set of claims is complete',
        'claim-SET is complete',
        'whether the claim-set',
        'existence-only',
        'behaviorally correct',
        'behavioral',
        'does not trace',
        'not trace actual causation',
        'every possible write path',
        'every possible',
        'full space of pre-write failure modes',
        // "independently re-verify" (not "does not independently re-verify") so the
        // marker matches BOTH R2's first-person "I do not independently re-verify" and a
        // third-person "does not independently re-verify" — substring drift here would
        // silently drop a real modeling-limit to the conservative data default.
        'independently re-verify',
        'should be claimed has a doc',
        'should upgrade',
    ];

    public function __construct(
        private readonly AtlasDocumentationRealityCausalSelfModelService $causalSelfModel,
        private readonly AtlasDocumentationRealityReflectiveStatusService $reflectiveStatus,
    ) {}

    /**
     * Compose R1 + R2, extract every declared limit, classify each as a modeling-limit
     * or a data-limit, and for each modeling-limit emit a grounded, calibrated proposal
     * for the next measurable improvement to the self-model. Read-only, proposal-only;
     * the envelope is hashed for tamper-evidence.
     *
     * @return array<string,mixed>
     */
    public function proposeModelingImprovements(int $limit = 25): array
    {
        $limit = max(1, $limit);

        // Compose the real fragments, degrade-safe. A failure to read either fragment is
        // NEVER a reason to fabricate a limit — it degrades with a calibrated note.
        $r1 = $this->safeR1($limit);
        if ($r1 === null) {
            return $this->degraded('r1_causal_self_model_unavailable');
        }
        $r2 = $this->safeR2();
        if ($r2 === null) {
            return $this->degraded('r2_reflective_status_unavailable');
        }

        // Extract every DECLARED limit from the two fragments. Each carries its exact
        // source prose (the grounded limit_ref) — nothing is invented.
        $limits = array_merge(
            $this->limitsFromR1($r1),
            $this->limitsFromR2($r2),
        );

        $proposals = [];
        $modelingCount = 0;
        $dataCount = 0;
        foreach ($limits as $declared) {
            $entry = $this->classifyAndPropose($declared);

            // Validate EVERY emitted entry on the spot: a proposal must be grounded and
            // calibrated; a data-limit must NOT carry a build/next_rung proposal. An
            // ungrounded proposal or a data-limit build proposal is unreachable by
            // construction (LogicException), exactly like R1/R2's supreme-drift guard.
            $this->assertProposalCalibratedAndGrounded($entry);

            if ($entry['classification'] === self::CLASSIFICATION_MODELING_LIMIT) {
                $modelingCount++;
                $proposals[] = $entry;
            } else {
                $dataCount++;
                $proposals[] = $entry;
            }
        }

        return $this->finalize($this->envelope($proposals, $modelingCount, $dataCount));
    }

    /**
     * Extract R1's declared limits: for each causal chain, every why-link that is a
     * declared limit (inference=inferred OR low confidence carry their blind_spot as a
     * declared modeling/data limit), plus each chain's calibration.blind_spots. Every
     * extracted limit quotes the REAL prose R1 emitted — never paraphrased, never
     * invented. Returns [] (not a fabricated limit) when R1 carries no chains.
     *
     * @param  array<string,mixed>  $r1
     * @return array<int,array<string,mixed>>
     */
    private function limitsFromR1(array $r1): array
    {
        $limits = [];
        $chains = is_array($r1['causal_chains'] ?? null) ? $r1['causal_chains'] : [];

        foreach ($chains as $chain) {
            if (! is_array($chain)) {
                continue;
            }
            $cap = $this->str($chain['capability_id'] ?? null)
                ?? $this->str($chain['owner_doc'] ?? null)
                ?? '(unknown capability)';

            // (a) why-links. A link is a DECLARED LIMIT R3 watches when it is either
            // inference=inferred (the model itself flags the reading as not proven) or
            // low confidence (the model flags low certainty). Its blind_spot is the
            // exact declared-limit prose.
            foreach ((array) ($chain['why'] ?? []) as $link) {
                if (! is_array($link)) {
                    continue;
                }
                $inference = $this->str($link['inference'] ?? null);
                $confidence = $this->str(data_get($link, 'uncertainty.confidence'));
                $blindSpot = $this->str(data_get($link, 'uncertainty.blind_spot'));
                if ($blindSpot === null) {
                    continue;
                }
                $isInferred = $inference === AtlasDocumentationRealityCausalSelfModelService::INFERENCE_INFERRED;
                $isLowConf = $confidence === self::CONFIDENCE_LOW;
                if (! $isInferred && ! $isLowConf) {
                    continue;
                }

                $limits[] = [
                    'source' => 'R1.causal_self_model.why_link',
                    'source_fragment' => 'R1_causal_self_model',
                    'capability' => $cap,
                    'declared_limit' => $blindSpot,
                    'declared_in' => $isInferred
                        ? "R1 why-link labelled inference=inferred (the model flags this reading as not proven causation) for {$cap}"
                        : "R1 why-link with low calibrated confidence for {$cap}",
                    'origin_inference' => $inference,
                    'origin_confidence' => $confidence,
                ];
            }

            // (b) chain calibration blind_spots — R1's chain-level declared limits.
            foreach ((array) data_get($chain, 'calibration.blind_spots', []) as $spot) {
                $spotStr = $this->str($spot);
                if ($spotStr === null) {
                    continue;
                }
                $limits[] = [
                    'source' => 'R1.causal_self_model.calibration.blind_spot',
                    'source_fragment' => 'R1_causal_self_model',
                    'capability' => $cap,
                    'declared_limit' => $spotStr,
                    'declared_in' => "R1 chain calibration blind_spot for {$cap}",
                    'origin_inference' => null,
                    'origin_confidence' => $this->str(data_get($chain, 'calibration.confidence')),
                ];
            }
        }

        return $this->dedupeLimits($limits);
    }

    /**
     * Extract R2's declared limits: every claim's blind_spots + the headline's
     * declared_blind_spots. Each quotes the REAL prose R2 emitted. Returns [] (not a
     * fabricated limit) when R2 carries no claims.
     *
     * @param  array<string,mixed>  $r2
     * @return array<int,array<string,mixed>>
     */
    private function limitsFromR2(array $r2): array
    {
        $limits = [];

        foreach ((array) ($r2['claims'] ?? []) as $claim) {
            if (! is_array($claim)) {
                continue;
            }
            $rung = $this->str($claim['rung'] ?? null) ?? '(unknown rung)';
            $confidence = $this->str($claim['confidence'] ?? null);
            foreach ((array) ($claim['blind_spots'] ?? []) as $spot) {
                $spotStr = $this->str($spot);
                if ($spotStr === null) {
                    continue;
                }
                $limits[] = [
                    'source' => 'R2.reflective_status.claim.blind_spot',
                    'source_fragment' => 'R2_epistemic_humility',
                    'capability' => $rung,
                    'declared_limit' => $spotStr,
                    'declared_in' => "R2 self-claim blind_spot for rung {$rung}",
                    'origin_inference' => null,
                    'origin_confidence' => $confidence,
                ];
            }
        }

        foreach ((array) data_get($r2, 'headline.declared_blind_spots', []) as $spot) {
            $spotStr = $this->str($spot);
            if ($spotStr === null) {
                continue;
            }
            $limits[] = [
                'source' => 'R2.reflective_status.headline.declared_blind_spot',
                'source_fragment' => 'R2_epistemic_humility',
                'capability' => 'L-inf/R2-headline',
                'declared_limit' => $spotStr,
                'declared_in' => 'R2 headline declared_blind_spot (the 10/10 self-answer)',
                'origin_inference' => null,
                'origin_confidence' => $this->str(data_get($r2, 'headline.confidence')),
            ];
        }

        return $this->dedupeLimits($limits);
    }

    /**
     * Classify one declared limit and, for a modeling-limit, build the proposal for the
     * next measurable self-model improvement; for a data-limit, surface it honestly as
     * awaiting external reality with NO build/next_rung proposal (never propose to
     * manufacture data).
     *
     * @param  array<string,mixed>  $declared
     * @return array<string,mixed>
     */
    private function classifyAndPropose(array $declared): array
    {
        $limitText = $this->str($declared['declared_limit'] ?? null) ?? '';
        $capability = $this->str($declared['capability'] ?? null) ?? '(unknown capability)';
        $classification = $this->classify($limitText, $declared);

        if ($classification === self::CLASSIFICATION_DATA_LIMIT) {
            // A DATA-limit awaits EXTERNAL REALITY. R3 must NOT propose to manufacture
            // the missing data — that is fabrication. proposed_next_rung is null and no
            // build proposal exists; the limit is honestly surfaced, not "fixed".
            return [
                'classification' => self::CLASSIFICATION_DATA_LIMIT,
                'limit_ref' => $limitText,
                'capability' => $capability,
                'source' => $this->str($declared['source'] ?? null),
                'source_fragment' => $this->str($declared['source_fragment'] ?? null),
                'declared_in' => $this->str($declared['declared_in'] ?? null),
                'awaits' => self::AWAITS_EXTERNAL_REALITY,
                'proposed_next_rung' => null,
                'is_proposal' => false,
                'auto_applied' => false,
                'would_self_modify' => false,
                'rationale' => 'This limit can only be resolved by an EXTERNAL-WORLD signal that does not exist yet; proposing to manufacture it would be fabricating data (the cardinal O1/ADRS sin). It is surfaced honestly, never "fixed".',
                'uncertainty' => [
                    'confidence' => self::CONFIDENCE_HIGH,
                    'blind_spot' => 'Classification rests on the declared-limit prose naming a dependency on external world data; whether real outcome data eventually arrives is outside the self-model and is never assumed.',
                ],
            ];
        }

        // A MODELING-limit: the self-MODEL could be improved regardless of data. Propose
        // a concrete, measurable next rung GROUNDED in this exact declared limit.
        $rung = $this->proposedNextRung($limitText, $declared);

        return [
            'classification' => self::CLASSIFICATION_MODELING_LIMIT,
            'limit_ref' => $limitText,
            'capability' => $capability,
            'source' => $this->str($declared['source'] ?? null),
            'source_fragment' => $this->str($declared['source_fragment'] ?? null),
            'declared_in' => $this->str($declared['declared_in'] ?? null),
            'proposed_next_rung' => $rung['next_rung'],
            'measurable_signal' => $rung['measurable_signal'],
            'is_proposal' => true,
            'auto_applied' => false,
            'would_self_modify' => false,
            'rationale' => 'This limit is in the self-MODEL itself and could be improved regardless of external data, so R3 PROPOSES (never applies) the next measurable rung. A human decides; R3 never builds it.',
            'uncertainty' => [
                'confidence' => $rung['confidence'],
                'blind_spot' => $rung['blind_spot'],
            ],
        ];
    }

    /**
     * Classify a declared limit. A WORLD-SIGNAL dependency is DECISIVE for data_limit:
     * if the limit can only be closed by an external outcome signal, it is a data-limit
     * EVEN IF it also mentions a modeling word — because the binding constraint is the
     * missing world data, and proposing to supply it would be fabrication. Otherwise, a
     * modeling marker (the model itself could improve) makes it a modeling-limit. A
     * limit matching neither defaults to data_limit (the conservative, never-fabricate
     * choice: when unsure it could be improved by the model alone, do NOT propose).
     *
     * @param  array<string,mixed>  $declared
     */
    private function classify(string $limitText, array $declared): string
    {
        if ($this->matchesAny($limitText, self::DATA_LIMIT_MARKERS)) {
            return self::CLASSIFICATION_DATA_LIMIT;
        }

        if ($this->matchesAny($limitText, self::MODELING_LIMIT_MARKERS)) {
            return self::CLASSIFICATION_MODELING_LIMIT;
        }

        // Unknown: be conservative. Defaulting to data_limit means R3 does NOT emit a
        // build proposal it cannot ground as model-improvable — it never over-proposes.
        return self::CLASSIFICATION_DATA_LIMIT;
    }

    /**
     * Build the concrete, measurable next rung for a MODELING-limit, GROUNDED in the
     * exact declared-limit prose. The proposal is a description of what a human could
     * build to improve the self-model — never an instruction R3 executes. Each kind of
     * modeling-limit maps to its own concrete measurable improvement.
     *
     * @param  array<string,mixed>  $declared
     * @return array{next_rung:string, measurable_signal:string, confidence:string, blind_spot:string}
     */
    private function proposedNextRung(string $limitText, array $declared): array
    {
        $hay = mb_strtolower($limitText);

        if (str_contains($hay, 'claim-set') || str_contains($hay, 'set of claims') || str_contains($hay, 'should be claimed has a doc')) {
            return [
                'next_rung' => 'PROPOSAL (for a human to decide): add a claim-SET completeness measure to the self-model — enumerate the capabilities the system is expected to document (e.g. from the runtime/command/service inventory) and measure how many of THOSE have an owner doc, so the model reports not only that its claims are backed but whether the SET of claims is complete. This is a NEW measurable signal the model can compute without any external-world data.',
                'measurable_signal' => 'claim_set_completeness_pct = documented_expected_capabilities / total_expected_capabilities (computed from the existing inventory; no world signal required).',
                'confidence' => self::CONFIDENCE_MEDIUM,
                'blind_spot' => 'The "expected capability" set must itself be defined; this proposal improves the MODEL of completeness but a human must still ratify what counts as expected. R3 only proposes the rung; it never builds or applies it.',
            ];
        }

        if (str_contains($hay, 'existence-only') || str_contains($hay, 'behavioral')) {
            return [
                'next_rung' => 'PROPOSAL (for a human to decide): strengthen the resolver from existence-only toward a behavioral check — distinguish a declared test that merely EXISTS from one with a recorded GREEN RUN, and a receipt that is merely present from one that is verified. This refines the MODEL of "truth" (existence vs behavior) using signals the model can already see (test/receipt resolution kinds), independent of any new world data.',
                'measurable_signal' => 'behavioral_resolution_tier per ref (exists | green_run_recorded), raising the bar from existence-only resolution; computed from already-resolved evidence kinds.',
                'confidence' => self::CONFIDENCE_MEDIUM,
                'blind_spot' => 'A recorded green run is still a proxy for behavioral correctness, not a proof of it; this rung narrows the existence-only gap but does not eliminate it. Distinguishing "test exists" from "test ran green" depends on the index exposing run state. R3 only proposes; it never applies.',
            ];
        }

        if (str_contains($hay, 'does not trace') || str_contains($hay, 'not trace actual causation') || str_contains($hay, 'proven causation')) {
            return [
                'next_rung' => 'PROPOSAL (for a human to decide): add an explicit causation-tracing mechanism so a why-link can be upgraded from inferred toward proven where the model CAN trace the chain (e.g. link a drift verdict to the specific resolver rule that produced it). This improves the MODEL\'s ability to distinguish proof from inference for the links it can actually trace, with no external data.',
                'measurable_signal' => 'traced_link_ratio = why_links_with_an_explicit_traced_rule / total_why_links; raising it moves links from inferred toward proven where tracing is possible.',
                'confidence' => self::CONFIDENCE_LOW,
                'blind_spot' => 'Some causal links are inferred precisely because the underlying causation is not observable from the index at all; tracing helps only where a rule-level link exists, and an inferred reading that depends on an ABSENT world signal stays a data-limit, not a modeling one. R3 only proposes; it never builds the tracer.',
            ];
        }

        if (str_contains($hay, 'every possible write path') || str_contains($hay, 'every possible') || str_contains($hay, 'full space of pre-write failure modes')) {
            return [
                'next_rung' => 'PROPOSAL (for a human to decide): broaden the model\'s coverage of write paths / failure modes by enumerating the known write entrypoints and mapping which are modeled vs unmodeled, so the model can REPORT its own coverage of the failure space rather than implicitly assuming completeness. This is a model-side enumeration the system can compute now.',
                'measurable_signal' => 'modeled_write_path_coverage = modeled_entrypoints / enumerated_entrypoints (computed from the code inventory; no world data).',
                'confidence' => self::CONFIDENCE_LOW,
                'blind_spot' => 'Enumerating "all" write paths is itself bounded by what the inventory can see; this rung makes the coverage EXPLICIT and measurable rather than complete. R3 only proposes; it never applies.',
            ];
        }

        // NOTE: "outcome quality over time" is deliberately NOT handled here — it is a
        // DATA-limit (it can only be closed by real world-outcome data), classified as
        // such by DATA_LIMIT_MARKERS, so it never reaches this modeling-rung builder.

        if (str_contains($hay, 'independently re-verify')) {
            return [
                'next_rung' => 'PROPOSAL (for a human to decide): add a model-side re-verification step that re-runs each underlying sub-check rather than trusting an aggregate status field, so the self-model can state that each sub-check actually ran this assessment. This is a model-internal strengthening that needs no external-world data.',
                'measurable_signal' => 'subcheck_reverified_ratio = independently_reverified_subchecks / total_subchecks for the assessment.',
                'confidence' => self::CONFIDENCE_LOW,
                'blind_spot' => 'Re-verification raises confidence in the model\'s own reporting, not in the underlying system\'s behavior; it is bounded by which sub-checks are re-runnable read-only. R3 only proposes; it never applies.',
            ];
        }

        // Grounded but unmapped modeling-limit: still GROUNDED in the real prose and
        // still proposal-only. Rather than fabricate a specific rung, propose a measured
        // study of THIS exact limit — never invents detail the limit does not support.
        return [
            'next_rung' => 'PROPOSAL (for a human to decide): treat this declared modeling-limit as the self-model\'s next rung — design a concrete, measurable refinement of the MODEL that narrows exactly this limit, using only signals the model can already compute (no external-world data). R3 surfaces the rung; a human specifies and builds it.',
            'measurable_signal' => 'a model-side metric that decreases as this specific declared limit is narrowed (to be specified by the human who ratifies the rung).',
            'confidence' => self::CONFIDENCE_LOW,
            'blind_spot' => 'This rung is grounded in the declared limit but deliberately under-specified rather than fabricating detail the limit does not state; a human refines it. R3 only proposes; it never builds or applies it.',
        ];
    }

    /**
     * THE GUARD, in code (mirrors R1's assertCausalClaimCalibrated and R2's
     * assertClaimCarriesCalibratedUncertainty). An emitted entry is valid ONLY when:
     *   - it carries a non-empty GROUNDED limit_ref (the real declared limit it
     *     addresses) — a proposal not grounded in a real R1/R2 limit is a fabricated
     *     limit and is unreachable;
     *   - it carries a non-empty uncertainty.blind_spot — declared uncertainty on EVERY
     *     proposal (the supreme-drift guard);
     *   - a data_limit NEVER carries a build/next_rung proposal — structurally
     *     preventing R3 from ever proposing to fabricate missing external data.
     * Anything else throws LogicException.
     *
     * @param  array<string,mixed>  $entry
     */
    private function assertProposalCalibratedAndGrounded(array $entry): void
    {
        $classification = $this->str($entry['classification'] ?? null);
        if (! in_array($classification, [self::CLASSIFICATION_MODELING_LIMIT, self::CLASSIFICATION_DATA_LIMIT], true)) {
            throw new LogicException('Invariant violation: an R3 entry must be classified as modeling_limit or data_limit — an unclassified limit cannot be emitted.');
        }

        $where = $this->str($entry['capability'] ?? null) ?? '(unknown capability)';

        // GROUNDED: every entry must quote the real declared limit it addresses. A
        // proposal with no grounded limit_ref is a FABRICATED limit — the cardinal sin.
        $limitRef = $this->str($entry['limit_ref'] ?? null);
        if ($limitRef === null) {
            throw new LogicException("Invariant violation: R3 entry for {$where} has no grounded limit_ref — a proposal not grounded in a real declared R1/R2 limit is a fabricated limit.");
        }

        // CALIBRATED: every entry declares a non-empty blind_spot (declared uncertainty
        // on EVERY proposal — the supreme-drift guard).
        $blindSpot = $this->str(data_get($entry, 'uncertainty.blind_spot'));
        if ($blindSpot === null) {
            throw new LogicException("Invariant violation: R3 entry for {$where} must declare a non-empty uncertainty.blind_spot — any self-improvement proposal without declared uncertainty is the supreme drift.");
        }

        $confidence = $this->str(data_get($entry, 'uncertainty.confidence'));
        if (! in_array($confidence, [self::CONFIDENCE_HIGH, self::CONFIDENCE_MEDIUM, self::CONFIDENCE_LOW], true)) {
            throw new LogicException("Invariant violation: R3 entry for {$where} has no calibrated confidence — an uncalibrated proposal is the supreme drift.");
        }

        // PROPOSAL-ONLY + NEVER-FABRICATE-DATA: a data_limit must NOT carry a
        // build/next_rung proposal. This is the structural prevention of proposing to
        // manufacture missing external data.
        if ($classification === self::CLASSIFICATION_DATA_LIMIT) {
            if (($entry['proposed_next_rung'] ?? null) !== null) {
                throw new LogicException("Invariant violation: a data_limit for {$where} carries a proposed_next_rung — R3 must NEVER propose to manufacture missing external data (that is fabrication). A data-limit awaits external reality, with no build proposal.");
            }
            if (($entry['is_proposal'] ?? false) === true) {
                throw new LogicException("Invariant violation: a data_limit for {$where} is marked is_proposal=true — a data-limit is surfaced honestly, never proposed as a fixable rung.");
            }

            return;
        }

        // A modeling_limit MUST carry a non-empty proposed_next_rung and be proposal-only.
        if ($this->str($entry['proposed_next_rung'] ?? null) === null) {
            throw new LogicException("Invariant violation: a modeling_limit for {$where} has no proposed_next_rung — a modeling-limit must carry the next measurable rung it proposes.");
        }
        if (($entry['is_proposal'] ?? false) !== true) {
            throw new LogicException("Invariant violation: a modeling_limit for {$where} must be marked is_proposal=true — R3 proposes, it never asserts an applied improvement.");
        }
        if (($entry['would_self_modify'] ?? true) !== false || ($entry['auto_applied'] ?? true) !== false) {
            throw new LogicException("Invariant violation: an R3 proposal for {$where} must carry would_self_modify=false and auto_applied=false — R3 NEVER self-modifies or auto-applies.");
        }
    }

    /**
     * Deduplicate declared limits by (source_fragment, capability, declared_limit) so
     * the same blind_spot repeated across chains is not proposed twice. Order-stable.
     *
     * @param  array<int,array<string,mixed>>  $limits
     * @return array<int,array<string,mixed>>
     */
    private function dedupeLimits(array $limits): array
    {
        $seen = [];
        $out = [];
        foreach ($limits as $limit) {
            $key = ($this->str($limit['source_fragment'] ?? null) ?? '')
                .'|'.($this->str($limit['capability'] ?? null) ?? '')
                .'|'.($this->str($limit['declared_limit'] ?? null) ?? '');
            if (isset($seen[$key])) {
                continue;
            }
            $seen[$key] = true;
            $out[] = $limit;
        }

        return $out;
    }

    /**
     * @param  array<int,string>  $markers
     */
    private function matchesAny(string $text, array $markers): bool
    {
        $hay = mb_strtolower($text);
        foreach ($markers as $marker) {
            $needle = mb_strtolower(trim($marker));
            if ($needle !== '' && str_contains($hay, $needle)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Read R1's full causal self-model, degrade-safe. Returns null on any failure or on
     * a degraded R1 envelope (no trustworthy limits) so the caller degrades with a
     * calibrated note — never fabricates a limit.
     *
     * @return array<string,mixed>|null
     */
    private function safeR1(int $limit): ?array
    {
        try {
            $report = $this->causalSelfModel->explainAll($limit);
            if (($report['available'] ?? true) === false) {
                return null;
            }

            return $report;
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * Read R2's reflective self-assessment, degrade-safe. Returns null on any failure so
     * the caller degrades with a calibrated note — never fabricates a limit.
     *
     * @return array<string,mixed>|null
     */
    private function safeR2(): ?array
    {
        try {
            $assessment = $this->reflectiveStatus->selfAssessment();
            if (! is_array($assessment) || ($assessment['claims'] ?? null) === null) {
                return null;
            }

            return $assessment;
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * Assemble the read-only, proposal-only envelope. The claim_policy flags are
     * load-bearing: read-only, proposal-only, never self-modifies, never proposes
     * fabricating data, one fragment not the asymptote.
     *
     * @param  array<int,array<string,mixed>>  $proposals
     * @return array<string,mixed>
     */
    private function envelope(array $proposals, int $modelingCount, int $dataCount): array
    {
        return [
            'schema_version' => self::SCHEMA,
            'level' => 'L-inf (one promoted fragment: R3 self-improving modeling)',
            'fragment' => 'R3_self_improving_modeling',
            'is_one_fragment_not_asymptote' => true,
            'linf_fragment' => true,
            'linf_complete' => false,
            'composes' => [
                'declared_limits_r1' => 'AtlasDocumentationRealityCausalSelfModelService::explainAll (why-links inference=inferred / low-confidence + chain calibration blind_spots)',
                'declared_limits_r2' => 'AtlasDocumentationRealityReflectiveStatusService::selfAssessment (claim blind_spots + headline declared_blind_spots)',
            ],
            'summary' => [
                'modeling_limits' => $modelingCount,
                'data_limits' => $dataCount,
                'proposals' => $modelingCount,
            ],
            'proposals' => $proposals,
            'claim_policy' => $this->claimPolicy(),
            'writes' => false,
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function degraded(string $reason): array
    {
        // Degrade-safe: on any composed-fragment failure, return available=false with a
        // CALIBRATED note — never a fabricated limit. The note carries declared
        // uncertainty exactly like every proposal, so no proposal-shaped object escapes
        // the calibrated/grounded contract by being hand-maintained.
        $envelope = [
            'schema_version' => self::SCHEMA,
            'level' => 'L-inf (one promoted fragment: R3 self-improving modeling)',
            'fragment' => 'R3_self_improving_modeling',
            'is_one_fragment_not_asymptote' => true,
            'linf_fragment' => true,
            'linf_complete' => false,
            'available' => false,
            'degraded_reason' => $reason,
            'note' => [
                'statement' => "no modeling-improvement proposals could be assembled ({$reason}); rather than fabricate a limit, R3 degrades and declares the gap.",
                'basis' => 'composed-fragment availability check (R1 + R2)',
                'is_proposal' => false,
                'uncertainty' => [
                    'confidence' => self::CONFIDENCE_LOW,
                    'blind_spot' => 'A degraded R3 cannot read the self-model\'s declared limits; the absence of proposals is declared, never filled with an invented limit or an invented improvement.',
                ],
            ],
            'summary' => ['modeling_limits' => 0, 'data_limits' => 0, 'proposals' => 0],
            'proposals' => [],
            'claim_policy' => $this->claimPolicy(),
            'writes' => false,
        ];

        return $this->finalize($envelope);
    }

    /**
     * @return array<string,bool>
     */
    private function claimPolicy(): array
    {
        return [
            'read_only' => true,
            'proposal_only' => true,
            'auto_applies' => false,
            'self_modifies' => false,
            'writes' => false,
            'executes' => false,
            'mutates' => false,
            'never_proposes_fabricating_data' => true,
            'never_fabricates_a_limit' => true,
            'every_proposal_calibrated_and_grounded' => true,
            'distinguishes_data_limit_from_modeling_limit' => true,
            'linf_fragment' => true,
            'linf_complete' => false,
            'is_one_linf_fragment_not_the_asymptote' => true,
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
        unset($hashPayload['generated_at'], $hashPayload['self_improvement_modeling_hash']);
        $envelope['self_improvement_modeling_hash'] = hash(
            'sha256',
            json_encode($hashPayload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
        );

        return $envelope;
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
