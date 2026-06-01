<?php

declare(strict_types=1);

namespace App\Services\Ai\Aaeos\Generated;

/**
 * Atlas AI Cognitive Runtime Failure Modes — pure, deterministic failure decider.
 *
 * The failure-modes matrix is the safety floor for the cognitive runtime
 * (memory, retrieval, long sessions, compaction, handoff and cognitive audit).
 * Where the runbook (sibling doc) decides the happy path — compaction review,
 * stop criteria, audit packet, promotion — this service turns the FAILURE
 * MATRIX, the SEVERITY ladder and the RECOVERY RULES into runtime. It is
 * read-only: given a set of observed failure signals it classifies each one,
 * resolves the documented required response and the recovery action, and emits
 * the worst-severity verdict plus the runtime posture the operator must adopt.
 * It never runs a provider, never writes evidence and never relaxes a gate.
 *
 * Three documented surfaces are implemented:
 *
 *   1. Failure Matrix ("Failure Matrix"). Fifteen failure modes, each carrying
 *      its documented signal, required response, severity and recovery rule. The
 *      severity of each mode is taken from its required-response column: a mode
 *      whose response is "critical; ..." is `critical`; a mode that must
 *      "bloquear" / "rejected" / be stopped before execution is `blocked`; the
 *      softer cost/efficiency modes degrade to `watch`. `classify()` walks a set
 *      of active modes and returns the single worst severity (info < watch <
 *      blocked < critical) — a `critical` mode is never masked by a `watch`.
 *
 *   2. Severity ladder ("Severity"). Four closed-set levels, each mapped to its
 *      documented runtime posture: info -> log, watch -> continue-with-warning,
 *      blocked -> stop-before-execution, critical -> stop-audit-repair. The two
 *      canonical decisions are honoured: cognitive failures degrade to
 *      read/plan/watch BEFORE affecting critical runtime, and a safe context
 *      failure is preferred over contaminated continuity (so `critical` and
 *      `blocked` both withhold execution; only `critical` additionally forces a
 *      repair + audit).
 *
 *   3. Recovery Rules ("Recovery Rules"). Six documented recovery actions, each
 *      keyed to the failure condition that triggers it (missing context, stale
 *      context, lost decisions, privacy issue, repetition loop, hot-file
 *      ambiguity). `recoveryFor()` returns the documented action for a mode.
 *
 * Non-goals honoured ("Regras para IA" / forbidden_changes):
 *   - does NOT declare runtime / maturity / readiness — it only reports the
 *     posture the matrix demands; the operator acts;
 *   - prefers a safe context failure over contaminated continuity;
 *   - an audit score never auto-applies a behaviour change (anti-pattern), so
 *     the audit posture is always proposal-only / read-only.
 *
 * @see docs/engineering-knowledge-base/cognitive-runtime/failure-modes.md
 * @see docs/engineering-knowledge-base/cognitive-runtime/runbook.md
 */
final class AtlasCognitiveRuntimeFailureModesService
{
    /** Canonical schema id for this decider's envelopes. */
    public const FAILURE_MODES_SCHEMA = 'atlas.cognitive_runtime.failure_modes.v1';

    // --- Severity ladder (closed set; "Severity", weakest -> strongest). -------
    public const SEVERITY_INFO = 'info';
    public const SEVERITY_WATCH = 'watch';
    public const SEVERITY_BLOCKED = 'blocked';
    public const SEVERITY_CRITICAL = 'critical';

    /**
     * The four severity levels in documented order, lowest impact first. The
     * index is the comparison rank used to pick the single worst active mode.
     *
     * @var list<string>
     */
    public const SEVERITY_ORDER = [
        self::SEVERITY_INFO,
        self::SEVERITY_WATCH,
        self::SEVERITY_BLOCKED,
        self::SEVERITY_CRITICAL,
    ];

    /**
     * Runtime posture per severity ("Severity" · "Runtime posture"). These are
     * the documented postures: info registers, watch continues with a warning,
     * blocked stops before execution, critical stops + audits + repairs.
     *
     * @var array<string,string>
     */
    public const SEVERITY_POSTURE = [
        self::SEVERITY_INFO => 'log',
        self::SEVERITY_WATCH => 'continue_with_warning',
        self::SEVERITY_BLOCKED => 'stop_before_execution',
        self::SEVERITY_CRITICAL => 'stop_audit_repair',
    ];

    // --- Failure-mode keys (closed set; "Failure Matrix", documented order). ---
    public const MODE_LOST_OBJECTIVE = 'lost_objective';
    public const MODE_HOT_FILE_AMBIGUITY = 'hot_file_ambiguity';
    public const MODE_MISSING_EVIDENCE_REFS = 'missing_evidence_refs';
    public const MODE_STALE_CANONICAL_DOC = 'stale_canonical_doc';
    public const MODE_RAW_CAPTURE_ADMITTED = 'raw_capture_admitted';
    public const MODE_PROVIDER_MEMORY_MERGE = 'provider_memory_merge';
    public const MODE_RETRIEVAL_WITHOUT_REASON = 'retrieval_without_reason';
    public const MODE_CONTEXT_OVERSTUFFING = 'context_overstuffing';
    public const MODE_CRITICAL_CONTEXT_MISSED = 'critical_context_missed';
    public const MODE_COMPACTION_REQUIRES_CHAT = 'compaction_requires_chat';
    public const MODE_HANDOFF_WITHOUT_RECEIPT = 'handoff_without_receipt';
    public const MODE_PRIVACY_LEAK = 'privacy_leak';
    public const MODE_MEMORY_HARM = 'memory_harm';
    public const MODE_REPEAT_WORK_LOOP = 'repeat_work_loop';
    public const MODE_COST_WITHOUT_GAIN = 'cost_without_gain';

    /**
     * The full Failure Matrix ("Failure Matrix"), one row per documented mode.
     * Each row carries the documented signal, required response, the severity
     * implied by that response, and the recovery-rule key that applies.
     *
     * Severity assignment (from the "Required response" column):
     *   - critical : raw_capture_admitted, critical_context_missed, privacy_leak
     *                (responses explicitly say "critical; ...").
     *   - blocked  : lost_objective, missing_evidence_refs, provider_memory_merge,
     *                compaction_requires_chat, handoff_without_receipt
     *                (responses "bloquear" / "rejected" / "emitir receipt antes
     *                 de agir" — continuity must stop before execution).
     *   - watch    : hot_file_ambiguity (read-only, degrade), stale_canonical_doc
     *                (refresh), retrieval_without_reason (bug, narrow),
     *                context_overstuffing (compact/rerank), memory_harm
     *                (tombstone candidate), repeat_work_loop (audit watch),
     *                cost_without_gain (reduce budget) — quality degrades but
     *                continuity is not unsafe.
     *
     * @var array<string,array{signal:string,required_response:string,severity:string,recovery:string}>
     */
    public const FAILURE_MATRIX = [
        self::MODE_LOST_OBJECTIVE => [
            'signal' => 'summary changes the objective with no decision',
            'required_response' => 'block continuity; request a fresh snapshot',
            'severity' => self::SEVERITY_BLOCKED,
            'recovery' => 'lost_decisions',
        ],
        self::MODE_HOT_FILE_AMBIGUITY => [
            'signal' => 'hot file absent or uncertain',
            'required_response' => 'read-only until ownership is rebuilt',
            'severity' => self::SEVERITY_WATCH,
            'recovery' => 'hot_file_ambiguity',
        ],
        self::MODE_MISSING_EVIDENCE_REFS => [
            'signal' => 'compaction without refs',
            'required_response' => 'block handoff',
            'severity' => self::SEVERITY_BLOCKED,
            'recovery' => 'missing_context',
        ],
        self::MODE_STALE_CANONICAL_DOC => [
            'signal' => 'stale doc outranks the active doc',
            'required_response' => 'refresh context pack',
            'severity' => self::SEVERITY_WATCH,
            'recovery' => 'stale_context',
        ],
        self::MODE_RAW_CAPTURE_ADMITTED => [
            'signal' => 'raw/unclassified capture becomes context',
            'required_response' => 'critical; remove and audit',
            'severity' => self::SEVERITY_CRITICAL,
            'recovery' => 'privacy_issue',
        ],
        self::MODE_PROVIDER_MEMORY_MERGE => [
            'signal' => 'provider file becomes the primary source',
            'required_response' => 'block; use the Atlas memory source',
            'severity' => self::SEVERITY_BLOCKED,
            'recovery' => 'missing_context',
        ],
        self::MODE_RETRIEVAL_WITHOUT_REASON => [
            'signal' => 'ref without a reason',
            'required_response' => 'retrieval bug',
            'severity' => self::SEVERITY_WATCH,
            'recovery' => 'missing_context',
        ],
        self::MODE_CONTEXT_OVERSTUFFING => [
            'signal' => 'budget dominated by logs/raw docs',
            'required_response' => 'compact; rerank',
            'severity' => self::SEVERITY_WATCH,
            'recovery' => 'stale_context',
        ],
        self::MODE_CRITICAL_CONTEXT_MISSED => [
            'signal' => 'action-packet / test / hot file absent',
            'required_response' => 'critical context-quality failure',
            'severity' => self::SEVERITY_CRITICAL,
            'recovery' => 'missing_context',
        ],
        self::MODE_COMPACTION_REQUIRES_CHAT => [
            'signal' => 'executor needs the raw chat',
            'required_response' => 'compaction rejected',
            'severity' => self::SEVERITY_BLOCKED,
            'recovery' => 'lost_decisions',
        ],
        self::MODE_HANDOFF_WITHOUT_RECEIPT => [
            'signal' => 'provider/surface changed without evidence',
            'required_response' => 'emit a receipt before acting',
            'severity' => self::SEVERITY_BLOCKED,
            'recovery' => 'lost_decisions',
        ],
        self::MODE_PRIVACY_LEAK => [
            'signal' => 'secret/private data in a provider-safe surface',
            'required_response' => 'critical; redact and audit',
            'severity' => self::SEVERITY_CRITICAL,
            'recovery' => 'privacy_issue',
        ],
        self::MODE_MEMORY_HARM => [
            'signal' => 'memory makes the decision worse',
            'required_response' => 'downgrade/tombstone the candidate',
            'severity' => self::SEVERITY_WATCH,
            'recovery' => 'repetition_loop',
        ],
        self::MODE_REPEAT_WORK_LOOP => [
            'signal' => 'the same investigation reappears',
            'required_response' => 'audit-packet watch',
            'severity' => self::SEVERITY_WATCH,
            'recovery' => 'repetition_loop',
        ],
        self::MODE_COST_WITHOUT_GAIN => [
            'signal' => 'cost grows without useful refs',
            'required_response' => 'reduce budget/retrieval',
            'severity' => self::SEVERITY_WATCH,
            'recovery' => 'repetition_loop',
        ],
    ];

    /**
     * Recovery Rules ("Recovery Rules"), keyed by recovery condition. The map
     * value is the documented recovery action.
     *
     * @var array<string,string>
     */
    public const RECOVERY_RULES = [
        'missing_context' => 'refresh Open Brain and rerun retrieval',
        'stale_context' => 'prefer the canonical active doc and record the stale exclusion',
        'lost_decisions' => 'use ledger/receipt refs, not chat memory',
        'privacy_issue' => 'redact, tombstone the unsafe packet, emit an audit',
        'repetition_loop' => 'produce a handoff summary and narrow the next action',
        'hot_file_ambiguity' => 'require git status --short and an owner report',
    ];

    /**
     * Modes whose required response is `critical` — they additionally force an
     * audit + repair, not just a stop ("Severity" · critical posture).
     *
     * @var list<string>
     */
    public const CRITICAL_MODES = [
        self::MODE_RAW_CAPTURE_ADMITTED,
        self::MODE_CRITICAL_CONTEXT_MISSED,
        self::MODE_PRIVACY_LEAK,
    ];

    // ---------------------------------------------------------------------
    // 1. Single-mode lookup
    // ---------------------------------------------------------------------

    /**
     * Resolve one documented failure mode to its full matrix row + posture.
     *
     * @return array<string,mixed>|null null when the key is not a known mode
     */
    public function describeMode(string $mode): ?array
    {
        $key = strtolower(trim($mode));
        if (! isset(self::FAILURE_MATRIX[$key])) {
            return null;
        }

        $row = self::FAILURE_MATRIX[$key];
        $severity = $row['severity'];

        return [
            'mode' => $key,
            'signal' => $row['signal'],
            'required_response' => $row['required_response'],
            'severity' => $severity,
            'posture' => self::SEVERITY_POSTURE[$severity],
            'withholds_execution' => $this->withholdsExecution($severity),
            'requires_audit' => $severity === self::SEVERITY_CRITICAL,
            'recovery' => $this->recoveryFor($key),
        ];
    }

    /**
     * Documented recovery action for a mode ("Recovery Rules"), or null if the
     * mode is unknown.
     */
    public function recoveryFor(string $mode): ?string
    {
        $key = strtolower(trim($mode));
        if (! isset(self::FAILURE_MATRIX[$key])) {
            return null;
        }

        $recoveryKey = self::FAILURE_MATRIX[$key]['recovery'];

        return self::RECOVERY_RULES[$recoveryKey] ?? null;
    }

    /**
     * Documented runtime posture for a severity ("Severity"), or null when the
     * severity is outside the closed set.
     */
    public function postureForSeverity(string $severity): ?string
    {
        return self::SEVERITY_POSTURE[strtolower(trim($severity))] ?? null;
    }

    // ---------------------------------------------------------------------
    // 2. Classify a set of active failure modes
    // ---------------------------------------------------------------------

    /**
     * Classify a set of observed/active failure modes and decide the runtime
     * posture for the whole session.
     *
     * The verdict severity is the SINGLE WORST active mode (info < watch <
     * blocked < critical), so a `critical` privacy leak is never masked by a
     * `watch` cost signal. Execution is withheld when the worst severity is
     * `blocked` or `critical` (a safe context failure is preferred over
     * contaminated continuity); an audit/repair is additionally required when any
     * `critical` mode is present.
     *
     * Unknown mode keys are ignored but reported under `unknown_modes`, never
     * silently treated as safe.
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
            $key = strtolower(trim($mode));
            if ($key === '') {
                continue;
            }
            if (isset(self::FAILURE_MATRIX[$key])) {
                if (! in_array($key, $known, true)) {
                    $known[] = $key;
                }
            } elseif (! in_array($key, $unknown, true)) {
                $unknown[] = $key;
            }
        }

        $details = [];
        $worstRank = -1;
        $worstSeverity = self::SEVERITY_INFO;
        $criticalModes = [];

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
            if ($severity === self::SEVERITY_CRITICAL) {
                $criticalModes[] = $key;
            }
        }

        // No known active failure -> the session is clean: info / log / continue.
        if ($known === []) {
            $worstSeverity = self::SEVERITY_INFO;
        }

        $withholdExecution = $this->withholdsExecution($worstSeverity);
        $requiresAudit = $criticalModes !== [];

        return [
            'schema' => self::FAILURE_MODES_SCHEMA,
            'surface' => 'failure_matrix',
            'severity' => $worstSeverity,
            'posture' => self::SEVERITY_POSTURE[$worstSeverity],
            'withhold_execution' => $withholdExecution,
            'can_proceed' => ! $withholdExecution,
            'requires_audit' => $requiresAudit,
            'active_modes' => $known,
            'critical_modes' => $criticalModes,
            'unknown_modes' => $unknown,
            'recovery_actions' => $this->recoveryActionsFor($known),
            'details' => $details,
        ];
    }

    /**
     * The de-duplicated, documented recovery actions for a set of modes, in
     * first-seen order.
     *
     * @param list<string> $modes known mode keys
     * @return list<string>
     */
    public function recoveryActionsFor(array $modes): array
    {
        $actions = [];
        foreach ($modes as $mode) {
            $action = $this->recoveryFor($mode);
            if ($action !== null && ! in_array($action, $actions, true)) {
                $actions[] = $action;
            }
        }

        return $actions;
    }

    // ---------------------------------------------------------------------
    // Helpers
    // ---------------------------------------------------------------------

    /**
     * Whether a severity withholds execution. Both `blocked` and `critical`
     * stop before execution; `info` and `watch` may proceed (the latter with a
     * warning). This is the "safe context failure over contaminated continuity"
     * rule made concrete.
     */
    private function withholdsExecution(string $severity): bool
    {
        return $severity === self::SEVERITY_BLOCKED || $severity === self::SEVERITY_CRITICAL;
    }

    /** Comparison rank for a severity (its index in SEVERITY_ORDER; -1 if unknown). */
    private function severityRank(string $severity): int
    {
        $rank = array_search($severity, self::SEVERITY_ORDER, true);

        return $rank === false ? -1 : $rank;
    }
}
