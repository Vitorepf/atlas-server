<?php

declare(strict_types=1);

namespace App\Services\Ai\Aaeos\Quarantine;

/**
 * Atlas AI Cognitive Runtime Runbook — pure, deterministic operational decider.
 *
 * The runbook is the operating procedure for long engineering sessions:
 * compaction review, stop / downgrade-to-watch criteria, the read-only
 * post-session audit packet, and the promotion gate that lets a long session
 * become maturity evidence. This service turns those documented rules into
 * runtime. It is read-only: it classifies a session's signals and emits a
 * verdict plus the documented blocking reasons; it never runs a provider, never
 * writes evidence, never relaxes a gate and never expands scope.
 *
 * Four documented decision surfaces are implemented, one cluster of methods
 * each. Field names and closed-set status values are taken from the canonical
 * packet schemas (schemas-and-packets.md), so callers/tests pin the exact
 * documented shapes rather than invented ones.
 *
 *   1. Compaction Review ("Compaction Review"). A compaction is acceptable only
 *      when it preserves the ten documented fields (objective, phase, hot files,
 *      ownership, decisions, rejected alternatives, evidence refs, validation
 *      status, next action, forbidden actions). It is BLOCKED when any of the
 *      five documented unsafe conditions holds — and each maps to the exact
 *      compaction-packet status: needs raw chat / missing evidence refs /
 *      ambiguous policy-receipt-ledger / privacy not redacted / canonical doc
 *      contradicts the summary.
 *
 *   2. Stop Criteria ("Stop Criteria"). Seven documented triggers stop the
 *      session or downgrade it to `watch`. The runbook is explicit that
 *      objective drift counts only on its SECOND appearance ("drift de objetivo
 *      aparece duas vezes"), so a single drift is tolerated and the second flips
 *      the verdict. An unsafe-provider-context or a hot-file-edited-by-mistake
 *      trigger is treated as hard `blocked` (continuity is unsafe), the softer
 *      efficiency triggers degrade to `watch`.
 *
 *   3. Post-Session Audit ("Post-Session Audit" + audit_packet schema). Emits a
 *      read-only audit packet: net_value, gain, harm, recommendations
 *      (proposal-only) and evidence refs. Status is `ready` when there is no
 *      harm, `critical` when a hard harm (lost decision / policy violation /
 *      contamination) occurred, otherwise `watch`.
 *
 *   4. Promotion ("Promotion" + Promotion Rule). A long session becomes maturity
 *      evidence ONLY when all five gates hold: snapshot complete, compaction
 *      packet ready, audit packet ready (or watch with accepted risks),
 *      validations passed, and replay can rebuild state without raw chat.
 *
 * Non-goals honoured ("Regras para IA" / "Escopo de Implementacao" / forbidden
 * changes):
 *   - does NOT declare runtime / maturity / readiness — it only reports whether
 *     the documented gates are green; the operator promotes;
 *   - does NOT accept continuity when evidence refs are missing;
 *   - does NOT execute a provider, write the ledger or relax privacy redaction.
 *
 * @see docs/engineering-knowledge-base/cognitive-runtime/runbook.md
 * @see docs/engineering-knowledge-base/cognitive-runtime/schemas-and-packets.md
 */
final class AtlasCognitiveRuntimeRunbookService
{
    /** Canonical packet schema ids (schemas-and-packets.md). */
    public const COMPACTION_SCHEMA = 'atlas.cognitive_runtime.compaction_packet.v1';
    public const AUDIT_SCHEMA = 'atlas.cognitive_runtime.audit_packet.v1';
    public const RUNBOOK_SCHEMA = 'atlas.cognitive_runtime.runbook.v1';

    // --- Compaction-review verdicts (closed set; mirror packet status). -------
    public const COMPACTION_READY = 'ready';
    public const COMPACTION_BLOCKED_MISSING_EVIDENCE = 'blocked_missing_evidence';
    public const COMPACTION_BLOCKED_HOT_FILES_AMBIGUOUS = 'blocked_hot_files_ambiguous';
    public const COMPACTION_BLOCKED_POLICY_GAP = 'blocked_policy_gap';
    public const COMPACTION_BLOCKED_PRIVACY_GAP = 'blocked_privacy_gap';
    public const COMPACTION_BLOCKED_CANONICAL_CONFLICT = 'blocked_canonical_conflict';

    // --- Session continuity verdicts (closed set; failure-modes.md). ----------
    public const SESSION_CONTINUE = 'continue';
    public const SESSION_WATCH = 'watch';
    public const SESSION_BLOCKED = 'blocked';

    // --- Audit-packet status (closed set; schemas-and-packets.md). ------------
    public const AUDIT_READY = 'ready';
    public const AUDIT_WATCH = 'watch';
    public const AUDIT_CRITICAL = 'critical';

    /**
     * The ten fields a compaction MUST preserve to be acceptable
     * ("Aceitar compactacao somente se ela preservar: ..."), in documented order.
     *
     * @var list<string>
     */
    public const REQUIRED_PRESERVED_FIELDS = [
        'objective',
        'phase',
        'hot_files',
        'ownership',
        'decisions',
        'rejected_alternatives',
        'evidence_refs',
        'validation_status',
        'next_action',
        'forbidden_actions',
    ];

    /**
     * The seven documented Stop-Criteria triggers ("Parar ou rebaixar para
     * `watch` quando: ..."), in documented order.
     *
     * @var list<string>
     */
    public const STOP_TRIGGERS = [
        'objective_drift',          // counts only on the SECOND appearance
        'repeated_work_no_evidence',
        'stale_context_in_prompt',
        'hot_file_edited_by_mistake',
        'unsafe_provider_context',
        'cost_up_without_gain',
        'manual_state_rebuild',
    ];

    /**
     * Stop triggers that make continuity UNSAFE -> hard `blocked` (not just a
     * degraded `watch`): a hot file was edited by mistake, or a provider received
     * unsafe context. The remaining triggers degrade quality -> `watch`.
     *
     * @var list<string>
     */
    public const BLOCKING_STOP_TRIGGERS = [
        'hot_file_edited_by_mistake',
        'unsafe_provider_context',
    ];

    /**
     * Number of objective-drift occurrences required before the session must
     * stop ("drift de objetivo aparece duas vezes"). One drift is tolerated.
     */
    public const DRIFT_STOP_THRESHOLD = 2;

    /**
     * The five gates a long session must clear to become maturity evidence
     * ("Promotion"), in documented order.
     *
     * @var list<string>
     */
    public const PROMOTION_GATES = [
        'snapshot_complete',
        'compaction_packet_ready',
        'audit_packet_ready_or_watch_justified',
        'validations_passed',
        'replay_reconstructs_without_raw_chat',
    ];

    /**
     * Harms that, if present, force the audit packet to `critical` rather than a
     * recoverable `watch` (audit_packet `harm` block, hard subset).
     *
     * @var list<string>
     */
    public const CRITICAL_HARMS = [
        'lost_decision',
        'policy_violation',
        'context_contamination',
    ];

    // ---------------------------------------------------------------------
    // 1. Compaction Review
    // ---------------------------------------------------------------------

    /**
     * Decide whether a proposed compaction may be accepted.
     *
     * @param array<string,mixed> $compaction
     *        preserved        : array<string,bool>  per-field preservation flags
     *                           (keys = REQUIRED_PRESERVED_FIELDS)
     *        needs_raw_chat   : bool  next executor would need the raw chat
     *        evidence_refs    : list  ledger/trace/test/file/AP refs
     *        policy_ambiguous : bool  policy/receipt/ledger left ambiguous
     *        privacy_redacted : bool  private data was redacted (default false)
     *        canonical_conflict: bool canonical doc contradicts the summary
     *
     * @return array<string,mixed> the compaction-review decision + audit fields
     */
    public function reviewCompaction(array $compaction): array
    {
        $preserved = is_array($compaction['preserved'] ?? null) ? $compaction['preserved'] : [];
        $missingFields = [];
        foreach (self::REQUIRED_PRESERVED_FIELDS as $field) {
            if (! (bool) ($preserved[$field] ?? false)) {
                $missingFields[] = $field;
            }
        }

        $evidenceRefs = is_array($compaction['evidence_refs'] ?? null)
            ? array_values(array_filter($compaction['evidence_refs'], static fn ($r): bool => is_string($r) && trim($r) !== ''))
            : [];

        $needsRawChat = (bool) ($compaction['needs_raw_chat'] ?? false);
        $policyAmbiguous = (bool) ($compaction['policy_ambiguous'] ?? false);
        $privacyRedacted = (bool) ($compaction['privacy_redacted'] ?? false);
        $canonicalConflict = (bool) ($compaction['canonical_conflict'] ?? false);

        $reasons = [];
        $status = self::COMPACTION_READY;

        // Block conditions, evaluated in the doc's order. The first hard block
        // sets the canonical packet status; every failing condition is recorded
        // in `reasons` so the operator sees the full picture.
        if ($needsRawChat) {
            // "proximo executor precisaria do chat bruto" is a continuity gap; it
            // is captured under the policy-gap status (ambiguous handoff state).
            $reasons[] = 'next_executor_needs_raw_chat';
            $status = self::COMPACTION_BLOCKED_POLICY_GAP;
        }

        if ($evidenceRefs === []) {
            $reasons[] = 'evidence_refs_missing';
            $status = self::COMPACTION_BLOCKED_MISSING_EVIDENCE;
        }

        if ($policyAmbiguous) {
            $reasons[] = 'policy_receipt_ledger_ambiguous';
            if ($status === self::COMPACTION_READY) {
                $status = self::COMPACTION_BLOCKED_POLICY_GAP;
            }
        }

        if (! $privacyRedacted) {
            $reasons[] = 'privacy_not_redacted';
            $status = self::COMPACTION_BLOCKED_PRIVACY_GAP;
        }

        if ($canonicalConflict) {
            $reasons[] = 'canonical_doc_contradicts_summary';
            $status = self::COMPACTION_BLOCKED_CANONICAL_CONFLICT;
        }

        if ($missingFields !== []) {
            $reasons[] = 'incomplete_preserved_fields';
            // A summary that drops hot files / ownership is itself a hot-files /
            // policy ambiguity; only override a still-ready status so an explicit
            // privacy/evidence/canonical block keeps its more specific code.
            if ($status === self::COMPACTION_READY) {
                $status = self::COMPACTION_BLOCKED_HOT_FILES_AMBIGUOUS;
            }
        }

        $accepted = $status === self::COMPACTION_READY;

        return [
            'schema' => self::COMPACTION_SCHEMA,
            'surface' => 'compaction_review',
            'status' => $status,
            'accepted' => $accepted,
            'missing_preserved_fields' => $missingFields,
            'preserved_count' => count(self::REQUIRED_PRESERVED_FIELDS) - count($missingFields),
            'evidence_ref_count' => count($evidenceRefs),
            'blocked_reasons' => $reasons,
        ];
    }

    /** Convenience predicate: may this compaction be written? */
    public function compactionAccepted(array $compaction): bool
    {
        return $this->reviewCompaction($compaction)['accepted'] === true;
    }

    // ---------------------------------------------------------------------
    // 2. Stop Criteria
    // ---------------------------------------------------------------------

    /**
     * Decide whether a long session must continue, downgrade to `watch`, or hard
     * `block` before execution.
     *
     * @param array<string,mixed> $signals
     *        objective_drift_count      : int   how many distinct drifts so far
     *        repeated_work_no_evidence  : bool
     *        stale_context_in_prompt    : bool
     *        hot_file_edited_by_mistake : bool
     *        unsafe_provider_context    : bool
     *        cost_up_without_gain       : bool
     *        manual_state_rebuild       : bool
     *
     * @return array<string,mixed>
     */
    public function evaluateStopCriteria(array $signals): array
    {
        $driftCount = max(0, (int) ($signals['objective_drift_count'] ?? 0));

        $triggered = [];

        // Objective drift only stops on its SECOND appearance.
        if ($driftCount >= self::DRIFT_STOP_THRESHOLD) {
            $triggered[] = 'objective_drift';
        }

        $boolTriggers = [
            'repeated_work_no_evidence',
            'stale_context_in_prompt',
            'hot_file_edited_by_mistake',
            'unsafe_provider_context',
            'cost_up_without_gain',
            'manual_state_rebuild',
        ];
        foreach ($boolTriggers as $trigger) {
            if ((bool) ($signals[$trigger] ?? false)) {
                $triggered[] = $trigger;
            }
        }

        $hasBlocking = array_intersect($triggered, self::BLOCKING_STOP_TRIGGERS) !== [];

        if ($hasBlocking) {
            $verdict = self::SESSION_BLOCKED;
        } elseif ($triggered !== []) {
            $verdict = self::SESSION_WATCH;
        } else {
            $verdict = self::SESSION_CONTINUE;
        }

        return [
            'schema' => self::RUNBOOK_SCHEMA,
            'surface' => 'stop_criteria',
            'verdict' => $verdict,
            'should_stop' => $verdict !== self::SESSION_CONTINUE,
            'objective_drift_count' => $driftCount,
            'drift_threshold' => self::DRIFT_STOP_THRESHOLD,
            'triggered' => array_values($triggered),
            'blocking_triggers' => array_values(array_intersect($triggered, self::BLOCKING_STOP_TRIGGERS)),
        ];
    }

    // ---------------------------------------------------------------------
    // 3. Post-Session Audit
    // ---------------------------------------------------------------------

    /**
     * Emit the read-only post-session cognitive audit packet.
     *
     * The audit packet is proposal-only: `recommendations` are next-action
     * proposals, never executed here. Status is `critical` when any hard harm
     * occurred (lost decision / policy violation / contamination), `ready` when
     * there is no harm at all, otherwise `watch`.
     *
     * @param array<string,mixed> $session
     *        subject_type    : string  long_session|compaction|handoff|retrieval
     *        subject_id      : string
     *        gain            : array{useful_context?:int,repeated_work_avoided?:int,decision_reuse?:int}
     *        harm            : array{wrong_context?:int,stale_context?:int,context_contamination?:int,lost_decision?:int,policy_violation?:int}
     *        recommendations : list<string>  proposal-only
     *        evidence_refs   : list<string>
     *
     * @return array<string,mixed>
     */
    public function evaluateAudit(array $session): array
    {
        $subjectType = is_string($session['subject_type'] ?? null) && trim((string) $session['subject_type']) !== ''
            ? strtolower(trim((string) $session['subject_type']))
            : 'long_session';

        $gain = $this->intBlock($session['gain'] ?? [], [
            'useful_context', 'repeated_work_avoided', 'decision_reuse',
        ]);
        $harm = $this->intBlock($session['harm'] ?? [], [
            'wrong_context', 'stale_context', 'context_contamination', 'lost_decision', 'policy_violation',
        ]);

        $gainTotal = array_sum($gain);
        $harmTotal = array_sum($harm);
        $netValue = $gainTotal - $harmTotal;

        $criticalHarms = [];
        foreach (self::CRITICAL_HARMS as $key) {
            if (($harm[$key] ?? 0) > 0) {
                $criticalHarms[] = $key;
            }
        }

        if ($criticalHarms !== []) {
            $status = self::AUDIT_CRITICAL;
        } elseif ($harmTotal === 0) {
            $status = self::AUDIT_READY;
        } else {
            $status = self::AUDIT_WATCH;
        }

        $recommendations = is_array($session['recommendations'] ?? null)
            ? array_values(array_filter(
                $session['recommendations'],
                static fn ($r): bool => is_string($r) && trim($r) !== ''
            ))
            : [];

        $evidenceRefs = is_array($session['evidence_refs'] ?? null)
            ? array_values(array_filter(
                $session['evidence_refs'],
                static fn ($r): bool => is_string($r) && trim($r) !== ''
            ))
            : [];

        return [
            'schema' => self::AUDIT_SCHEMA,
            'surface' => 'post_session_audit',
            'subject_type' => $subjectType,
            'subject_id' => is_string($session['subject_id'] ?? null) ? (string) $session['subject_id'] : null,
            'status' => $status,
            'net_value' => $netValue,
            'gain' => $gain,
            'harm' => $harm,
            'critical_harms' => $criticalHarms,
            'recommendations' => $recommendations,
            'read_only' => true,
            'evidence_refs' => $evidenceRefs,
        ];
    }

    // ---------------------------------------------------------------------
    // 4. Promotion
    // ---------------------------------------------------------------------

    /**
     * Decide whether a long session may be promoted to maturity evidence.
     *
     * All five documented gates must hold. The audit gate is satisfied when the
     * audit packet is `ready`, OR `watch` with risks explicitly accepted by the
     * human operator ("audit packet esta ready ou watch justificado").
     *
     * @param array<string,mixed> $session
     *        snapshot_complete       : bool
     *        compaction_status       : string  a COMPACTION_* status
     *        audit_status            : string  ready|watch|critical
     *        audit_risks_accepted    : bool   operator accepted watch risks
     *        validations_passed      : bool
     *        replay_reconstructs     : bool   replay rebuilds state w/o raw chat
     *
     * @return array<string,mixed>
     */
    public function evaluatePromotion(array $session): array
    {
        $compactionStatus = is_string($session['compaction_status'] ?? null)
            ? strtolower(trim((string) $session['compaction_status']))
            : '';
        $auditStatus = is_string($session['audit_status'] ?? null)
            ? strtolower(trim((string) $session['audit_status']))
            : '';
        $auditRisksAccepted = (bool) ($session['audit_risks_accepted'] ?? false);

        $auditGateMet = $auditStatus === self::AUDIT_READY
            || ($auditStatus === self::AUDIT_WATCH && $auditRisksAccepted);

        $gates = [
            'snapshot_complete' => (bool) ($session['snapshot_complete'] ?? false),
            'compaction_packet_ready' => $compactionStatus === self::COMPACTION_READY,
            'audit_packet_ready_or_watch_justified' => $auditGateMet,
            'validations_passed' => (bool) ($session['validations_passed'] ?? false),
            'replay_reconstructs_without_raw_chat' => (bool) ($session['replay_reconstructs'] ?? false),
        ];

        $unmet = [];
        foreach (self::PROMOTION_GATES as $gate) {
            if (! ($gates[$gate] ?? false)) {
                $unmet[] = $gate;
            }
        }

        $promote = $unmet === [];

        return [
            'schema' => self::RUNBOOK_SCHEMA,
            'surface' => 'promotion',
            'promote' => $promote,
            'gates' => $gates,
            'unmet_gates' => $unmet,
            'gates_met' => count(self::PROMOTION_GATES) - count($unmet),
            'gates_total' => count(self::PROMOTION_GATES),
        ];
    }

    /** Convenience predicate: may this session become maturity evidence? */
    public function mayPromote(array $session): bool
    {
        return $this->evaluatePromotion($session)['promote'] === true;
    }

    // ---------------------------------------------------------------------
    // Helpers
    // ---------------------------------------------------------------------

    /**
     * Coerce an input map into a fixed-key block of non-negative ints.
     *
     * @param mixed $raw
     * @param list<string> $keys
     * @return array<string,int>
     */
    private function intBlock(mixed $raw, array $keys): array
    {
        $raw = is_array($raw) ? $raw : [];
        $block = [];
        foreach ($keys as $key) {
            $block[$key] = max(0, (int) ($raw[$key] ?? 0));
        }

        return $block;
    }
}
