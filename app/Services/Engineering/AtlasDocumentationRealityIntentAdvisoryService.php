<?php

declare(strict_types=1);

namespace App\Services\Engineering;

/**
 * L2-O2 — Intent Co-Formation (first increment): the INTENT ADVISORY.
 *
 * L0/L1 verify EXECUTION ("does the code match the doc?"). O1 grades the OUTCOME
 * ("did it work in the world?"). O2 asks the question that comes BEFORE the build:
 *
 *   "Is this the RIGHT thing to build for your objective —
 *    or is there something of higher leverage?"
 *
 * THE CARDINAL RULE (atlas-documentation-reality-outcome-grounded-leap.md:38, :80,
 * :167, :191 — the doc names "co-formacao que invade soberania" as THE risk of the
 * whole L2): O2 is STRICTLY ADVISORY + HUMAN-GATED. It produces an OPINION / a set
 * of CONSIDERATIONS — NEVER a decision. It NEVER overrides the operator, NEVER
 * auto-acts, NEVER claims to know "the right thing" with false certainty. It
 * surfaces signals + questions and EXPLICITLY defers to the operator, who decides.
 * The output is unmistakably advisory: every envelope carries a mandatory
 * sovereignty block with is_a_decision=false and never_overrides_operator=true, and
 * there is deliberately NO allow/block/deny/gate verdict anywhere — a binding
 * verdict would turn advice into a decision, which is exactly what this contract
 * forbids.
 *
 * COMPOSITION (does NOT re-implement prediction): the technical signal is the
 * L1-P1 predictive simulator (AtlasSoftwareTwinRuntimeService::simulate) reused
 * as-is — the same duplication / drift / owner / blast-radius foresight. O2 wraps
 * that PLUS a thin intent layer: considerations derived ONLY from those signals +
 * structural facts, and leverage questions for the operator to weigh. No new
 * prediction, no new truth source.
 *
 * RECOMMENDATION (conservative, never authoritative): the advisory_recommendation
 * is an OPINION, clearly labelled ADVISORY, and carries the sovereignty block on
 * EVERY value:
 *   - simulate predicts would_duplicate          => reconsider_advisory
 *   - objective is empty (cannot assess leverage) => needs_operator_judgment
 *   - otherwise                                   => proceed_advisory
 * It is the operator — not this service — who decides what gets built.
 *
 * CRITICAL SAFETY: strictly READ-ONLY. It reads the simulate() prediction and the
 * structural facts of the proposal; it writes NOTHING, executes nothing, mutates
 * nothing, and authorizes nothing. O3 (multi-estate compounding) is a LATER
 * increment and is explicitly out of scope.
 *
 * @see docs/engineering-knowledge-base/atlas-documentation-reality-intent-coformation.md
 * @see docs/engineering-knowledge-base/atlas-documentation-reality-outcome-grounded-leap.md
 */
class AtlasDocumentationRealityIntentAdvisoryService
{
    public const SCHEMA = 'atlas.documentation_reality.intent_advisory.v1';

    /**
     * The simulator predicts the proposal would DUPLICATE an existing capability —
     * the conservative advisory is to reconsider / reuse what already exists. Still
     * an opinion: the operator may have a deliberate reason to proceed.
     */
    public const RECO_RECONSIDER = 'reconsider_advisory';

    /**
     * No objective was stated, so leverage CANNOT be assessed. The honest advisory
     * is that this needs the operator's judgment — the substrate will not pretend to
     * know the right target without the goal.
     */
    public const RECO_NEEDS_JUDGMENT = 'needs_operator_judgment';

    /**
     * No duplication predicted and an objective is stated — the advisory does not
     * see a structural reason to pause. Still ADVISORY: it is a green-tinted opinion,
     * never an authorization, and the operator decides.
     */
    public const RECO_PROCEED = 'proceed_advisory';

    public function __construct(
        private readonly AtlasSoftwareTwinRuntimeService $twin,
    ) {}

    /**
     * Advise on a PROPOSED spec BEFORE it is built. Given the same proposal shape
     * P1 simulate() takes ({kind, slug, graph_id, owner, capabilities, governs,
     * implementation_state, symbol}) and an OPTIONAL operator objective, return an
     * ADVISORY: the technical signal (from simulate), intent considerations, leverage
     * questions, a conservative advisory_recommendation, and the mandatory
     * sovereignty block. Read-only end to end — it opines, the operator decides.
     *
     * @param  array<string,mixed>  $proposed
     * @return array<string,mixed>
     */
    public function adviseProposal(array $proposed, string $objective = ''): array
    {
        $objective = trim($objective);
        $hasObjective = $objective !== '';

        // (a) Technical signal — REUSE the L1-P1 predictive simulator. We do not
        //     re-predict anything; we read its verdict and the structural facts it
        //     already computed.
        $prediction = $this->twin->simulate($proposed);
        $signal = $this->technicalSignal($prediction);

        $wouldDuplicate = $signal['would_duplicate'];
        $needsOwnerReview = $signal['verdict'] === 'needs_owner_review';
        $wouldDrift = $signal['would_drift'];
        $degraded = $signal['degraded'];

        // (b) Intent CONSIDERATIONS — derived ONLY from those signals + structural
        //     facts. Each is a thought for the operator to weigh, phrased as a
        //     question/suggestion, never an instruction.
        $considerations = $this->considerations($signal, $proposed, $hasObjective);

        // (c) LEVERAGE QUESTIONS — what the operator should ask themselves before
        //     committing to build. These are the heart of "is this the RIGHT thing?".
        $leverageQuestions = $this->leverageQuestions($signal, $objective, $hasObjective);

        // (d) The advisory recommendation — CONSERVATIVE, never authoritative. It is
        //     an opinion that always travels with the sovereignty block.
        $recommendation = $this->recommend($wouldDuplicate, $hasObjective);

        $envelope = [
            'schema_version' => self::SCHEMA,
            'mode' => 'intent_advisory',
            'level' => 'L2-O2',
            'increment' => 'intent_advisory_read_only',
            'proposal' => $this->echoProposal($proposed, $objective, $hasObjective),
            'technical_signal' => $signal,
            'considerations' => $considerations,
            'leverage_questions' => $leverageQuestions,
            'advisory_recommendation' => [
                'value' => $recommendation,
                'is_advisory' => true,
                'is_a_decision' => false,
                'label' => 'ADVISORY OPINION — the operator decides; this never binds.',
                'rationale' => $this->rationale($recommendation, $wouldDuplicate, $needsOwnerReview, $wouldDrift, $degraded, $hasObjective),
            ],
            // MANDATORY sovereignty block — present on EVERY advisory, no exceptions.
            // This is the operator-sovereignty guardrail the doc names as THE risk.
            'sovereignty' => $this->sovereignty(),
            'writes' => false,
            'claim_policy' => $this->claimPolicy(),
        ];

        return $this->finalize($envelope);
    }

    /**
     * Read the simulate() prediction into a compact technical signal. We project,
     * we never recompute — this is the same foresight P1 already produced (it
     * predicts; it never authorizes the write, and neither does this wrapper).
     *
     * @param  array<string,mixed>  $prediction
     * @return array<string,mixed>
     */
    private function technicalSignal(array $prediction): array
    {
        $verdict = $this->str(data_get($prediction, 'prediction.verdict')) ?? 'unknown';
        $duplicateBlock = (array) data_get($prediction, 'prediction.would_duplicate', []);
        $owner = data_get($prediction, 'prediction.owner');

        return [
            'source' => 'AtlasSoftwareTwinRuntimeService::simulate',
            'schema_version' => $this->str($prediction['schema_version'] ?? null),
            'verdict' => $verdict,
            'would_duplicate' => ($duplicateBlock['duplicate'] ?? false) === true,
            'duplicate_reason' => $this->str($duplicateBlock['reason'] ?? null),
            'graph_id_collisions' => array_values((array) ($duplicateBlock['graph_id_collisions'] ?? [])),
            'capability_overlap' => array_values((array) ($duplicateBlock['capability_overlap'] ?? [])),
            'would_drift' => (bool) data_get($prediction, 'prediction.would_drift', false),
            'owner_resolved' => is_array($owner)
                && ($owner['resolved'] ?? false) === true,
            'owner_doc_id' => is_array($owner) ? $this->str($owner['owner_doc_id'] ?? null) : null,
            'degraded' => (bool) data_get($prediction, 'prediction.degraded', false),
            'blast_radius_resolved' => (bool) data_get($prediction, 'prediction.blast_radius.resolved', false),
        ];
    }

    /**
     * Intent considerations — thoughts for the operator, derived ONLY from the
     * technical signal and structural facts of the proposal. Every entry is framed
     * as a question or a suggestion ("reconsider?", "is this a new domain?"); none
     * is an instruction or a verdict. The list is intentionally non-empty: even a
     * clean proposal gets the "no structural objection — but is it the highest
     * leverage?" nudge, because clean execution is not the same as the right target.
     *
     * @param  array<string,mixed>  $signal
     * @param  array<string,mixed>  $proposed
     * @return array<int,array<string,string>>
     */
    private function considerations(array $signal, array $proposed, bool $hasObjective): array
    {
        $considerations = [];

        if ($signal['would_duplicate']) {
            $considerations[] = [
                'kind' => 'possible_duplication',
                'consideration' => 'The simulator predicts this would duplicate an existing capability. Reconsider — could you reuse or extend what already exists instead of building anew?',
            ];
        }

        if (! $signal['owner_resolved']) {
            $considerations[] = [
                'kind' => 'no_clear_owner',
                'consideration' => 'No clear owner area resolved for this capability. Is this genuinely a new domain, or should it live under an existing owner?',
            ];
        }

        if ($signal['would_drift']) {
            $considerations[] = [
                'kind' => 'declared_state_ahead_of_evidence',
                'consideration' => 'The proposed implementation_state looks ahead of its evidence. Is the scope right, or is the claim larger than what would actually ship first?',
            ];
        }

        if ($signal['degraded']) {
            $considerations[] = [
                'kind' => 'signal_incomplete',
                'consideration' => 'A predictive check could not fully run (an index/graph was unavailable), so the technical signal is partial. Consider re-running once the read models are built before committing.',
            ];
        }

        if (! $hasObjective) {
            $considerations[] = [
                'kind' => 'objective_not_stated',
                'consideration' => 'No objective was stated, so leverage cannot be assessed — whether this is the RIGHT thing depends on the goal it serves. State the objective to let the advisory weigh it.',
            ];
        }

        // Always present: clean execution-foresight is not a verdict on the target.
        if ($considerations === []) {
            $considerations[] = [
                'kind' => 'no_structural_objection',
                'consideration' => 'No structural objection surfaced (no predicted duplication, drift or missing owner). That confirms it is buildable — it does NOT confirm it is the highest-leverage next step. That judgment is yours.',
            ];
        }

        return $considerations;
    }

    /**
     * Leverage questions — the BEFORE-you-build questions O2 exists to ask. These
     * are for the operator to answer; the service never answers them. When an
     * objective is given it is woven in verbatim so the question is concrete.
     *
     * @param  array<string,mixed>  $signal
     * @return array<int,string>
     */
    private function leverageQuestions(array $signal, string $objective, bool $hasObjective): array
    {
        $questions = [];

        if ($signal['would_duplicate']) {
            $questions[] = 'Is there an existing capability that already does this — and would reusing it beat building a second one?';
        } else {
            $questions[] = 'Is there already a capability, doc or service that covers this, even partially?';
        }

        $questions[] = $hasObjective
            ? "Is this the highest-leverage next step for: \"{$objective}\" — or is there something that moves it more?"
            : 'What objective does this serve, and is it the highest-leverage step toward that objective?';

        $questions[] = 'If you did NOT build this, what would you build instead — and is that more valuable?';
        $questions[] = 'Does this move a real outcome (use, production, revenue), or only add internal structure?';

        if (! $signal['owner_resolved']) {
            $questions[] = 'If this is a new domain with no owner, is opening that domain itself the right move right now?';
        }

        return $questions;
    }

    /**
     * The CONSERVATIVE recommendation logic. It is intentionally simple and never
     * authoritative: a predicted duplication is the one structural signal strong
     * enough to advise "reconsider"; a missing objective means leverage cannot be
     * judged at all (needs_operator_judgment); otherwise the advisory does not see a
     * reason to pause (proceed_advisory). In ALL cases the sovereignty block travels
     * with the value and the operator decides.
     */
    private function recommend(bool $wouldDuplicate, bool $hasObjective): string
    {
        if ($wouldDuplicate) {
            return self::RECO_RECONSIDER;
        }
        if (! $hasObjective) {
            return self::RECO_NEEDS_JUDGMENT;
        }

        return self::RECO_PROCEED;
    }

    private function rationale(
        string $recommendation,
        bool $wouldDuplicate,
        bool $needsOwnerReview,
        bool $wouldDrift,
        bool $degraded,
        bool $hasObjective,
    ): string {
        return match ($recommendation) {
            self::RECO_RECONSIDER => 'The simulator predicts duplication of an existing capability; reusing what exists is usually higher leverage than rebuilding. This is advice — you may proceed deliberately.',
            self::RECO_NEEDS_JUDGMENT => 'No objective was stated, so leverage cannot be assessed. Without the goal the substrate will not claim to know the right target — this needs your judgment.'
                .($needsOwnerReview ? ' (Note: no clear owner area resolved either.)' : '')
                .($wouldDrift ? ' (Note: declared state looks ahead of evidence.)' : '')
                .($degraded ? ' (Note: a predictive check was degraded.)' : ''),
            default => 'No predicted duplication and an objective is stated, so no structural reason to pause surfaced. This confirms buildability, not that it is the highest-leverage step — that judgment remains yours.'
                .($needsOwnerReview ? ' Consider, though: no clear owner area resolved.' : '')
                .($wouldDrift ? ' Consider, though: the declared state looks ahead of its evidence.' : '')
                .($degraded ? ' Consider, though: a predictive check was degraded, so the signal is partial.' : ''),
        };
    }

    /**
     * Echo the proposal back (read-only projection), so the advisory is self-describing
     * about what it was asked to weigh.
     *
     * @param  array<string,mixed>  $proposed
     * @return array<string,mixed>
     */
    private function echoProposal(array $proposed, string $objective, bool $hasObjective): array
    {
        return [
            'kind' => $this->str($proposed['kind'] ?? null) ?? 'doc',
            'slug' => $this->str($proposed['slug'] ?? null),
            'graph_id' => $this->str($proposed['graph_id'] ?? null),
            'owner' => $this->str($proposed['owner'] ?? null),
            'symbol' => $this->str($proposed['symbol'] ?? ($proposed['symbol_name'] ?? null)),
            'capabilities' => array_values(array_map('strval', (array) ($proposed['capabilities'] ?? []))),
            'governs' => array_values(array_map('strval', (array) ($proposed['governs'] ?? []))),
            'implementation_state' => $this->str($proposed['implementation_state'] ?? null),
            'objective' => $hasObjective ? $objective : null,
            'objective_stated' => $hasObjective,
        ];
    }

    /**
     * The mandatory sovereignty block. This is the operator-SOVEREIGNTY guardrail:
     * it makes the advisory unmistakable. is_a_decision=false and
     * never_overrides_operator=true are the load-bearing flags — they assert, in the
     * envelope itself, that O2 opines and the operator decides.
     *
     * @return array<string,bool>
     */
    private function sovereignty(): array
    {
        return [
            'advisory_only' => true,
            'human_gated' => true,
            'never_overrides_operator' => true,
            'operator_decides' => true,
            'is_a_decision' => false,
            'auto_acts' => false,
        ];
    }

    /**
     * claim_policy MIRRORS the sovereignty guarantees (plus the read-only ones), so a
     * reader checking either block reaches the same conclusion: this never binds,
     * never writes, never acts.
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
            'authorizes_mutation' => false,
            'advisory_only' => true,
            'human_gated' => true,
            'never_overrides_operator' => true,
            'is_a_decision' => false,
            'auto_acts' => false,
            'reuses_p1_predictive_simulator' => true,
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
        unset($hashPayload['generated_at'], $hashPayload['intent_advisory_hash']);
        $envelope['intent_advisory_hash'] = hash(
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
