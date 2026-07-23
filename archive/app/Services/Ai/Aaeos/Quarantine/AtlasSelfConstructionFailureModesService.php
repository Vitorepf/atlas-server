<?php

declare(strict_types=1);

namespace App\Services\Ai\Aaeos\Quarantine;

/**
 * Atlas Self-Construction Failure Modes — pure, deterministic failure governor.
 *
 * Self-construction fails when Atlas becomes confident faster than it becomes
 * correct. This service turns the four documented surfaces of the canonical doc
 * into runtime. It is read-only: it classifies failure modes, decides whether an
 * autonomous run must STOP on a red flag, validates an incident packet, and
 * enforces the recovery sequence. It never runs a provider, writes evidence,
 * mutates a template/policy or relaxes a gate.
 *
 * Four documented surfaces are implemented:
 *
 *   1. Failure Table ("Failure Table"). Twelve failure modes, each carrying its
 *      documented signal and countermeasure. `describeFailure()` resolves one
 *      mode; `countermeasureFor()` returns its required countermeasure. The three
 *      modes the frontmatter names as most dangerous — silent drift, false
 *      completeness and unsafe learning — are flagged `most_dangerous` so a
 *      verdict can never treat them as routine.
 *
 *   2. Red Flags ("Red Flags"). Six documented stop conditions. The doc is
 *      categorical: "Stop construction if" ANY of them holds. `evaluateRedFlags()`
 *      walks a set of observed flags and returns `stop_construction = true` the
 *      moment one is raised — a single red flag halts the run; zero flags allow
 *      it to proceed.
 *
 *   3. Required Incident Packet ("Required Incident Packet"). The eight-field
 *      YAML packet that MUST exist when a self-construction failure occurs.
 *      `buildIncidentPacket()` reports exactly which fields are missing and is
 *      `complete` only when every documented field is present and non-empty — a
 *      "done" claim without a complete packet is the False completeness mode.
 *
 *   4. Recovery Order ("Recovery Order"). Seven ordered steps. The order is a
 *      hard contract: stop writes (1) before preserving evidence (2) before
 *      identifying the failure (3) … before resuming (7). `nextRecoveryStep()`
 *      returns the single next step given the completed ones, and rejects any
 *      attempt to skip ahead (e.g. resuming before evidence is preserved).
 *
 * Non-goals honoured ("Regras para IA" / forbidden_changes):
 *   - never declares runtime / maturity / readiness — it only reports the posture
 *     the doc demands; the operator acts;
 *   - prefers stopping (red flag, incomplete packet, out-of-order recovery) over
 *     optimistic continuation;
 *   - learning is proposal-first: the Unsafe learning mode is always `blocked`.
 *
 * @see docs/engineering-knowledge-base/self-construction/failure-modes.md
 */
final class AtlasSelfConstructionFailureModesService
{
    /** Canonical schema id for this governor's envelopes. */
    public const SCHEMA = 'atlas.self_construction.failure_modes.v1';

    // --- Failure-mode keys (closed set; "Failure Table", documented order). ----
    public const MODE_VIBE_SELF_CODING = 'vibe_self_coding';
    public const MODE_PARALLEL_ARCHITECTURE = 'parallel_architecture';
    public const MODE_DOC_THEATRE = 'doc_theatre';
    public const MODE_FALSE_COMPLETENESS = 'false_completeness';
    public const MODE_SCOPE_CREEP = 'scope_creep';
    public const MODE_MEMORY_CONTAMINATION = 'memory_contamination';
    public const MODE_RESEARCH_HALLUCINATION = 'research_hallucination';
    public const MODE_DRIFT = 'drift';
    public const MODE_UNSAFE_LEARNING = 'unsafe_learning';
    public const MODE_OVERENGINEERING = 'overengineering';
    public const MODE_GATE_BLINDNESS = 'gate_blindness';
    public const MODE_LONG_SESSION_DECAY = 'long_session_decay';

    /**
     * The full Failure Table ("Failure Table"), one row per documented mode.
     * Each row carries the documented signal and the required countermeasure,
     * verbatim from the table.
     *
     * @var array<string,array{signal:string,countermeasure:string}>
     */
    public const FAILURE_TABLE = [
        self::MODE_VIBE_SELF_CODING => [
            'signal' => 'code without Meta-SDD',
            'countermeasure' => 'block; require spec and receipt',
        ],
        self::MODE_PARALLEL_ARCHITECTURE => [
            'signal' => 'new flow bypasses Kernel/docs',
            'countermeasure' => 'architecture validate and AP review',
        ],
        self::MODE_DOC_THEATRE => [
            'signal' => 'docs exist but code/gates absent',
            'countermeasure' => 'maturity ladder labels',
        ],
        self::MODE_FALSE_COMPLETENESS => [
            'signal' => '"done" without evidence',
            'countermeasure' => 'evidence closeout required',
        ],
        self::MODE_SCOPE_CREEP => [
            'signal' => 'adjacent features added',
            'countermeasure' => 'receipt allowed files/actions',
        ],
        self::MODE_MEMORY_CONTAMINATION => [
            'signal' => 'weak facts promoted',
            'countermeasure' => 'Cognitive Immune gate',
        ],
        self::MODE_RESEARCH_HALLUCINATION => [
            'signal' => 'claims without primary source',
            'countermeasure' => 'Evidence Lake and citation health',
        ],
        self::MODE_DRIFT => [
            'signal' => 'spec, code and tests diverge',
            'countermeasure' => 'drift detector',
        ],
        self::MODE_UNSAFE_LEARNING => [
            'signal' => 'template/policy auto-mutated',
            'countermeasure' => 'proposal-first learning',
        ],
        self::MODE_OVERENGINEERING => [
            'signal' => 'large abstraction before need',
            'countermeasure' => 'small slice rule',
        ],
        self::MODE_GATE_BLINDNESS => [
            'signal' => 'passing wrong tests',
            'countermeasure' => 'acceptance traceability',
        ],
        self::MODE_LONG_SESSION_DECAY => [
            'signal' => 'repeated decisions, stale context',
            'countermeasure' => 'compaction and handoff metrics',
        ],
    ];

    /**
     * The most dangerous failures (frontmatter decision: "The most dangerous
     * failures are silent drift, false completeness and unsafe learning").
     *
     * @var list<string>
     */
    public const MOST_DANGEROUS_MODES = [
        self::MODE_DRIFT,
        self::MODE_FALSE_COMPLETENESS,
        self::MODE_UNSAFE_LEARNING,
    ];

    // --- Red-flag keys (closed set; "Red Flags", documented order). ------------
    public const FLAG_NO_NAMED_CAPABILITY = 'no_named_capability';
    public const FLAG_NO_BUILD_GRAPH_GAIN = 'no_build_graph_gain';
    public const FLAG_TEST_DOES_NOT_PROVE_ACCEPTANCE = 'test_does_not_prove_acceptance';
    public const FLAG_DOCS_CODE_DISAGREE = 'docs_code_disagree';
    public const FLAG_AUTONOMY_SECURITY_SIDE_EFFECT = 'autonomy_security_side_effect';
    public const FLAG_COMPLETE_WITHOUT_EVIDENCE = 'complete_without_evidence';

    /**
     * The Red Flags ("Red Flags") — stop conditions, keyed to their documented
     * description. ANY raised flag stops construction.
     *
     * @var array<string,string>
     */
    public const RED_FLAGS = [
        self::FLAG_NO_NAMED_CAPABILITY => 'no one can name the target capability',
        self::FLAG_NO_BUILD_GRAPH_GAIN => 'the change improves no build graph dependency',
        self::FLAG_TEST_DOES_NOT_PROVE_ACCEPTANCE => 'test output does not prove acceptance criteria',
        self::FLAG_DOCS_CODE_DISAGREE => 'docs and code disagree',
        self::FLAG_AUTONOMY_SECURITY_SIDE_EFFECT => 'implementation changes autonomy or security as a side effect',
        self::FLAG_COMPLETE_WITHOUT_EVIDENCE => 'the agent says "complete" but cannot cite evidence',
    ];

    /**
     * Required Incident Packet fields ("Required Incident Packet"), in documented
     * order. Every field must be present and non-empty for the packet to close.
     *
     * @var list<string>
     */
    public const INCIDENT_PACKET_FIELDS = [
        'operation_id',
        'failure_mode',
        'root_cause',
        'affected_docs',
        'affected_files',
        'failed_gates',
        'rollback',
        'prevention_proposal',
    ];

    /**
     * Recovery Order ("Recovery Order") — the seven steps in strict sequence.
     * The list index + 1 is the documented step number.
     *
     * @var list<string>
     */
    public const RECOVERY_ORDER = [
        'stop_writes',
        'preserve_evidence',
        'identify_failure_mode',
        'revert_or_propose_rollback',
        'update_docs_spec',
        'add_test_or_gate',
        'resume_with_smaller_receipt',
    ];

    // ---------------------------------------------------------------------
    // 1. Failure Table
    // ---------------------------------------------------------------------

    /**
     * Resolve one documented failure mode to its full row + flags.
     *
     * @return array<string,mixed>|null null when the key is not a known mode
     */
    public function describeFailure(string $mode): ?array
    {
        $key = $this->normalize($mode);
        if (! isset(self::FAILURE_TABLE[$key])) {
            return null;
        }

        $row = self::FAILURE_TABLE[$key];

        return [
            'mode' => $key,
            'signal' => $row['signal'],
            'countermeasure' => $row['countermeasure'],
            'most_dangerous' => in_array($key, self::MOST_DANGEROUS_MODES, true),
        ];
    }

    /**
     * Documented countermeasure for a failure mode ("Failure Table"), or null
     * when the mode is unknown.
     */
    public function countermeasureFor(string $mode): ?string
    {
        $key = $this->normalize($mode);

        return self::FAILURE_TABLE[$key]['countermeasure'] ?? null;
    }

    // ---------------------------------------------------------------------
    // 2. Red Flags — "Stop construction if" ANY holds
    // ---------------------------------------------------------------------

    /**
     * Evaluate a set of observed red flags and decide whether construction must
     * stop. The doc is categorical: ANY raised flag halts the run.
     *
     * Accepts either a list of flag keys (all treated as raised) or a map of
     * flag => bool. Unknown keys are reported, never silently treated as safe.
     *
     * @param array<int|string,mixed> $flags
     *
     * @return array<string,mixed>
     */
    public function evaluateRedFlags(array $flags): array
    {
        $raised = [];
        $unknown = [];

        foreach ($flags as $key => $value) {
            // List form: ['docs_code_disagree', ...] — value is the flag key,
            // membership means raised. Map form: ['docs_code_disagree' => true].
            if (is_int($key)) {
                $flagKey = is_string($value) ? $this->normalize($value) : '';
                $isRaised = true;
            } else {
                $flagKey = $this->normalize((string) $key);
                $isRaised = (bool) $value;
            }

            if ($flagKey === '') {
                continue;
            }
            if (! isset(self::RED_FLAGS[$flagKey])) {
                if (! in_array($flagKey, $unknown, true)) {
                    $unknown[] = $flagKey;
                }

                continue;
            }
            if ($isRaised && ! in_array($flagKey, $raised, true)) {
                $raised[] = $flagKey;
            }
        }

        $stop = $raised !== [];

        return [
            'schema' => self::SCHEMA,
            'surface' => 'red_flags',
            'stop_construction' => $stop,
            'can_proceed' => ! $stop,
            'raised_flags' => $raised,
            'raised_reasons' => array_values(array_map(
                static fn (string $flag): string => self::RED_FLAGS[$flag],
                $raised,
            )),
            'unknown_flags' => $unknown,
        ];
    }

    // ---------------------------------------------------------------------
    // 3. Required Incident Packet
    // ---------------------------------------------------------------------

    /**
     * Validate / build an incident packet ("Required Incident Packet"). Returns
     * the normalized packet, the list of missing fields, and `complete` only when
     * every documented field is present and non-empty.
     *
     * A failure that claims closure without a complete packet is the documented
     * False completeness mode — so `complete` is the gate the operator must pass.
     *
     * @param array<string,mixed> $packet
     *
     * @return array<string,mixed>
     */
    public function buildIncidentPacket(array $packet): array
    {
        $normalized = [];
        $missing = [];

        foreach (self::INCIDENT_PACKET_FIELDS as $field) {
            $value = $packet[$field] ?? null;
            $normalized[$field] = $value;

            if ($this->isEmptyField($value)) {
                $missing[] = $field;
            }
        }

        $complete = $missing === [];

        return [
            'schema' => self::SCHEMA,
            'surface' => 'incident_packet',
            'complete' => $complete,
            'missing_fields' => $missing,
            'required_fields' => self::INCIDENT_PACKET_FIELDS,
            'packet' => $normalized,
        ];
    }

    // ---------------------------------------------------------------------
    // 4. Recovery Order
    // ---------------------------------------------------------------------

    /**
     * Return the single next recovery step given the steps already completed,
     * enforcing the documented strict order ("Recovery Order").
     *
     * The contract: step N may only be taken once steps 1..N-1 are all complete.
     * Any completed step that is out of order (a later step done while an earlier
     * one is still pending — e.g. resuming before evidence is preserved) makes the
     * sequence invalid and the verdict refuses to advance.
     *
     * @param list<string> $completed completed recovery-step keys
     *
     * @return array<string,mixed>
     */
    public function nextRecoveryStep(array $completed): array
    {
        $done = [];
        $unknown = [];
        foreach ($completed as $step) {
            if (! is_string($step)) {
                continue;
            }
            $key = $this->normalize($step);
            if ($key === '') {
                continue;
            }
            if (! in_array($key, self::RECOVERY_ORDER, true)) {
                if (! in_array($key, $unknown, true)) {
                    $unknown[] = $key;
                }

                continue;
            }
            if (! in_array($key, $done, true)) {
                $done[] = $key;
            }
        }

        // Walk the canonical order: the first step not yet done is the next one.
        // A step done AFTER a not-yet-done step means the sequence was broken.
        $nextStep = null;
        $nextIndex = null;
        $ordered = true;
        $expectedSatisfied = true;

        foreach (self::RECOVERY_ORDER as $index => $step) {
            $isDone = in_array($step, $done, true);
            if ($isDone) {
                // A done step is only valid if every prior step is also done.
                if (! $expectedSatisfied) {
                    $ordered = false;
                }

                continue;
            }
            // First not-done step: this is the next required step.
            $expectedSatisfied = false;
            if ($nextStep === null) {
                $nextStep = $step;
                $nextIndex = $index;
            }
        }

        $allComplete = $nextStep === null && $ordered;

        return [
            'schema' => self::SCHEMA,
            'surface' => 'recovery_order',
            'ordered' => $ordered,
            'completed_steps' => $done,
            'next_step' => $ordered ? $nextStep : null,
            'next_step_number' => ($ordered && $nextIndex !== null) ? $nextIndex + 1 : null,
            'all_complete' => $allComplete,
            'total_steps' => count(self::RECOVERY_ORDER),
            'unknown_steps' => $unknown,
        ];
    }

    // ---------------------------------------------------------------------
    // 5. Batch stop/go verdict — combines red flags, incident packet, and
    //    recovery order into one deterministic autonomous_run_decision.
    // ---------------------------------------------------------------------

    /**
     * One deterministic stop/go verdict for an autonomous run, combining all three
     * documented surfaces (Red Flags, Required Incident Packet, Recovery Order).
     * Prefers stopping: ANY raised red flag stops the run outright; an incomplete
     * incident packet blocks resume even with zero red flags (when an incident
     * occurred); out-of-order recovery also blocks. Only when all three are clean
     * does the verdict allow continue.
     *
     * @param array<int|string,mixed> $redFlags observed red flags (list or map form, see evaluateRedFlags())
     * @param bool $incidentOccurred whether a failure/incident has occurred this run (gates the packet-completeness check)
     * @param array<string,mixed> $incidentPacket the incident packet, when $incidentOccurred is true
     * @param list<string> $completedRecoverySteps completed recovery-step keys, when $incidentOccurred is true
     *
     * @return array<string,mixed>
     */
    public function evaluateAutonomousRunDecision(
        array $redFlags = [],
        bool $incidentOccurred = false,
        array $incidentPacket = [],
        array $completedRecoverySteps = [],
    ): array {
        $redFlagResult = $this->evaluateRedFlags($redFlags);

        if ($redFlagResult['stop_construction']) {
            return [
                'schema' => self::SCHEMA,
                'surface' => 'autonomous_run_decision',
                'autonomous_run_decision' => 'stop_construction',
                'stop_reason' => 'red_flag_raised:'.implode(',', $redFlagResult['raised_flags']),
                'red_flags' => $redFlagResult,
                'incident_packet' => null,
                'recovery_order' => null,
            ];
        }

        if (! $incidentOccurred) {
            return [
                'schema' => self::SCHEMA,
                'surface' => 'autonomous_run_decision',
                'autonomous_run_decision' => 'continue',
                'stop_reason' => null,
                'red_flags' => $redFlagResult,
                'incident_packet' => null,
                'recovery_order' => null,
            ];
        }

        $packetResult = $this->buildIncidentPacket($incidentPacket);
        if (! $packetResult['complete']) {
            return [
                'schema' => self::SCHEMA,
                'surface' => 'autonomous_run_decision',
                'autonomous_run_decision' => 'stop_construction',
                'stop_reason' => 'incomplete_incident_packet:missing='.implode(',', $packetResult['missing_fields']),
                'red_flags' => $redFlagResult,
                'incident_packet' => $packetResult,
                'recovery_order' => null,
            ];
        }

        $recoveryResult = $this->nextRecoveryStep($completedRecoverySteps);
        if (! $recoveryResult['ordered']) {
            return [
                'schema' => self::SCHEMA,
                'surface' => 'autonomous_run_decision',
                'autonomous_run_decision' => 'stop_construction',
                'stop_reason' => 'recovery_order_violated',
                'red_flags' => $redFlagResult,
                'incident_packet' => $packetResult,
                'recovery_order' => $recoveryResult,
            ];
        }

        return [
            'schema' => self::SCHEMA,
            'surface' => 'autonomous_run_decision',
            'autonomous_run_decision' => 'continue',
            'stop_reason' => null,
            'red_flags' => $redFlagResult,
            'incident_packet' => $packetResult,
            'recovery_order' => $recoveryResult,
        ];
    }

    // ---------------------------------------------------------------------
    // Helpers
    // ---------------------------------------------------------------------

    private function normalize(string $value): string
    {
        return strtolower(trim($value));
    }

    /**
     * Whether an incident-packet field counts as empty (missing). Null, empty
     * string, whitespace-only string and empty array all count as missing.
     */
    private function isEmptyField(mixed $value): bool
    {
        if ($value === null) {
            return true;
        }
        if (is_string($value)) {
            return trim($value) === '';
        }
        if (is_array($value)) {
            return $value === [];
        }

        return false;
    }
}
