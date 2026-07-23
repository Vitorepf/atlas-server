<?php

declare(strict_types=1);

namespace App\Services\Ai\Aaeos\Quarantine;

/**
 * Runtime for the Atlas AI Cognitive Runtime canonical LAW doc.
 *
 * This is the parent "lei mae" of the cognitive frente (governed memory, context
 * retrieval, 72h long sessions, automatic compaction, cognitive audit). The
 * child runbook doc already has its own decider
 * (AtlasCognitiveRuntimeRunbookService: compaction review / stop criteria /
 * post-session audit packet / promotion). This service implements the FOUR
 * decision surfaces that belong to the PARENT law and are not in the runbook:
 *
 *   1. Non-Negotiable Invariants ("Non-Negotiable Invariants", 10 rules). Given a
 *      proposed memory/context/handoff action, report which of the ten declared
 *      invariants are violated. Two of them are directional rules with real
 *      decision content, not just flags:
 *        - #8 "Contexto insuficiente e melhor que contexto contaminado" -> when a
 *          prompt is contaminated, this service prefers DROPPING context over
 *          shipping it; the action is blocked.
 *        - #10 "Nenhuma surface monta memoria manualmente no prompt" -> a surface
 *          that hand-assembles memory bypasses retrieval/policy and is blocked.
 *      Any violation makes the action `blocked` (an invariant is, by definition,
 *      non-negotiable).
 *
 *   2. 72h Long Session readiness gate ("72h Long Session Goal" table). A long
 *      session counts as `ready` ONLY when every one of the ten documented
 *      metrics shows its "bom sinal" and none is in "alerta". The meta is NOT a
 *      process open for 72h: it is sustained decision quality. This method takes
 *      per-metric alert flags and returns ready / not-ready plus the exact
 *      metrics in alert. Several alerts are hard-unsafe (missed invariant, context
 *      contamination, missed critical context) and additionally mark the session
 *      `unsafe`, not merely "not ready".
 *
 *   3. Retrieval Quality DoD ("Retrieval Quality DoD", 6 conditions). Retrieval is
 *      mature only when all six hold. The two with real per-item content:
 *        - every context ref must carry source, reason, scope, priority and a
 *          provider-safe summary (a ref missing any of these is non-conformant);
 *        - vector/hybrid search must NEVER bypass the deterministic filters
 *          (a candidate that skipped filtering is non-conformant).
 *
 *   4. Cognitive Audit Loop Net Value ("Cognitive Audit Loop"). Computes the
 *      canonical read-only score:
 *        net = gain(useful context/memory)
 *            - harm from wrong context
 *            - avoidable repetition
 *            - objective drift
 *            - cognitive/token cost
 *            - policy/privacy violations
 *      The score is proposal-only: it may feed Self-Improvement as a proposal but
 *      MUST NOT promote memory, change policy or auto-apply critical changes. This
 *      service therefore returns `read_only: true` and never mutates anything.
 *
 * Non-goals honoured ("Regras para IA" / "Escopo de Implementacao" / forbidden
 * changes):
 *   - does NOT declare runtime / maturity / readiness without the gates being
 *     green -- it only reports whether the documented gates are green;
 *   - prefers insufficient context over contaminated context (invariant #8);
 *   - never promotes memory, relaxes a gate, runs a provider or writes evidence.
 *
 * Pure & deterministic: no database, no IO, no clock. Same input -> same output.
 *
 * @see docs/engineering-knowledge-base/atlas-ai-cognitive-runtime.md
 */
final class AtlasAiCognitiveRuntimeService
{
    /** Canonical schema ids for the law-level packets emitted here. */
    public const INVARIANTS_SCHEMA = 'atlas.cognitive_runtime.invariants.v1';
    public const LONG_SESSION_SCHEMA = 'atlas.cognitive_runtime.long_session_readiness.v1';
    public const RETRIEVAL_SCHEMA = 'atlas.cognitive_runtime.retrieval_quality.v1';
    public const NET_VALUE_SCHEMA = 'atlas.cognitive_runtime.net_value.v1';

    // --- Invariant verdicts (closed set). ------------------------------------
    public const INVARIANTS_OK = 'ok';
    public const INVARIANTS_BLOCKED = 'blocked';

    // --- Long-session readiness verdicts (closed set). -----------------------
    public const SESSION_READY = 'ready';
    public const SESSION_NOT_READY = 'not_ready';
    public const SESSION_UNSAFE = 'unsafe';

    // --- Retrieval DoD verdicts (closed set). --------------------------------
    public const RETRIEVAL_MATURE = 'mature';
    public const RETRIEVAL_IMMATURE = 'immature';

    /**
     * The ten Non-Negotiable Invariants, in documented order. Each key is checked
     * against a same-named boolean *violation* flag on the input action.
     *
     * @var list<string>
     */
    public const INVARIANTS = [
        'raw_capture_is_not_memory',              // 1
        'memory_belongs_to_atlas_not_provider',   // 2
        'retrieval_gets_candidates_not_authority', // 3
        'context_pack_explains_each_ref',         // 4
        'compaction_preserves_state_not_chat',    // 5
        'long_session_measured_by_quality',       // 6
        'handoff_requires_fresh_receipt',         // 7
        'prefer_insufficient_over_contaminated',  // 8
        'critical_promotion_passes_gate_policy_evidence', // 9
        'no_surface_assembles_memory_manually',   // 10
    ];

    /**
     * The ten 72h Long Session metrics ("72h Long Session Goal" table), in
     * documented order. Each is `ready` on its "bom sinal" and in `alerta`
     * otherwise.
     *
     * @var list<string>
     */
    public const LONG_SESSION_METRICS = [
        'decision_quality_by_hour',
        'repeated_work_rate',
        'drift_rate',
        'compaction_recovery_time',
        'missed_invariant_count',
        'context_precision_at_k',
        'missed_critical_context',
        'stale_context_use',
        'context_contamination_rate',
        'cost_per_useful_hour',
    ];

    /**
     * Long-session metrics whose alert makes the session not merely "not ready"
     * but `unsafe` -- a rule already declared elsewhere in the doc was violated:
     *   - missed_invariant_count (Invariant #9 / "missed invariant count = zero");
     *   - context_contamination_rate (Invariant #8 / contamination must be zero);
     *   - missed_critical_context (DoD / failing to fetch an existing doc).
     *
     * @var list<string>
     */
    public const UNSAFE_LONG_SESSION_ALERTS = [
        'missed_invariant_count',
        'context_contamination_rate',
        'missed_critical_context',
    ];

    /**
     * The six Retrieval Quality DoD conditions, in documented order. Each key is
     * checked against a same-named boolean *satisfied* flag (the per-item ones,
     * #1 and #4, are derived from the candidate refs rather than trusted blindly).
     *
     * @var list<string>
     */
    public const RETRIEVAL_CONDITIONS = [
        'refs_carry_full_metadata',          // 1 source/reason/scope/priority/summary
        'ranking_privileges_canonical',      // 2
        'records_excluded_refs_with_reason', // 3
        'vector_never_bypasses_filters',     // 4
        'code_tasks_get_code_and_tests',     // 5
        'quality_metrics_measured',          // 6 precision@k, missed critical, contamination
    ];

    /**
     * Fields every context ref MUST carry to be DoD-conformant ("cada context ref
     * tem source, reason, scope, priority e provider-safe summary").
     *
     * @var list<string>
     */
    public const REQUIRED_REF_FIELDS = [
        'source',
        'reason',
        'scope',
        'priority',
        'provider_safe_summary',
    ];

    /**
     * Closed set of documented reasons a ref may be excluded from retrieval
     * ("refs excluidas por stale, private, conflicted, out_of_scope ou
     * low_relevance"). Retrieval must record excluded refs against these.
     *
     * @var list<string>
     */
    public const EXCLUSION_REASONS = [
        'stale',
        'private',
        'conflicted',
        'out_of_scope',
        'low_relevance',
    ];

    /**
     * The six harm/cost terms subtracted in the Cognitive Runtime Net Value
     * formula, in documented order.
     *
     * @var list<string>
     */
    public const NET_VALUE_COST_TERMS = [
        'wrong_context',
        'avoidable_repetition',
        'objective_drift',
        'cognitive_token_cost',
        'policy_privacy_violations',
    ];

    // ---------------------------------------------------------------------
    // 1. Non-Negotiable Invariants
    // ---------------------------------------------------------------------

    /**
     * Evaluate a proposed memory/context/handoff action against the ten
     * Non-Negotiable Invariants. Any violation blocks the action.
     *
     * Two invariants carry directional logic instead of a raw flag:
     *   #8 prefer_insufficient_over_contaminated: derived as violated when the
     *      prompt is `contaminated` AND the action chose to ship it anyway
     *      (dropping the context would have satisfied the invariant);
     *   #10 no_surface_assembles_memory_manually: derived as violated when a
     *      surface set `manual_memory_assembly` (it bypassed retrieval/policy).
     *
     * @param array<string,mixed> $action
     *        violations            : array<string,bool>  explicit per-invariant flags
     *        prompt_contaminated   : bool   the prompt carries raw/untrusted/private
     *        ship_contaminated     : bool   the action chose to ship anyway
     *        manual_memory_assembly: bool   a surface hand-assembled memory
     *
     * @return array<string,mixed>
     */
    public function evaluateInvariants(array $action): array
    {
        $explicit = is_array($action['violations'] ?? null) ? $action['violations'] : [];

        $violated = [];
        foreach (self::INVARIANTS as $invariant) {
            if ((bool) ($explicit[$invariant] ?? false)) {
                $violated[$invariant] = true;
            }
        }

        // #8 — contaminated context that was shipped instead of dropped.
        if ((bool) ($action['prompt_contaminated'] ?? false) && (bool) ($action['ship_contaminated'] ?? false)) {
            $violated['prefer_insufficient_over_contaminated'] = true;
        }

        // #10 — a surface assembled memory manually.
        if ((bool) ($action['manual_memory_assembly'] ?? false)) {
            $violated['no_surface_assembles_memory_manually'] = true;
        }

        $violatedList = array_values(array_keys($violated));
        $status = $violatedList === [] ? self::INVARIANTS_OK : self::INVARIANTS_BLOCKED;

        return [
            'schema' => self::INVARIANTS_SCHEMA,
            'surface' => 'non_negotiable_invariants',
            'status' => $status,
            'allowed' => $status === self::INVARIANTS_OK,
            'violated' => $violatedList,
            'violated_count' => count($violatedList),
            'invariants_total' => count(self::INVARIANTS),
        ];
    }

    /** Convenience predicate: may this memory/context/handoff action proceed? */
    public function actionAllowed(array $action): bool
    {
        return $this->evaluateInvariants($action)['allowed'] === true;
    }

    // ---------------------------------------------------------------------
    // 2. 72h Long Session readiness gate
    // ---------------------------------------------------------------------

    /**
     * Decide whether a long session is `ready` per the 72h goal table.
     *
     * A session is `ready` ONLY when no metric is in alert. If any of the
     * hard-unsafe metrics (missed invariant / contamination / missed critical
     * context) is in alert, the session is `unsafe` (a declared rule was broken),
     * which is strictly worse than `not_ready`.
     *
     * @param array<string,mixed> $session
     *        alerts : array<string,bool>  per-metric alert flags
     *                 (keys = LONG_SESSION_METRICS; true = in "alerta")
     *
     * @return array<string,mixed>
     */
    public function evaluateLongSessionReadiness(array $session): array
    {
        $alertsIn = is_array($session['alerts'] ?? null) ? $session['alerts'] : [];

        $alerting = [];
        foreach (self::LONG_SESSION_METRICS as $metric) {
            if ((bool) ($alertsIn[$metric] ?? false)) {
                $alerting[] = $metric;
            }
        }

        $unsafeAlerts = array_values(array_intersect($alerting, self::UNSAFE_LONG_SESSION_ALERTS));

        if ($unsafeAlerts !== []) {
            $verdict = self::SESSION_UNSAFE;
        } elseif ($alerting !== []) {
            $verdict = self::SESSION_NOT_READY;
        } else {
            $verdict = self::SESSION_READY;
        }

        return [
            'schema' => self::LONG_SESSION_SCHEMA,
            'surface' => 'long_session_72h',
            'verdict' => $verdict,
            'ready' => $verdict === self::SESSION_READY,
            'metrics_in_alert' => $alerting,
            'unsafe_alerts' => $unsafeAlerts,
            'metrics_ok' => count(self::LONG_SESSION_METRICS) - count($alerting),
            'metrics_total' => count(self::LONG_SESSION_METRICS),
        ];
    }

    /** Convenience predicate: is this long session `ready`? */
    public function longSessionReady(array $session): bool
    {
        return $this->evaluateLongSessionReadiness($session)['ready'] === true;
    }

    // ---------------------------------------------------------------------
    // 3. Retrieval Quality DoD
    // ---------------------------------------------------------------------

    /**
     * Evaluate a retrieval result against the six Retrieval Quality DoD
     * conditions. Conditions #1 and #4 are derived from the actual candidate
     * refs, not trusted as bare flags:
     *   #1 each ref must carry source, reason, scope, priority, provider-safe
     *      summary; a ref missing any is non-conformant;
     *   #4 a ref that skipped the deterministic filters (bypassed_filters=true)
     *      means vector/hybrid search bypassed filtering -> non-conformant.
     *
     * The remaining conditions (#2 ranking, #3 excluded-ref logging, #5 code refs,
     * #6 quality metrics measured) are taken as satisfied/not-satisfied flags.
     *
     * @param array<string,mixed> $retrieval
     *        refs : list<array{source?:string,reason?:string,scope?:string,priority?:mixed,provider_safe_summary?:string,bypassed_filters?:bool}>
     *        ranking_privileges_canonical     : bool
     *        records_excluded_refs_with_reason: bool
     *        code_tasks_get_code_and_tests    : bool
     *        quality_metrics_measured         : bool
     *
     * @return array<string,mixed>
     */
    public function evaluateRetrievalQuality(array $retrieval): array
    {
        $refs = is_array($retrieval['refs'] ?? null) ? array_values($retrieval['refs']) : [];

        $refsMissingMetadata = [];
        $refsBypassingFilters = [];
        foreach ($refs as $index => $ref) {
            $ref = is_array($ref) ? $ref : [];
            foreach (self::REQUIRED_REF_FIELDS as $field) {
                $value = $ref[$field] ?? null;
                if (! is_scalar($value) || trim((string) $value) === '') {
                    $refsMissingMetadata[] = $index;
                    break;
                }
            }
            if ((bool) ($ref['bypassed_filters'] ?? false)) {
                $refsBypassingFilters[] = $index;
            }
        }

        $conditions = [
            'refs_carry_full_metadata' => $refsMissingMetadata === [],
            'ranking_privileges_canonical' => (bool) ($retrieval['ranking_privileges_canonical'] ?? false),
            'records_excluded_refs_with_reason' => (bool) ($retrieval['records_excluded_refs_with_reason'] ?? false),
            'vector_never_bypasses_filters' => $refsBypassingFilters === [],
            'code_tasks_get_code_and_tests' => (bool) ($retrieval['code_tasks_get_code_and_tests'] ?? false),
            'quality_metrics_measured' => (bool) ($retrieval['quality_metrics_measured'] ?? false),
        ];

        $failed = [];
        foreach (self::RETRIEVAL_CONDITIONS as $condition) {
            if (! ($conditions[$condition] ?? false)) {
                $failed[] = $condition;
            }
        }

        $mature = $failed === [];

        return [
            'schema' => self::RETRIEVAL_SCHEMA,
            'surface' => 'retrieval_quality_dod',
            'status' => $mature ? self::RETRIEVAL_MATURE : self::RETRIEVAL_IMMATURE,
            'mature' => $mature,
            'conditions' => $conditions,
            'failed_conditions' => $failed,
            'refs_missing_metadata' => $refsMissingMetadata,
            'refs_bypassing_filters' => $refsBypassingFilters,
            'conditions_met' => count(self::RETRIEVAL_CONDITIONS) - count($failed),
            'conditions_total' => count(self::RETRIEVAL_CONDITIONS),
        ];
    }

    /** Convenience predicate: is retrieval DoD-mature? */
    public function retrievalMature(array $retrieval): bool
    {
        return $this->evaluateRetrievalQuality($retrieval)['mature'] === true;
    }

    // ---------------------------------------------------------------------
    // 4. Cognitive Audit Loop — Net Value
    // ---------------------------------------------------------------------

    /**
     * Compute the canonical, read-only Cognitive Runtime Net Value:
     *
     *   net = gain
     *       - wrong_context
     *       - avoidable_repetition
     *       - objective_drift
     *       - cognitive_token_cost
     *       - policy_privacy_violations
     *
     * Proposal-only: the result may feed Self-Improvement as a proposal but must
     * never promote memory, alter policy or auto-apply critical changes. This
     * method mutates nothing and flags `read_only: true`.
     *
     * @param array<string,mixed> $signals
     *        gain                      : int|float  gain from useful context/memory
     *        wrong_context             : int|float
     *        avoidable_repetition      : int|float
     *        objective_drift           : int|float
     *        cognitive_token_cost      : int|float
     *        policy_privacy_violations : int|float
     *
     * @return array<string,mixed>
     */
    public function computeNetValue(array $signals): array
    {
        $gain = $this->numeric($signals['gain'] ?? 0);

        $costs = [];
        $costTotal = 0.0;
        foreach (self::NET_VALUE_COST_TERMS as $term) {
            $value = $this->numeric($signals[$term] ?? 0);
            $costs[$term] = $value;
            $costTotal += $value;
        }

        $net = $gain - $costTotal;

        // A policy/privacy violation is special: even a positive net score must
        // not be read as "good", because invariant #9 / #8 forbid bypass. We
        // surface it so a proposal consumer can refuse promotion.
        $hasPolicyPrivacyViolation = $costs['policy_privacy_violations'] > 0.0;

        return [
            'schema' => self::NET_VALUE_SCHEMA,
            'surface' => 'cognitive_audit_loop',
            'net_value' => $net,
            'positive' => $net > 0.0,
            'gain' => $gain,
            'costs' => $costs,
            'cost_total' => $costTotal,
            'has_policy_privacy_violation' => $hasPolicyPrivacyViolation,
            'read_only' => true,
            'may_promote_memory' => false,
            'may_alter_policy' => false,
        ];
    }

    // ---------------------------------------------------------------------
    // Helpers
    // ---------------------------------------------------------------------

    /** Coerce a possibly-mixed numeric input to float; non-numeric -> 0.0. */
    private function numeric(mixed $value): float
    {
        if (is_int($value) || is_float($value)) {
            return (float) $value;
        }
        if (is_string($value) && is_numeric(trim($value))) {
            return (float) trim($value);
        }

        return 0.0;
    }
}
