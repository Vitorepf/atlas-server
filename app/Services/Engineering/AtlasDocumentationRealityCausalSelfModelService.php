<?php

declare(strict_types=1);

namespace App\Services\Engineering;

use App\Services\Ai\AgenticEngineeringOs\Maturity\AtlasImplementationTruthService;
use App\Services\Engineering\SharedAtlasDocumentationRealitySelfImprovementModelingServiceSeam as RealityEnvelopeHashSeam;
use LogicException;

/**
 * L-inf (ONE promoted fragment: R1 CAUSAL SELF-MODEL) — the queryable causal
 * account of what the Atlas IS, what it INTENDED, what RESULTED, and WHY.
 *
 * R2 (AtlasDocumentationRealityReflectiveStatusService) already promoted the FIRST
 * fragment of the L-inf asymptote: epistemic humility — every self-claim carries
 * calibrated uncertainty. R1 is the NEXT fragment, one at a time
 * (reflective-self-model.md:38/171 — "So fragmentos mensuraveis da reflexao podem
 * virar runtime, um por vez, com prova e incerteza declarada; o todo permanece
 * norte"). R1 answers, per capability, the question the mother doc names at
 * reflective-self-model.md:134/146/156-159:
 *
 *   "o que o Atlas e, por que, o que e verdade, o que foi intencao e o que resultou"
 *
 * COMPOSITION (reflective-self-model.md:157-159 — R1 responds from the causal
 * self-model; R2 ATTACHES calibrated confidence + known blind-spots). This service
 * NEVER re-derives truth; it INJECTS and CALLS the real ladder signals, read-only,
 * and assembles them into a CAUSAL CHAIN per capability:
 *   - intent : what the doc CLAIMS — claimed_state from the capability truth ledger
 *     row (AtlasImplementationTruthService::ledger) — the declared INTENTION.
 *   - truth  : what the code RESOLVES — computed_state + which evidence_refs
 *     resolved (the same ledger row) — the machine TRUTH.
 *   - result : what OUTCOME resulted — the L2-O1 grade for this capability
 *     (AtlasDocumentationRealityOutcomeGroundingService) — honestly no-signal/0 today.
 *   - why    : the CAUSAL account of WHY the state is what it is, derived from the
 *     REAL gap signals — the ledger's drift/under_claim, the per-ref resolution, the
 *     bidirectional reconciliation, and the code-contract proposer (which ref is
 *     unresolved, in which direction). Each why-link carries {basis, inference, uncertainty}.
 *   - calibration : R2 is REUSED (not duplicated) to attach calibrated confidence +
 *     blind_spots to the whole chain.
 *
 * THE SUPREME DRIFT this fragment must make STRUCTURALLY IMPOSSIBLE
 * (reflective-self-model.md:169 — "Auto-conhecimento sem incerteza calibrada e o
 * DRIFT SUPREMO"): a causal claim WITHOUT calibrated, declared uncertainty. Mirroring
 * R2's assertClaimCarriesCalibratedUncertainty, this service's assertCausalClaimCalibrated()
 * throws a LogicException if ANY emitted why-link lacks a non-empty basis OR a
 * non-empty uncertainty.blind_spot OR a calibrated confidence — an uncalibrated causal
 * claim is unreachable by construction, never a matter of discipline.
 *
 * NEVER FABRICATE A CAUSE: every why-link is GROUNDED in a real composed signal (the
 * ledger row, the O1 grade, the reconciliation, the contract proposer) named in its
 * `basis`, and it DISTINGUISHES inference from proof via `inference`:
 *   - "proven"   : the link is a direct read of a real signal (e.g. "drift=false
 *                  BECAUSE every declared ref resolves" — the resolver said so).
 *   - "inferred" : the link is a plausible reading the signal does NOT itself prove
 *                  as causation (e.g. "intent=verified but result=no_outcome — likely
 *                  built-but-not-yet-validated-in-world"); an inferred correlation is
 *                  LABELLED inferred, NEVER asserted as proven causation.
 *
 * CRITICAL SAFETY: strictly READ-ONLY. It composes existing read-only reports; it
 * writes NOTHING, executes nothing, mutates nothing. R3 (self-improving modeling —
 * register modeling limits) is NOT built; it is a LATER fragment of the same
 * asymptote and explicitly out of scope. linf_complete stays HARD false; the mother
 * reflective-self-model doc is UNCHANGED and remains north_star.
 *
 * @see docs/engineering-knowledge-base/atlas-documentation-reality-causal-self-model-fragment.md
 * @see docs/engineering-knowledge-base/atlas-documentation-reality-reflective-self-model.md
 */
class AtlasDocumentationRealityCausalSelfModelService
{
    public const SCHEMA = 'atlas.documentation_reality.causal_self_model.v1';

    /** Calibrated confidence levels. As in R2 there is no fourth — "certain" is absent. */
    public const CONFIDENCE_HIGH = 'high';

    public const CONFIDENCE_MEDIUM = 'medium';

    public const CONFIDENCE_LOW = 'low';

    /** A why-link is either a direct read of a signal (proven) or a labelled reading (inferred). */
    public const INFERENCE_PROVEN = 'proven';

    public const INFERENCE_INFERRED = 'inferred';

    public function __construct(
        private readonly AtlasImplementationTruthService $truth,
        private readonly AtlasDocumentationRealityOutcomeGroundingService $outcomeGrounding,
        private readonly AtlasDocumentationRealityBidirectionalReconciliationService $reconciliation,
        private readonly AtlasDocumentationRealityReflectiveStatusService $reflectiveStatus,
    ) {}

    /**
     * Explain ONE capability/claim as a causal chain (intent -> truth -> result ->
     * why) with calibrated uncertainty on every why-link. The capability is named by
     * id/slug or owner-doc path substring — the same filter the ledger uses — so the
     * causal model and the truth ledger always agree on which capability is in scope.
     *
     * @return array<string,mixed>
     */
    public function explainCapability(string $capability): array
    {
        $capability = trim($capability);
        if ($capability === '') {
            return $this->degraded('empty_capability_filter', null);
        }

        // Compose the real signals, degrade-safe. A failure to read any collaborator
        // is NEVER a reason to fabricate a cause — it degrades to {available:false}
        // with a calibrated note, never an invented causal link.
        $ledger = $this->safeLedger($capability);
        if ($ledger === null) {
            return $this->degraded('capability_truth_ledger_unavailable', $capability);
        }

        $rows = is_array($ledger['capabilities'] ?? null) ? $ledger['capabilities'] : [];
        if ($rows === []) {
            return $this->degraded('capability_not_found_in_ledger', $capability);
        }

        $outcome = $this->safeOutcomeGrades($capability);
        $reconcile = $this->safeReconciliation($capability);

        $chains = [];
        foreach ($rows as $row) {
            if (! is_array($row)) {
                continue;
            }
            $chains[] = $this->causalChain($row, $outcome, $reconcile);
        }

        return $this->finalize($this->envelope($capability, $chains));
    }

    /**
     * Explain every capability that declares evidence_refs (the ledger population),
     * bounded by $limit. Same composition as explainCapability, fanned across the
     * whole capability truth ledger.
     *
     * @return array<string,mixed>
     */
    public function explainAll(int $limit = 25): array
    {
        $limit = max(1, $limit);

        $ledger = $this->safeLedger(null);
        if ($ledger === null) {
            return $this->degraded('capability_truth_ledger_unavailable', null);
        }

        $rows = is_array($ledger['capabilities'] ?? null) ? $ledger['capabilities'] : [];
        $rows = array_slice(array_values(array_filter($rows, 'is_array')), 0, $limit);

        $outcome = $this->safeOutcomeGrades(null);
        $reconcile = $this->safeReconciliation(null);

        $chains = [];
        foreach ($rows as $row) {
            $chains[] = $this->causalChain($row, $outcome, $reconcile);
        }

        return $this->finalize($this->envelope(null, $chains));
    }

    /**
     * Assemble the causal chain for ONE ledger row: intent (claimed) -> truth
     * (computed + resolved refs) -> result (O1 grade) -> why (the causal account from
     * the real gap signals), then attach R2 calibration to the whole chain. Every
     * why-link is validated on the spot so an uncalibrated causal claim can NEVER
     * leave this method.
     *
     * @param  array<string,mixed>  $row
     * @param  array<string,mixed>  $outcome  the O1 grade index keyed by capability_id|owner_doc
     * @param  array<string,mixed>  $reconcile  the bidirectional reconciliation lookup
     * @return array<string,mixed>
     */
    private function causalChain(array $row, array $outcome, array $reconcile): array
    {
        $capabilityId = $this->str($row['capability_id'] ?? null) ?? '';
        $ownerDoc = $this->str($row['owner_doc'] ?? null) ?? '';
        $claimed = $this->str($row['claimed_state'] ?? null) ?? 'spec';
        $computed = $this->str($row['computed_state'] ?? null) ?? 'spec';
        $drift = (bool) ($row['drift'] ?? false);
        $underClaim = (bool) ($row['under_claim'] ?? false);
        $resolved = is_array($row['resolved'] ?? null) ? $row['resolved'] : [];
        $unmet = array_values(array_filter(
            (array) ($row['unmet_evidence'] ?? []),
            static fn (mixed $u): bool => is_string($u) && trim($u) !== '',
        ));
        $resolvedRefs = $this->resolvedRefs($row);
        $unresolvedRefs = $this->unresolvedRefs($row);

        // result: the O1 grade for THIS capability (honest no-signal/0 today).
        $grade = $this->gradeFor($capabilityId, $ownerDoc, $outcome);

        $intent = [
            'claimed_state' => $claimed,
            'as_declared' => "the doc DECLARES implementation_state '{$claimed}' — this is the stated INTENTION of the capability",
            'source' => 'capability_truth_ledger.claimed_state',
        ];

        $truth = [
            'computed_state' => $computed,
            'as_resolved' => "the code-intelligence index RESOLVES implementation_state '{$computed}' — this is the machine TRUTH",
            'resolved_kinds' => [
                'symbol' => (bool) ($resolved['symbol'] ?? false),
                'wiring' => (bool) ($resolved['wiring'] ?? false),
                'test' => (bool) ($resolved['test'] ?? false),
                'receipt' => (bool) ($resolved['receipt'] ?? false),
            ],
            'resolved_refs' => $resolvedRefs,
            'unmet_evidence' => $unmet,
            'source' => 'capability_truth_ledger.computed_state',
        ];

        $result = [
            'grade' => $grade['grade'],
            'outcome_grounded' => $grade['outcome_grounded'],
            'as_resulted' => $grade['as_resulted'],
            'outcome_signal_source_available' => $grade['source_available'],
            'source' => $grade['source'],
        ];

        // why: the causal account, every link grounded in a real signal + carrying
        // calibrated uncertainty + distinguishing proof from inference.
        $why = $this->whyLinks(
            claimed: $claimed,
            computed: $computed,
            drift: $drift,
            underClaim: $underClaim,
            unmet: $unmet,
            resolvedRefs: $resolvedRefs,
            unresolvedRefs: $unresolvedRefs,
            grade: $grade,
            reconcileLink: $this->reconcileLinkFor($capabilityId, $ownerDoc, $reconcile),
        );

        // INVARIANT (enforced in code): every why-link must carry a basis AND a
        // calibrated uncertainty.blind_spot. Validate the WHOLE chain before it can be
        // emitted — an uncalibrated causal claim is the supreme drift.
        foreach ($why as $link) {
            $this->assertCausalClaimCalibrated($link, $capabilityId !== '' ? $capabilityId : $ownerDoc);
        }

        // calibration: REUSE R2 to attach calibrated confidence + blind_spots to the
        // chain — never duplicate its assessment, compose it.
        $calibration = $this->calibrationFor($claimed, $computed, $drift, $grade, $why);

        return [
            'capability_id' => $capabilityId,
            'owner_doc' => $ownerDoc,
            'intent' => $intent,
            'truth' => $truth,
            'result' => $result,
            'why' => $why,
            'calibration' => $calibration,
            'linf_fragment' => true,
            'linf_complete' => false,
            'fragment' => 'R1_causal_self_model',
        ];
    }

    /**
     * The causal WHY: one or more grounded links explaining why intent/truth/result
     * are what they are. NEVER fabricates: each link names the REAL signal in `basis`
     * and labels `inference` as proven (a direct read of a signal) or inferred (a
     * labelled reading the signal does not itself prove as causation). Each link
     * carries calibrated uncertainty {confidence, blind_spot}.
     *
     * @param  array<int,array{kind:string,ref:string}>  $resolvedRefs
     * @param  array<int,array{kind:string,ref:string}>  $unresolvedRefs
     * @param  array<int,string>  $unmet
     * @param  array<string,mixed>  $grade
     * @param  array<string,mixed>|null  $reconcileLink
     * @return array<int,array<string,mixed>>
     */
    private function whyLinks(
        string $claimed,
        string $computed,
        bool $drift,
        bool $underClaim,
        array $unmet,
        array $resolvedRefs,
        array $unresolvedRefs,
        array $grade,
        ?array $reconcileLink,
    ): array {
        $links = [];

        // (1) The doc<->code state link — drift / under_claim / agreement. This is a
        // DIRECT read of the resolver's verdict, so it is PROVEN, not inferred.
        if ($drift) {
            $unmetClause = $unmet !== [] ? ' (unmet: '.implode('; ', $unmet).')' : '';
            $unresolvedClause = $unresolvedRefs !== []
                ? ' Specifically, declared ref(s) '.$this->refList($unresolvedRefs).' do not resolve in the index.'
                : '';
            $links[] = $this->link(
                statement: "computed='{$computed}' is BELOW claimed='{$claimed}' (OVER-claim drift) BECAUSE the evidence_refs needed for '{$claimed}' do not all resolve in the code-intelligence index{$unmetClause}.{$unresolvedClause}",
                basis: 'capability_truth_ledger.drift + per-ref resolution (resolver verdict)',
                inference: self::INFERENCE_PROVEN,
                confidence: self::CONFIDENCE_HIGH,
                blindSpot: 'The resolver is existence-only: it proves a declared ref resolves to a symbol/file, not that the capability behaves correctly. "Drift=true" is a proven doc<->index gap, not a proven behavioral defect.',
            );
        } elseif ($underClaim) {
            $links[] = $this->link(
                statement: "computed='{$computed}' is ABOVE claimed='{$claimed}' (UNDER-claim) BECAUSE the code resolves MORE than the doc declares — the doc under-documents reality.",
                basis: 'capability_truth_ledger.under_claim + per-ref resolution (resolver verdict)',
                inference: self::INFERENCE_PROVEN,
                confidence: self::CONFIDENCE_MEDIUM,
                blindSpot: 'Under-claim rests on existence-only resolution; a verified tier can be reached off a test that merely EXISTS (not a green run) or a receipt that is merely present. Whether the doc SHOULD upgrade still needs human confirmation — see the reconciliation signal.',
            );
        } else {
            $agreeClause = $resolvedRefs !== []
                ? 'all declared evidence_refs resolve ('.$this->refList($resolvedRefs).')'
                : "the doc claims '{$claimed}' and the index agrees at that tier";
            $links[] = $this->link(
                statement: "doc and code AGREE (drift=false): computed='{$computed}' equals claimed='{$claimed}' BECAUSE {$agreeClause}.",
                basis: 'capability_truth_ledger.drift=false + per-ref resolution (resolver verdict)',
                inference: self::INFERENCE_PROVEN,
                confidence: self::CONFIDENCE_HIGH,
                blindSpot: 'Agreement means the declared claim-set is backed by resolvable refs; it does NOT prove the claim-SET is complete, nor that the resolved code is behaviorally correct (resolution is existence-only).',
            );
        }

        // (2) The reconciliation link — WHICH ref is unresolved, in WHICH direction.
        // Composed from the real bidirectional reconciliation packet when present.
        if ($reconcileLink !== null) {
            $links[] = $reconcileLink;
        }

        // (3) The truth<->result (world) link. This is the intent-vs-result gap. When a
        // capability is implemented but has NO outcome signal, that is NOT a failure —
        // it is honestly "built but not yet validated in the world". This is an
        // INFERRED reading (the absence of a signal does not PROVE the cause), labelled
        // inferred, never asserted as proven causation.
        $links[] = $this->resultLink($computed, $drift, $grade);

        return $links;
    }

    /**
     * The truth<->result (world) causal link. Distinguishes the three honest cases and
     * NEVER reads a missing outcome as a failure:
     *   - not implemented in code yet -> there is nothing to validate in the world.
     *   - implemented + a REAL positive outcome -> proven world result.
     *   - implemented + NO outcome signal -> built-but-not-yet-validated-in-world,
     *     INFERRED (absence of signal is not proof of the cause), labelled inferred.
     *
     * @param  array<string,mixed>  $grade
     * @return array<string,mixed>
     */
    private function resultLink(string $computed, bool $drift, array $grade): array
    {
        $implemented = in_array($computed, ['partial', 'verified'], true) && ! $drift;
        $gradeName = (string) ($grade['grade'] ?? '');
        $sourceAvailable = (bool) ($grade['source_available'] ?? false);

        if (! $implemented) {
            return $this->link(
                statement: "result=no_world_outcome BECAUSE the capability is not implemented in code yet (computed='{$computed}'); you cannot ask 'did it work in the world?' of something that does not exist in code — this is a missing PREREQUISITE, not a failure.",
                basis: 'capability_truth_ledger.computed_state + L2-O1 gate (not_implemented)',
                inference: self::INFERENCE_PROVEN,
                confidence: self::CONFIDENCE_HIGH,
                blindSpot: 'This says nothing about whether the capability WOULD work once built; it only states there is no code to validate yet.',
            );
        }

        if ($gradeName === AtlasDocumentationRealityOutcomeGroundingService::GRADE_OUTCOME_GROUNDED) {
            return $this->link(
                statement: 'intent=implemented AND result=outcome_grounded BECAUSE a REAL, resolved outcome signal links to this capability — the world used/accepted it.',
                basis: 'L2-O1 grade (real_positive_outcome_signal_linked, ai_outcome_links)',
                inference: self::INFERENCE_PROVEN,
                confidence: self::CONFIDENCE_MEDIUM,
                blindSpot: 'A linked positive outcome proves it worked AT LEAST ONCE in the world; it does not measure outcome QUALITY over time, nor that every use succeeds. O1 is a first increment.',
            );
        }

        if ($gradeName === AtlasDocumentationRealityOutcomeGroundingService::GRADE_NEGATIVE_OUTCOME) {
            return $this->link(
                statement: 'intent=implemented BUT result=negative_outcome BECAUSE a REAL outcome signal shows the world PUSHED BACK (rejected/failed) — implemented is not the same as accepted.',
                basis: 'L2-O1 grade (real_outcome_signal_but_world_pushed_back, ai_outcome_links)',
                inference: self::INFERENCE_PROVEN,
                confidence: self::CONFIDENCE_MEDIUM,
                blindSpot: 'A negative signal proves at least one rejection; it does not prove the capability is universally bad, nor isolate the cause of rejection.',
            );
        }

        // The honest live default: implemented, drift-zero, but NO outcome signal.
        $reason = $sourceAvailable
            ? 'no real outcome signal is currently linked to it'
            : 'the outcome-signal source was unavailable this run, so no world outcome could be observed';

        return $this->link(
            statement: "intent=implemented (computed='{$computed}') BUT result=no_outcome_signal BECAUSE {$reason} — i.e. it is built but NOT YET validated in the world. This is NOT a failure: internal truth (drift-checked) is not yet external truth.",
            basis: 'L2-O1 grade (implemented_no_outcome_signal / source_unavailable, ai_outcome_links)',
            // INFERRED: the ABSENCE of a signal does not PROVE "built-but-not-validated"
            // as causation — it is the honest reading, labelled inferred, never asserted
            // as proven causation. (reflective-self-model.md:169 supreme-drift guard.)
            inference: self::INFERENCE_INFERRED,
            confidence: self::CONFIDENCE_LOW,
            blindSpot: 'Absence of an outcome signal is NOT proof the capability was never used — it may have been used without a logged ai_outcome_links row. This link is an inferred reading of a no-signal state, never proven world-causation.',
        );
    }

    /**
     * The reconciliation why-link for this capability, composed from the REAL
     * bidirectional reconciliation packet (which ref is unresolved, in which
     * direction). Returns null when the reconciliation is degraded/absent or has
     * nothing for this capability — never fabricates a direction.
     *
     * @param  array<string,mixed>  $reconcile
     * @return array<string,mixed>|null
     */
    private function reconcileLinkFor(string $capabilityId, string $ownerDoc, array $reconcile): ?array
    {
        if (($reconcile['available'] ?? false) !== true) {
            return null;
        }

        $under = $this->matchReconcileEntry($reconcile['under_claim'] ?? [], $capabilityId, $ownerDoc);
        if ($under !== null) {
            $to = $this->str($under['computed_state'] ?? null) ?? 'a higher tier';
            $needsConfirm = (bool) ($under['requires_human_confirmation'] ?? false);
            $confirmClause = $needsConfirm
                ? ' on existence-only proof — a human must confirm before upgrading'
                : ' on confirmed-resolved proof';

            return $this->link(
                statement: "the bidirectional reconciliation proposes a DOC-SIDE upgrade to '{$to}' BECAUSE the code resolves more than the doc claims{$confirmClause}.",
                basis: 'AtlasDocumentationRealityBidirectionalReconciliationService.under_claim_upgrades',
                inference: $needsConfirm ? self::INFERENCE_INFERRED : self::INFERENCE_PROVEN,
                confidence: $needsConfirm ? self::CONFIDENCE_LOW : self::CONFIDENCE_MEDIUM,
                blindSpot: $needsConfirm
                    ? 'The upgrade rests on existence-only resolution (a test that exists, not a green run; a receipt merely present); whether the doc SHOULD upgrade is an inferred recommendation pending human confirmation, never proven.'
                    : 'The reconciliation proposes a doc-side change only; it never proves the capability is behaviorally complete, only that the declared refs resolve at the higher tier.',
            );
        }

        $over = $this->matchReconcileEntry($reconcile['over_claim'] ?? [], $capabilityId, $ownerDoc);
        if ($over !== null) {
            return $this->link(
                statement: 'the bidirectional reconciliation proposes a DOC-SIDE downgrade / evidence-supply BECAUSE the doc claims a higher tier than the index can resolve (over-claim).',
                basis: 'AtlasDocumentationRealityBidirectionalReconciliationService.over_claim_repairs',
                inference: self::INFERENCE_PROVEN,
                confidence: self::CONFIDENCE_MEDIUM,
                blindSpot: 'The over-claim verdict is a proven doc<->index gap; it does not identify WHY the code is missing (intent never shipped, ref typo, or renamed symbol) — that distinction is not in the signal.',
            );
        }

        return null;
    }

    /**
     * Find the reconciliation entry (over- or under-claim) for this capability by
     * capability_id or owner_doc. Returns null when none matches — never invents one.
     *
     * @param  mixed  $entries
     * @return array<string,mixed>|null
     */
    private function matchReconcileEntry($entries, string $capabilityId, string $ownerDoc): ?array
    {
        if (! is_array($entries)) {
            return null;
        }
        foreach ($entries as $entry) {
            if (! is_array($entry)) {
                continue;
            }
            $entryCap = $this->str($entry['capability_id'] ?? null);
            $entryDoc = $this->str($entry['owner_doc'] ?? null);
            if (($capabilityId !== '' && $entryCap === $capabilityId)
                || ($ownerDoc !== '' && $entryDoc === $ownerDoc)) {
                return $entry;
            }
        }

        return null;
    }

    /**
     * Attach R2 calibration to the chain: reuse the reflective status service's
     * self-assessment headline confidence as the baseline, then state the chain-level
     * calibrated confidence + the blind_spots that always apply to a CAUSAL claim. The
     * R2 service is composed (called), never duplicated. Degrade-safe: if R2 cannot be
     * read, calibration still carries a blind_spot and lowers confidence, never asserts.
     *
     * @param  array<string,mixed>  $grade
     * @param  array<int,array<string,mixed>>  $why
     * @return array<string,mixed>
     */
    private function calibrationFor(string $claimed, string $computed, bool $drift, array $grade, array $why): array
    {
        $r2 = $this->safeReflectiveHeadlineConfidence();

        // The chain confidence is the MINIMUM honesty across its why-links: a chain is
        // only as confident as its least-confident causal link.
        $chainConfidence = $this->minConfidence(array_map(
            static fn (array $l): string => (string) ($l['confidence'] ?? self::CONFIDENCE_LOW),
            $why,
        ));

        $blindSpots = [
            'This causal model explains the STATE (intent/truth/result) from real composed signals; it is ONE measurable fragment (R1) of L-inf, NOT the asymptote, which remains a permanent compass.',
            'Resolution is existence-only: "truth" proves declared refs resolve to symbols/files, not that the capability is behaviorally correct.',
            $grade['outcome_grounded'] === true
                ? 'A linked outcome proves world-use at least once, not outcome quality over time.'
                : 'result carries no world-outcome signal yet: internal truth (drift-checked) is not external truth — built is not validated.',
            'Every causal link distinguishes proof from inference; an inferred link is a labelled reading of a signal, never asserted as proven causation.',
        ];

        if ($r2['available'] !== true) {
            $blindSpots[] = 'The R2 epistemic-humility calibration source could not be read this run, so this calibration is degraded (confidence lowered, not asserted).';
        }

        return [
            'confidence' => $r2['available'] === true
                ? $this->minConfidence([$chainConfidence, $r2['confidence']])
                : self::CONFIDENCE_LOW,
            'attached_by' => 'AtlasDocumentationRealityReflectiveStatusService (R2 epistemic humility, composed)',
            'r2_headline_confidence' => $r2['available'] === true ? $r2['confidence'] : null,
            'r2_available' => $r2['available'] === true,
            'blind_spots' => array_values($blindSpots),
            'linf_fragment' => true,
            'linf_complete' => false,
            'fragment' => 'R1_causal_self_model',
        ];
    }

    /**
     * Build ONE why-link and validate it on the spot. statement, basis, inference,
     * confidence and a non-empty blind_spot are ALL required — the build path itself
     * cannot produce an uncalibrated or ungrounded causal claim.
     *
     * @return array<string,mixed>
     */
    private function link(string $statement, string $basis, string $inference, string $confidence, string $blindSpot): array
    {
        $built = [
            'statement' => $statement,
            'basis' => $basis,
            'inference' => $inference,
            'uncertainty' => [
                'confidence' => $confidence,
                'blind_spot' => $blindSpot,
            ],
        ];

        // Validate immediately so an uncalibrated/ungrounded link can NEVER leave here.
        $this->assertCausalClaimCalibrated($built, '(building)');

        return $built;
    }

    /**
     * THE SUPREME-DRIFT GUARD, in code (mirrors R2's
     * assertClaimCarriesCalibratedUncertainty). A causal/why claim is valid ONLY when
     * it carries: a real `basis` (the signal that grounds it), an `inference` label
     * (proven|inferred — distinguishing proof from inference), a calibrated confidence,
     * AND a non-empty uncertainty.blind_spot. Anything else is a causal claim without
     * calibrated uncertainty — the supreme drift (reflective-self-model.md:169) — made
     * structurally impossible by this LogicException.
     *
     * @param  array<string,mixed>  $link
     */
    private function assertCausalClaimCalibrated(array $link, string $capability): void
    {
        $where = $capability !== '' ? $capability : '(unknown capability)';

        // basis: the real signal that grounds the cause. NEVER fabricate a cause.
        $basis = $this->str($link['basis'] ?? null);
        if ($basis === null) {
            throw new LogicException("Invariant violation: causal claim for {$where} has no grounding basis — a cause without a real composed signal is fabrication.");
        }

        // inference: proof vs inference must be explicit.
        $inference = $this->str($link['inference'] ?? null);
        if (! in_array($inference, [self::INFERENCE_PROVEN, self::INFERENCE_INFERRED], true)) {
            throw new LogicException("Invariant violation: causal claim for {$where} must label inference as 'proven' or 'inferred' — an unlabelled correlation asserted as causation is drift.");
        }

        // calibrated confidence.
        $uncertainty = is_array($link['uncertainty'] ?? null) ? $link['uncertainty'] : [];
        $confidence = $this->str($uncertainty['confidence'] ?? null);
        if (! in_array($confidence, [self::CONFIDENCE_HIGH, self::CONFIDENCE_MEDIUM, self::CONFIDENCE_LOW], true)) {
            throw new LogicException("Invariant violation: causal claim for {$where} has no calibrated confidence — a causal claim without calibrated uncertainty is the supreme drift.");
        }

        // a non-empty declared blind_spot — EVERY causal claim names a known limit.
        $blindSpot = $this->str($uncertainty['blind_spot'] ?? null);
        if ($blindSpot === null) {
            throw new LogicException("Invariant violation: causal claim for {$where} must declare a non-empty uncertainty.blind_spot — ANY causal claim about itself without a declared limit is the supreme drift.");
        }
    }

    /**
     * The O1 grade for this capability, projected to the causal "result" shape. The
     * grade is READ from the composed O1 index (never recomputed); a capability with no
     * grade is honestly reported as no-signal, never fabricated as grounded.
     *
     * @param  array<string,mixed>  $outcome
     * @return array<string,mixed>
     */
    private function gradeFor(string $capabilityId, string $ownerDoc, array $outcome): array
    {
        $sourceAvailable = (bool) ($outcome['source_available'] ?? false);
        $grades = is_array($outcome['grades'] ?? null) ? $outcome['grades'] : [];

        $match = null;
        foreach ($grades as $g) {
            if (! is_array($g)) {
                continue;
            }
            $gid = $this->str($g['capability_id'] ?? null);
            $gdoc = $this->str($g['owner_doc'] ?? null);
            if (($capabilityId !== '' && $gid === $capabilityId)
                || ($ownerDoc !== '' && $gdoc === $ownerDoc)) {
                $match = $g;
                break;
            }
        }

        if ($match === null) {
            // Source UNAVAILABLE is an outcome-undeterminable state, NOT a
            // not-implemented state: implementation is read from `truth` (computed_state),
            // never inferred from O1's availability. Conflating the two would mislabel a
            // genuinely-implemented capability as unimplemented (an intent/truth/result
            // confusion this fragment exists to prevent).
            return [
                'grade' => $sourceAvailable
                    ? AtlasDocumentationRealityOutcomeGroundingService::GRADE_NO_SIGNAL
                    : 'outcome_source_unavailable',
                'outcome_grounded' => false,
                'as_resulted' => $sourceAvailable
                    ? 'no O1 grade matched this capability — honestly reported as no world-outcome signal, never fabricated'
                    : 'the L2-O1 outcome source was unavailable, so no world-outcome could be read — this is NOT a claim that the capability is unimplemented (implementation is read separately, from truth)',
                'source_available' => $sourceAvailable,
                'source' => 'L2-O1 outcome grounding (no match)',
            ];
        }

        $gradeName = $this->str($match['grade'] ?? null) ?? AtlasDocumentationRealityOutcomeGroundingService::GRADE_NO_SIGNAL;
        $grounded = (bool) ($match['outcome_grounded'] ?? false);

        return [
            'grade' => $gradeName,
            'outcome_grounded' => $grounded,
            'as_resulted' => $grounded
                ? 'a REAL outcome signal links to this capability — it resulted in a world outcome'
                : 'no real world-outcome signal is linked yet — built, but not validated in the world (honest, never fabricated)',
            'source_available' => $sourceAvailable,
            'source' => 'L2-O1 outcome grounding grade',
        ];
    }

    /**
     * The declared refs that DID resolve (the proof backing the computed tier).
     *
     * @param  array<string,mixed>  $row
     * @return array<int,array{kind:string, ref:string}>
     */
    private function resolvedRefs(array $row): array
    {
        return $this->refsWhere($row, true);
    }

    /**
     * The declared refs that did NOT resolve (named-but-missing code = the WHY of a gap).
     *
     * @param  array<string,mixed>  $row
     * @return array<int,array{kind:string, ref:string}>
     */
    private function unresolvedRefs(array $row): array
    {
        return $this->refsWhere($row, false);
    }

    /**
     * Project the ledger row's per-ref evidence list to {kind, ref}, filtered by
     * whether the ref resolved. Never invents a ref: this is exactly what the doc
     * declared and the resolver verdicted.
     *
     * @param  array<string,mixed>  $row
     * @return array<int,array{kind:string, ref:string}>
     */
    private function refsWhere(array $row, bool $resolved): array
    {
        $refs = [];
        foreach ((array) ($row['evidence'] ?? []) as $entry) {
            if (! is_array($entry)) {
                continue;
            }
            if ((($entry['resolved'] ?? false) === true) !== $resolved) {
                continue;
            }
            $kind = strtolower(trim((string) ($entry['kind'] ?? '')));
            $ref = trim((string) ($entry['ref'] ?? ''));
            if ($kind !== '' && $ref !== '') {
                $refs[] = ['kind' => $kind, 'ref' => $ref];
            }
        }

        return $refs;
    }

    /**
     * @param  array<int,array{kind:string, ref:string}>  $refs
     */
    private function refList(array $refs): string
    {
        return implode(', ', array_map(static fn (array $r): string => "{$r['kind']}:{$r['ref']}", $refs));
    }

    /**
     * Read the capability truth ledger, degrade-safe. Returns null on any failure (the
     * caller degrades with a calibrated note, never a fabricated cause).
     *
     * @return array<string,mixed>|null
     */
    private function safeLedger(?string $capability): ?array
    {
        try {
            return $this->truth->ledger($capability);
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * Read the L2-O1 grades, degrade-safe. Returns an index of grades + the source
     * availability flag; on failure, available=false with no grades — honest.
     *
     * @return array<string,mixed>
     */
    private function safeOutcomeGrades(?string $capability): array
    {
        try {
            $graded = $capability !== null
                ? $this->outcomeGrounding->gradeForDoc($capability)
                : $this->outcomeGrounding->gradeAll();

            return [
                'available' => true,
                'source_available' => (bool) data_get($graded, 'summary.outcome_signal_source_available', false),
                'grades' => is_array($graded['grades'] ?? null) ? $graded['grades'] : [],
            ];
        } catch (\Throwable) {
            return ['available' => false, 'source_available' => false, 'grades' => []];
        }
    }

    /**
     * Read the bidirectional reconciliation packet, degrade-safe. Returns the
     * over/under-claim lists + availability; a degraded delegate (blind index) is
     * treated as unavailable so no reconciliation cause is fabricated.
     *
     * @return array<string,mixed>
     */
    private function safeReconciliation(?string $capability): array
    {
        try {
            $packet = $capability !== null
                ? $this->reconciliation->reconcileForDoc($capability)
                : $this->reconciliation->reconcileAll();

            // A degraded reconciliation (blind/empty index) carries no trustworthy
            // direction; treat it as unavailable rather than read fabricated causes.
            if (($packet['degraded'] ?? false) === true) {
                return ['available' => false, 'under_claim' => [], 'over_claim' => []];
            }

            return [
                'available' => true,
                'under_claim' => is_array($packet['under_claim_upgrades'] ?? null) ? $packet['under_claim_upgrades'] : [],
                'over_claim' => is_array($packet['over_claim_repairs'] ?? null) ? $packet['over_claim_repairs'] : [],
            ];
        } catch (\Throwable) {
            return ['available' => false, 'under_claim' => [], 'over_claim' => []];
        }
    }

    /**
     * Read R2's reflective-status headline confidence, degrade-safe. Used only to
     * calibrate (lower) the chain confidence — never to assert it.
     *
     * @return array{available:bool, confidence:string}
     */
    private function safeReflectiveHeadlineConfidence(): array
    {
        try {
            $assessment = $this->reflectiveStatus->selfAssessment();
            $confidence = $this->str(data_get($assessment, 'headline.confidence'));
            if (in_array($confidence, [self::CONFIDENCE_HIGH, self::CONFIDENCE_MEDIUM, self::CONFIDENCE_LOW], true)) {
                return ['available' => true, 'confidence' => (string) $confidence];
            }

            return ['available' => false, 'confidence' => self::CONFIDENCE_LOW];
        } catch (\Throwable) {
            return ['available' => false, 'confidence' => self::CONFIDENCE_LOW];
        }
    }

    /**
     * The least-confident of a set of calibrated confidences (low < medium < high). A
     * chain is only as confident as its weakest causal link.
     *
     * @param  array<int,string>  $confidences
     */
    private function minConfidence(array $confidences): string
    {
        $rank = [self::CONFIDENCE_LOW => 0, self::CONFIDENCE_MEDIUM => 1, self::CONFIDENCE_HIGH => 2];
        $min = self::CONFIDENCE_HIGH;
        foreach ($confidences as $c) {
            if (! array_key_exists($c, $rank)) {
                return self::CONFIDENCE_LOW;
            }
            if ($rank[$c] < $rank[$min]) {
                $min = $c;
            }
        }

        return $min;
    }

    /**
     * Assemble the read-only envelope. The claim_policy flags are load-bearing: this
     * is ONE fragment, the asymptote is never claimed, every causal claim is
     * calibrated, and inference is distinguished from proof.
     *
     * @param  array<int,array<string,mixed>>  $chains
     * @return array<string,mixed>
     */
    private function envelope(?string $capability, array $chains): array
    {
        return [
            'schema_version' => self::SCHEMA,
            'level' => 'L-inf (one promoted fragment: R1 causal self-model)',
            'fragment' => 'R1_causal_self_model',
            'capability_filter' => $capability,
            'is_one_fragment_not_asymptote' => true,
            'linf_fragment' => true,
            'linf_complete' => false,
            'composes' => [
                'intent' => 'AtlasImplementationTruthService::ledger (claimed_state)',
                'truth' => 'AtlasImplementationTruthService::ledger (computed_state + per-ref resolution)',
                'result' => 'AtlasDocumentationRealityOutcomeGroundingService (L2-O1 grade)',
                'why' => 'ledger drift/under_claim + AtlasDocumentationRealityBidirectionalReconciliationService',
                'calibration' => 'AtlasDocumentationRealityReflectiveStatusService (R2 epistemic humility)',
            ],
            'causal_chains' => $chains,
            'claim_policy' => $this->claimPolicy(),
            'writes' => false,
        ];
    }

    /**
     * @param  array<string,mixed>  $envelope
     * @return array<string,mixed>
     */
    private function degraded(string $reason, ?string $capability): array
    {
        // Degrade-safe: on any composed-signal failure we return available=false with a
        // CALIBRATED note — never a fabricated cause. The note is built through link(),
        // so it passes the SAME supreme-drift guard as every why-link: no causal-shaped
        // object escapes assertCausalClaimCalibrated() by being hand-maintained.
        $envelope = [
            'schema_version' => self::SCHEMA,
            'level' => 'L-inf (one promoted fragment: R1 causal self-model)',
            'fragment' => 'R1_causal_self_model',
            'capability_filter' => $capability,
            'is_one_fragment_not_asymptote' => true,
            'linf_fragment' => true,
            'linf_complete' => false,
            'available' => false,
            'degraded_reason' => $reason,
            'note' => $this->link(
                "no causal chain could be assembled ({$reason}); rather than fabricate a cause, the model degrades and declares the gap.",
                'composed-signal availability check',
                self::INFERENCE_PROVEN,
                self::CONFIDENCE_LOW,
                'A degraded model cannot explain this capability; the absence of a chain is declared, never filled with an invented cause.',
            ),
            'causal_chains' => [],
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
            'writes' => false,
            'executes' => false,
            'mutates' => false,
            'linf_fragment' => true,
            'linf_complete' => false,
            'every_causal_claim_calibrated' => true,
            'distinguishes_inference_from_proof' => true,
            'never_fabricates_a_cause' => true,
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
        return RealityEnvelopeHashSeam::finalizeTamperEvidentEnvelope(
            $envelope,
            'causal_self_model_hash',
        );
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
