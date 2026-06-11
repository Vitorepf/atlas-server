<?php

declare(strict_types=1);

namespace App\Services\Engineering;

use App\Models\AiOutcomeLink;
use App\Services\Ai\Aaeos\AtlasAaeosImplementationTruthService;
use App\Services\Ai\Support\DatabaseTableAvailability;
use Illuminate\Support\Collection;

/**
 * L2-O1 — Outcome-Grounded Truth (first increment): the OUTCOME-GROUNDING SCORER.
 *
 * L0/L1 prove a doc is IMPLEMENTED (drift zero): "does the code match the doc?".
 * O1 asks the NEXT question — the category leap from INTERNAL truth to EXTERNAL
 * truth: "was it USED / did it WORK in the world?". A capability is graded by
 * whether a REAL, resolved outcome signal links back to it.
 *
 * THE CARDINAL RULE (atlas-documentation-reality-outcome-grounded-leap.md:168 +
 * :96 — "outcome-grounded exige sinal real; sem sinal, o L2 nao pontua verdade
 * externa e NAO PODE FINGIR que pontua"): a capability is `outcome_grounded`
 * ONLY when a real, resolved outcome signal exists and is tied to it. Absence of
 * signal => HONESTLY `implemented_no_outcome_signal` (technically alive, NOT
 * world-validated). This service NEVER fabricates, infers, or correlates a world
 * outcome — no signal means no outcome score, full stop.
 *
 * The internal-truth GATE is reused, not re-implemented: only an IMPLEMENTED
 * capability (computed_state partial/verified, drift false) from the L0/L1 ledger
 * is even ELIGIBLE for outcome grading. A spec / north-star doc (computed_state
 * spec) is graded `not_implemented` and is NOT outcome-graded at all — you cannot
 * ask "did it work in the world?" of something that does not exist in code yet.
 *
 * OUTCOME-SIGNAL SOURCE — ai_outcome_links (App\Models\AiOutcomeLink): the genuine,
 * purpose-built, NON-inferential link primitive. A row records an explicit outcome
 * (outcome_type) tied to a target (target_type/target_id) with a value/confidence
 * and an occurred_at timestamp. We treat a row as a REAL resolved signal only when
 * it (a) targets THIS capability — by target_type in {capability, capability_id,
 * owner_doc, doc} with target_id equal to the capability_id or owner_doc, OR via
 * metadata.capability_id / metadata.owner_doc — and (b) has a non-null occurred_at
 * (the outcome actually happened in time). No row, no signal, no score. Other
 * outcome tables (ai_run_outcomes, telemetry) tie to a run/flow, not a capability,
 * so linking them to a doc would require inference — exactly the false-attribution
 * trap the doc forbids — and they are deliberately NOT used as the link source here.
 *
 * CRITICAL SAFETY: strictly READ-ONLY. It reads the ledger and (if present) the
 * ai_outcome_links table; it writes NOTHING, executes nothing, and creates no
 * outcome. O2 (intent co-formation) and O3 (multi-estate compounding) are LATER
 * increments and are explicitly out of scope.
 *
 * @see docs/engineering-knowledge-base/atlas-documentation-reality-outcome-grounded-truth.md
 * @see docs/engineering-knowledge-base/atlas-documentation-reality-outcome-grounded-leap.md
 */
class AtlasDocumentationRealityOutcomeGroundingService
{
    public const SCHEMA = 'atlas.documentation_reality.outcome_grounded.v1';

    /** A spec/north-star doc: not in code, so not outcome-gradable. */
    public const GRADE_NOT_IMPLEMENTED = 'not_implemented';

    /** Implemented (drift zero) AND a real resolved outcome signal links to it. */
    public const GRADE_OUTCOME_GROUNDED = 'outcome_grounded';

    /** Implemented (drift zero) but NO real outcome signal — the honest default. */
    public const GRADE_NO_SIGNAL = 'implemented_no_outcome_signal';

    /** Implemented, has a real outcome signal, but the world PUSHED BACK (rejected/failed). */
    public const GRADE_NEGATIVE_OUTCOME = 'implemented_negative_outcome';

    /**
     * Curated WHITELIST of outcome types that mean the capability actually WORKED /
     * was used / was adopted in the world — the ONLY types that ground "it worked".
     * (Drawn from App\Services\Ai\Telemetry\AiOutcomeAttributionService::OUTCOME_TYPES
     * plus other real producers, e.g. capability_adopted.) Deliberately conservative:
     * an UNKNOWN outcome type does NOT ground — a missed success is a safe under-claim,
     * whereas grading an unknown (possibly-failed) outcome as "it worked" is the
     * cardinal L2 sin. Add a type here only once it is confirmed to mean success.
     *
     * @var array<int,string>
     */
    private const POSITIVE_OUTCOME_TYPES = [
        'task_created', 'task_completed', 'project_created', 'project_plan_accepted',
        'blocker_resolved', 'routine_created', 'decision_recorded', 'conversation_continued',
        'human_marked_useful', 'capability_adopted',
    ];

    /**
     * Outcome types that mean the world REJECTED / failed the capability. A doc with
     * only these grades implemented_negative_outcome — NEVER outcome_grounded.
     * Grading a failure as "it worked in the world" is the cardinal L2 sin this set
     * prevents.
     *
     * @var array<int,string>
     */
    private const NEGATIVE_OUTCOME_TYPES = [
        'user_reasked_same_intent', 'user_abandoned_thread', 'provider_switched_after_bad_answer',
        'human_marked_not_useful', 'human_marked_wrong_context', 'human_marked_too_slow',
        'human_marked_too_expensive', 'human_marked_unsafe', 'human_dismissed',
    ];

    /**
     * The table that holds explicit outcome<->target links. The only outcome source
     * this scorer reads — see the class docblock for why the run/flow/telemetry
     * tables are excluded (they would require inference to tie to a capability).
     */
    private const OUTCOME_TABLE = 'ai_outcome_links';

    /**
     * target_type values that can name a capability/doc directly (non-inferential).
     * A link whose target_type is one of these and whose target_id equals the
     * capability_id or owner_doc is a real, explicit tie — no guessing.
     *
     * @var array<int,string>
     */
    private const CAPABILITY_TARGET_TYPES = ['capability', 'capability_id', 'owner_doc', 'doc', 'documentation'];

    public function __construct(
        private readonly AtlasAaeosImplementationTruthService $truth,
        private readonly AiOutcomeLink $outcomeLinks,
    ) {}

    /**
     * Grade every capability that declares evidence_refs: internal truth first
     * (from the L0/L1 ledger), then — for the implemented ones only — a real
     * outcome-signal lookup. Degrade-safe end to end.
     *
     * @return array<string,mixed>
     */
    public function gradeAll(): array
    {
        return $this->grade(null);
    }

    /**
     * Grade only the capability/doc whose id/slug/path matches $ownerDoc. Passes
     * the same filter straight through to the ledger so the gate and the grade
     * always agree on which capability is in scope.
     *
     * @return array<string,mixed>
     */
    public function gradeForDoc(string $ownerDoc): array
    {
        return $this->grade($ownerDoc);
    }

    /**
     * @return array<string,mixed>
     */
    private function grade(?string $capabilityFilter): array
    {
        $ledger = $this->truth->ledger($capabilityFilter);
        $capabilities = is_array($ledger['capabilities'] ?? null) ? $ledger['capabilities'] : [];

        // Can we see outcomes at all? If the link table is absent, we CANNOT observe
        // any world outcome — so every implemented doc is honestly no-signal, and we
        // say so explicitly. We never invent a signal to fill the void.
        $sourceAvailable = DatabaseTableAvailability::has(self::OUTCOME_TABLE);

        $grades = [];
        $outcomeGrounded = 0;
        $noSignal = 0;
        $notImplemented = 0;
        $negativeOutcome = 0;

        foreach ($capabilities as $row) {
            $grade = $this->gradeCapability($row, $sourceAvailable);
            match ($grade['grade']) {
                self::GRADE_OUTCOME_GROUNDED => $outcomeGrounded++,
                self::GRADE_NO_SIGNAL => $noSignal++,
                self::GRADE_NEGATIVE_OUTCOME => $negativeOutcome++,
                default => $notImplemented++,
            };
            $grades[] = $grade;
        }

        // Invariant, enforced in code: outcome_grounded is impossible without a real
        // signal source. If the source is unavailable the count MUST be zero — a
        // non-zero count here would be fabrication, so we fail loud instead.
        if (! $sourceAvailable && $outcomeGrounded !== 0) {
            throw new \LogicException('Invariant violation: outcome_grounded without an available outcome-signal source is fabrication.');
        }

        $envelope = [
            'schema_version' => self::SCHEMA,
            'mode' => 'outcome_grounding_scorer',
            'level' => 'L2-O1',
            'increment' => 'outcome_grounding_scorer_read_only',
            'capability_filter' => $capabilityFilter,
            'summary' => [
                'evaluated' => count($grades),
                'outcome_grounded_count' => $outcomeGrounded,
                'implemented_no_outcome_count' => $noSignal,
                'implemented_negative_outcome_count' => $negativeOutcome,
                'not_implemented_count' => $notImplemented,
                'outcome_signal_source_available' => $sourceAvailable,
            ],
            'outcome_signal_source' => self::OUTCOME_TABLE,
            'grades' => $grades,
            'writes' => false,
            'claim_policy' => $this->claimPolicy(),
        ];

        return $this->finalize($envelope);
    }

    /**
     * Grade a single ledger row. The internal-truth gate runs FIRST: a row whose
     * computed_state is not partial/verified, or that is in drift, is NOT eligible
     * for outcome grading and is graded not_implemented. Only a genuinely
     * implemented, non-drift capability proceeds to the real-signal lookup.
     *
     * @param  array<string,mixed>  $row
     * @return array<string,mixed>
     */
    private function gradeCapability(array $row, bool $sourceAvailable): array
    {
        $capabilityId = $this->str($row['capability_id'] ?? null) ?? '';
        $ownerDoc = $this->str($row['owner_doc'] ?? null) ?? '';
        $computed = $this->str($row['computed_state'] ?? null) ?? 'spec';
        $drift = (bool) ($row['drift'] ?? false);

        $base = [
            'capability_id' => $capabilityId,
            'owner_doc' => $ownerDoc,
            'computed_state' => $computed,
            'drift' => $drift,
        ];

        // GATE: only an IMPLEMENTED (partial/verified) capability with NO drift can
        // be asked "did it work in the world?". Everything else is a spec/north-star
        // (or over-claiming) doc — outcome grading is N/A, never outcome_grounded.
        $implemented = in_array($computed, ['partial', 'verified'], true) && $drift === false;
        if (! $implemented) {
            return $base + [
                'grade' => self::GRADE_NOT_IMPLEMENTED,
                'outcome_grounded' => false,
                'reason' => $drift
                    ? 'in_drift_not_eligible_for_outcome_grading'
                    : 'spec_or_north_star_not_implemented',
                'outcome_signals' => [],
            ];
        }

        // The doc is alive in code. Now — and only now — look for a REAL outcome
        // signal. Only a POSITIVE outcome grounds "it worked in the world". A doc with
        // only NEGATIVE outcomes (the world pushed back) is implemented_negative_outcome,
        // NEVER grounded. No signal => the honest implemented_no_outcome_signal. If the
        // source is unavailable we cannot observe outcomes at all and never fabricate one.
        $positive = $sourceAvailable
            ? $this->findSignals($capabilityId, $ownerDoc, $row, self::POSITIVE_OUTCOME_TYPES, true)
            : [];
        if ($positive !== []) {
            return $base + [
                'grade' => self::GRADE_OUTCOME_GROUNDED,
                'outcome_grounded' => true,
                'reason' => 'real_positive_outcome_signal_linked',
                'outcome_signals' => $positive,
            ];
        }

        $negative = $sourceAvailable
            ? $this->findSignals($capabilityId, $ownerDoc, $row, self::NEGATIVE_OUTCOME_TYPES, false)
            : [];
        if ($negative !== []) {
            return $base + [
                'grade' => self::GRADE_NEGATIVE_OUTCOME,
                'outcome_grounded' => false,
                'reason' => 'real_outcome_signal_but_world_pushed_back',
                'outcome_signals' => $negative,
            ];
        }

        return $base + [
            'grade' => self::GRADE_NO_SIGNAL,
            'outcome_grounded' => false,
            'reason' => $sourceAvailable
                ? 'implemented_but_no_real_outcome_signal_linked'
                : 'outcome_signal_source_unavailable',
            'outcome_signals' => [],
        ];
    }

    /**
     * Find REAL, resolved outcome links tied to this capability — explicitly, never
     * by inference. A link counts only when it both names the capability AND has a
     * non-null occurred_at (the outcome actually happened). Two explicit ties are
     * honoured: (a) a capability/doc-typed target whose target_id matches, and (b)
     * metadata.capability_id / metadata.owner_doc set by whoever logged the outcome.
     *
     * IMPORTANT (runtime pgsql): target_id is a UUID column, so only UUID-shaped
     * identifiers are ever compared against it — a human doc-slug can never equal a
     * UUID and comparing them would throw, not match. Slug/path identifiers tie only
     * through the string metadata JSON. Returns matched signals as plain refs; an
     * empty array means "no real signal" => the honest implemented_no_outcome_signal.
     *
     * @param  array<string,mixed>  $row
     * @return array<int,array<string,mixed>>
     */
    private function findSignals(string $capabilityId, string $ownerDoc, array $row, array $outcomeTypes, bool $requirePositiveValue): array
    {
        $identifiers = $this->capabilityIdentifiers($capabilityId, $ownerDoc, $row);
        if ($identifiers === []) {
            return [];
        }

        // target_id is a UUID column at runtime: only UUID-shaped identifiers can be
        // compared to it without a type error. (A capability whose id is itself a
        // UUID is the only case that ties through target_id.)
        $uuidIdentifiers = array_values(array_filter(
            $identifiers,
            static fn (string $v): bool => preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', $v) === 1,
        ));

        $query = $this->outcomeLinks->newQuery()
            // A resolved signal must have actually occurred IN THE PAST — no
            // occurred_at (or a FUTURE date) is not a settled world outcome.
            ->whereNotNull('occurred_at')
            ->where('occurred_at', '<=', now())
            // Polarity gate: only the requested outcome types count. A failure type can
            // NEVER be read as "it worked" (the cardinal L2 sin); a success type is the
            // only thing that grounds.
            ->whereIn('outcome_type', $outcomeTypes)
            ->where(function ($q) use ($identifiers, $uuidIdentifiers): void {
                // (a) Explicit capability/doc-typed target with a UUID target_id match.
                if ($uuidIdentifiers !== []) {
                    $q->where(function ($inner) use ($uuidIdentifiers): void {
                        $inner->whereIn('target_type', self::CAPABILITY_TARGET_TYPES)
                            ->whereIn('target_id', $uuidIdentifiers);
                    });
                }
                // (b) metadata names the capability/owner_doc directly (string JSON —
                // safe for slugs/paths, still explicit, never inferred by us).
                foreach ($identifiers as $identifier) {
                    $q->orWhere('metadata->capability_id', $identifier);
                    $q->orWhere('metadata->owner_doc', $identifier);
                }
            });

        if ($requirePositiveValue) {
            // A "success" with an explicit ZERO value is contradictory — a worthless
            // win must not ground. A null score is allowed: the positive type alone is
            // the signal (value_score is often simply unscored).
            $query->where(function ($q): void {
                $q->whereNull('value_score')->orWhere('value_score', '>', 0);
            });
        }

        return $this->mapSignals($query->orderByDesc('occurred_at')->limit(25)->get());
    }

    /**
     * Map matched outcome-link rows to plain signal refs (read-only projection).
     *
     * @param  Collection<int,AiOutcomeLink>  $links
     * @return array<int,array<string,mixed>>
     */
    private function mapSignals($links): array
    {
        $signals = [];
        foreach ($links as $link) {
            $signals[] = [
                'source' => self::OUTCOME_TABLE,
                'outcome_link_id' => $this->str($link->id),
                'outcome_type' => $this->str($link->outcome_type),
                'target_type' => $this->str($link->target_type),
                'target_id' => $this->str($link->target_id),
                'value_score' => is_numeric($link->value_score) ? (int) $link->value_score : null,
                'confidence' => is_numeric($link->confidence) ? (float) $link->confidence : null,
                'signal_source' => $this->str($link->source),
                'occurred_at' => $link->occurred_at?->toISOString(),
            ];
        }

        return $signals;
    }

    /**
     * The set of strings that can legitimately identify this capability in an
     * outcome link: its capability_id, its owner_doc path, and the doc's basename
     * (a common id form). Blanks are dropped and the list is de-duplicated. These
     * are the ONLY values matched against target_id / metadata — anything else is
     * not an explicit tie and is ignored.
     *
     * @param  array<string,mixed>  $row
     * @return array<int,string>
     */
    private function capabilityIdentifiers(string $capabilityId, string $ownerDoc, array $row): array
    {
        $candidates = [$capabilityId, $ownerDoc];

        if ($ownerDoc !== '') {
            $base = basename($ownerDoc);
            $candidates[] = $base;
            $candidates[] = preg_replace('/\.md$/', '', $base) ?? $base;
        }

        return EngineeringStringListNormalizer::uniqueNonEmptyStrings(
            array_map(fn (mixed $v): string => $this->str($v) ?? '', $candidates),
        );
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
            'fabricates_outcome' => false,
            'infers_outcome' => false,
            'outcome_grounded_requires_real_signal' => true,
            'reuses_internal_truth_gate' => true,
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
        unset($hashPayload['generated_at'], $hashPayload['outcome_grounding_hash']);
        $envelope['outcome_grounding_hash'] = hash(
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
