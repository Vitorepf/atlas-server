<?php

declare(strict_types=1);

namespace App\Services\Ai\Aaeos\Quarantine;

/**
 * Legacy Cleanup Waves And Gates sequencer.
 *
 * Pure, deterministic decider that turns the documented cleanup pipeline into a
 * contract. The doc defines eight ORDERED waves, each with a single named Gate
 * that must pass before the session may advance to the next wave, plus a strict
 * four-step Rollback procedure when a cleanup creates confusion.
 *
 * The waves, in canonical doc order, are:
 *   0    Freeze Authority         gate: can answer "which doc wins in conflict?"
 *   1    Promote Stable Decisions gate: promoted doc passes line limits + owner.
 *   2    Merge Partial Duplicates gate: no sentence creates a new master flow.
 *   2.5  Normalize Domain Docs    gate: domain stays below Kernel/Master/Pipeline.
 *   3    Redirect And Archive     gate: old doc clearly points to the replacement.
 *   4    Quarantine Delete Cand.  gate: delete waits for a separate human change.
 *   5    Human Knowledge Surface  gate: provider-safe content is explicit.
 *
 * Two invariants from the frontmatter constrain every wave:
 *   - "Runtime changes are outside cleanup scope unless explicitly declared."
 *   - "Cleanup waves must be small, reversible and validated."
 * A wave that touched runtime without an explicit declaration, or that is not
 * reversible, fails its gate regardless of the wave-specific evidence.
 *
 * This service NEVER reads a doc, runs a command, or touches git. It consumes
 * already-normalized booleans/strings describing one wave's evidence and emits a
 * single gate verdict, the allowed next wave, and an audit receipt. Gates are
 * sequential: the doc lists the waves in order, so the next wave is the next id
 * in the canonical order, and the pipeline is "complete" only when the final
 * wave (5) has passed its gate.
 *
 * @see docs/engineering-knowledge-base/legacy-cleanup/waves-and-gates.md
 */
final class AtlasWavesAndGatesService
{
    /** Stable receipt schema id for the verdict this service emits. */
    public const RECEIPT_SCHEMA = 'atlas.legacy_cleanup.waves_and_gates.v1';

    /** Closed set of gate verdicts for a single wave. */
    public const GATE_PASS = 'pass';
    public const GATE_BLOCKED = 'blocked';

    /** Required next action per gate verdict. */
    public const NEXT_ADVANCE = 'advance_to_next_wave';
    public const NEXT_STAY = 'stay_and_satisfy_gate';
    public const NEXT_PIPELINE_COMPLETE = 'cleanup_pipeline_complete';

    /**
     * The eight waves in canonical doc order. The key is the stable wave id
     * (string so "2.5" is exact); the value is the human goal. Order in this
     * array IS the execution order — advancement always moves to the next key.
     *
     * @var array<string,string>
     */
    private const WAVE_ORDER = [
        '0' => 'Freeze Authority',
        '1' => 'Promote Stable Decisions',
        '2' => 'Merge Partial Duplicates',
        '2.5' => 'Normalize Domain Docs',
        '3' => 'Redirect And Archive',
        '4' => 'Quarantine Delete Candidates',
        '5' => 'Human Knowledge Surface',
    ];

    /**
     * The single Gate sentence the doc gives each wave. This is the wave-specific
     * obligation that the caller must prove satisfied (the matching evidence key
     * in WAVE_GATE_EVIDENCE) before the gate can pass.
     *
     * @var array<string,string>
     */
    private const WAVE_GATE = [
        '0' => 'the session can answer "which doc wins in conflict?"',
        '1' => 'promoted doc passes line limits and has a clear owner',
        '2' => 'no sentence creates a new master flow',
        '2.5' => 'domain remains below Kernel, Master and Pipeline',
        '3' => 'old doc clearly points to the replacement',
        '4' => 'delete waits for a separate human-approved change',
        '5' => 'provider-safe content is explicit',
    ];

    /**
     * The evidence flag whose truth satisfies each wave's Gate. The caller raises
     * the boolean once the documented condition holds for that wave.
     *
     * @var array<string,string>
     */
    private const WAVE_GATE_EVIDENCE = [
        '0' => 'conflict_winner_decided',
        '1' => 'within_line_limits_with_owner',
        '2' => 'no_new_master_flow',
        '2.5' => 'domain_below_kernel_master_pipeline',
        '3' => 'redirect_points_to_replacement',
        '4' => 'delete_deferred_to_human_change',
        '5' => 'provider_safe_explicit',
    ];

    /**
     * The four Rollback steps, in the strict order the doc requires when a
     * cleanup creates confusion.
     *
     * @var list<string>
     */
    private const ROLLBACK_STEPS = [
        'revert_only_the_cleanup_patch',
        'restore_previous_redirect_or_header',
        'keep_archived_source_material',
        'record_cause_before_retry',
    ];

    /**
     * Evaluate one wave's gate and decide whether the session may advance.
     *
     * @param array<string,mixed> $wave
     *   wave                : string|int  the wave id (one of WAVE_ORDER keys,
     *                         e.g. "0", "2.5", 4). Unknown id => blocked.
     *   gate_satisfied      : bool        the wave-specific Gate evidence flag is
     *                         true (caller may also pass the named evidence key,
     *                         see WAVE_GATE_EVIDENCE, which takes precedence).
     *   reversible          : bool        the wave is reversible (doc invariant
     *                         "small, reversible and validated"); default true.
     *   validated           : bool        the wave ran its validation; default
     *                         true (informational unless explicitly false).
     *   runtime_touched     : bool        did the wave change runtime paths.
     *   runtime_declared    : bool        was that runtime change explicitly
     *                         declared in scope (frontmatter invariant).
     *   <evidence_key>      : bool        optionally, the exact named gate
     *                         evidence flag for this wave (overrides
     *                         gate_satisfied when present).
     *
     * @return array<string,mixed> verdict + breakdown + next wave + receipt
     */
    public function evaluateGate(array $wave): array
    {
        $waveId = $this->normalizeWaveId($wave['wave'] ?? null);
        $blockers = [];

        // Unknown wave id is an immediate block — the pipeline only knows the
        // seven canonical waves; anything else cannot be gated safely.
        if ($waveId === null) {
            return $this->blockedUnknownWave($wave['wave'] ?? null);
        }

        // --- Invariant 1: runtime is outside cleanup scope unless declared. ---
        // "Runtime changes are outside cleanup scope unless explicitly declared."
        $runtimeTouched = (bool) ($wave['runtime_touched'] ?? false);
        $runtimeDeclared = (bool) ($wave['runtime_declared'] ?? false);
        if ($runtimeTouched && ! $runtimeDeclared) {
            $blockers[] = 'runtime_touched_without_declaration';
        }

        // --- Invariant 2: cleanup waves must be reversible. ---
        // "Cleanup waves must be small, reversible and validated." Reversibility
        // and validation default to true; an explicit false blocks the gate.
        if (array_key_exists('reversible', $wave) && ! (bool) $wave['reversible']) {
            $blockers[] = 'wave_not_reversible';
        }
        if (array_key_exists('validated', $wave) && ! (bool) $wave['validated']) {
            $blockers[] = 'wave_not_validated';
        }

        // --- Wave-specific Gate evidence must be satisfied. ---
        $gateSatisfied = $this->gateEvidenceSatisfied($waveId, $wave);
        if (! $gateSatisfied) {
            $blockers[] = 'gate_evidence_missing:' . self::WAVE_GATE_EVIDENCE[$waveId];
        }

        $passed = $blockers === [];
        $verdict = $passed ? self::GATE_PASS : self::GATE_BLOCKED;
        $nextWave = $passed ? $this->nextWaveId($waveId) : $waveId;
        $isFinal = $passed && $nextWave === null;

        return [
            'schema' => self::RECEIPT_SCHEMA,
            'wave' => $waveId,
            'wave_goal' => self::WAVE_ORDER[$waveId],
            'gate' => self::WAVE_GATE[$waveId],
            'gate_evidence_key' => self::WAVE_GATE_EVIDENCE[$waveId],
            'verdict' => $verdict,
            'gate_satisfied' => $gateSatisfied,
            'may_advance' => $passed && ! $isFinal,
            'next_wave' => $nextWave,
            'pipeline_complete' => $isFinal,
            'required_next_action' => $this->requiredNextAction($passed, $isFinal),
            'blockers' => $blockers,
            'auditable' => true,
        ];
    }

    /**
     * Convenience predicate for the pipeline driver: may the session move past
     * this wave's gate? Only a passing, non-final gate advances.
     *
     * @param array<string,mixed> $wave
     */
    public function mayAdvance(array $wave): bool
    {
        return $this->evaluateGate($wave)['may_advance'] === true;
    }

    /**
     * Resolve the next wave that should run after a given wave id, in canonical
     * order. Returns null when the given wave is the final one (5).
     *
     * @param string|int|null $waveId
     */
    public function nextWave(string|int|null $waveId): ?string
    {
        $id = $this->normalizeWaveId($waveId);

        return $id === null ? null : $this->nextWaveId($id);
    }

    /**
     * Validate a proposed wave transition against the canonical order. A
     * transition is legal only when `to` is exactly the wave that follows `from`
     * (the doc lists waves sequentially; skipping or going backwards is illegal).
     *
     * @param string|int|null $from
     * @param string|int|null $to
     * @return array<string,mixed>
     */
    public function validateTransition(string|int|null $from, string|int|null $to): array
    {
        $fromId = $this->normalizeWaveId($from);
        $toId = $this->normalizeWaveId($to);

        if ($fromId === null || $toId === null) {
            return [
                'legal' => false,
                'from' => $fromId,
                'to' => $toId,
                'expected_next' => $fromId === null ? null : $this->nextWaveId($fromId),
                'reason' => 'unknown_wave_id',
            ];
        }

        $expected = $this->nextWaveId($fromId);
        $legal = $expected !== null && $expected === $toId;

        return [
            'legal' => $legal,
            'from' => $fromId,
            'to' => $toId,
            'expected_next' => $expected,
            'reason' => $legal
                ? 'sequential_advance'
                : ($expected === null ? 'already_at_final_wave' : 'must_advance_one_wave_in_order'),
        ];
    }

    /**
     * Build the strict, ordered Rollback plan for when a cleanup creates
     * confusion. The doc fixes the order: revert the cleanup patch, restore the
     * previous redirect/header, keep archived source material, record the cause
     * before retrying. The plan is the same deterministic sequence every time.
     *
     * @param array<string,mixed> $context optional { cause:string }
     * @return array<string,mixed>
     */
    public function rollbackPlan(array $context = []): array
    {
        $steps = [];
        foreach (self::ROLLBACK_STEPS as $i => $step) {
            $steps[] = [
                'order' => $i + 1,
                'step' => $step,
            ];
        }

        return [
            'schema' => self::RECEIPT_SCHEMA,
            'reason' => 'cleanup_created_confusion',
            'steps' => $steps,
            'total_steps' => count(self::ROLLBACK_STEPS),
            'preserves_history' => true,
            'cause' => trim((string) ($context['cause'] ?? '')),
            'retry_allowed_after' => 'record_cause_before_retry',
        ];
    }

    /**
     * The full canonical wave order, as an ordered list of { wave, goal, gate }.
     * Useful for the driver to render the pipeline.
     *
     * @return list<array<string,string>>
     */
    public function waveOrder(): array
    {
        $out = [];
        foreach (self::WAVE_ORDER as $id => $goal) {
            // Numeric-string keys arrive as ints from PHP; normalize to string.
            $id = (string) $id;
            $out[] = [
                'wave' => $id,
                'goal' => $goal,
                'gate' => self::WAVE_GATE[$id],
                'gate_evidence_key' => self::WAVE_GATE_EVIDENCE[$id],
            ];
        }

        return $out;
    }

    /**
     * Decide whether this wave's gate evidence is satisfied. The exact named
     * evidence key for the wave takes precedence; otherwise the generic
     * `gate_satisfied` flag is honoured.
     *
     * @param array<string,mixed> $wave
     */
    private function gateEvidenceSatisfied(string $waveId, array $wave): bool
    {
        $evidenceKey = self::WAVE_GATE_EVIDENCE[$waveId];
        if (array_key_exists($evidenceKey, $wave)) {
            return (bool) $wave[$evidenceKey];
        }

        return (bool) ($wave['gate_satisfied'] ?? false);
    }

    /**
     * Normalize a wave id to its canonical string key, or null if unknown.
     * Accepts "0", 0, "2.5", 2.5, 4, etc.
     *
     * @param string|int|float|null $value
     */
    private function normalizeWaveId(string|int|float|null $value): ?string
    {
        if ($value === null) {
            return null;
        }

        if (is_string($value)) {
            $candidate = trim($value);
        } elseif (is_float($value)) {
            // 2.5 must stay "2.5"; 2.0 must collapse to "2".
            $candidate = rtrim(rtrim(sprintf('%.1f', $value), '0'), '.');
        } else {
            $candidate = (string) $value;
        }

        return array_key_exists($candidate, self::WAVE_ORDER) ? $candidate : null;
    }

    /**
     * The id of the wave that follows `$waveId` in canonical order, or null when
     * `$waveId` is the final wave.
     */
    private function nextWaveId(string $waveId): ?string
    {
        // PHP coerces numeric-string array keys to ints ("0" => 0), so cast every
        // key back to string to keep "2.5" exact and the comparison type-safe.
        $keys = array_map('strval', array_keys(self::WAVE_ORDER));
        $pos = array_search($waveId, $keys, true);
        if ($pos === false) {
            return null;
        }
        $nextPos = $pos + 1;

        return $nextPos < count($keys) ? $keys[$nextPos] : null;
    }

    private function requiredNextAction(bool $passed, bool $isFinal): string
    {
        if (! $passed) {
            return self::NEXT_STAY;
        }

        return $isFinal ? self::NEXT_PIPELINE_COMPLETE : self::NEXT_ADVANCE;
    }

    /**
     * @param mixed $rawWave
     * @return array<string,mixed>
     */
    private function blockedUnknownWave(mixed $rawWave): array
    {
        return [
            'schema' => self::RECEIPT_SCHEMA,
            'wave' => null,
            'wave_goal' => null,
            'gate' => null,
            'gate_evidence_key' => null,
            'verdict' => self::GATE_BLOCKED,
            'gate_satisfied' => false,
            'may_advance' => false,
            'next_wave' => null,
            'pipeline_complete' => false,
            'required_next_action' => self::NEXT_STAY,
            'blockers' => ['unknown_wave:' . (is_scalar($rawWave) ? (string) $rawWave : 'non_scalar')],
            'auditable' => true,
        ];
    }
}
