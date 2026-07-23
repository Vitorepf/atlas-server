<?php

declare(strict_types=1);

namespace App\Services\Ai\Aaeos\Quarantine;

/**
 * Atlas AI Research Self-Improvement Failure Modes — pure, deterministic decider.
 *
 * This service turns the failure taxonomy for research, documentation promotion
 * and self-improvement automation into runtime. The governing decision in the
 * doc frontmatter is absolute: "Research and self-improvement failures must fail
 * closed" and "Hallucinated source is a stop-the-line condition." This decider
 * is read-only: given a set of observed failure signals (and/or stop-the-line
 * triggers) it classifies each one, resolves the documented required response
 * and the recovery action, and emits the worst-severity verdict plus whether
 * promotion/execution must be withheld. It never runs a crawler, never writes
 * to memory, never promotes a packet and never relaxes a gate.
 *
 * Four documented surfaces are implemented:
 *
 *   1. Failure Table ("Failure Table"). Ten failure modes, each carrying its
 *      documented risk and the documented required response. The severity of a
 *      mode is derived deterministically from its required-response verb:
 *        - `stop`  : the response "Stop the line" / refuses / blocks / holds /
 *                    revokes — promotion AND execution are withheld and a
 *                    fail-closed recovery is mandatory (hallucinated source,
 *                    auto-applied proposal, polluted memory, missing
 *                    evaluation, missing rollback);
 *        - `route` : the response routes the claim through a stronger gate or
 *                    repairs the evidence (hype release -> Provider Evolution,
 *                    secondary-as-primary -> downgrade tier, research without
 *                    docs -> promote/archive, docs without AP -> create AP,
 *                    contradiction -> conflict record). Quality is at risk but
 *                    the line does not stop.
 *      `classify()` walks a set of active modes and returns the single worst
 *      severity (clean < route < stop) — a `stop` mode is never masked by a
 *      `route` mode.
 *
 *   2. Stop-The-Line Conditions ("Stop-The-Line Conditions"). Six fail-closed
 *      triggers. ANY one present forces severity `stop`: invented citation,
 *      critical source unavailable, runtime/policy change from research output
 *      only, memory write from a Tier 4/5 source, Kernel/Policy/Receipt/Ledger
 *      touched without an AP, and validation impossible while risk is med/high.
 *      `evaluateStopTheLine()` is the fail-closed gate.
 *
 *   3. Recovery ("Recovery"). The six ordered recovery steps, returned verbatim
 *      and in order whenever a fail-closed condition is hit (preserve evidence,
 *      mark invalid, identify contamination, roll back/supersede, add
 *      guardrail/test, record a Self-Improvement finding).
 *
 *   4. The full decision ("decide()") fuses the Failure Table verdict with the
 *      Stop-The-Line gate: it fails closed if EITHER surface demands a stop, and
 *      only then attaches the recovery sequence.
 *
 * Non-goals honoured ("Regras para IA" / forbidden_changes):
 *   - does NOT declare runtime / maturity / readiness — it reports only the
 *     posture the taxonomy demands;
 *   - a research output alone never authorises a runtime/policy change;
 *   - fails closed on ambiguity: an unknown mode is reported, never treated as
 *     safe, and the doc's bias is always toward withholding promotion.
 *
 * @see docs/engineering-knowledge-base/research-self-improvement/failure-modes.md
 */
final class AtlasResearchFailureModesService
{
    /** Canonical schema id for this decider's envelopes. */
    public const SCHEMA = 'atlas.research_self_improvement.failure_modes.v1';

    // --- Severity ladder (closed set; weakest -> strongest). -------------------
    public const SEVERITY_CLEAN = 'clean';
    public const SEVERITY_ROUTE = 'route';
    public const SEVERITY_STOP = 'stop';

    /**
     * Severity levels in documented order, lowest impact first. The index is the
     * comparison rank used to pick the single worst active mode.
     *
     * @var list<string>
     */
    public const SEVERITY_ORDER = [
        self::SEVERITY_CLEAN,
        self::SEVERITY_ROUTE,
        self::SEVERITY_STOP,
    ];

    /**
     * Runtime posture per severity. `clean` proceeds; `route` proceeds only
     * through the stronger gate named in the required response; `stop` is the
     * fail-closed posture — promotion and execution are both withheld.
     *
     * @var array<string,string>
     */
    public const SEVERITY_POSTURE = [
        self::SEVERITY_CLEAN => 'proceed',
        self::SEVERITY_ROUTE => 'route_through_gate',
        self::SEVERITY_STOP => 'stop_the_line',
    ];

    // --- Failure-mode keys (closed set; "Failure Table", documented order). ----
    public const MODE_HALLUCINATED_SOURCE = 'hallucinated_source';
    public const MODE_HYPE_DRIVEN_RELEASE = 'hype_driven_release';
    public const MODE_SECONDARY_AS_PRIMARY = 'secondary_source_treated_primary';
    public const MODE_RESEARCH_WITHOUT_DOCS = 'research_without_docs';
    public const MODE_DOCS_WITHOUT_PLAN = 'docs_without_ap_plan';
    public const MODE_PROPOSAL_AUTO_APPLIED = 'proposal_auto_applied';
    public const MODE_MEMORY_POLLUTED = 'memory_polluted_by_weak_claim';
    public const MODE_EVALUATION_MISSING = 'evaluation_missing';
    public const MODE_CONTRADICTION_IGNORED = 'contradiction_ignored';
    public const MODE_NO_ROLLBACK = 'automation_has_no_rollback';

    /**
     * The full Failure Table ("Failure Table"), one row per documented mode. Each
     * row carries the documented risk, required response, and the severity that
     * response implies (a stop-the-line / block / hold / refuse / revoke response
     * is `stop`; a route/downgrade/create/add response is `route`).
     *
     * @var array<string,array{risk:string,required_response:string,severity:string}>
     */
    public const FAILURE_TABLE = [
        self::MODE_HALLUCINATED_SOURCE => [
            'risk' => 'False truth enters Atlas',
            'required_response' => 'Stop, mark packet invalid, require source verification.',
            'severity' => self::SEVERITY_STOP,
        ],
        self::MODE_HYPE_DRIVEN_RELEASE => [
            'risk' => 'Provider marketing becomes roadmap',
            'required_response' => 'Route through Provider Evolution and evaluation.',
            'severity' => self::SEVERITY_ROUTE,
        ],
        self::MODE_SECONDARY_AS_PRIMARY => [
            'risk' => 'Weak evidence',
            'required_response' => 'Downgrade tier, require primary source.',
            'severity' => self::SEVERITY_ROUTE,
        ],
        self::MODE_RESEARCH_WITHOUT_DOCS => [
            'risk' => 'Context lost',
            'required_response' => 'Promote to owner doc or archive.',
            'severity' => self::SEVERITY_ROUTE,
        ],
        self::MODE_DOCS_WITHOUT_PLAN => [
            'risk' => 'Unbounded implementation',
            'required_response' => 'Create AP/plan before code.',
            'severity' => self::SEVERITY_ROUTE,
        ],
        self::MODE_PROPOSAL_AUTO_APPLIED => [
            'risk' => 'Unsafe autonomy',
            'required_response' => 'Block, require review gate.',
            'severity' => self::SEVERITY_STOP,
        ],
        self::MODE_MEMORY_POLLUTED => [
            'risk' => 'Long-term degradation',
            'required_response' => 'Revoke memory, trace source, add guardrail.',
            'severity' => self::SEVERITY_STOP,
        ],
        self::MODE_EVALUATION_MISSING => [
            'risk' => 'Improvement unproven',
            'required_response' => 'Hold promotion.',
            'severity' => self::SEVERITY_STOP,
        ],
        self::MODE_CONTRADICTION_IGNORED => [
            'risk' => 'Bad decisions',
            'required_response' => 'Add conflict record and research more.',
            'severity' => self::SEVERITY_ROUTE,
        ],
        self::MODE_NO_ROLLBACK => [
            'risk' => 'Enterprise risk',
            'required_response' => 'Refuse promotion.',
            'severity' => self::SEVERITY_STOP,
        ],
    ];

    // --- Stop-The-Line conditions (closed set; "Stop-The-Line Conditions"). ----
    public const STL_INVENTED_CITATION = 'invented_citation';
    public const STL_CRITICAL_SOURCE_UNAVAILABLE = 'critical_source_unavailable';
    public const STL_RUNTIME_CHANGE_FROM_RESEARCH_ONLY = 'runtime_change_from_research_only';
    public const STL_MEMORY_WRITE_FROM_LOW_TIER = 'memory_write_from_tier_4_or_5';
    public const STL_KERNEL_TOUCHED_WITHOUT_AP = 'kernel_policy_receipt_ledger_without_ap';
    public const STL_VALIDATION_BLOCKED_AT_RISK = 'validation_impossible_risk_medium_high';

    /**
     * The six documented stop-the-line conditions, keyed to a human-readable
     * description ("Stop-The-Line Conditions"). ANY one present fails closed.
     *
     * @var array<string,string>
     */
    public const STOP_THE_LINE_CONDITIONS = [
        self::STL_INVENTED_CITATION => 'invented citation',
        self::STL_CRITICAL_SOURCE_UNAVAILABLE => 'source unavailable and claim is critical',
        self::STL_RUNTIME_CHANGE_FROM_RESEARCH_ONLY => 'runtime/policy change requested by research output only',
        self::STL_MEMORY_WRITE_FROM_LOW_TIER => 'memory write from Tier 4 or Tier 5 source',
        self::STL_KERNEL_TOUCHED_WITHOUT_AP => 'implementation touches Kernel/Policy/Receipt/Ledger without AP',
        self::STL_VALIDATION_BLOCKED_AT_RISK => 'validation cannot be run and risk is medium/high',
    ];

    /**
     * The six ordered Recovery steps ("Recovery"), verbatim and in sequence. The
     * sequence is attached to any fail-closed verdict.
     *
     * @var list<string>
     */
    public const RECOVERY_SEQUENCE = [
        'Preserve raw evidence.',
        'Mark invalid packet or proposal.',
        'Identify contaminated docs/memory/code.',
        'Roll back or supersede.',
        'Add guardrail/test.',
        'Record Self-Improvement finding.',
    ];

    /**
     * Stop-severity modes from the Failure Table — promotion is withheld and the
     * fail-closed recovery applies.
     *
     * @var list<string>
     */
    public const STOP_MODES = [
        self::MODE_HALLUCINATED_SOURCE,
        self::MODE_PROPOSAL_AUTO_APPLIED,
        self::MODE_MEMORY_POLLUTED,
        self::MODE_EVALUATION_MISSING,
        self::MODE_NO_ROLLBACK,
    ];

    // ---------------------------------------------------------------------
    // 1. Single-mode lookup
    // ---------------------------------------------------------------------

    /**
     * Resolve one documented failure mode to its full table row + posture.
     *
     * @return array<string,mixed>|null null when the key is not a known mode
     */
    public function describeMode(string $mode): ?array
    {
        $key = $this->normalize($mode);
        if (! isset(self::FAILURE_TABLE[$key])) {
            return null;
        }

        $row = self::FAILURE_TABLE[$key];
        $severity = $row['severity'];

        return [
            'mode' => $key,
            'risk' => $row['risk'],
            'required_response' => $row['required_response'],
            'severity' => $severity,
            'posture' => self::SEVERITY_POSTURE[$severity],
            'withhold_promotion' => $severity === self::SEVERITY_STOP,
            'fails_closed' => $severity === self::SEVERITY_STOP,
        ];
    }

    /**
     * Documented runtime posture for a severity, or null when the severity is
     * outside the closed set.
     */
    public function postureForSeverity(string $severity): ?string
    {
        return self::SEVERITY_POSTURE[$this->normalize($severity)] ?? null;
    }

    // ---------------------------------------------------------------------
    // 2. Stop-The-Line gate (fail-closed)
    // ---------------------------------------------------------------------

    /**
     * The fail-closed gate ("Stop-The-Line Conditions"). ANY known condition
     * present forces a stop. Unknown keys are reported but, per the doc's
     * fail-closed bias, never treated as a pass on their own — they are surfaced
     * so the operator resolves them.
     *
     * @param list<string> $conditions active stop-the-line condition keys
     *
     * @return array<string,mixed>
     */
    public function evaluateStopTheLine(array $conditions): array
    {
        $known = [];
        $unknown = [];
        foreach ($conditions as $condition) {
            if (! is_string($condition)) {
                continue;
            }
            $key = $this->normalize($condition);
            if ($key === '') {
                continue;
            }
            if (isset(self::STOP_THE_LINE_CONDITIONS[$key])) {
                if (! in_array($key, $known, true)) {
                    $known[] = $key;
                }
            } elseif (! in_array($key, $unknown, true)) {
                $unknown[] = $key;
            }
        }

        $stop = $known !== [];

        return [
            'stop' => $stop,
            'triggered' => $known,
            'unknown_conditions' => $unknown,
            'descriptions' => array_values(array_map(
                static fn (string $k): string => self::STOP_THE_LINE_CONDITIONS[$k],
                $known,
            )),
        ];
    }

    // ---------------------------------------------------------------------
    // 3. Classify a set of active failure modes
    // ---------------------------------------------------------------------

    /**
     * Classify a set of observed failure modes against the Failure Table and
     * decide the posture for the research/promotion packet.
     *
     * The verdict severity is the SINGLE WORST active mode (clean < route <
     * stop), so a `stop` hallucinated source is never masked by a `route` hype
     * signal. Promotion is withheld whenever the worst severity is `stop`.
     * Unknown mode keys are ignored for the verdict but reported, never treated
     * as safe.
     *
     * @param list<string> $modes active failure-mode keys
     *
     * @return array<string,mixed>
     */
    public function classify(array $modes): array
    {
        $known = [];
        $unknown = [];
        foreach ($modes as $mode) {
            if (! is_string($mode)) {
                continue;
            }
            $key = $this->normalize($mode);
            if ($key === '') {
                continue;
            }
            if (isset(self::FAILURE_TABLE[$key])) {
                if (! in_array($key, $known, true)) {
                    $known[] = $key;
                }
            } elseif (! in_array($key, $unknown, true)) {
                $unknown[] = $key;
            }
        }

        $details = [];
        $worstRank = -1;
        $worstSeverity = self::SEVERITY_CLEAN;
        $stopModes = [];

        foreach ($known as $key) {
            $detail = $this->describeMode($key);
            // describeMode never returns null for a $known key.
            $details[] = $detail;

            $severity = (string) $detail['severity'];
            $rank = $this->severityRank($severity);
            if ($rank > $worstRank) {
                $worstRank = $rank;
                $worstSeverity = $severity;
            }
            if ($severity === self::SEVERITY_STOP) {
                $stopModes[] = $key;
            }
        }

        if ($known === []) {
            $worstSeverity = self::SEVERITY_CLEAN;
        }

        $withholdPromotion = $worstSeverity === self::SEVERITY_STOP;

        return [
            'schema' => self::SCHEMA,
            'surface' => 'failure_table',
            'severity' => $worstSeverity,
            'posture' => self::SEVERITY_POSTURE[$worstSeverity],
            'withhold_promotion' => $withholdPromotion,
            'can_promote' => ! $withholdPromotion,
            'active_modes' => $known,
            'stop_modes' => $stopModes,
            'unknown_modes' => $unknown,
            'required_responses' => $this->requiredResponsesFor($known),
            'details' => $details,
        ];
    }

    /**
     * The de-duplicated documented required responses for a set of modes, in
     * first-seen order.
     *
     * @param list<string> $modes known mode keys
     * @return list<string>
     */
    public function requiredResponsesFor(array $modes): array
    {
        $responses = [];
        foreach ($modes as $mode) {
            $detail = $this->describeMode($mode);
            if ($detail === null) {
                continue;
            }
            $response = (string) $detail['required_response'];
            if (! in_array($response, $responses, true)) {
                $responses[] = $response;
            }
        }

        return $responses;
    }

    // ---------------------------------------------------------------------
    // 4. Full decision: Failure Table + Stop-The-Line, fused, fail-closed
    // ---------------------------------------------------------------------

    /**
     * Fuse the Failure Table verdict with the Stop-The-Line gate. The packet
     * fails closed if EITHER surface demands a stop; only then is the ordered
     * Recovery sequence attached. This is the doc's governing decision — research
     * and self-improvement failures fail closed — made into a single envelope.
     *
     * @param list<string> $modes      active failure-mode keys
     * @param list<string> $conditions active stop-the-line condition keys
     *
     * @return array<string,mixed>
     */
    public function decide(array $modes = [], array $conditions = []): array
    {
        $classification = $this->classify($modes);
        $stl = $this->evaluateStopTheLine($conditions);

        $failClosed = $classification['severity'] === self::SEVERITY_STOP || $stl['stop'] === true;

        return [
            'schema' => self::SCHEMA,
            'fail_closed' => $failClosed,
            'can_promote' => ! $failClosed,
            'severity' => $failClosed ? self::SEVERITY_STOP : $classification['severity'],
            'posture' => $failClosed
                ? self::SEVERITY_POSTURE[self::SEVERITY_STOP]
                : $classification['posture'],
            'classification' => $classification,
            'stop_the_line' => $stl,
            'recovery' => $failClosed ? self::RECOVERY_SEQUENCE : [],
        ];
    }

    // ---------------------------------------------------------------------
    // Helpers
    // ---------------------------------------------------------------------

    /** Comparison rank for a severity (its index in SEVERITY_ORDER; -1 if unknown). */
    private function severityRank(string $severity): int
    {
        $rank = array_search($severity, self::SEVERITY_ORDER, true);

        return $rank === false ? -1 : $rank;
    }

    /** Lowercase + trim a raw key for closed-set lookups. */
    private function normalize(string $value): string
    {
        return strtolower(trim($value));
    }
}
